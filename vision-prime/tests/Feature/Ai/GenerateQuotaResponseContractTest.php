<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * رگرسیون باگ تولیدی (۲۰۲۶-۰۸-۲۸): «مقاله تولید شد! مدل: خالی، کلمات: ۰»
 *
 * ریشه: پاسخ‌های 422 (سهمیهٔ پلن) و 429 (تروتل) کلید error ندارند؛ فرانت فقط
 * d.error را چک می‌کرد و پاسخ غیر-ok را «موفقیت خالی» رندر می‌کرد.
 * قرارداد این تست: پاسخ 422 حتماً errors.plan_limit با پیام فارسی دارد
 * (و فرانت باید آن را بخواند — اینجا سمت سرور قفل می‌شود).
 */
class GenerateQuotaResponseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_quota_rejection_returns_readable_plan_limit_error(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organization::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'qc-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $admin = User::factory()->create();
        Membership::query()->create(['organization_id' => $org->id, 'user_id' => $admin->id,
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active']);
        $client = Client::query()->create(['organization_id' => $org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $site = Site::query()->create(['organization_id' => $org->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://q.ir', 'status' => 'active']);

        // سازمان بدون اشتراک → سهمیهٔ آزمایشی ۱۰۰هزار توکن؛ آن را پر می‌کنیم
        DB::table('ai_usage_logs')->insert([
            'organization_id' => $org->id, 'generation_id' => null, 'provider' => 'groq',
            'model' => 'llama', 'input_tokens' => 60_000, 'output_tokens' => 45_000,
            'cost' => 1, 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['current_organization_id' => $org->id])
            ->postJson('/api/content/generate', [
                'site_id' => $site->id,
                'keyword' => 'تست سئو',
                'title' => 'تست سئو',
            ]);

        $response->assertStatus(422);
        $message = (string) $response->json('errors.plan_limit.0');
        Assert::assertNotSame('', $message, 'پیام فارسی سهمیه باید برای UI موجود باشد');
        Assert::assertStringContainsString('سهمیه', $message);

        // و مهم‌تر: هیچ draft ساخته نشده
        $this->assertSame(0, DB::table('content_drafts')->where('site_id', $site->id)->count());
    }
}
