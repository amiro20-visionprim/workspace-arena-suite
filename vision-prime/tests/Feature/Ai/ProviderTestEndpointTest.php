<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * رگرسیون دو باگ تولیدی (۲۰۲۶-۰۸-۲۷):
 *   ۱) «خطا: undefined» — endpoint تست provider باید همیشه JSON با کلید error بدهد
 *      (حتی 403) و مجوز آن با مسیر ذخیرهٔ کلید یکدست باشد.
 *   ۲) ستون users.last_seen_at باید migration رسمی داشته باشد (no-op اگر هست).
 */
class ProviderTestEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_admin_gets_json_error_for_unknown_provider_not_undefined(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'pt-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $admin = User::factory()->create();
        Membership::query()->create(['organization_id' => $org->id, 'user_id' => $admin->id,
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);

        $response = $this->actingAs($admin)
            ->withSession(['current_organization_id' => $org->id])
            ->postJson('/api/content/test-provider', [
                'provider' => 'no-such-provider',
                'api_key' => 'sk-x',
            ]);

        $response->assertOk()->assertJsonPath('success', false);
        $this->assertNotSame('', (string) $response->json('error'), 'پیام خطا هرگز نباید undefined باشد');
    }

    public function test_user_without_permission_gets_json_403_with_error_key(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'pt2-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $viewer = User::factory()->create();
        Membership::query()->create(['organization_id' => $org->id, 'user_id' => $viewer->id,
            'role_id' => Role::query()->where('key', 'client-viewer')->valueOrFail('id'), 'status' => 'active']);

        $response = $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $org->id])
            ->postJson('/api/content/test-provider', ['provider' => 'groq', 'api_key' => 'gsk_x']);

        $response->assertStatus(403)->assertJsonPath('success', false);
        $this->assertNotSame('', (string) $response->json('error'));
    }

    public function test_last_seen_migration_is_idempotent(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        User::factory()->create(); // جدول users پس از مهاجرت سالم است
        $this->assertTrue(Schema::hasColumn('users', 'last_seen_at'));
    }
}
