<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Models\Plan;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Services\PlanLimits;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use App\Models\User;
use Database\Seeders\PlatformBillingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * اعمال سقف‌های پلن روی مسیرهای تجاری (فاز F2):
 *   ۱) سازمان بدون اشتراک → سقف‌های trial پیش‌فرض
 *   ۲) پلن استارتر (۱ مشتری) → مشتری دوم رد می‌شود با پیام فارسی ارتقا
 *   ۳) پلن گروث (۵ سایت) → تا سقف آزاد است
 *   ۴) سهمیهٔ AI پایان‌یافته → تولید محتوا مسدود می‌شود
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformBillingSeeder::class);

        $this->org = Organization::query()->create([
            'public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'org-'.Str::lower(Str::random(6)), 'status' => 'active',
        ]);
        $this->admin = User::factory()->create();
        Membership::query()->create([
            'organization_id' => $this->org->id, 'user_id' => $this->admin->id,
            'role_id' => Role::query()->where('key', 'agency-admin')->valueOrFail('id'), 'status' => 'active',
        ]);
    }

    private function subscribe(string $planKey): void
    {
        $plan = Plan::query()->where('key', $planKey)->firstOrFail();
        Subscription::query()->create([
            'organization_id' => $this->org->id, 'plan_id' => $plan->id,
            'status' => 'active', 'starts_at' => now()->subDay(), 'current_period_end' => now()->addMonth(),
        ]);
    }

    private function newClient(): void
    {
        $this->actingAs($this->admin)->withSession(['current_organization_id' => $this->org->id])
            ->post('/app/clients', ['name' => 'مشتری '.Str::random(4)]);
    }

    public function test_trial_limits_block_second_site(): void
    {
        $client = Client::query()->create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $this->org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        Site::query()->create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'public_id' => (string) Str::ulid(), 'name' => 'S1', 'canonical_url' => 'https://a.ir', 'status' => 'active']);

        $response = $this->actingAs($this->admin)->withSession(['current_organization_id' => $this->org->id])
            ->post('/app/sites', [
                'project_id' => $project->id, 'name' => 'S2', 'canonical_url' => 'https://b.ir',
                'locale' => 'fa', 'timezone' => 'Asia/Tehran', 'business_importance' => 3,
            ]);

        $response->assertSessionHasErrors('plan_limit');
        $this->assertSame(1, Site::query()->where('organization_id', $this->org->id)->count(), 'سایت دوم نباید ساخته شود');
    }

    public function test_growth_plan_allows_five_sites(): void
    {
        $this->subscribe('growth');
        $client = Client::query()->create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $project = Project::query()->create(['organization_id' => $this->org->id, 'client_id' => $client->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($this->admin)->withSession(['current_organization_id' => $this->org->id])
                ->post('/app/sites', [
                    'project_id' => $project->id, 'name' => "S{$i}", 'canonical_url' => "https://g{$i}.ir",
                    'locale' => 'fa', 'timezone' => 'Asia/Tehran', 'business_importance' => 3,
                ])->assertRedirect();
        }

        $this->assertSame(5, Site::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_exhausted_ai_quota_blocks_generation_with_upgrade_message(): void
    {
        $this->subscribe('starter'); // max_ai_tokens_monthly = 500k
        DB::table('ai_usage_logs')->insert([
            'organization_id' => $this->org->id, 'generation_id' => null,
            'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'input_tokens' => 400_000, 'output_tokens' => 100_000, 'cost' => 1,
            'occurred_at' => now(),
        ]);

        $profileId = DB::table('url_profiles')->insertGetId([
            'site_id' => Site::query()->create([
                'organization_id' => $this->org->id, 'project_id' => Project::query()->create([
                    'organization_id' => $this->org->id,
                    'client_id' => Client::query()->create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active'])->id,
                    'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active',
                ])->id,
                'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://q.ir', 'status' => 'active',
            ])->id,
            'public_id' => (string) Str::ulid(), 'canonical_url' => 'https://q.ir/x',
            'content_type' => 'page', 'post_status' => 'publish',
            'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->withSession(['current_organization_id' => $this->org->id])
            ->post('/app/ai-drafts/article', ['url_profile_id' => $profileId]);

        $response->assertSessionHasErrors('plan_limit');
        $this->assertSame(0, DB::table('ai_generations')->whereNotNull('site_id')->count(), 'تولید نباید رخ دهد');
    }

    public function test_usage_summary_reflects_plan(): void
    {
        $this->subscribe('starter');
        $limits = app(PlanLimits::class);

        $summary = $limits->usageSummary($this->org);

        $this->assertSame('starter', $summary['plan']);
        $this->assertSame(1, $summary['sites']['max']);
        $this->assertSame(500_000, $summary['ai_tokens_monthly']['max']);
    }
}
