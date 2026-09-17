<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class SessionFingerprintTest extends TestCase
{
    private $sessionBackup;
    private $serverBackup;
    private $configBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = isset($_SESSION) ? $_SESSION : array();
        $this->serverBackup = $_SERVER;
        $this->configBackup = isset($GLOBALS['config']) ? $GLOBALS['config'] : array();
        $GLOBALS['config'] = array();
        $_SESSION = array();
        $_SESSION['user_id'] = 'u_admin';
        $this->client('203.0.113.10', 'Mozilla/5.0 (Test Browser)', 'en-US,en;q=0.9');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        $_SERVER = $this->serverBackup;
        $GLOBALS['config'] = $this->configBackup;
        parent::tearDown();
    }

    private function client($ip, $ua, $lang)
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $lang;
    }

    private function authenticate()
    {
        $_SESSION['authenticated'] = true;
    }

    private function signals($ip = '203.0.113.10', $ua = 'Mozilla/5.0 (Test Browser)', $lang = 'en-US,en;q=0.9')
    {
        return gojs_session_fingerprint_signals(array(
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $ua,
            'HTTP_ACCEPT_LANGUAGE' => $lang,
        ));
    }

    public function testAnonymousRequestIsNotApplicable(): void
    {
        $result = gojs_session_fingerprint_verify(1000);

        $this->assertTrue($result['ok']);
        $this->assertSame('not_applicable', $result['code']);
        $this->assertFalse($result['issued']);
        $this->assertArrayNotHasKey('fp_value', $_SESSION);
    }

    public function testFirstAuthenticatedRequestIssuesAFingerprint(): void
    {
        $this->authenticate();

        $result = gojs_session_fingerprint_verify(1000);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['issued']);
        $this->assertFalse($result['rotated']);
        $this->assertSame(0, $result['rotations']);
        $this->assertSame(64, strlen($_SESSION['fp_salt']));
        $this->assertSame(64, strlen($_SESSION['fp_value']));
        $this->assertSame(1000, $_SESSION['fp_issued_at']);
    }

    public function testStableClientKeepsTheBinding(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        $bound = $_SESSION['fp_value'];

        $result = gojs_session_fingerprint_verify(1200);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['code']);
        $this->assertFalse($result['rotated']);
        $this->assertSame($bound, $_SESSION['fp_value']);
        $this->assertSame(200, $result['age']);
    }

    public function testNoRotationBeforeTheInterval(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        $bound = $_SESSION['fp_value'];

        $result = gojs_session_fingerprint_verify(1899);

        $this->assertFalse($result['rotated']);
        $this->assertSame(0, $result['rotations']);
        $this->assertSame($bound, $_SESSION['fp_value']);
    }

    public function testRotationAfterTheInterval(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        $bound = $_SESSION['fp_value'];
        $salt = $_SESSION['fp_salt'];

        $result = gojs_session_fingerprint_verify(1900);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['rotated']);
        $this->assertSame(1, $result['rotations']);
        $this->assertSame(1, $_SESSION['fp_rotations']);
        $this->assertNotSame($bound, $_SESSION['fp_value']);
        $this->assertNotSame($salt, $_SESSION['fp_salt']);
        $this->assertSame(1900, $_SESSION['fp_issued_at']);
        $this->assertSame(0, $result['age']);
    }

    public function testRotationKeepsTheSessionUsable(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        gojs_session_fingerprint_verify(1900);

        $result = gojs_session_fingerprint_verify(2000);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['rotated']);
        $this->assertSame(1, $result['rotations']);
    }

    public function testRotationCountsAcrossIntervals(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        gojs_session_fingerprint_verify(1900);
        gojs_session_fingerprint_verify(2800);

        $this->assertSame(2, gojs_session_fingerprint_state()['rotations']);
    }

    public function testRotationIntervalIsConfigurable(): void
    {
        $GLOBALS['config']['session_fingerprint'] = array('rotate_seconds' => 120);
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);

        $this->assertFalse(gojs_session_fingerprint_verify(1119)['rotated']);
        $this->assertTrue(gojs_session_fingerprint_verify(1120)['rotated']);
    }

    public function testRotationIntervalHasALowerBound(): void
    {
        $GLOBALS['config']['session_fingerprint'] = array('rotate_seconds' => 1);

        $this->assertSame(60, gojs_session_fingerprint_settings()['rotate_seconds']);
    }

    public function testChangedUserAgentIsRejectedWhenEnforced(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);

        $this->client('203.0.113.10', 'Mozilla/5.0 (Another Browser)', 'en-US,en;q=0.9');
        $result = gojs_session_fingerprint_verify(1100);

        $this->assertFalse($result['ok']);
        $this->assertSame('session_fingerprint_mismatch', $result['code']);
        $this->assertSame(1, $result['mismatches']);
        $this->assertSame(array(), $_SESSION);
    }

    public function testChangedSubnetIsRejectedWhenEnforced(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);

        $this->client('198.51.100.10', 'Mozilla/5.0 (Test Browser)', 'en-US,en;q=0.9');
        $result = gojs_session_fingerprint_verify(1100);

        $this->assertFalse($result['ok']);
        $this->assertSame('session_fingerprint_mismatch', $result['code']);
    }

    public function testChangedLanguageIsRejectedWhenEnforced(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);

        $this->client('203.0.113.10', 'Mozilla/5.0 (Test Browser)', 'de-DE,de;q=0.9');
        $result = gojs_session_fingerprint_verify(1100);

        $this->assertFalse($result['ok']);
        $this->assertSame('session_fingerprint_mismatch', $result['code']);
    }

    public function testChangedUserAgentRefreshesWhenEnforcementIsOff(): void
    {
        $GLOBALS['config']['session_fingerprint'] = array('enforce' => false);
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        $bound = $_SESSION['fp_value'];

        $this->client('203.0.113.10', 'Mozilla/5.0 (Another Browser)', 'en-US,en;q=0.9');
        $result = gojs_session_fingerprint_verify(1100);

        $this->assertTrue($result['ok']);
        $this->assertSame('session_fingerprint_refreshed', $result['code']);
        $this->assertTrue($result['issued']);
        $this->assertNotSame($bound, $_SESSION['fp_value']);
        $this->assertTrue($_SESSION['authenticated']);
        $this->assertSame(1, $result['mismatches']);
    }

    public function testRefreshedSessionAcceptsTheNewClient(): void
    {
        $GLOBALS['config']['session_fingerprint'] = array('enforce' => false);
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);

        $this->client('203.0.113.10', 'Mozilla/5.0 (Another Browser)', 'en-US,en;q=0.9');
        gojs_session_fingerprint_verify(1100);

        $result = gojs_session_fingerprint_verify(1200);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['code']);
        $this->assertFalse($result['rotated']);
    }

    public function testMismatchCounterIsCumulativeWhenNotEnforced(): void
    {
        $GLOBALS['config']['session_fingerprint'] = array('enforce' => false);
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);

        $this->client('198.51.100.10', 'Mozilla/5.0 (Test Browser)', 'en-US,en;q=0.9');
        gojs_session_fingerprint_verify(1100);
        $this->assertSame(1, gojs_session_fingerprint_state()['mismatches']);

        $this->client('192.0.2.5', 'Mozilla/5.0 (Test Browser)', 'en-US,en;q=0.9');
        gojs_session_fingerprint_verify(1200);

        $state = gojs_session_fingerprint_state();
        $this->assertSame(2, $state['mismatches']);
        $this->assertSame(1200, $state['last_mismatch_at']);
    }

    public function testIpPrefixToleratesANewAddressInsideTheSameSubnet(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        $bound = $_SESSION['fp_value'];

        $this->client('203.0.113.77', 'Mozilla/5.0 (Test Browser)', 'en-US,en;q=0.9');
        $result = gojs_session_fingerprint_verify(1100);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['rotated']);
        $this->assertSame($bound, $_SESSION['fp_value']);
    }

    public function testIpv4PrefixKeepsTheFirstThreeOctets(): void
    {
        $this->assertSame('192.168.1', gojs_session_fingerprint_ip_prefix('192.168.1.44'));
        $this->assertSame('10.0.0', gojs_session_fingerprint_ip_prefix('10.0.0.1'));
    }

    public function testIpv6PrefixKeepsTheFirstThreeHextets(): void
    {
        $this->assertSame('2001:db8:85a3', gojs_session_fingerprint_ip_prefix('2001:DB8:85a3::8a2e:370:7334'));
    }

    public function testEmptyOrInvalidIpYieldsAnEmptyPrefix(): void
    {
        $this->assertSame('', gojs_session_fingerprint_ip_prefix(''));
        $this->assertSame('', gojs_session_fingerprint_ip_prefix('not-an-ip'));
    }

    public function testLanguageIsNormalisedToThePrimaryTag(): void
    {
        $signals = $this->signals('203.0.113.10', 'ua', 'ZH-CN,zh;q=0.9,en;q=0.8');

        $this->assertSame('zh-cn', $signals['lang']);
    }

    public function testMissingLanguageYieldsAnEmptySignal(): void
    {
        $signals = gojs_session_fingerprint_signals(array('REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'ua'));

        $this->assertSame('', $signals['lang']);
    }

    public function testSignalsCanBeNarrowedToTheUserAgent(): void
    {
        $GLOBALS['config']['session_fingerprint'] = array('signals' => array('ua'));
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        $bound = $_SESSION['fp_value'];

        $this->client('198.51.100.10', 'Mozilla/5.0 (Test Browser)', 'de-DE,de;q=0.9');
        $this->assertTrue(gojs_session_fingerprint_verify(1100)['ok']);
        $this->assertSame($bound, $_SESSION['fp_value']);

        $this->client('198.51.100.10', 'Mozilla/5.0 (Another Browser)', 'de-DE,de;q=0.9');
        $this->assertFalse(gojs_session_fingerprint_verify(1200)['ok']);
    }

    public function testUnknownSignalNamesAreIgnored(): void
    {
        $GLOBALS['config']['session_fingerprint'] = array('signals' => array('ua', 'nope', 42));

        $this->assertSame(array('ua'), gojs_session_fingerprint_settings()['signals']);
    }

    public function testSignalNamesAreDeduplicated(): void
    {
        $GLOBALS['config']['session_fingerprint'] = array('signals' => array('ua', 'ua', 'ip'));

        $this->assertSame(array('ua', 'ip'), gojs_session_fingerprint_settings()['signals']);
    }

    public function testEnforcementCanBeConfigured(): void
    {
        $this->assertTrue(gojs_session_fingerprint_settings()['enforce']);

        $GLOBALS['config']['session_fingerprint'] = array('enforce' => false);

        $this->assertFalse(gojs_session_fingerprint_settings()['enforce']);
    }

    public function testMalformedConfigurationIsIgnored(): void
    {
        $GLOBALS['config']['session_fingerprint'] = 'not-an-array';

        $settings = gojs_session_fingerprint_settings();

        $this->assertTrue($settings['enforce']);
        $this->assertSame(900, $settings['rotate_seconds']);
        $this->assertSame(array('ip', 'ua', 'lang'), $settings['signals']);
    }

    public function testComputeDependsOnTheSalt(): void
    {
        $signals = $this->signals();

        $this->assertNotSame(
            gojs_session_fingerprint_compute('salt-a', $signals),
            gojs_session_fingerprint_compute('salt-b', $signals)
        );
    }

    public function testComputeDependsOnEverySignal(): void
    {
        $base = $this->signals();
        $changed = $base;
        $changed['ua'] = hash('sha256', 'other');

        $this->assertNotSame(
            gojs_session_fingerprint_compute('salt', $base),
            gojs_session_fingerprint_compute('salt', $changed)
        );
    }

    public function testSaltsAreRandom(): void
    {
        $first = gojs_session_fingerprint_new_salt();
        $second = gojs_session_fingerprint_new_salt();

        $this->assertSame(64, strlen($first));
        $this->assertNotSame($first, $second);
    }

    public function testStateClearRemovesEveryKey(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        $_SESSION['keep'] = 'value';

        gojs_session_fingerprint_state_clear();
        $state = gojs_session_fingerprint_state();

        $this->assertSame('', $state['value']);
        $this->assertSame('', $state['salt']);
        $this->assertSame(0, $state['issued_at']);
        $this->assertSame('value', $_SESSION['keep']);
    }

    public function testReportDescribesTheBinding(): void
    {
        $this->authenticate();
        $report = gojs_session_fingerprint_report();

        $this->assertFalse($report['bound']);
        $this->assertTrue($report['enforce']);
        $this->assertSame(900, $report['rotateSeconds']);
        $this->assertSame(array('ip', 'ua', 'lang'), $report['signals']);
        $this->assertSame(0, $report['rotations']);
        $this->assertSame(0, $report['lastMismatchAt']);

        gojs_session_fingerprint_verify(1000);
        $report = gojs_session_fingerprint_report();

        $this->assertTrue($report['bound']);
        $this->assertSame(1000, $report['issuedAt']);
        $this->assertSame(0, $report['rotations']);
    }

    public function testReportCountsRotations(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);
        gojs_session_fingerprint_verify(1900);

        $this->assertSame(1, gojs_session_fingerprint_report()['rotations']);
    }

    public function testReportNeverExposesTheFingerprint(): void
    {
        $this->authenticate();
        gojs_session_fingerprint_verify(1000);

        $report = gojs_session_fingerprint_report();
        $encoded = json_encode($report);

        $this->assertStringNotContainsString($_SESSION['fp_value'], $encoded);
        $this->assertStringNotContainsString($_SESSION['fp_salt'], $encoded);
    }
}
