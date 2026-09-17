<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class SecurityHeadersTest extends TestCase
{
    private $serverBackup;
    private $configBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->configBackup = isset($GLOBALS['config']) ? $GLOBALS['config'] : array();
        $GLOBALS['config'] = array();
        unset($_SERVER['HTTPS']);
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['SERVER_PORT'] = 80;
        gojs_security_headers_reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $GLOBALS['config'] = $this->configBackup;
        gojs_security_headers_reset();
        parent::tearDown();
    }

    private $sent = array();

    private function sink()
    {
        $this->sent = array();
        return function ($line) {
            $this->sent[] = $line;
        };
    }

    public function testBaselinePolicyCoversCoreHardeningHeaders(): void
    {
        $policy = gojs_security_headers_policy('api');

        $this->assertSame('nosniff', $policy['X-Content-Type-Options']);
        $this->assertSame('DENY', $policy['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $policy['Referrer-Policy']);
        $this->assertArrayHasKey('Permissions-Policy', $policy);
        $this->assertSame('same-origin', $policy['Cross-Origin-Opener-Policy']);
        $this->assertSame('same-origin', $policy['Cross-Origin-Resource-Policy']);
        $this->assertSame('none', $policy['X-Permitted-Cross-Domain-Policies']);
    }

    public function testPermissionsPolicyDisablesSensitiveFeatures(): void
    {
        $policy = gojs_security_headers_policy('api');

        foreach (array('camera', 'geolocation', 'microphone', 'payment', 'usb') as $feature) {
            $this->assertStringContainsString($feature . '=()', $policy['Permissions-Policy']);
        }
    }

    public function testHstsIsAbsentOnPlainHttp(): void
    {
        $policy = gojs_security_headers_policy('api');

        $this->assertArrayNotHasKey('Strict-Transport-Security', $policy);
    }

    public function testHstsIsSentWhenConnectionIsHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $policy = gojs_security_headers_policy('api');

        $this->assertArrayHasKey('Strict-Transport-Security', $policy);
        $this->assertStringContainsString('max-age=31536000', $policy['Strict-Transport-Security']);
    }

    public function testHstsHonoursForwardedProtoBehindAProxy(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertTrue(gojs_security_headers_is_https());
        $this->assertArrayHasKey('Strict-Transport-Security', gojs_security_headers_policy('api'));
    }

    public function testHttpsDetectionIgnoresForwardedOffValue(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $_SERVER['SERVER_PORT'] = 8080;

        $this->assertFalse(gojs_security_headers_is_https());
    }

    public function testApiContextLocksDownDocumentExecution(): void
    {
        $csp = gojs_security_headers_csp('api');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);
    }

    public function testHtmlContextAllowsPanelAssets(): void
    {
        $csp = gojs_security_headers_csp('html');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function testHtmlContextNeverAllowsRemoteOrigins(): void
    {
        $csp = gojs_security_headers_csp('html');

        $this->assertStringNotContainsString('http://', $csp);
        $this->assertStringNotContainsString('https://', $csp);
        $this->assertStringNotContainsString('*', $csp);
    }

    public function testUnknownContextFallsBackToTheStrictestPolicy(): void
    {
        $this->assertSame(gojs_security_headers_csp('api'), gojs_security_headers_csp('nope'));
    }

    public function testContextsAreDeclared(): void
    {
        $contexts = gojs_security_headers_contexts();

        $this->assertContains('api', $contexts);
        $this->assertContains('html', $contexts);
    }

    public function testBuildProducesNameValueLines(): void
    {
        $lines = gojs_security_headers_build('api');

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertNotFalse(strpos($line, ': '));
        }
        $this->assertContains('X-Frame-Options: DENY', $lines);
    }

    public function testBuildBaseOmitsContentSecurityPolicy(): void
    {
        $lines = gojs_security_headers_build_base();

        foreach ($lines as $line) {
            $this->assertStringStartsNotWith('Content-Security-Policy', $line);
        }
        $this->assertContains('X-Content-Type-Options: nosniff', $lines);
    }

    public function testApplySendsEachHeaderOnlyOnce(): void
    {
        $sink = $this->sink();

        gojs_security_headers_apply('api', $sink);
        $first = count($this->sent);
        gojs_security_headers_apply('api', $sink);

        $this->assertGreaterThan(3, $first);
        $this->assertSame($first, count($this->sent));
    }

    public function testApplyAfterBaseStillDeliversContentSecurityPolicy(): void
    {
        $sink = $this->sink();

        gojs_security_headers_apply_base($sink);
        gojs_security_headers_apply('api', $sink);

        $found = false;
        foreach ($this->sent as $line) {
            if (strpos($line, 'Content-Security-Policy: ') === 0) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testResetAllowsThePolicyToBeSentAgain(): void
    {
        $sink = $this->sink();

        gojs_security_headers_apply('api', $sink);
        $first = count($this->sent);
        gojs_security_headers_reset();
        gojs_security_headers_apply('api', $sink);

        $this->assertSame($first * 2, count($this->sent));
    }

    public function testConfigurationCanOverrideADirective(): void
    {
        $GLOBALS['config']['security_headers'] = array(
            'overrides' => array('X-Frame-Options' => 'SAMEORIGIN'),
        );

        $policy = gojs_security_headers_policy('api');

        $this->assertSame('SAMEORIGIN', $policy['X-Frame-Options']);
    }

    public function testOverrideMatchesHeaderNamesCaseInsensitively(): void
    {
        $GLOBALS['config']['security_headers'] = array(
            'overrides' => array('x-frame-options' => 'SAMEORIGIN'),
        );

        $policy = gojs_security_headers_policy('api');

        $this->assertSame('SAMEORIGIN', $policy['X-Frame-Options']);
        $this->assertArrayNotHasKey('x-frame-options', $policy);
    }

    public function testOverrideCanAddAnExtraHeader(): void
    {
        $GLOBALS['config']['security_headers'] = array(
            'overrides' => array('X-Custom-Policy' => 'strict'),
        );

        $policy = gojs_security_headers_policy('api');

        $this->assertSame('strict', $policy['X-Custom-Policy']);
    }

    public function testConfigurationCanRemoveADirective(): void
    {
        $GLOBALS['config']['security_headers'] = array(
            'disable' => array('X-Frame-Options'),
        );

        $policy = gojs_security_headers_policy('api');

        $this->assertArrayNotHasKey('X-Frame-Options', $policy);
        $this->assertArrayHasKey('X-Content-Type-Options', $policy);
    }

    public function testDisableIsCaseInsensitive(): void
    {
        $GLOBALS['config']['security_headers'] = array(
            'disable' => array('permissions-policy'),
        );

        $policy = gojs_security_headers_policy('api');

        $this->assertArrayNotHasKey('Permissions-Policy', $policy);
    }

    public function testConfigurationCanReplaceTheContentSecurityPolicyPerContext(): void
    {
        $GLOBALS['config']['security_headers'] = array(
            'csp' => array('html' => "default-src 'self'; script-src 'self' cdn.example.com"),
        );

        $this->assertSame("default-src 'self'; script-src 'self' cdn.example.com", gojs_security_headers_csp('html'));
        $this->assertStringContainsString("default-src 'none'", gojs_security_headers_csp('api'));
    }

    public function testMalformedConfigurationIsIgnored(): void
    {
        $GLOBALS['config']['security_headers'] = 'not-an-array';

        $policy = gojs_security_headers_policy('api');

        $this->assertSame('nosniff', $policy['X-Content-Type-Options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy['Content-Security-Policy']);
    }

    public function testNonStringDirectivesAreIgnored(): void
    {
        $GLOBALS['config']['security_headers'] = array(
            'disable' => array(123, null, ''),
            'overrides' => array('X-Frame-Options' => array('nested')),
            'csp' => array('html' => 42),
        );

        $settings = gojs_security_headers_settings();
        $policy = gojs_security_headers_policy('api');

        $this->assertSame(array(), $settings['disable']);
        $this->assertSame(array(), $settings['overrides']);
        $this->assertSame(array(), $settings['csp']);
        $this->assertSame('DENY', $policy['X-Frame-Options']);
    }

    public function testReportDescribesEveryContext(): void
    {
        $report = gojs_security_headers_report();

        $this->assertFalse($report['https']);
        $this->assertSame('api', $report['defaultContext']);
        $this->assertArrayHasKey('api', $report['contexts']);
        $this->assertArrayHasKey('html', $report['contexts']);
        $this->assertSame($report['headers'], $report['contexts']['api']);
        $this->assertSame(count($report['headers']), $report['count']);
    }

    public function testReportFollowsConfiguration(): void
    {
        $GLOBALS['config']['security_headers'] = array(
            'disable' => array('Referrer-Policy'),
        );

        $report = gojs_security_headers_report();

        $this->assertArrayNotHasKey('Referrer-Policy', $report['headers']);
    }
}
