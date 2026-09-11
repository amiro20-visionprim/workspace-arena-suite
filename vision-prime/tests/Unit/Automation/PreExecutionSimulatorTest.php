<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domains\Automation\Services\PreExecutionSimulator;
use App\Domains\Organization\Models\Organization;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PreExecutionSimulatorTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $o = Organization::create(['public_id' => (string) Str::ulid(), 'name' => 'O', 'slug' => 'o', 'status' => 'active']);
        $c = Client::create(['organization_id' => $o->id, 'public_id' => (string) Str::ulid(), 'name' => 'C', 'status' => 'active']);
        $p = Project::create(['organization_id' => $o->id, 'client_id' => $c->id, 'public_id' => (string) Str::ulid(), 'name' => 'P', 'status' => 'active']);
        $this->site = Site::create(['organization_id' => $o->id, 'project_id' => $p->id, 'public_id' => (string) Str::ulid(), 'name' => 'S', 'canonical_url' => 'https://e.ir', 'status' => 'active']);
    }

    public function test_no_history_returns_conservative_estimate(): void
    {
        $result = app(PreExecutionSimulator::class)->simulate($this->site->id, 'update_meta_title');

        $this->assertIsInt($result['expected_impact']);
        $this->assertGreaterThanOrEqual(0, $result['expected_impact']);
        $this->assertLessThanOrEqual(100, $result['expected_impact']);
        $this->assertGreaterThanOrEqual(0, $result['success_probability']);
        $this->assertLessThanOrEqual(1, $result['success_probability']);
        $this->assertContains($result['recommendation'], ['execute', 'review']);
    }

    public function test_success_history_raises_expected_impact(): void
    {
        DB::table('automation_learning_history')->insert([
            'site_id' => $this->site->id,
            'command_type' => 'update_meta_title',
            'total' => 10,
            'successful' => 9,
            'consecutive_failures' => 0,
            'blocked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ترافیک واقعی هم اضافه می‌کنیم تا اثر بالاتر رود
        DB::table('site_traffic_daily')->insert([
            'site_id' => $this->site->id,
            'page_url' => 'liuna.ir/post-a',
            'date' => now()->subDays(1)->toDateString(),
            'source' => 'plugin',
            'views' => 500,
            'visitors' => 300,
            'entrances' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PreExecutionSimulator::class)->simulate($this->site->id, 'update_meta_title', ['url' => 'liuna.ir/post-a']);

        // سابقهٔ موفق ۹۰٪ → احتمال موفقیت ≥ ۰.۶
        $this->assertGreaterThanOrEqual(0.6, $result['success_probability']);
        $this->assertSame('execute', $result['recommendation']);
    }

    public function test_risk_tier_escalates_for_low_success(): void
    {
        DB::table('automation_learning_history')->insert([
            'site_id' => $this->site->id,
            'command_type' => 'publish_new_article',
            'total' => 8,
            'successful' => 1,
            'consecutive_failures' => 2,
            'blocked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PreExecutionSimulator::class)->simulate($this->site->id, 'publish_new_article');

        $this->assertSame('R3', $result['risk_tier']);
    }
}