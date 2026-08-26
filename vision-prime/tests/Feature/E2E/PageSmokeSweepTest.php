<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Domains\Identity\Models\Role;
use App\Domains\Organization\Models\Membership;
use App\Domains\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\ContentStandardsSeeder;
use Database\Seeders\DemoWorkspaceSeeder;
use Database\Seeders\PlatformBillingSeeder;
use Database\Seeders\PromptTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * جاروی نهایی صفحات (pre-deploy QA):
 * هر مسیر GET محصول باید برای کاربرِ مناسب باز شود — هیچ 500ای وجود نداشته باشد.
 * پوشش: صفحات عمومی مارکتینگ + کل پنل آژانس (با دادهٔ دمو) + پرتال مشتری.
 */
class PageSmokeSweepTest extends TestCase
{
    use RefreshDatabase;

    private array $skipPatterns = [
        'api/',                        // API نیست صفحه
        '_design-system', '_localization',
        'connector/', 'up',
        'app/ai-drafts/{id}/edit',     // نیاز به id خاص در دیتای دمو هست → جداگانه
        'platform/',                   // پنل سوپرادمین — تست خودش موجود است
        'storage/',
    ];

    public function test_every_public_and_app_page_opens_for_real_users(): void
    {
        // فضای کاری دمو: سازمان، سایت، GSC، فرصت‌ها، بازبینی، لیدها
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformBillingSeeder::class);
        $this->seed(ContentStandardsSeeder::class);
        $this->seed(PromptTemplateSeeder::class);
        $this->seed(DemoWorkspaceSeeder::class);

        $admin = User::query()->where('email', 'demo@visionprime.test')->firstOrFail();
        $this->actingAs($admin);

        $checked = 0;
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            $methods = (array) $route->methods();
            if (! in_array('GET', $methods, true)) {
                continue;
            }
            $uri = $route->uri();

            // فقط مسیرهای صفحهٔ کاربر (نه API/کانکتور/پارامتری)
            if (str_contains($uri, '{')) {
                continue;
            }
            foreach ($this->skipPatterns as $skip) {
                if (str_starts_with($uri, $skip)) {
                    continue 2;
                }
            }
            if (! (str_starts_with($uri, 'app/') || str_starts_with($uri, 'marketing') || in_array($uri, ['login', 'register', 'forgot-password', 'for-agencies', 'for-ecommerce', 'for-clinics', 'for-education', 'for-hospitality', 'product', 'features', 'pricing', 'demo', 'security', 'about', 'contact', 'assistant/knowledge'], true))) {
                continue;
            }

            $response = $this->get('/'.$uri);
            $status = $response->status();
            $checked++;

            if ($status >= 500) {
                $failures[] = "{$uri} → {$status}";
            } elseif (! in_array($status, [200, 302, 301], true)) {
                $failures[] = "{$uri} → {$status}";
            }
        }

        $this->assertGreaterThan(
            30,
            $checked,
            'باید دست‌کم ۳۰ صفحه بررسی شود — چک‌لیست مسیرها را به‌روز کنید اگر کمتر است: '.PHP_EOL.implode(PHP_EOL, array_map(fn ($u) => '/'.$u, ['…'])),
        );

        $this->assertSame(
            [],
            $failures,
            'صفحات خراب (از چشم کاربر واقعی):'.PHP_EOL.implode(PHP_EOL, $failures),
        );
    }

    public function test_client_portal_pages_open_for_client_portal_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoWorkspaceSeeder::class);

        // کاربر پرتال مشتری: عضویت با نقش client-viewer + انتساب client
        $org = Organization::query()->where('slug', 'vision-prime-demo')->firstOrFail();
        $client = $org->clients()->first();

        $portalUser = User::factory()->create();
        Membership::query()->create([
            'organization_id' => $org->id,
            'user_id' => $portalUser->id,
            'role_id' => Role::query()->where('key', 'client-viewer')->valueOrFail('id'),
            'status' => 'active',
        ]);
        \DB::table('client_user_assignments')->insert([
            'client_id' => $client->id,
            'user_id' => $portalUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['/client/dashboard', '/client/reports'] as $uri) {
            $status = $this->actingAs($portalUser)->get($uri)->status();
            $this->assertLessThan(500, $status, "پرتال مشتری {$uri} نباید 500 بدهد");
        }
    }
}
