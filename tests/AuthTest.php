<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 认证模块（backend/auth.php）纯逻辑单元测试。
 */
class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 隔离上下文与全局状态
        \GoJS_Context::instance()->reset();
        // 为密封/解密提供稳定的加密密钥
        $GLOBALS['config'] = array(
            'encryption_key' => 'test-encryption-key-0123456789abcdef',
        );
    }

    protected function tearDown(): void
    {
        \GoJS_Context::instance()->reset();
        $GLOBALS['config'] = array();
        parent::tearDown();
    }

    public function testTotpGenerateSecretIsAlphanumericBase32(): void
    {
        $secret = gojs_totp_generate_secret(20);
        $this->assertIsString($secret);
        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testTotpComputeReturnsSixDigits(): void
    {
        $secret = gojs_totp_generate_secret(20);
        $code = gojs_totp_compute($secret, 1700000000);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testTotpValidateAcceptsComputedCode(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $code = gojs_totp_compute($secret, time());
        $this->assertTrue(gojs_totp_validate($secret, $code, 1));
    }

    public function testTotpValidateRejectsWrongCode(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $this->assertFalse(gojs_totp_validate($secret, '000000', 1));
    }

    public function testTotpValidateIgnoresNonDigits(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $code = gojs_totp_compute($secret, time());
        $this->assertTrue(gojs_totp_validate($secret, ' ' . $code . 'x'));
    }

    public function testCryptoGetRandAlphanumLength(): void
    {
        foreach (array(8, 16, 32) as $len) {
            $value = gojs_crypto_get_rand_alphanum($len);
            $this->assertEquals($len, strlen($value));
            $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', $value);
        }
    }

    public function testTotpBuildQrSvgDataUrl(): void
    {
        $url = gojs_totp_build_qr_svg_data_url('Go.js Panel', 'admin', 'JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('data:image/svg+xml;utf8,', $url);
        $decoded = rawurldecode($url);
        $this->assertStringContainsString('otpauth://totp/', $decoded);
    }

    public function testSealAndUnsealSecretRoundtrip(): void
    {
        $plain = 'gojs_secret_123';
        $sealed = gojs_seal_secret($plain);
        $this->assertNotSame($plain, $sealed);
        $this->assertSame($plain, gojs_unseal_secret($sealed));
    }

    public function testSealSecretEmptyReturnsEmpty(): void
    {
        $this->assertSame('', gojs_seal_secret(''));
        $this->assertFalse(gojs_unseal_secret(''));
        $this->assertFalse(gojs_unseal_secret('****'));
    }

    public function testDangerousFilenameDetection(): void
    {
        $this->assertTrue(gojs_is_dangerous_filename('shell.php'));
        $this->assertTrue(gojs_is_dangerous_filename('evil.phtml'));
        $this->assertTrue(gojs_is_dangerous_filename('a.b.pHp'));
        $this->assertFalse(gojs_is_dangerous_filename('readme.txt'));
        $this->assertFalse(gojs_is_dangerous_filename('photo.jpg'));
    }

    public function testValidateUploadFilenameRejectsBadNames(): void
    {
        $this->assertFalse(gojs_validate_upload_filename(''));
        $this->assertFalse(gojs_validate_upload_filename('.'));
        $this->assertFalse(gojs_validate_upload_filename('..'));
        $this->assertFalse(gojs_validate_upload_filename('a/b.php'));
        $this->assertFalse(gojs_validate_upload_filename('a\\b.php'));
        $this->assertFalse(gojs_validate_upload_filename('script.php'));
        $this->assertFalse(gojs_validate_upload_filename('bad:name.txt'));
        $this->assertTrue(gojs_validate_upload_filename('valid.txt'));
        $this->assertTrue(gojs_validate_upload_filename('photo-2024.jpg'));
    }

    public function testCheckBruteForceNoLock(): void
    {
        // 无 AUTH_LOG 时不应锁定
        $result = gojs_check_brute_force();
        $this->assertArrayHasKey('locked', $result);
        $this->assertFalse($result['locked']);
    }

    public function testCheckBruteForceLocksAfterFailures(): void
    {
        $ip = '203.0.113.9';
        $lines = '';
        for ($i = 0; $i < 5; $i++) {
            $lines .= json_encode(array(
                'ip' => $ip,
                'time' => time(),
                'success' => false,
            )) . "\n";
        }
        @file_put_contents(AUTH_LOG, $lines, LOCK_EX);

        $_SERVER['REMOTE_ADDR'] = $ip;
        $oldConfig = $GLOBALS['config'];
        $GLOBALS['config'] = array();

        $result = gojs_check_brute_force();
        $this->assertTrue($result['locked']);
        $this->assertGreaterThan(0, $result['retry_after']);

        $GLOBALS['config'] = $oldConfig;
        unset($_SERVER['REMOTE_ADDR']);
        @unlink(AUTH_LOG);
    }
}