<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Ai\Services\AiGateway;
use App\Domains\Content\Models\ContentDraft;
use App\Domains\Content\Models\ContentGuardrail;
use App\Domains\Content\Models\PromptTemplate;
use App\Domains\Content\Services\ContentProfiler;
use App\Domains\Content\Services\ContentQualityGuard;
use App\Domains\Content\Services\InternalLinkEngine;
use App\Domains\Content\Services\SchemaGenerator;
use App\Domains\Content\Services\SEOExpertAnalyzer;
use App\Domains\Content\Services\StandardsKB;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Workspace\Models\Site;
use App\Http\Controllers\App\Concerns\InteractsWithContentApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * تولید و بازنویسی محتوا با هوش مصنوعی.
 *
 * (از تفکیک ContentApiController ۱۴۱۴خطی — رفتار و مسیرها بدون تغییر)
 */
class ContentGenerateController extends Controller
{
    use InteractsWithContentApi;

    public function __construct(
        private readonly ContentProfiler $profiler,
        private readonly StandardsKB $standards,
        private readonly ContentQualityGuard $qualityGuard,
        private readonly InternalLinkEngine $linkEngine,
        private readonly SchemaGenerator $schemaGen,
        private readonly AiGateway $gateway
    ) {}

    /**
     * Generate content with AI (with failover).
     *
     * POST /api/content/generate
     */
    public function generate(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'site_id' => 'required|integer',
            'keyword' => 'required|string|max:200',
            'title' => 'nullable|string|max:200',
            'subtype' => 'nullable|string',
            'meta_title' => 'nullable|string|max:100',
            'meta_description' => 'nullable|string|max:300',
            'custom_prompt' => 'nullable|string|max:5000',
            'template_id' => 'nullable|integer|exists:prompt_templates,id',
            'tone' => 'nullable|string|max:50',
            'word_count' => 'nullable|integer|min:200|max:10000',
        ]);

        $site = Site::query()->where('organization_id', $org->id())->findOrFail($data['site_id']);

        $profiled = $this->profiler->profile([
            'title' => $data['title'] ?? $data['keyword'],
            'target_query' => $data['keyword'],
            'content_type' => 'article',
            'subtype' => $data['subtype'] ?? '',
        ]);

        $standard = $this->standards->standardFor($profiled, (int) $site->id);

        // Load template if specified
        $template = null;
        if (! empty($data['template_id'])) {
            $template = PromptTemplate::find((int) $data['template_id']);
        }

        $links = $this->linkEngine->suggest(
            (int) $site->id,
            $data['title'] ?? $data['keyword'],
            $data['keyword'],
            'article',
            $profiled['subtype'],
        );

        // Resolve guardrails for this site/subtype
        $guardrail = ContentGuardrail::resolve(
            (int) $site->organization_id,
            (int) $site->id,
            'article',
            $profiled['subtype'] ?? 'general',
        );

        // Build custom instructions from template and user prompt
        $customInstructions = '';
        if ($template !== null) {
            $customInstructions .= $template->render($data['title'] ?? $data['keyword']).'

';
        }
        if (! empty($data['custom_prompt'])) {
            $customInstructions .= 'دستور ویژه کاربر:
'.$data['custom_prompt'].'

';
        }
        if (! empty($data['tone'])) {
            $customInstructions .= 'لحن مورد نظر: '.$data['tone'].'
';
        }
        $wordCount = (int) ($data['word_count'] ?? 0);

        $context = [
            'title' => $data['title'] ?? $data['keyword'],
            'target_query' => $data['keyword'],
            'site_name' => (string) $site->name,
            'url' => '',
            'standard' => $standard,
            'metrics' => $this->fetchGscMetrics($site->id, $data['keyword'] ?? ''),
            'freshness' => null,
            'page_status' => 'publish',
            'internal_links' => $links,
            'guardrails' => $guardrail->toPromptArray(),
            'custom_instructions' => $customInstructions,
            'word_count' => $wordCount > 0 ? $wordCount : null,
        ];

        try {
            $result = $this->gateway->generateArticleDraft($site->organization, $context);

            $metaTitle = $data['meta_title'] ?? ($data['keyword'].' | '.$site->name);
            $metaDesc = $data['meta_description'] ?? '';

            // Auto-generate meta_description from content if empty
            if ($metaDesc === '' && ! empty($result['content'])) {
                $stripped = strip_tags($result['content']);
                $stripped = preg_replace('/\s+/', ' ', trim($stripped));
                // Extract first meaningful sentence after h1/title
                $parts = preg_split('/[.!?؟]+/', $stripped, -1, PREG_SPLIT_NO_EMPTY);
                $metaDesc = '';
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (mb_strlen($part) > 30 && ! str_contains($part, $data['title'] ?? '')) {
                        $metaDesc = mb_substr($part, 0, 155);
                        break;
                    }
                }
                if ($metaDesc === '') {
                    $metaDesc = mb_substr($stripped, 0, 155);
                }
                $metaDesc = rtrim($metaDesc, '،. ').'...';
            }

            $schemas = $this->schemaGen->generate(
                $profiled,
                $result['content'],
                $data['title'] ?? $data['keyword'],
                '',
                (string) $site->name,
                $metaDesc,
                $standard,
            );

            $evaluation = $this->qualityGuard->evaluate($profiled, [
                'title' => $data['title'] ?? $data['keyword'],
                'body' => $result['content'],
                'keyword' => $data['keyword'],
                'headings' => $this->extractHeadings($result['content']),
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
            ], (int) $site->id, $this->standards);

            // Auto-save draft
            $draft = ContentDraft::create([
                'site_id' => (int) $site->id,
                'title' => $data['title'] ?? $data['keyword'],
                'slug' => $this->validateSlug(str()->slug($data['title'] ?? $data['keyword']), $data['keyword'] ?? ''),
                'content' => $result['content'],
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'schemas' => $schemas,
                'quality_score' => $evaluation['score'] ?? 0,
                'subtype' => $profiled['subtype'] ?? 'article',
                'model_used' => $result['model'],
                'status' => 'draft',
                'audit_log' => $evaluation['audit_log'] ?? [],
            ]);

            // Auto expert analysis
            try {
                $analyzer = app(SEOExpertAnalyzer::class);
                $expertResult = $analyzer->analyze([
                    'body' => $result['content'],
                    'title' => $data['title'] ?? $data['keyword'],
                    'keyword' => $data['keyword'],
                    'audit_log' => $evaluation['audit_log'] ?? [],
                ]);
                ContentDraft::where('title', $data['title'] ?? $data['keyword'])
                    ->latest()->first()?->update(['expert_analysis' => $expertResult]);
                $evaluation['expert_analysis'] = $expertResult;
            } catch (\Throwable $e) {
                Log::warning('Expert analysis failed');
            }

            return response()->json([
                'content' => $result['content'],
                'model' => $result['model'],
                'source' => $result['source'],
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'schemas' => $schemas,
                'links' => $links,
                'quality' => $evaluation,
                'standard' => $standard,
                'profile' => $profiled,
                'draft_id' => $draft->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'تولید محتوا ناموفق بود: '.$e->getMessage()], 500);
        }
    }

    /**
     * Regenerate a specific section of content.
     *
     * POST /api/content/regenerate-section
     */
    public function regenerateSection(Request $request, CurrentOrganization $org): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'heading' => 'required|string|max:200',
            'context' => 'required|string',
            'full_content' => 'required|string',
            'keyword' => 'nullable|string|max:100',
            'instruction' => 'nullable|string|max:500',
        ]);

        $system = 'تو یک متخصص سئو و تولید محتوای فارسی هستی. فقط بخش درخواستی را بازنویسی کن.
'
            .'خروجی فقط HTML معتبر آن بخش باشد (بدون h1 اولیه).
'
            .'ساختار و لحن بقیه محتوا را حفظ کن.';

        $instruction = $data['instruction'] ?? '';
        $user = "بخش «{$data['heading']}» را بازنویسی کن.

"
            ."موضوع کلی مقاله: {$data['context']}
"
            .($data['keyword'] ? "کلمه کلیدی: {$data['keyword']}
" : '')
            .($instruction !== '' ? "دستور ویژه: {$instruction}
" : '')
            .'
فقط خروجی HTML این بخش را برگردان:';

        try {
            $result = $this->gateway->generate($system, $user, 'section');

            return response()->json([
                'content' => $result['content'],
                'model' => $result['model'],
                'source' => $result['source'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'بازنویسی بخش ناموفق بود: '.$e->getMessage()], 500);
        }
    }

    public function applySuggestions(Request $request, CurrentOrganization $org): JsonResponse
    {
        $this->authorizeSuperAdmin();
        $data = $request->validate([
            'content' => 'required|string',
            'suggestions' => 'required|array',
            'title' => 'required|string|max:200',
            'keyword' => 'required|string|max:200',
        ]);

        $system = 'تو یک متخصص سئو و ویرایش محتوا هستی. محتوا را بر اساس پیشنهادات بهبود بده. فقط HTML بهبود یافته را برگردان.';
        $user = "پیشنهادات:\n";
        foreach ($data['suggestions'] as $s) {
            $user .= "- {$s}\n";
        }
        $user .= "\nمحتوای فعلی:\n{$data['content']}\n\nفقط HTML بهبود یافته را برگردان:";

        try {
            $result = $this->gateway->generate($system, $user, 'apply_suggestions');

            return response()->json(['content' => $result['content'], 'model' => $result['model']]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'اعمال پیشنهادات ناموفق: '.$e->getMessage()], 500);
        }
    }

    /**
     * Validate and sanitize slug.
     * Rules: max 75 chars, lowercase, contains keyword, no special chars.
     */
    private function validateSlug(string $slug, string $keyword = ''): string
    {
        // Transliterate Persian/Arabic to ASCII-safe
        $slug = mb_strtolower($slug, 'UTF-8');
        // Remove special chars, keep alphanumeric and hyphens
        $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
        // Replace spaces with hyphens
        $slug = preg_replace('/[\s]+/', '-', $slug);
        // Remove consecutive hyphens
        $slug = preg_replace('/-+/', '-', $slug);
        // Trim hyphens from start/end
        $slug = trim($slug, '-');
        // Enforce max length
        if (mb_strlen($slug, 'UTF-8') > 75) {
            $slug = mb_substr($slug, 0, 75, 'UTF-8');
            $slug = rtrim($slug, '-');
        }
        // Ensure slug is not empty
        if ($slug === '') {
            $slug = str()->slug($keyword ?: 'article');
        }

        return $slug;
    }
}
