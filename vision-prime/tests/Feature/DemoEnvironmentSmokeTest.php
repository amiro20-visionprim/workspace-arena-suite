<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoWorkspaceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تست دود محیط دمو — تضمین می‌کند «php artisan demo:seed» محیطی می‌سازد که
 * واقعاً قابل لاگین و ارائه به مشتری است (نه فقط ردیف‌های دیتابیس).
 */
class DemoEnvironmentSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_demo_admin_can_log_in_and_see_workspace(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoWorkspaceSeeder::class);

        $user = User::query()->where('email', 'demo@visionprime.test')->firstOrFail();
        $this->assertDatabaseHas('organizations', ['slug' => 'vision-prime-demo']);

        // کاربر دمو عضو فعال سازمان با نقش agency-admin است
        $roleKey = (string) DB::table('memberships')
            ->join('roles', 'roles.id', '=', 'memberships.role_id')
            ->where('memberships.user_id', $user->id)
            ->where('memberships.status', 'active')
            ->value('roles.key');
        $this->assertSame('agency-admin', $roleKey);

        // ورود با رمز مستندشده و دسترسی به داشبورد
        $this->post('/login', [
            'email' => 'demo@visionprime.test',
            'password' => 'DemoAdmin2024!Secure#',
        ])->assertRedirect();

        $this->actingAs($user)
            ->get('/app/dashboard')
            ->assertOk();

        // داده‌های ارائه: سایت + فرصت + صف بازبینی + لیدها
        $this->assertTrue(DB::table('sites')->where('name', 'سایت نمونه')->exists());
        $this->assertTrue(DB::table('opportunities')->exists());
        $this->assertTrue(DB::table('review_items')->exists());
        $this->assertTrue(DB::table('leads')->where('source', 'demo')->exists());
    }
}
