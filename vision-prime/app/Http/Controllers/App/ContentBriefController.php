<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Ai\Services\AiGateway;
use App\Domains\Content\Services\ContentProfiler;
use App\Domains\Content\Services\StandardsKB;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Workspace\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * استودیوی محتوا v2 — گام Content Brief (حالت حرفه‌ای):
 * سیستم از GSC + استاندارد مؤثر + قالب محتوا یک بریف پیشنهادی می‌سازد؛
 * کاربر آن را ویرایش می‌کند و همان مبنای تولید می‌شود.
 */
class ContentBriefController extends Controller
{
    public function __construct(
        private readonly AiGateway $gateway,
    ) {}

    public function build(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'target_query' => ['nullable', 'string', 'max:200'],
        ]);

        $site = Site::query()
            ->where('organization_id', $org->id())
            ->findOrFail((int) $data['site_id']);

        $title = trim((string) $data['title']);
        $targetQuery = trim((string) ($data['target_query'] ?? ''));

        // بافت GSC: کوئری‌های برتر این URL/سایت
        $gscQueries = DB::table('keyword_insights')
            ->where('site_id', $site->id)
            ->where('status', 'active')
            ->limit(60)
            ->get(['query_normalized', 'latest_metrics', 'mapped_url_profile_id'])
            ->map(fn ($row): array => [
                'query' => (string) $row->query_normalized,
                'impressions' => (int) (json_decode((string) ($row->latest_metrics ?? 'null'), true)['impressions'] ?? 0),
            ])
            ->sortByDesc('impressions')
            ->values()
            ->take(8)
            ->all();

        // قالب محتوا و استاندارد مؤثر
        $profiled = app(ContentProfiler::class)->profile([
            'title' => $title,
            'target_query' => $targetQuery,
            'content_type' => 'article',
            'subtype' => '',
        ]);
        $standard = app(StandardsKB::class)->standardFor($profiled, (int) $site->id);

        // مخاطب و لحن پیشنهادی از تنظیمات سایت (در صورت وجود)
        $settings = (array) $site->settings;
        $audience = (string) ($settings['audience'] ?? '');
        $tone = (string) ($settings['tone'] ?? '');

        $brief = [
            'title' => $title,
            'target_query' => $targetQuery !== '' ? $targetQuery : ($gscQueries[0]['query'] ?? $title),
            'suggested_title' => $title,
            'content_type' => 'article',
            'subtype' => $profiled['subtype'] ?? 'guide',
            'intent' => $profiled['intent'] ?? 'informational',
            'audience' => $audience !== '' ? $audience : 'کاربران فارسی‌زبان جستجوکنندهٔ این موضوع',
            'tone' => $tone !== '' ? $tone : 'حرفه‌ای و قابل‌اعتماد',
            'word_range' => [$standard['word_min'] ?? 800, $standard['word_max'] ?? 2000],
            'required_elements' => $standard['required_elements'] ?? ['faq', 'cta'],
            'gsc_queries' => $gscQueries,
            'internal_link_candidates' => $this->internalLinkCandidates($site->id, $targetQuery ?: $title),
            'notes' => '',
        ];

        return response()->json(['success' => true, 'brief' => $brief]);
    }

    /** @return array<int, array{url: string, title: string}> */
    private function internalLinkCandidates(int $siteId, string $query): array
    {
        return DB::table('url_profiles')
            ->where('site_id', $siteId)
            ->whereNotNull('metadata')
            ->limit(50)
            ->get(['canonical_url', 'metadata'])
            ->map(fn ($row): array => [
                'url' => (string) $row->canonical_url,
                'title' => (string) (json_decode((string) $row->metadata, true)['title'] ?? ''),
            ])
            ->filter(fn (array $c): bool => $c['title'] !== '' && str_contains(mb_strtolower($c['title'].' '.$c['url']), mb_strtolower($query)) === false)
            ->take(5)
            ->values()
            ->all();
    }
}
