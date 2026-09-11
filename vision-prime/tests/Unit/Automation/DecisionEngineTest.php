<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domains\Automation\Services\DecisionEngine;
use App\Domains\Organization\Models\Organization;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;
use App\Domains\Workspace\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecisionEngineTest extends TestCase
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

    private function makeOpportunity(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 1,
            'site_id' => $this->site->id,
            'url_profile_id' => null,
            'type' => 'keyword_opportunity',
            'score' => 80.0,
            'confidence' => 0.9,
            'status' => 'open',
            'explanation' => 'test',
            'source' => 'analyzer',
            'title' => 'Test',
            'keyword_suggested' => 'test keyword',
        ], $overrides);
    }

    public function test_high_score_low_risk_opportunity_allocated_first(): void
    {
        $engine = app(DecisionEngine::class);

        $strong = $this->makeOpportunity([
            'id' => 1, 'type' => 'meta_optimization', 'score' => 95, 'confidence' => 0.95,
        ]);
        $weak = $this->makeOpportunity([
            'id' => 2, 'type' => 'interlink_opportunity', 'score' => 40, 'confidence' => 0.4,
        ]);

        $result = $engine->allocate($this->site->id, [$weak, $strong], 10);

        $this->assertCount(2, $result['allocated']);
        $this->assertSame(1, $result['allocated'][0]['opportunity_id']);
    }

    public function test_budget_respected_and_excess_deferred(): void
    {
        $engine = app(DecisionEngine::class);

        // keyword_opportunity هزینهٔ ۳ دارد؛ بودجه ۵ → فقط یک عدد تخصیص می‌یابد
        $a = $this->makeOpportunity(['id' => 1, 'type' => 'keyword_opportunity', 'score' => 90, 'confidence' => 0.9]);
        $b = $this->makeOpportunity(['id' => 2, 'type' => 'keyword_opportunity', 'score' => 85, 'confidence' => 0.85]);

        $result = $engine->allocate($this->site->id, [$a, $b], 5);

        $this->assertCount(1, $result['allocated']);
        $this->assertCount(1, $result['deferred']);
        $this->assertSame(3, $result['used']);
        $this->assertSame(5, $result['budget']);
    }

    public function test_blocked_command_type_is_never_allocated(): void
    {
        DB::table('automation_learning_history')->insert([
            'site_id' => $this->site->id,
            'command_type' => 'publish_new_article',
            'total' => 5,
            'successful' => 0,
            'consecutive_failures' => 5,
            'blocked' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $engine = app(DecisionEngine::class);
        $opp = $this->makeOpportunity(['id' => 1, 'type' => 'keyword_opportunity', 'score' => 95, 'confidence' => 0.95]);

        $result = $engine->allocate($this->site->id, [$opp], 10);

        $this->assertCount(0, $result['allocated']);
    }

    public function test_cheap_meta_opportunities_fill_budget(): void
    {
        $engine = app(DecisionEngine::class);

        $opps = [];
        for ($i = 1; $i <= 5; $i++) {
            $opps[] = $this->makeOpportunity([
                'id' => $i,
                'type' => 'meta_optimization',
                'score' => 70 + $i,
                'confidence' => 0.8,
            ]);
        }

        // هزینهٔ هر meta = 1 → هر ۵ تا با بودجهٔ ۵ تخصیص می‌یابند
        $result = $engine->allocate($this->site->id, $opps, 5);

        $this->assertCount(5, $result['allocated']);
        $this->assertCount(0, $result['deferred']);
    }
}