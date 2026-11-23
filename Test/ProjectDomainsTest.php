<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Core\Security\Role;

require_once __DIR__.'/SettingsControllerTest.php';

class ProjectDomainsTest extends DeedPageTestCase
{
    public function testMemberSeesOnlyDomainsLinkedToThisProject(): void
    {
        $projects = $this->container['projectModel'];
        $projectId = $projects->create(['name' => 'Member project']);
        $otherId = $projects->create(['name' => 'Other project']);
        $this->container['projectUserRoleModel']->addUser($projectId, 1, Role::PROJECT_MEMBER);

        $domains = $this->container['deedDomainModel'];
        $domains->update($domains->create('member.example', 1), $projectId, 0);
        $domains->update($domains->create('other.example', 1), $otherId, 0);
        $domains->create('unlinked.example', 1);
        $this->container['configModel']->save(['deed_default_project_id' => $projectId]);

        [$status, $body] = $this->dispatch('deed/projects/'.$projectId.'/domains', Role::APP_USER);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('>Domains</a>', $body);
        $this->assertStringContainsString('member.example', $body);
        $this->assertStringNotContainsString('other.example', $body);
        $this->assertStringNotContainsString('unlinked.example', $body);
        $this->assertStringNotContainsString('deed/domains/show/', $body);
    }

    /** @dataProvider applicationRoles */
    public function testNonMemberIsForbidden(string $role): void
    {
        $projectId = $this->container['projectModel']->create(['name' => 'Restricted project']);
        $domains = $this->container['deedDomainModel'];
        $domains->update($domains->create('restricted.example', 1), $projectId, 0);

        [$status, $body] = $this->dispatch('deed/projects/'.$projectId.'/domains', $role);
        $this->assertSame(403, $status);
        $this->assertStringNotContainsString('restricted.example', $body);
    }

    public static function applicationRoles(): iterable
    {
        yield [Role::APP_USER];
        yield [Role::APP_MANAGER];
    }

    public function testAppAdminSeesAnyProjectLikeTheRestOfKanboard(): void
    {
        $projectId = $this->container['projectModel']->create(['name' => 'Admin is not a member']);
        $domains = $this->container['deedDomainModel'];
        $domains->update($domains->create('admin-visible.example', 1), $projectId, 0);

        [$status, $body] = $this->dispatch('deed/projects/'.$projectId.'/domains', Role::APP_ADMIN);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('admin-visible.example', $body);
    }

    public function testProjectZeroCannotExposeUnlinkedDomains(): void
    {
        $this->container['deedDomainModel']->create('unlinked.example', 1);

        [$status, $body] = $this->dispatch('deed/projects/0/domains', Role::APP_USER);
        $this->assertSame(404, $status);
        $this->assertStringNotContainsString('unlinked.example', $body);
    }
}
