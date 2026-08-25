<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI Content Generation Gateway with automatic failover.
 *
 * Priority chain:
 *   1. User-configured provider (DeepSeek / OpenAI / OpenRouter with custom key)
 *   2. OpenRouter free models (17+ models, auto-rotating)
 *   3. RuleBased offline fallback (never fails)
 *
 * Features:
 *   - Auto-detect rate limits (429, 402, rate_limit_exceeded)
 *   - Auto-switch to next provider/model on limit hit
 *   - Restart generation if limit hit mid-generation (max 3 retries)
 *   - Cache rate limit state in Cache (TTL = reset time)
 *   - Log all switches and errors
 */
class AiGateway
{
    /** Maximum retries when rate-limited mid-generation */
    private const MAX_RETRIES = 3;

    /** Cache prefix for rate limit tracking */
    private const CACHE_PREFIX = 'ai_gateway:limit:';

    /**
     * OpenRouter free models — loaded from ProviderRegistry.
     * These rotate automatically when rate-limited.
     */
    private const FREE_MODELS = [
        'meta-llama/llama-3.3-70b-versatile:free',
        'qwen/qwen3-235b-a22b:free',
        'deepseek/deepseek-r1-0528:free',
        'google/gemma-4-31b-it:free',
        'mistralai/mistral-small-3.2-24b-instruct:free',
        'nvidia/nemotron-3-ultra-550b-a55b:free',
        'meta-llama/llama-3.3-8b-instruct:free',
        'nvidia/nemotron-3.5-lightning:free',
    ];

    public function __construct(
        private readonly RuleBasedDraft $fallback,
        private readonly AiPromptBuilder $prompts = new AiPromptBuilder,
    ) {}

    /**
     * Generate article draft with automatic failover.
     *
     * @param  array<string, mixed>  $context
     * @return array{content: string, model: string, source: string, usage: array<string, mixed>}
     */
    public function generateArticleDraft(Organization $org, array $context): array
    {
        [$system, $user] = $this->prompts->articlePrompts($context);

        return $this->generateWithFailover($org, 'article', $system, $user, $context);
    }

    /**
     * Generate meta title/description with automatic failover.
     */
    public function generateMetaDraft(Organization $org, array $context): array
    {
        $kind = in_array($context['kind'] ?? '', ['meta_title', 'meta_description'], true)
            ? $context['kind']
            : 'meta_title';

        [$system, $user] = $this->prompts->metaPrompts($kind, $context);

        return $this->generateWithFailover($org, $kind, $system, $user, $context);
    }

    /**
     * Generate raw content from system+user prompts.
     */
    public function generate(string $system, string $user, string $kind = 'article', array $context = []): array
    {
        $org = app(CurrentOrganization::class)->get();

        return $this->generateWithFailover($org, $kind, $system, $user, $context);
    }

    /**
     * Core generation with failover chain.
     * Tries user-configured provider first, then free models, then rule-based.
     */
    private function generateWithFailover(Organization $org, string $kind, string $system, string $user, array $context = []): array
    {
        $retryCount = 0;

        while (true) {
            try {
                // 1. Try user-configured provider
                $result = $this->tryUserProvider($org, $system, $user);
                if ($result !== null) {
                    return $result;
                }

                // 2. Try OpenRouter free models
                $result = $this->tryFreeModels($system, $user);
                if ($result !== null) {
                    return $result;
                }

                // 3. Fallback to rule-based
                Log::info('AiGateway: all AI providers exhausted, using RuleBased fallback');

                return $this->fallback->generate($kind, ['kind' => $kind] + ($context !== [] ? $context : $this->contextFromPrompts($system, $user)));

            } catch (\RuntimeException $e) {
                $retryCount++;
                if ($retryCount >= self::MAX_RETRIES) {
                    Log::error("AiGateway: max retries ({$retryCount}) reached, using RuleBased fallback", [
                        'error' => $e->getMessage(),
                    ]);

                    return $this->fallback->generate($kind, ['kind' => $kind] + ($context !== [] ? $context : $this->contextFromPrompts($system, $user)));
                }

                Log::warning("AiGateway: retry {$retryCount}/".self::MAX_RETRIES.' after rate limit', [
                    'error' => $e->getMessage(),
                ]);
                // Brief pause before retry
                usleep(500_000); // 0.5 second
            }
        }
    }

    /**
     * Try user-configured provider from ai_provider_settings table.
     */
    private function tryUserProvider(Organization $org, string $system, string $user): ?array
    {
        $settings = DB::table('ai_provider_settings')
            ->where('organization_id', $org->getKey())
            ->where('status', 'active')
            ->first();

        if ($settings === null) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX.'user:'.$org->getKey();
        if (Cache::has($cacheKey)) {
            return null; // Rate limited, skip
        }

        $config = json_decode(Crypt::decryptString($settings->encrypted_config), true) ?? [];
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($apiKey === '') {
            return null;
        }

        $model = (string) ($config['model'] ?? match ($settings->provider) {
            'anthropic' => 'claude-3-5-haiku-latest',
            'openrouter' => 'openai/gpt-4o-mini',
            'deepseek' => 'deepseek-chat',

            'groq' => 'llama-3.3-70b-versatile',
            'gapgpt' => 'gpt-4o-mini',
            default => 'gpt-4o-mini',
        });

        try {
            $result = match ($settings->provider) {
                'anthropic' => $this->callAnthropic($apiKey, $model, $system, $user),
                'deepseek' => $this->callOpenAiCompatible('deepseek', $apiKey, $model, $system, $user),

                'groq' => $this->callOpenAiCompatible('groq', $apiKey, $model, $system, $user),
                'gapgpt' => $this->callOpenAiCompatible('gapgpt', $apiKey, $model, $system, $user),
                default => $this->callOpenAiCompatible((string) $settings->provider, $apiKey, $model, $system, $user),
            };

            Log::info('AiGateway: user provider succeeded', [
                'provider' => $settings->provider,
                'model' => $model,
            ]);

            return $result;
        } catch (\RuntimeException $e) {
            if ($this->isRateLimit($e)) {
                Log::warning('AiGateway: user provider rate limited', [
                    'provider' => $settings->provider,
                    'model' => $model,
                ]);
                // Cache the rate limit for 60 seconds
                Cache::put($cacheKey, true, 60);
                throw $e; // Re-throw to trigger retry
            }
            Log::warning('AiGateway: user provider failed', [
                'provider' => $settings->provider,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null; // Non-rate-limit error, try next provider
        }
    }

    /**
     * Try OpenRouter free models in order, skipping rate-limited ones.
     */
    private function tryFreeModels(string $system, string $user): ?array
    {
        $apiKey = config('services.openrouter.key', '');

        if ($apiKey === '') {
            // Try to get from env or config
            $apiKey = env('OPENROUTER_API_KEY', '');
            if ($apiKey === '') {
                return null;
            }
        }

        foreach (self::FREE_MODELS as $model) {
            $cacheKey = self::CACHE_PREFIX.'free:'.$model;
            if (Cache::has($cacheKey)) {
                continue; // Rate limited, skip
            }

            try {
                $result = $this->callOpenAiCompatible('openrouter', $apiKey, $model, $system, $user);

                Log::info('AiGateway: free model succeeded', ['model' => $model]);

                return $result;
            } catch (\RuntimeException $e) {
                if ($this->isRateLimit($e)) {
                    Log::warning('AiGateway: free model rate limited', ['model' => $model]);
                    // Cache for 60 seconds
                    Cache::put($cacheKey, true, 60);

                    continue; // Try next model
                }
                Log::warning('AiGateway: free model failed', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                continue; // Try next model
            }
        }

        return null; // All free models exhausted
    }

    /**
     * Call OpenAI-compatible API (OpenAI, DeepSeek, OpenRouter).
     */
    private function callOpenAiCompatible(string $provider, string $apiKey, string $model, string $system, string $user): array
    {
        $endpoint = match ($provider) {
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'deepseek' => 'https://api.deepseek.com/v1/chat/completions',

            'groq' => 'https://api.groq.com/openai/v1/chat/completions',
            'gapgpt' => config('services.gapgpt.endpoint', 'https://api.gapgpt.app/v1/chat/completions'),
            default => 'https://api.openai.com/v1/chat/completions',
        };

        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($provider === 'openrouter') {
            $headers['HTTP-Referer'] = config('app.url', 'https://visionprime-suite.ir');
            $headers['X-Title'] = 'Vision Prime SEO Suite';
        }

        // Proxy support for OpenRouter (Iran → bypass geo-restrictions)
        $http = Http::timeout(120)->withHeaders($headers);
        if ($provider === 'openrouter') {
            $proxy = config('services.openrouter.proxy', '');
            if ($proxy !== '') {
                $http = $http->withOptions(['proxy' => ['http' => $proxy, 'https' => $proxy]]);
            }
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.7,
            'max_tokens' => $provider === 'gapgpt' ? 8000 : 4096,
        ];
        $response = $http->withBody(json_encode($payload), 'application/json')->post($endpoint);

        if (! $response->successful()) {
            $status = $response->status();
            $body = $response->body();

            if ($status === 429 || $status === 402 || str_contains($body, 'rate_limit') || str_contains($body, 'insufficient_quota')) {
                throw new \RuntimeException("Rate limit hit on {$provider}/{$model}: {$status}");
            }

            throw new \RuntimeException("AI provider {$provider}/{$model} error {$status}: ".mb_substr($body, 0, 200));
        }

        $data = $response->json();
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if ($content === '') {
            throw new \RuntimeException("AI provider {$provider}/{$model} returned empty content");
        }

        return [
            'content' => $content,
            'model' => $model,
            'source' => 'ai',
            'usage' => [
                'input_tokens' => (int) ($data['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Call Anthropic Messages API.
     */
    private function callAnthropic(string $apiKey, string $model, string $system, string $user): array
    {
        $response = Http::timeout(120)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 4096,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]);

        if (! $response->successful()) {
            $status = $response->status();
            $body = $response->body();

            if ($status === 429 || $status === 402 || str_contains($body, 'rate_limit')) {
                throw new \RuntimeException("Rate limit hit on anthropic/{$model}: {$status}");
            }

            throw new \RuntimeException("Anthropic error {$status}: ".mb_substr($body, 0, 200));
        }

        $blocks = $response->json('content') ?? [];
        $content = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= (string) ($block['text'] ?? '');
            }
        }

        return [
            'content' => trim($content),
            'model' => $model,
            'source' => 'ai',
            'usage' => [
                'input_tokens' => (int) ($response->json('usage.input_tokens') ?? 0),
                'output_tokens' => (int) ($response->json('usage.output_tokens') ?? 0),
            ],
        ];
    }

    /**
     * Check if an exception is a rate limit error.
     */
    private function isRateLimit(\RuntimeException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'rate limit')
            || str_contains($message, '429')
            || str_contains($message, '402')
            || str_contains($message, 'insufficient_quota')
            || str_contains($message, 'rate_limit_exceeded');
    }

    /**
     * Get status of all available providers for UI display.
     *
     * @return array<int, array{name: string, model: string, status: string, provider: string}>
     */
    public function getProviderStatus(): array
    {
        $status = [];

        $settings = DB::table('ai_provider_settings')->where('status', 'active')->first();
        if ($settings !== null) {
            $cacheKey = self::CACHE_PREFIX.'user:'.$settings->organization_id;
            $status[] = [
                'name' => $settings->provider,
                'model' => json_decode($settings->encrypted_config, true)['model'] ?? 'default',
                'status' => Cache::has($cacheKey) ? 'rate_limited' : 'active',
                'provider' => $settings->provider,
            ];
        }

        $apiKey = config('services.openrouter.key', env('OPENROUTER_API_KEY', ''));
        if ($apiKey !== '') {
            foreach (self::FREE_MODELS as $model) {
                $cacheKey = self::CACHE_PREFIX.'free:'.$model;
                $status[] = [
                    'name' => $model,
                    'model' => $model,
                    'status' => Cache::has($cacheKey) ? 'rate_limited' : 'active',
                    'provider' => 'openrouter_free',
                ];
            }
        }

        $status[] = [
            'name' => 'RuleBased',
            'model' => 'rule-based',
            'status' => 'active',
            'provider' => 'rule_based',
        ];

        return $status;
    }

    /**
     * Test connection to a specific provider.
     */
    public function testConnection(string $provider, string $apiKey = '', string $model = ''): array
    {
        try {
            $system = 'تو یک دستیار ساده هستی. فقط بنویس: "اتصال موفق"';
            $user = 'سلام';

            $result = match ($provider) {
                'deepseek' => $this->callOpenAiCompatible('deepseek', $apiKey, $model ?: 'deepseek-chat', $system, $user),

                'groq' => $this->callOpenAiCompatible('groq', $apiKey, $model ?: 'llama-3.3-70b-versatile', $system, $user),
                'openai' => $this->callOpenAiCompatible('openai', $apiKey, $model ?: 'gpt-4o-mini', $system, $user),
                'openrouter' => $this->callOpenAiCompatible('openrouter', $apiKey, $model ?: 'openai/gpt-4o-mini', $system, $user),
                'anthropic' => $this->callAnthropic($apiKey, $model ?: 'claude-3-5-haiku-latest', $system, $user),
                'gapgpt' => $this->callOpenAiCompatible('gapgpt', $apiKey, $model ?: 'gpt-4o-mini', $system, $user),
                default => throw new \RuntimeException("Unknown provider: {$provider}"),
            };

            return ['success' => true, 'model' => $result['model'], 'content' => mb_substr($result['content'], 0, 100)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate content outline (H2/H3 structure) for a given title.
     *
     * @return array{system: string, user: string}
     */
    public function generateOutline(string $title, string $subtype = 'how_to_guide', string $siteName = '', array $gscData = []): array
    {
        $subtypeLabels = [
            'how_to_guide' => 'راهنمای گام‌به‌گام',
            'listicle' => 'لیستی (فهرستی)',
            'comparison' => 'مقایسه‌ای',
            'review' => 'بررسی و نقد',
            'tutorial' => 'آموزشی',
            'guide' => 'راهنمای جامع',
            'news' => 'خبری',
            'opinion' => 'نظری',
        ];
        $subtypeLabel = $subtypeLabels[$subtype] ?? 'عمومی';

        $gscHint = '';
        if (! empty($gscData['queries'])) {
            $queries = array_slice($gscData['queries'], 0, 5);
            $queryList = [];
            foreach ($queries as $q) {
                $queryList[] = $q['query'].' (position: '.$q['position'].', impressions: '.$q['impressions'].')';
            }
            $gscHint = '

کوئری‌های مرتبط در Google Search Console:
'.implode('
', $queryList);
        }

        $system = 'تو یک متخصص سئو و معماری محتوا هستی. وظیفه تو طراحی یک outline حرفه‌ای برای مقاله‌ای است که قرار است در صفحه اول Google رتبه بگیرد.
'
            .' principles:
'
            .'- از اصول E-E-A-T پیروی کن (تجربه، تخصص، اعتبار، اعتماد)
'
            .'- عناوین باید "کلیک‌خور" باشند (شامل عدد، سال، یا کلمه جذاب)
'
            .'- ساختار محتوا باید Skyscraper باشد (عمیق‌تر و جامع‌تر از رقبا)
'
            .'- هر H2 باید یک سوال یا نیاز واقعی کاربر را پوشش دهد
'
            .'خروجی باید JSON array باشد:
'
            .'[{"heading": "متن عنوان", "level": 2, "note": "توضیح اختیاری - چه محتوایی اینجا برود"}]
'
            .'حداقل ۷ H2 و ۳ H3. H2 اول: مقدمه. H2 آخر: نتیجه‌گیری.
'
            .'فقط JSON array برگردان — بدون توضیح اضافه.';

        $user = 'ساختار outline حرفه‌ای برای مقاله زیر بساز:

'
            ."عنوان: {$title}
"
            ."زیرنوع: {$subtypeLabel}
"
            .($siteName !== '' ? "نام برند: {$siteName}
" : '')
            .$gscHint.'

'
            .'اهداف:
'
            .'- outline باید جامع‌تر و عمیق‌تر از مقالات رقبا باشد
'
            .'- هر H2 باید یک بخش مستقل و مفید باشد (نه فقط پرکردن فضا)
'
            .'- از عناوین جذاب و کلیک‌خور استفاده کن (اعداد، سال، مقایسه)
'
            .'- زیربخش‌ها (H3) باید اطلاعات عملی و کاربردی ارائه دهند
'
            .'- شامل بخش‌های: مقدمه، آموزش، مقایسه/بررسی، مزایا/معایب، FAQ، نتیجه‌گیری
'
            .'- هر عنوان حداکثر ۶۰ کاراکتر
'
            .'- فقط JSON array: [{"heading": "...", "level": 2, "note": "چه محتوایی"}]';

        return [$system, $user];
    }

    private function contextFromPrompts(string $system, string $user): array
    {
        $title = '';
        $keyword = '';
        if (preg_match('/^عنوان:\s*(.+)$/m', $user, $m)) {
            $title = trim($m[1]);
        }
        if (preg_match('/^کلمهٔ کلیدی:\s*(.+)$/m', $user, $m)) {
            $keyword = trim($m[1]);
        }

        return [
            'title' => $title,
            'target_query' => $keyword,
            'site_name' => '',
        ];
    }
}
