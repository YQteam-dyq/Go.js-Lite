<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class InvitationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/invitations.json');
        @unlink(CONFIG_DIR . '/groups.json');
        @unlink(CONFIG_DIR . '/users.json');
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/invitations.json');
        @unlink(CONFIG_DIR . '/groups.json');
        @unlink(CONFIG_DIR . '/users.json');
        parent::tearDown();
    }

    public function testCreateAndLoad(): void
    {
        $r = gojs_invitations_create('bob@example.com', 'viewer', array('/var/www'), array());
        $this->assertTrue($r['ok']);
        $this->assertSame('bob@example.com', $r['invitation']['email']);
        $this->assertSame('pending', $r['invitation']['status']);
        $this->assertArrayHasKey('token', $r['invitation']);
        $this->assertStringContainsString('/invite/', $r['invitation']['invite_url']);

        $store = gojs_invitations_load();
        $this->assertCount(1, $store['invitations']);
        $this->assertArrayNotHasKey('token', $store['invitations'][0]);
        $this->assertArrayHasKey('token_hash', $store['invitations'][0]);
    }

    public function testCreateRejectsBadEmail(): void
    {
        $r = gojs_invitations_create('not-an-email', 'viewer');
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_email', $r['code']);
    }

    public function testCreateRejectsBadRole(): void
    {
        $r = gojs_invitations_create('bob@example.com', 'superuser');
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_role', $r['code']);
    }

    public function testCreateRejectsDuplicatePending(): void
    {
        gojs_invitations_create('bob@example.com', 'viewer');
        $r = gojs_invitations_create('BOB@example.com', 'viewer');
        $this->assertFalse($r['ok']);
        $this->assertSame('already_pending', $r['code']);
    }

    public function testRevoke(): void
    {
        $created = gojs_invitations_create('bob@example.com', 'viewer');
        $id = $created['invitation']['id'];
        $this->assertTrue(gojs_invitations_revoke($id)['ok']);
        $this->assertSame('revoked', gojs_invitations_effective_status(gojs_invitations_find($id)));
        $this->assertFalse(gojs_invitations_revoke($id)['ok']);
    }

    public function testExpiredInvitationIsNotPending(): void
    {
        $created = gojs_invitations_create('bob@example.com', 'viewer');
        $id = $created['invitation']['id'];

        $store = gojs_invitations_load();
        foreach ($store['invitations'] as $i => $inv) {
            if ($inv['id'] === $id) {
                $store['invitations'][$i]['expires_at'] = time() - 10;
            }
        }
        gojs_invitations_save($store);

        $this->assertSame('expired', gojs_invitations_effective_status(gojs_invitations_find($id)));

        $r = gojs_invitations_accept($created['invitation']['token'], 'bob', 'Str0ngPass1');
        $this->assertFalse($r['ok']);
        $this->assertSame('invite_expired', $r['code']);
        $this->assertSame(410, $r['status']);
    }

    public function testAcceptRevokedRejected(): void
    {
        $created = gojs_invitations_create('bob@example.com', 'viewer');
        gojs_invitations_revoke($created['invitation']['id']);
        $r = gojs_invitations_accept($created['invitation']['token'], 'bob', 'Str0ngPass1');
        $this->assertFalse($r['ok']);
        $this->assertSame('invite_revoked', $r['code']);
    }

    public function testAcceptCreatesUser(): void
    {
        $created = gojs_invitations_create('carol@example.com', 'operator', array('/srv/app'), array());
        $r = gojs_invitations_accept($created['invitation']['token'], 'carol', 'Str0ngPass1');
        $this->assertTrue($r['ok']);
        $this->assertSame('operator', $r['role']);

        $user = gojs_users_find('carol');
        $this->assertNotNull($user);
        $this->assertSame('operator', $user['role']);
        $this->assertSame(array('/srv/app'), $user['path_allowlist']);

        $inv = gojs_invitations_find($created['invitation']['id']);
        $this->assertSame('accepted', $inv['status']);
        $this->assertSame($user['id'], $inv['accepted_user_id']);
    }

    public function testAcceptReusedTokenRejected(): void
    {
        $created = gojs_invitations_create('carol@example.com', 'viewer');
        gojs_invitations_accept($created['invitation']['token'], 'carol', 'Str0ngPass1');
        $r = gojs_invitations_accept($created['invitation']['token'], 'carol2', 'Str0ngPass1');
        $this->assertFalse($r['ok']);
        $this->assertSame('invite_used', $r['code']);
    }

    public function testAcceptWeakPasswordRejected(): void
    {
        $created = gojs_invitations_create('dave@example.com', 'viewer');
        $r = gojs_invitations_accept($created['invitation']['token'], 'dave', 'short');
        $this->assertFalse($r['ok']);
        $this->assertSame('weak_password', $r['code']);
    }

    public function testAcceptUnknownTokenRejected(): void
    {
        $r = gojs_invitations_accept('deadbeef', 'x', 'Str0ngPass1');
        $this->assertFalse($r['ok']);
        $this->assertSame('invite_not_found', $r['code']);
        $this->assertSame(404, $r['status']);
    }

    public function testAcceptAddsUserToGroups(): void
    {
        $g = gojs_groups_create('devs', array('/opt'), array());
        $gid = $g['group']['id'];

        $created = gojs_invitations_create('erin@example.com', 'viewer', array(), array($gid));
        $r = gojs_invitations_accept($created['invitation']['token'], 'erin', 'Str0ngPass1');
        $this->assertTrue($r['ok']);

        $group = gojs_groups_find($gid);
        $this->assertContains($r['user_id'], $group['member_ids']);
    }

    public function testListSortsNewestFirst(): void
    {
        $a = gojs_invitations_create('a@example.com', 'viewer');
        $b = gojs_invitations_create('b@example.com', 'viewer');

        $store = gojs_invitations_load();
        foreach ($store['invitations'] as $i => $inv) {
            if ($inv['id'] === $a['invitation']['id']) $store['invitations'][$i]['created_at'] = time() - 100;
            if ($inv['id'] === $b['invitation']['id']) $store['invitations'][$i]['created_at'] = time();
        }
        gojs_invitations_save($store);

        $rows = gojs_invitations_list();
        $this->assertSame('b@example.com', $rows[0]['email']);
    }

    public function testSanitizeHidesTokenHash(): void
    {
        $created = gojs_invitations_create('bob@example.com', 'viewer');
        $row = gojs_invitations_sanitize(gojs_invitations_find($created['invitation']['id']));
        $this->assertArrayNotHasKey('token_hash', $row);
        $this->assertArrayHasKey('token_prefix', $row);
    }
}
