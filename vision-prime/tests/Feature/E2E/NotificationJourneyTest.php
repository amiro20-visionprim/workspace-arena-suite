<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Models\User;
use App\Notifications\NewLeadNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جورنی اعلان‌ها (pre-deploy QA): فرم دمو ← لید ← نوتیفیکیشن به مدیر بازاریابی
 * + صفحهٔ اعلان‌های کاربر باز است و اعلان را نشان می‌دهد.
 */
class NotificationJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_lead_notifies_marketing_team_and_shows_in_notification_center(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $org = Organization::query()->create([
            'public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'notif-'.Str::lower(Str::random(5)), 'status' => 'active',
        ]);
        $marketing = User::factory()->create();
        Membership::query()->create([
            'organization_id' => $org->id, 'user_id' => $marketing->id,
            'role_id' => Role::query()->where('key', 'marketing-manager')->valueOrFail('id'), 'status' => 'active',
        ]);

        // کاربر مهمان فرم دمو را پر می‌کند (جورنی واقعی بازدیدکننده)
        $response = $this->post('/demo', [
            'name' => 'آژانس تست',
            'email' => 'lead@agency.ir',
            'company' => 'آژانس تست',
            'website' => 'https://agency.ir',
            'message' => 'برای ۴ سایت مشتری پرتال می‌خواهیم.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('leads', ['email' => 'lead@agency.ir']);

        $lead = \DB::table('leads')->where('email', 'lead@agency.ir')->first();

        // نوتیفیکیشن به مدیر بازاریابی رسید
        Notification::assertSentTo(
            [$marketing],
            NewLeadNotification::class,
            fn ($notification, $channels, $notifiable) => true,
        );

        // مرکز اعلان‌های کاربر باز است
        $this->actingAs($marketing)->get('/app/notifications')->assertOk();
    }
}
