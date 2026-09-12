<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class GroupsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/groups.json');
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/groups.json');
        parent::tearDown();
    }

    public function testCreateAndLoadGroup(): void
    {
        $r = gojs_groups_create('devs', array('/var/www/site1'), array());
        $this->assertTrue($r['ok']);
        $this->assertSame('devs', $r['group']['name']);
        $this->assertSame(array('/var/www/site1'), $r['group']['path_allowlist']);

        $store = gojs_groups_load();
        $this->assertCount(1, $store['groups']);
    }

    public function testCreateRejectsDuplicateName(): void
    {
        gojs_groups_create('devs');
        $r = gojs_groups_create('DEVS');
        $this->assertFalse($r['ok']);
        $this->assertSame('name_exists', $r['code']);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $r = gojs_groups_create('   ');
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_name', $r['code']);
    }

    public function testNormalizePathsDedupesAndDropsBlanks(): void
    {
        $paths = gojs_groups_normalize_paths(array('/a', '/a', '', '  ', '/b'));
        $this->assertSame(array('/a', '/b'), $paths);
    }

    public function testUpdateGroupPathsAndName(): void
    {
        $created = gojs_groups_create('devs');
        $id = $created['group']['id'];
        $r = gojs_groups_update($id, array('name' => 'engineers', 'path_allowlist' => array('/opt/app')));
        $this->assertTrue($r['ok']);
        $this->assertSame('engineers', $r['group']['name']);
        $this->assertSame(array('/opt/app'), $r['group']['path_allowlist']);
    }

    public function testUpdateMissingGroupReturnsNotFound(): void
    {
        $r = gojs_groups_update('g_nope', array('name' => 'x'));
        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['code']);
    }

    public function testSetMembersAddAndRemove(): void
    {
        $created = gojs_groups_create('devs');
        $id = $created['group']['id'];

        $r = gojs_groups_set_members($id, array('u_bob', 'u_carol'), array());
        $this->assertTrue($r['ok']);
        $this->assertSame(array('u_bob', 'u_carol'), $r['group']['member_ids']);

        $r = gojs_groups_set_members($id, array(), array('u_bob'));
        $this->assertSame(array('u_carol'), $r['group']['member_ids']);
    }

    public function testDeleteGroup(): void
    {
        $created = gojs_groups_create('devs');
        $id = $created['group']['id'];
        $this->assertTrue(gojs_groups_delete($id)['ok']);
        $this->assertNull(gojs_groups_find($id));
        $this->assertFalse(gojs_groups_delete($id)['ok']);
    }

    public function testGroupsForUser(): void
    {
        $a = gojs_groups_create('a', array(), array('u_bob'));
        $b = gojs_groups_create('b', array(), array('u_bob', 'u_carol'));
        $this->assertCount(2, gojs_groups_for_user('u_bob'));
        $this->assertCount(1, gojs_groups_for_user('u_carol'));
        $this->assertCount(0, gojs_groups_for_user('u_dave'));
    }

    public function testUserPathsAreUnionOfUserAndGroups(): void
    {
        gojs_groups_create('g1', array('/b'), array('u_bob'));
        gojs_groups_create('g2', array('/c'), array('u_bob'));

        $user = array('id' => 'u_bob', 'path_allowlist' => array('/a'));
        $paths = gojs_groups_user_paths($user);
        sort($paths);
        $this->assertSame(array('/a', '/b', '/c'), $paths);
    }

    public function testUserPathsEmptyWhenNothingGranted(): void
    {
        $user = array('id' => 'u_solo', 'path_allowlist' => array());
        $this->assertSame(array(), gojs_groups_user_paths($user));
    }
}
