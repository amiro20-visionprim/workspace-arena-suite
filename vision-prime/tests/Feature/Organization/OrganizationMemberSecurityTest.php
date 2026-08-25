<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationMemberSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function org(string $slug = 'acme'): Organization
    {
        return Organization::query()->create([
            'public_id' => (string) Str::ulid(),
            'name' => $slug,
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function membership(User $user, Organization $organization, string $roleKey): Membership
    {
        return Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => Role::query()->where('key', $roleKey)->valueOrFail('id'),
            'status' => 'active',
        ]);
    }

    public function test_agency_admin_cannot_assign_super_admin_role(): void
    {
        $organization = $this->org();
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->membership($admin, $organization, 'agency-admin');

        $superAdminRoleId = Role::query()->where('key', 'super-admin')->valueOrFail('id');

        $this->actingAs($admin)->post('/app/settings/organization/members', [
            'email' => $target->email,
            'role_id' => $superAdminRoleId,
        ])->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('memberships', [
            'organization_id' => $organization->getKey(),
            'user_id' => $target->getKey(),
            'role_id' => $superAdminRoleId,
        ]);
    }

    public function test_agency_admin_cannot_elevate_member_to_super_admin_via_update(): void
    {
        $organization = $this->org();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $this->membership($admin, $organization, 'agency-admin');
        $membership = $this->membership($member, $organization, 'seo-manager');

        $superAdminRoleId = Role::query()->where('key', 'super-admin')->valueOrFail('id');

        $this->actingAs($admin)->put("/app/settings/organization/members/{$membership->getKey()}", [
            'role_id' => $superAdminRoleId,
        ])->assertSessionHasErrors('role_id');

        $this->assertDatabaseHas('memberships', [
            'id' => $membership->getKey(),
            'role_id' => Role::query()->where('key', 'seo-manager')->valueOrFail('id'),
        ]);
    }

    public function test_cannot_remove_own_membership(): void
    {
        $organization = $this->org();
        $admin = User::factory()->create();
        $membership = $this->membership($admin, $organization, 'agency-admin');

        $this->actingAs($admin)->delete("/app/settings/organization/members/{$membership->getKey()}")
            ->assertStatus(422);

        $this->assertDatabaseHas('memberships', ['id' => $membership->getKey()]);
    }

    public function test_cannot_demote_the_last_agency_admin(): void
    {
        $organization = $this->org();
        $admin = User::factory()->create();
        $membership = $this->membership($admin, $organization, 'agency-admin');

        $seoManagerRoleId = Role::query()->where('key', 'seo-manager')->valueOrFail('id');

        $this->actingAs($admin)->put("/app/settings/organization/members/{$membership->getKey()}", [
            'role_id' => $seoManagerRoleId,
        ])->assertStatus(422);

        $this->assertDatabaseHas('memberships', [
            'id' => $membership->getKey(),
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'),
        ]);
    }

    public function test_admin_can_remove_a_non_admin_member(): void
    {
        $organization = $this->org();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $this->membership($admin, $organization, 'agency-admin');
        $membership = $this->membership($member, $organization, 'seo-manager');

        $this->actingAs($admin)->delete("/app/settings/organization/members/{$membership->getKey()}")
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('memberships', ['id' => $membership->getKey()]);
    }
}
