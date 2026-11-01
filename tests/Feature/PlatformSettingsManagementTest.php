<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Functional\Setting\SettingService;
use App\Support\Auth\PlatformPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PlatformTestHelpers;
use Tests\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — admin settings: JSON values round-trip,
 * key/is_monetary fixed after creation, monetary values validated.
 */
class PlatformSettingsManagementTest extends TestCase
{
    use PlatformTestHelpers, RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->adminToken([PlatformPermissions::SETTINGS_MANAGE]);
    }

    public function test_settings_store_any_json_value_and_record_the_admin(): void
    {
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', [
            'key' => 'support.contacts',
            'value' => ['phone' => '+963900000000', 'whatsapp' => '+963900000001'],
        ])->assertOk()->assertJsonPath('data.setting.value.whatsapp', '+963900000001');

        $setting = Setting::where('key', 'support.contacts')->firstOrFail();
        $this->assertNotNull($setting->updated_by_admin_id);
        $this->assertFalse($setting->is_monetary);

        // The pre-existing static SettingService now has a real model to read.
        $this->assertSame('+963900000000', SettingService::get('support.contacts')['phone']);
    }

    public function test_key_format_and_uniqueness_are_enforced(): void
    {
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'Bad Key', 'value' => 1])
            ->assertStatus(400);

        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'orders.flag', 'value' => true])
            ->assertOk();
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'orders.flag', 'value' => false])
            ->assertStatus(400);

        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'nothing', 'value' => null])
            ->assertStatus(400);
    }

    public function test_monetary_settings_require_a_non_negative_four_decimal_amount(): void
    {
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'fees.min', 'value' => '-1', 'is_monetary' => true])
            ->assertStatus(400);
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'fees.min', 'value' => '1.12345', 'is_monetary' => true])
            ->assertStatus(400);
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'fees.min', 'value' => ['x' => 1], 'is_monetary' => true])
            ->assertStatus(400);

        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'fees.min', 'value' => '2500.5', 'is_monetary' => true])
            ->assertOk();

        $setting = Setting::where('key', 'fees.min')->firstOrFail();
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/update', ['id' => $setting->id, 'value' => 'abc'])
            ->assertStatus(400);
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/update', ['id' => $setting->id, 'value' => '3000'])
            ->assertOk();
        $this->assertSame('3000', $setting->fresh()->value);
    }

    public function test_key_and_is_monetary_cannot_change_after_creation(): void
    {
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/create', ['key' => 'catalog.flag', 'value' => 1])->assertOk();
        $setting = Setting::where('key', 'catalog.flag')->firstOrFail();

        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/update', ['id' => $setting->id, 'value' => 2, 'key' => 'other'])
            ->assertStatus(400);
        $this->asBearer($this->token)->postJson('/api/v1/admin/settings/update', ['id' => $setting->id, 'value' => 2, 'is_monetary' => true])
            ->assertStatus(400);

        $this->assertSame('catalog.flag', $setting->fresh()->key);
        $this->assertSame(1, $setting->fresh()->value);
    }
}
