<?php

namespace Tests\Feature\Admin;

use Filament\Facades\Filament;

/**
 * WAFFRLI (2026-10-31) — every admin resource index (and every custom
 * page) renders for a Super Admin, and is refused for an employee
 * without permissions.
 */
class AdminPanelSmokeTest extends AdminPanelTestCase
{
    public function test_every_resource_index_renders_for_super_admin(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        $resources = Filament::getPanel('admin')->getResources();
        $this->assertNotEmpty($resources);

        foreach ($resources as $resource) {
            $this->get($resource::getUrl('index'))->assertOk();
            if (array_key_exists('create', $resource::getPages()) && $resource::canCreate()) {
                $this->get($resource::getUrl('create'))->assertOk();
            }
        }

        foreach (Filament::getPanel('admin')->getPages() as $page) {
            $this->get($page::getUrl())->assertOk();
        }
    }

    public function test_employee_without_permissions_is_refused_everywhere(): void
    {
        $this->actingAsAdmin($this->employee());

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $this->get($resource::getUrl('index'))->assertForbidden();
        }
    }
}
