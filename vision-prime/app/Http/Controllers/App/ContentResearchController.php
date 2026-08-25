<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Content\Models\ContentGuardrail;
use App\Domains\Content\Models\PromptTemplate;
use App\Domains\Content\Services\SERPAnalyzer;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Workspace\Models\Site;
use App\Http\Controllers\App\Concerns\InteractsWithContentApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تحقیق محتوا — پیشنهاد موضوع، بافت GSC و تحلیل SERP.
 *
 * (از تفکیک ContentApiController ۱۴۱۴خطی — رفتار و مسیرها بدون تغییر)
 */
class ContentResearchController extends Controller
{
    use InteractsWithContentApi;

    public function __construct(
        private readonly profiler $profiler,
        private readonly gateway $gateway
    ) {}

    public function research(Request $request, CurrentOrganization $org): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);

        if ($siteId === 0) {
            return response()->json(['error' => 'site_id الزامی است'], 422);
        }

        $site = Site::query()->where('organization_id', $org->id())->findOrFail($siteId);

        // 1. Get keyword insights with opportunities
        $insights = DB::table('keyword_insights')
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // 2. Get open opportunities
        $opportunities = DB::table('opportunities')
            ->where('site_id', $siteId)
            ->where('status', 'open')
            ->orderByDesc('score')
            ->limit(20)
            ->get();

        // 3. Get money page audits needing improvement
        $audits = DB::table('money_page_audits')
            ->join('url_profiles', 'url_profiles.id', '=', 'money_page_audits.url_profile_id')
            ->where('url_profiles.site_id', $siteId)
            ->where('money_page_audits.score', '<', 80)
            ->orderBy('money_page_audits.score')
            ->limit(10)
            ->get();

        $topics = [];

        // From opportunities
        foreach ($opportunities as $opp) {
            $insight = $insights->first(fn ($i) => $i->id === $opp->keyword_insight_id);
            $query = $insight->query_normalized ?? '';
            if ($query === '') {
                continue;
            }

            $profiled = $this->profiler->profile([
                'title' => $query,
                'target_query' => $query,
                'content_type' => 'article',
                'subtype' => '',
            ]);

            $topics[] = [
                'type' => 'opportunity',
                'keyword' => $query,
                'score' => (int) $opp->score,
                'explanation' => $opp->explanation ?? '',
                'suggested_type' => $profiled['content_type'],
                'suggested_subtype' => $profiled['subtype'],
                'intent' => $profiled['intent'],
                'metrics' => [
                    'clicks' => (int) ($insight->latest_metrics->clicks ?? 0),
                    'impressions' => (int) ($insight->latest_metrics->impressions ?? 0),
                    'position' => (float) ($insight->latest_metrics->position ?? 0),
                ],
            ];
        }

        // From audits (pages needing improvement)
        foreach ($audits as $audit) {
            $meta = json_decode($audit->metadata ?? '{}', true) ?? [];
            $topics[] = [
                'type' => 'audit_improvement',
                'keyword' => $meta['title'] ?? $audit->canonical_url ?? '',
                'url' => $audit->canonical_url ?? '',
                'score' => (int) $audit->score,
                'explanation' => "صفحه نیاز به بهبود دارد (امتیاز: {$audit->score})",
                'suggested_type' => $audit->content_type ?? 'article',
                'url_profile_id' => (int) $audit->url_profile_id,
            ];
        }

        // From keyword insights (top queries not yet covered)
        foreach ($insights->take(10) as $insight) {
            $query = $insight->query_normalized ?? '';
            if ($query === '') {
                continue;
            }

            $exists = DB::table('url_profiles')
                ->where('site_id', $siteId)
                ->where('canonical_url', '!=', '')
                ->first();

            $topics[] = [
                'type' => 'keyword_gap',
                'keyword' => $query,
                'score' => 50,
                'explanation' => 'کوئری پرجستجو بدون محتوای اختصاصی',
                'suggested_type' => 'article',
                'intent' => DB::table('intent_classifications')
                    ->where('keyword_insight_id', $insight->id)
                    ->value('intent') ?? 'informational',
                'metrics' => [
                    'clicks' => (int) ($insight->latest_metrics->clicks ?? 0),
                    'impressions' => (int) ($insight->latest_metrics->impressions ?? 0),
                    'position' => (float) ($insight->latest_metrics->position ?? 0),
                ],
            ];
        }

        // Sort by score
        usort($topics, fn ($a, $b) => $b['score'] <=> $a['score']);

        return response()->json([
            'topics' => array_slice($topics, 0, 30),
            'site' => ['id' => $site->id, 'name' => $site->name],
        ]);
    }

    /**
     * Get GSC context for a title — find related queries with real metrics.
     *
     * GET /api/content/gsc-context?site_id=1&title=...
     */
    public function gscContext(Request $request, CurrentOrganization $org): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);
        $title = (string) $request->query('title', '');

        if ($siteId === 0 || $title === '') {
            return response()->json(['error' => 'site_id و title الزامی است'], 422);
        }

        $site = Site::query()->where('organization_id', $org->id())->findOrFail($siteId);

        // Search keyword_insights for queries matching the title
        $keywords = ['clicks', 'impressions', 'ctr', 'position'];
        $insights = DB::table('keyword_insights')
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->where(function ($q) use ($title) {
                // Split title into words and match any
                $words = array_filter(explode(' ', trim($title)), fn ($w) => mb_strlen($w) > 2);
                foreach ($words as $word) {
                    $q->orWhere('query_normalized', 'LIKE', '%'.$word.'%');
                }
            })
            ->orderByDesc('latest_metrics->clicks')
            ->limit(20)
            ->get();

        $queries = [];
        $totalClicks = 0;
        $totalImpressions = 0;

        foreach ($insights as $insight) {
            $metrics = json_decode($insight->latest_metrics, true) ?? [];
            $clicks = (int) ($metrics['clicks'] ?? 0);
            $impressions = (int) ($metrics['impressions'] ?? 0);
            $ctr = (float) ($metrics['ctr'] ?? 0);
            $position = (float) ($metrics['position'] ?? 0);

            // Calculate CTR gap: what CTR should be based on position
            $expectedCtr = ($position <= 3) ? 0.30 : (($position <= 10) ? 0.10 : 0.03);
            $ctrGap = max(0, $expectedCtr - $ctr);

            $queries[] = [
                'query' => $insight->query_normalized,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => round($ctr * 100, 2),
                'position' => round($position, 1),
                'ctr_gap' => round($ctrGap * 100, 2),
                'opportunity_score' => (int) ($impressions * $ctrGap),
            ];

            $totalClicks += $clicks;
            $totalImpressions += $impressions;
        }

        // Sort by opportunity score
        usort($queries, fn ($a, $b) => $b['opportunity_score'] <=> $a['opportunity_score']);

        return response()->json([
            'queries' => $queries,
            'summary' => [
                'total_queries' => count($queries),
                'total_clicks' => $totalClicks,
                'total_impressions' => $totalImpressions,
                'avg_ctr' => $totalImpressions > 0 ? round($totalClicks / $totalImpressions * 100, 2) : 0,
                'has_data' => count($queries) > 0,
            ],
            'site' => ['id' => $site->id, 'name' => $site->name],
        ]);
    }

    /**
     * Get AI provider status.
     *
     * GET /api/content/providers
     */
    /**
     * SERP Intelligence — analyze competitor content for a keyword.
     *
     * POST /api/content/serp-analysis
     */
    public function serpAnalysis(Request $request, CurrentOrganization $org): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'keyword' => 'required|string|max:500',
            'subtype' => 'nullable|string|max:100',
            'outline' => 'nullable|array',
        ]);

        $keyword = $data['keyword'];
        $subtype = $data['subtype'] ?? 'how_to_guide';
        $outline = $data['outline'] ?? [];

        $analysis = app(SERPAnalyzer::class)->analyze($keyword, $subtype, $outline);

        return response()->json($analysis);
    }

    /**
     * Generate outline for article.
     *
     * POST /api/content/outline
     */
    public function outline(Request $request, CurrentOrganization $org): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
            'title' => 'required|string|max:500',
            'subtype' => 'nullable|string|max:100',
            'template_id' => 'nullable|integer|exists:prompt_templates,id',
        ]);

        $siteId = (int) $data['site_id'];
        $title = $data['title'];
        $subtype = $data['subtype'] ?? 'how_to_guide';
        $site = Site::query()->where('organization_id', $org->id())->findOrFail($siteId);

        // Fetch GSC data for context
        $gscData = $this->fetchGscMetrics($siteId, $title);

        // Load prompt template if selected
        $template = null;
        if (! empty($data['template_id'])) {
            $template = PromptTemplate::find((int) $data['template_id']);
        }

        // Get guardrails for context
        $guardrails = ContentGuardrail::resolve($org->id(), $siteId, 'article', $subtype);
        $guardrailConfig = $guardrails->toArray();

        // Generate outline via AI
        if ($template) {
            $system = $template->system_prompt;
            $user = $template->render($title).'
'.'Subtype: '.$subtype.'. Site: '.$site->name;
            if ($gscData) {
                $user .= '
'.'GSC Context: '.json_encode($gscData, JSON_UNESCAPED_UNICODE);
            }
        } else {
            [$system, $user] = $this->gateway->generateOutline($title, $subtype, $site->name, $gscData);
        }

        // Use the AI gateway to generate
        $result = $this->gateway->generate($system, $user, 'outline');

        // Parse JSON from response (handle markdown fences, extra text, etc.)
        $content = $result['content'] ?? '[]';
        $outline = [];

        // Step 1: Try direct decode
        $trimmed = trim($content);
        $outline = json_decode($trimmed, true);

        // Step 2: Strip markdown code fences
        if (! is_array($outline)) {
            $cleaned = preg_replace('/^```(?:json)?\s*/im', '', $trimmed);
            $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
            $outline = json_decode(trim($cleaned), true);
        }

        // Step 3: Extract JSON array from mixed text
        if (! is_array($outline)) {
            if (preg_match('/\[{.*?}\]/s', $content, $matches)) {
                $outline = json_decode($matches[0], true);
            }
        }

        // Step 4: Try non-greedy match for array
        if (! is_array($outline)) {
            if (preg_match('/\[.*\]/s', $content, $matches)) {
                $outline = json_decode($matches[0], true);
            }
        }

        if (! is_array($outline)) {
            $outline = [];
            Log::warning('Outline parsing failed', [
                'raw_content' => mb_substr($content, 0, 500),
                'model' => $result['model'] ?? 'unknown',
                'source' => $result['source'] ?? 'unknown',
            ]);
        }

        // Validate and normalize each item
        $normalized = [];
        foreach ($outline as $item) {
            if (! is_array($item) || empty($item['heading'])) {
                continue;
            }
            $normalized[] = [
                'heading' => (string) $item['heading'],
                'level' => in_array(($item['level'] ?? 2), [2, 3]) ? (int) $item['level'] : 2,
                'note' => (string) ($item['note'] ?? ''),
            ];
        }

        return response()->json([
            'outline' => $normalized,
            'model' => $result['model'] ?? 'unknown',
            'source' => $result['source'] ?? 'unknown',
            'gsc_queries_count' => count($gscData['related_queries'] ?? []),
            'guardrails_applied' => ! empty($guardrailConfig),
        ]);
    }
}
