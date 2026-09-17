<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class DeprecationsTest extends TestCase
{
    private $serverBackup = array();
    private $getBackup = array();
    private $sessionBackup = array();
    private $configBackup = array();

    protected function setUp(): void
    {
        parent::setUp();

        unset($GLOBALS['gojs_deprecations_emitted']);

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->sessionBackup = isset($_SESSION) ? $_SESSION : array();
        $this->configBackup = isset($GLOBALS['config']) ? $GLOBALS['config'] : array();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_SESSION = $this->sessionBackup;
        $GLOBALS['config'] = $this->configBackup;

        unset($GLOBALS['gojs_deprecations_emitted']);

        parent::tearDown();
    }

    public function testRegistryCoversTheQueryApiAndTheLegacyAccessToken(): void
    {
        $registry = gojs_deprecation_registry();

        $this->assertArrayHasKey('query_api', $registry);
        $this->assertArrayHasKey('legacy_access_token', $registry);
        $this->assertSame(array('query_api', 'legacy_access_token'), gojs_deprecation_ids());
    }

    public function testEveryEntryIsScheduledForRemovalInOnePointZero(): void
    {
        foreach (gojs_deprecation_registry() as $id => $entry) {
            $this->assertSame('0.8.0', $entry['deprecated_in'], $id);
            $this->assertSame('1.0.0', $entry['remove_in'], $id);
            $this->assertNotEmpty($entry['target'], $id);
            $this->assertNotEmpty($entry['replacement'], $id);
            $this->assertNotEmpty($entry['message'], $id);
            $this->assertNotEmpty($entry['docs'], $id);
            $this->assertNotSame($entry['target'], $entry['replacement'], $id);
        }
    }

    public function testSunsetIsLaterThanTheDeprecationDate(): void
    {
        foreach (gojs_deprecation_registry() as $id => $entry) {
            $deprecatedAt = strtotime($entry['deprecated_at']);
            $sunsetAt = strtotime($entry['sunset_at']);

            $this->assertNotFalse($deprecatedAt, $id);
            $this->assertNotFalse($sunsetAt, $id);
            $this->assertGreaterThan($deprecatedAt, $sunsetAt, $id);
        }
    }

    public function testHttpDateIsRfcFormatted(): void
    {
        $this->assertSame('Wed, 30 Jun 2027 00:00:00 GMT', gojs_deprecation_http_date('2027-06-30T00:00:00Z'));
        $this->assertSame('', gojs_deprecation_http_date('not-a-date'));
    }

    public function testNoticePayloadCarriesTheRemovalSchedule(): void
    {
        $payload = gojs_deprecation_payload();

        $this->assertArrayHasKey('query_api', $payload);
        $this->assertArrayHasKey('legacy_access_token', $payload);

        $notice = $payload['query_api'];

        $this->assertTrue($notice['deprecated']);
        $this->assertSame('1.0.0', $notice['removeIn']);
        $this->assertSame('0.8.0', $notice['deprecatedIn']);
        $this->assertSame('api.php?api=<action>', $notice['target']);
        $this->assertSame('/api/<action>', $notice['replacement']);
    }

    public function testEmitRecordsEachIdOnlyOnce(): void
    {
        $this->assertSame(array(), gojs_deprecation_emitted());

        $this->assertTrue(gojs_deprecation_emit('query_api'));
        $this->assertTrue(gojs_deprecation_emit('query_api'));
        $this->assertTrue(gojs_deprecation_emit('legacy_access_token'));

        $emitted = gojs_deprecation_emitted();

        $this->assertSame(array('query_api', 'legacy_access_token'), array_keys($emitted));
        $this->assertArrayHasKey('removeIn', $emitted['legacy_access_token']);
    }

    public function testEmitRejectsUnknownIds(): void
    {
        $this->assertFalse(gojs_deprecation_emit('does_not_exist'));
        $this->assertSame(array(), gojs_deprecation_emitted());
    }

    public function testQueryFormIsDetectedFromTheRequestUri(): void
    {
        $_SERVER['REQUEST_URI'] = '/gojs/api.php?api=files';
        $_SERVER['QUERY_STRING'] = 'api=files';
        $_GET = array('api' => 'files');

        $this->assertSame('query', gojs_request_api_style());
    }

    public function testPathFormIsDetectedWithAndWithoutADeploymentPrefix(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/files';
        $_SERVER['QUERY_STRING'] = '';
        $_GET = array('api' => 'files');

        $this->assertSame('path', gojs_request_api_style());

        $_SERVER['REQUEST_URI'] = '/gojs/api/files';
        $this->assertSame('path', gojs_request_api_style());

        $_SERVER['REQUEST_URI'] = '/gojs/api';
        $this->assertSame('path', gojs_request_api_style());
    }

    public function testLegacyAccessTokenUsageIsReportedAsDeprecated(): void
    {
        global $config;

        $config = array('installed' => true, 'access_token' => 'legacy-token-value');
        $GLOBALS['config'] = $config;
        $_GET = array('token' => 'legacy-token-value');
        $_SESSION = array();

        gojs_check_access_token();

        $this->assertArrayHasKey('legacy_access_token', gojs_deprecation_emitted());
    }

    public function testLegacyAccessTokenHeaderIsReportedAsDeprecated(): void
    {
        global $config;

        $config = array('installed' => true, 'access_token' => 'legacy-token-value');
        $GLOBALS['config'] = $config;
        $_GET = array();
        $_SESSION = array();
        $_SERVER['HTTP_X_ACCESS_TOKEN'] = 'legacy-token-value';

        gojs_check_access_token();

        $this->assertArrayHasKey('legacy_access_token', gojs_deprecation_emitted());
    }

    public function testNoDeprecationIsRaisedWithoutALegacyCredential(): void
    {
        global $config;

        $config = array('installed' => true, 'access_token' => 'legacy-token-value');
        $GLOBALS['config'] = $config;
        $_GET = array();
        $_SESSION = array();
        unset($_SERVER['HTTP_X_ACCESS_TOKEN']);

        gojs_check_access_token();

        $this->assertSame(array(), gojs_deprecation_emitted());
    }
}
