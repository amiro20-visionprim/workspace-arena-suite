<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Content\Models\ContentDraft;
use App\Domains\Content\Services\ContentProfiler;
use App\Domains\Content\Services\ContentQualityGuard;
use App\Domains\Content\Services\InternalLinkEngine;
use App\Domains\Content\Services\SchemaGenerator;
use App\Domains\Content\Services\StandardsKB;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Http\Controllers\App\Concerns\InteractsWithContentApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ارزیابی سئو — امتیازدهی، لینک داخلی، اسکیما و تشخیص تکراری.
 *
 * (از تفکیک ContentApiController ۱۴۱۴خطی — رفتار و مسیرها بدون تغییر)
 */
class ContentSeoController extends Controller
{
    use InteractsWithContentApi;

    public function __construct(
        private readonly ContentProfiler $profiler,
        private readonly StandardsKB $standards,
        private readonly ContentQualityGuard $qualityGuard,
        private readonly InternalLinkEngine $linkEngine,
        private readonly SchemaGenerator $schemaGen
    ) {}

    /**
     * Real-time SEO scoring.
     *
     * POST /api/content/score
     */
    public function score(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'keyword' => 'required|string|max:100',
            'subtype' => 'nullable|string',
            'meta_title' => 'nullable|string|max:100',
            'meta_description' => 'nullable|string|max:300',
        ]);

        $profiled = $this->profiler->profile([
            'title' => $data['title'],
            'target_query' => $data['keyword'],
            'content_type' => 'article',
            'subtype' => $data['subtype'] ?? '',
        ]);

        $evaluation = $this->qualityGuard->evaluate($profiled, [
            'title' => $data['title'],
            'body' => $data['body'],
            'keyword' => $data['keyword'],
            'headings' => $this->extractHeadings($data['body']),
            'meta_title' => $data['meta_title'] ?? '',
            'meta_description' => $data['meta_description'] ?? '',
        ], null, $this->standards);

        return response()->json($evaluation);
    }

    /**
     * Internal link suggestions.
     *
     * POST /api/content/links
     */
    public function links(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'site_id' => 'required|integer',
            'title' => 'required|string|max:200',
            'keyword' => 'required|string|max:100',
            'content_type' => 'nullable|string',
            'subtype' => 'nullable|string',
        ]);

        $suggestions = $this->linkEngine->suggest(
            (int) $data['site_id'],
            $data['title'],
            $data['keyword'],
            $data['content_type'] ?? 'article',
            $data['subtype'] ?? 'article',
        );

        return response()->json(['suggestions' => $suggestions]);
    }

    /**
     * Schema.org preview.
     *
     * POST /api/content/schema
     */
    public function schema(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'content_type' => 'required|string',
            'subtype' => 'nullable|string',
            'html' => 'required|string',
            'title' => 'required|string|max:200',
            'url' => 'nullable|string|max:500',
            'site_name' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:500',
            'site_id' => 'nullable|integer',
        ]);

        $profiled = [
            'content_type' => $data['content_type'],
            'subtype' => $data['subtype'] ?? 'article',
            'intent' => 'informational',
        ];

        $standard = $this->standards->standardFor($profiled, $data['site_id'] ?? null);

        $schemas = $this->schemaGen->generate(
            $profiled,
            $data['html'],
            $data['title'],
            $data['url'] ?? '',
            $data['site_name'] ?? '',
            $data['description'] ?? '',
            $standard,
        );

        return response()->json(['schemas' => $schemas]);
    }

    /**
     * Check for duplicate content before generation.
     *
     * POST /api/content/check-duplicate
     */
    public function checkDuplicate(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:500',
            'site_id' => 'required|integer',
        ]);

        $title = $data['title'];
        $siteId = (int) $data['site_id'];

        // Search for similar titles in content_drafts
        $normalizedName = ContentProfiler::normalizeFa($title);
        $words = array_filter(explode(' ', $normalizedName), fn (string $w): bool => mb_strlen($w) > 2);

        $existingDrafts = ContentDraft::query()
            ->where('site_id', $siteId)
            ->where(function ($q) use ($words, $title) {
                // Exact match
                $q->where('title', $title);
                // Or similar (any word match)
                foreach ($words as $word) {
                    $q->orWhere('title', 'LIKE', '%'.$word.'%');
                }
            })
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'status', 'quality_score', 'created_at']);

        $similar = [];
        foreach ($existingDrafts as $draft) {
            $draftNormalized = ContentProfiler::normalizeFa($draft->title);
            $overlap = count(array_intersect($words, array_filter(explode(' ', $draftNormalized))));
            $similarity = count($words) > 0 ? round($overlap / count($words) * 100) : 0;

            if ($similarity >= 40) {
                $similar[] = [
                    'id' => $draft->id,
                    'title' => $draft->title,
                    'status' => $draft->status,
                    'quality_score' => $draft->quality_score,
                    'similarity' => $similarity,
                    'created_at' => $draft->created_at,
                ];
            }
        }

        usort($similar, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return response()->json([
            'has_duplicate' => count($similar) > 0,
            'similar_count' => count($similar),
            'similar_drafts' => $similar,
        ]);
    }
}
