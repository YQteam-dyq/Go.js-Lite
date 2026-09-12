<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class ApprovalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/users.json');
        @unlink(CONFIG_DIR . '/approvals.json');
        $_SESSION = array();
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/users.json');
        @unlink(CONFIG_DIR . '/approvals.json');
        $_SESSION = array();
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $GLOBALS['gojs_body_override'] = null;
        $GLOBALS['gojs_approval_bypass'] = false;
        parent::tearDown();
    }

    private function makeAdmin(string $id, string $username): array
    {
        $user = array(
            'id' => $id,
            'username' => $username,
            'role' => 'admin',
            'path_allowlist' => array(),
            'disabled' => false,
            'created_at' => time(),
        );
        gojs_users_upsert($user);
        return $user;
    }

    private function seedTwoAdmins(): void
    {
        $this->makeAdmin('u_admin1', 'admin1');
        $this->makeAdmin('u_admin2', 'admin2');
    }

    private function gate(string $api, string $method = 'POST'): array
    {
        $GLOBALS['gojs_defer_response'] = true;
        $GLOBALS['gojs_deferred_response'] = null;
        try {
            gojs_approvals_gate($api, $method);
        } catch (\GoJSApiResponseSent $e) {
        }
        $GLOBALS['gojs_defer_response'] = false;
        $resp = $GLOBALS['gojs_deferred_response'];
        $GLOBALS['gojs_deferred_response'] = null;
        return is_array($resp) ? $resp : array('data' => null, 'error' => null, 'status' => 0);
    }

    public function testPolicyCoversSpecActions(): void
    {
        $policy = gojs_approvals_policy();
        foreach (array('database.delete', 'monitoring.disable', 'sessions.kick_all', 'trash.purge_all', 'appstore.uninstall') as $action) {
            $this->assertArrayHasKey($action, $policy);
        }
    }

    public function testActionLookupFromApi(): void
    {
        $this->assertSame('appstore.uninstall', gojs_approvals_action_for_api('appstore/uninstall'));
        $this->assertSame('trash.purge_all', gojs_approvals_action_for_api('trash/purge'));
        $this->assertSame('database.delete', gojs_approvals_action_for_api('db/import'));
        $this->assertNull(gojs_approvals_action_for_api('dashboard'));
    }

    public function testSingleAdminCannotRequest(): void
    {
        $this->makeAdmin('u_admin1', 'admin1');
        $_SESSION['user_id'] = 'u_admin1';
        $_SESSION['authenticated'] = true;

        $resp = $this->gate('appstore/uninstall');
        $this->assertSame(409, $resp['status']);
        $this->assertSame('single_admin_no_second_factor', $resp['error']['code']);

        $r = gojs_approval_require('appstore.uninstall', 'appstore/uninstall', 'POST', array(), 'u_admin1');
        $this->assertFalse($r['ok']);
        $this->assertSame('single_admin_no_second_factor', $r['code']);
    }

    public function testGateReturns202WithTwoAdmins(): void
    {
        $this->seedTwoAdmins();
        $_SESSION['user_id'] = 'u_admin1';
        $_SESSION['authenticated'] = true;

        $resp = $this->gate('trash/purge');
        $this->assertSame(202, $resp['status']);
        $this->assertSame('approval_pending', $resp['data']['status']);
        $this->assertSame('trash.purge_all', $resp['data']['approval']['action']);
        $this->assertSame('pending', $resp['data']['approval']['status']);
        $this->assertSame(3600, $resp['data']['approval']['expires_at'] - $resp['data']['approval']['created_at']);
    }

    public function testDuplicateRequestRejected(): void
    {
        $this->seedTwoAdmins();
        $_SESSION['user_id'] = 'u_admin1';
        $_SESSION['authenticated'] = true;

        $this->gate('trash/purge');
        $resp = $this->gate('trash/purge');
        $this->assertSame(409, $resp['status']);
        $this->assertSame('approval_already_pending', $resp['error']['code']);
    }

    public function testNonMarkedActionPassesThrough(): void
    {
        $this->seedTwoAdmins();
        $_SESSION['user_id'] = 'u_admin1';
        $_SESSION['authenticated'] = true;

        $GLOBALS['gojs_defer_response'] = true;
        $GLOBALS['gojs_deferred_response'] = null;
        gojs_approvals_gate('dashboard', 'GET');
        $GLOBALS['gojs_defer_response'] = false;
        $this->assertNull($GLOBALS['gojs_deferred_response']);
        $GLOBALS['gojs_deferred_response'] = null;
    }

    public function testApproveExecutesOriginalAction(): void
    {
        $this->seedTwoAdmins();
        $created = gojs_approval_require('trash.purge_all', 'devices', 'GET', array(), 'u_admin1');
        $this->assertTrue($created['ok']);

        $r = gojs_approval_decide($created['approval']['id'], 'approve', 'looks fine', 'u_admin2');
        $this->assertTrue($r['ok']);
        $this->assertSame('approved', $r['approval']['status']);
        $this->assertSame('u_admin2', $r['approval']['second_factor_user_id']);
        $this->assertSame('looks fine', $r['approval']['reason']);
        $this->assertIsArray($r['result']);
        $this->assertSame(401, $r['result']['status']);
    }

    public function testDenyDoesNotExecute(): void
    {
        $this->seedTwoAdmins();
        $created = gojs_approval_require('trash.purge_all', 'devices', 'GET', array(), 'u_admin1');

        $r = gojs_approval_decide($created['approval']['id'], 'deny', 'not now', 'u_admin2');
        $this->assertTrue($r['ok']);
        $this->assertSame('denied', $r['approval']['status']);
        $this->assertNull($r['result']);
    }

    public function testCannotSelfApprove(): void
    {
        $this->seedTwoAdmins();
        $created = gojs_approval_require('trash.purge_all', 'devices', 'GET', array(), 'u_admin1');

        $r = gojs_approval_decide($created['approval']['id'], 'approve', '', 'u_admin1');
        $this->assertFalse($r['ok']);
        $this->assertSame('cannot_self_approve', $r['code']);
        $this->assertSame(409, $r['status']);
    }

    public function testCannotDecideTwice(): void
    {
        $this->seedTwoAdmins();
        $created = gojs_approval_require('trash.purge_all', 'devices', 'GET', array(), 'u_admin1');

        gojs_approval_decide($created['approval']['id'], 'deny', '', 'u_admin2');
        $r = gojs_approval_decide($created['approval']['id'], 'approve', '', 'u_admin2');
        $this->assertFalse($r['ok']);
        $this->assertSame('approval_already_decided', $r['code']);
        $this->assertSame(409, $r['status']);
    }

    public function testExpiredApprovalCannotBeDecided(): void
    {
        $this->seedTwoAdmins();
        $created = gojs_approval_require('trash.purge_all', 'devices', 'GET', array(), 'u_admin1');
        $id = $created['approval']['id'];

        $store = gojs_approvals_load();
        foreach ($store['approvals'] as $i => $row) {
            if ($row['id'] === $id) $store['approvals'][$i]['expires_at'] = time() - 5;
        }
        gojs_approvals_save($store);

        $r = gojs_approval_decide($id, 'approve', '', 'u_admin2');
        $this->assertFalse($r['ok']);
        $this->assertSame('approval_expired', $r['code']);
        $this->assertSame(410, $r['status']);
    }

    public function testPruneMarksTimedOutAsExpired(): void
    {
        $this->seedTwoAdmins();
        $created = gojs_approval_require('trash.purge_all', 'devices', 'GET', array(), 'u_admin1');
        $id = $created['approval']['id'];

        $store = gojs_approvals_load();
        foreach ($store['approvals'] as $i => $row) {
            if ($row['id'] === $id) $store['approvals'][$i]['expires_at'] = time() - 5;
        }
        gojs_approvals_save($store);

        $row = gojs_approvals_find($id);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('expired', gojs_approvals_effective_status($row));

        $all = gojs_approvals_all();
        $this->assertSame('expired', $all[0]['status']);
        $this->assertSame('timeout', $all[0]['reason']);
    }

    public function testPendingListExcludesOwnRequests(): void
    {
        $this->seedTwoAdmins();
        gojs_approval_require('trash.purge_all', 'trash/purge', 'POST', array(), 'u_admin1');

        $this->assertCount(1, gojs_approvals_pending_for_decider('u_admin2'));
        $this->assertCount(0, gojs_approvals_pending_for_decider('u_admin1'));
        $this->assertCount(1, gojs_approvals_mine('u_admin1'));
    }

    public function testInvalidDecisionRejected(): void
    {
        $this->seedTwoAdmins();
        $created = gojs_approval_require('trash.purge_all', 'devices', 'GET', array(), 'u_admin1');
        $r = gojs_approval_decide($created['approval']['id'], 'maybe', '', 'u_admin2');
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_decision', $r['code']);
    }

    public function testUnknownApprovalReturnsNotFound(): void
    {
        $r = gojs_approval_decide('ap_missing', 'approve', '', 'u_admin2');
        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['code']);
    }

    public function testApprovalPayloadIsPersisted(): void
    {
        $this->seedTwoAdmins();
        $created = gojs_approval_require('database.delete', 'db/import', 'POST', array('table' => 'orders'), 'u_admin1');
        $this->assertSame(array('table' => 'orders'), $created['approval']['payload']);

        $row = gojs_approvals_find($created['approval']['id']);
        $this->assertSame('db/import', $row['api']);
        $this->assertSame('POST', $row['method']);
        $this->assertSame(array('table' => 'orders'), $row['payload']);
    }

    public function testDisabledAdminNotCounted(): void
    {
        $this->makeAdmin('u_admin1', 'admin1');
        $second = $this->makeAdmin('u_admin2', 'admin2');
        $second['disabled'] = true;
        gojs_users_upsert($second);

        $this->assertSame(1, gojs_approvals_admin_count());
    }
}
