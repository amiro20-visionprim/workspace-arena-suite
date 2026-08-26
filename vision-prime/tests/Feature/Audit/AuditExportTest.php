<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * خروجی CSV حسابرسی (F3-07) — ابزار انطباق/RFP آژانس‌ها.
 */
class AuditExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_csv_rows_for_organization(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'exp-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $user = User::factory()->create();
        Membership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role_id' => Role::query()->where('key', 'agency-admin')->value('id'), 'status' => 'active']);

        $this->actingAs($user);

        app(RecordAuditLog::class)->handle(action: 'site.created', subject: $user, organization: $org, metadata: ['name' => 'S']);
        app(RecordAuditLog::class)->handle(action: 'review.decided', subject: $user, organization: $org, metadata: ['decision' => 'approved']);

        $this->artisan('audit:export', ['organization' => $org->id, '--days' => 7])
            ->assertSuccessful()
            ->expectsOutputToContain('2 ردیف');

        $files = glob(storage_path('app/audit-export-org'.$org->id.'-*.csv'));
        $this->assertNotEmpty($files, 'فایل CSV باید ساخته شود');
        sort($files);
        $content = (string) file_get_contents((string) end($files));
        $this->assertStringContainsString('site.created', $content);
        $this->assertStringContainsString('review.decided', $content);
        // actor/email در context وب همیشه ثبت می‌شود؛ در تستِ دستور فقط رکوردها اهمیت دارد
    }

    public function test_unknown_organization_fails_gracefully(): void
    {
        $this->artisan('audit:export', ['organization' => 99999])
            ->assertFailed();
    }
}
