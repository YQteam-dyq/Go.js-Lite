<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class TokensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/api_tokens.json');
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/api_tokens.json');
        parent::tearDown();
    }

    public function testCreateReturnsPlainOnceAndStoresHash(): void
    {
        $r = gojs_tokens_create('ci', array('admin'), '', 60, 0, 'u_admin', 'admin');
        $this->assertTrue($r['ok']);
        $token = $r['token'];
        $this->assertArrayHasKey('token_plain_once', $token);
        $this->assertStringStartsWith('gojs_', $token['token_plain_once']);

        $store = gojs_tokens_load();
        $this->assertCount(1, $store['tokens']);
        $this->assertArrayHasKey('token_hash', $store['tokens'][0]);
        $this->assertArrayNotHasKey('token_plain_once', $store['tokens'][0]);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $r = gojs_tokens_create('', array('admin'), '', 60, 0, 'u_admin', 'admin');
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_name', $r['code']);
    }

    public function testCreateRejectsEmptyScopes(): void
    {
        $r = gojs_tokens_create('ci', array(), '', 60, 0, 'u_admin', 'admin');
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_scopes', $r['code']);
    }

    public function testOperatorCannotCreateAdminScope(): void
    {
        $r = gojs_tokens_create('ci', array('admin'), '', 60, 0, 'u_op', 'operator');
        $this->assertFalse($r['ok']);
        $this->assertSame('scope_not_allowed', $r['code']);
    }

    public function testOperatorCanCreateReadonlyScope(): void
    {
        $r = gojs_tokens_create('ci', array('readonly', 'user-self'), '', 60, 0, 'u_op', 'operator');
        $this->assertTrue($r['ok']);
    }

    public function testSanitizeStripsHash(): void
    {
        $r = gojs_tokens_create('ci', array('admin'), '', 60, 0, 'u_admin', 'admin');
        $clean = gojs_tokens_sanitize($r['token']);
        $this->assertArrayNotHasKey('token_hash', $clean);
        $this->assertSame('ci', $clean['name']);
    }

    public function testFindByPlainMatchesOnlyCorrectToken(): void
    {
        $r = gojs_tokens_create('ci', array('admin'), '', 60, 0, 'u_admin', 'admin');
        $plain = $r['token']['token_plain_once'];

        $found = gojs_tokens_find_by_plain($plain);
        $this->assertNotNull($found);
        $this->assertSame($r['token']['id'], $found['id']);
        $this->assertNull(gojs_tokens_find_by_plain('gojs_deadbeef'));
    }

    public function testListFiltersByCreator(): void
    {
        gojs_tokens_create('a', array('admin'), '', 60, 0, 'u_admin', 'admin');
        gojs_tokens_create('b', array('readonly'), '', 60, 0, 'u_op', 'operator');

        $this->assertCount(2, gojs_tokens_list());
        $this->assertCount(1, gojs_tokens_list('u_op'));
        $this->assertSame('b', gojs_tokens_list('u_op')[0]['name']);
    }

    public function testRevokeMarksToken(): void
    {
        $r = gojs_tokens_create('ci', array('admin'), '', 60, 0, 'u_admin', 'admin');
        $id = $r['token']['id'];
        $this->assertTrue(gojs_tokens_revoke($id)['ok']);
        $this->assertTrue(gojs_tokens_find($id)['revoked']);
        $this->assertFalse(gojs_tokens_revoke('tok_nope')['ok']);
    }

    public function testScopeAllowsAdminEverything(): void
    {
        $this->assertTrue(gojs_token_scope_allows(array('admin'), 'files', 'DELETE'));
        $this->assertTrue(gojs_token_scope_allows(array('admin'), 'profile', 'GET'));
    }

    public function testScopeReadonlyOnlyGets(): void
    {
        $this->assertTrue(gojs_token_scope_allows(array('readonly'), 'files', 'GET'));
        $this->assertFalse(gojs_token_scope_allows(array('readonly'), 'files', 'POST'));
    }

    public function testScopeUserSelfOnlyProfile(): void
    {
        $this->assertTrue(gojs_token_scope_allows(array('user-self'), 'profile', 'GET'));
        $this->assertFalse(gojs_token_scope_allows(array('user-self'), 'files', 'GET'));
    }

    public function testScopeResourceReadWrite(): void
    {
        $this->assertTrue(gojs_token_scope_allows(array('files.read'), 'files', 'GET'));
        $this->assertFalse(gojs_token_scope_allows(array('files.read'), 'files', 'POST'));
        $this->assertTrue(gojs_token_scope_allows(array('files.write'), 'files', 'POST'));
        $this->assertFalse(gojs_token_scope_allows(array('files.write'), 'files', 'GET'));
        $this->assertTrue(gojs_token_scope_allows(array('db.write'), 'db/sql', 'POST'));
    }

    public function testBearerTokenParsing(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer gojs_abc123';
        $this->assertSame('gojs_abc123', gojs_request_bearer_token());

        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc';
        $this->assertNull(gojs_request_bearer_token());

        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->assertNull(gojs_request_bearer_token());
    }

    public function testRateLimitAllowsUnderQuota(): void
    {
        $token = array('id' => 'tok_rl', 'rate_limit_per_min' => 5);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(gojs_token_rate_limit_ok($token));
        }
        $this->assertFalse(gojs_token_rate_limit_ok($token));
    }

    public function testRateLimitZeroMeansUnlimited(): void
    {
        $token = array('id' => 'tok_unlim', 'rate_limit_per_min' => 0);
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue(gojs_token_rate_limit_ok($token));
        }
    }
}
