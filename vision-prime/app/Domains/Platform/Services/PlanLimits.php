<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Models\Plan;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * سقف‌های پلن هر سازمان — منبع واحد اعمال محدودیت‌های تجاری.
 *
 * منبع limits: پلنِ subscription فعال سازمان (ستون JSON plans.limits).
 * بدون subscription (مثلاً دورهٔ آزمایشی): پیش‌فرض‌های پایین config/vision-prime.php.
 *
 * مصرف ماهانهٔ AI از ai_usage_logs (input+output tokens) محاسبه می‌شود.
 */
class PlanLimits
{
    public const LIMIT_KEYS = ['max_sites', 'max_clients', 'max_ai_tokens_monthly', 'max_profiles'];

    /**
     * سقف‌های مؤثر سازمان.
     *
     * @return array{max_sites: int, max_clients: int, max_ai_tokens_monthly: int, max_profiles: int, plan_key: string}
     */
    public function limitsFor(Organization $organization): array
    {
        $subscription = Subscription::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('starts_at')
            ->with('plan')
            ->first();

        $plan = $subscription?->plan;

        $raw = $plan?->limits ?? (array) config('vision-prime.trial_limits');

        return [
            'max_sites' => max(1, (int) ($raw['max_sites'] ?? 1)),
            'max_clients' => max(1, (int) ($raw['max_clients'] ?? 1)),
            'max_ai_tokens_monthly' => max(0, (int) ($raw['max_ai_tokens_monthly'] ?? 0)),
            'max_profiles' => max(1, (int) ($raw['max_profiles'] ?? 1)),
            'plan_key' => $plan?->key ?? 'trial',
        ];
    }

    /** آیا سازمان می‌تواند سایت/مشتری جدید بسازد؟ پیام فارسی برای UI برمی‌گرداند. */
    public function canCreate(Organization $organization, string $resource): ?string
    {
        $limits = $this->limitsFor($organization);

        return match ($resource) {
            'sites' => $this->siteCount($organization) < $limits['max_sites']
                ? null
                : "سقف پلن «{$limits['plan_key']}» {$limits['max_sites']} سایت است. برای افزودن سایت جدید پلن خود را ارتقا دهید.",
            'clients' => $this->clientCount($organization) < $limits['max_clients']
                ? null
                : "سقف پلن «{$limits['plan_key']}» {$limits['max_clients']} مشتری است. برای افزودن مشتری جدید پلن خود را ارتقا دهید.",
            default => null,
        };
    }

    private function siteCount(Organization $organization): int
    {
        return Site::query()
            ->where('organization_id', $organization->getKey())
            ->whereNull('deleted_at')
            ->count();
    }

    private function clientCount(Organization $organization): int
    {
        return Client::query()
            ->where('organization_id', $organization->getKey())
            ->count();
    }

    /** مصرف توکن AI سازمان در ماه جاری (شمسی-بی‌اعتنا؛ میلادی کافی است). */
    public function aiTokensUsedThisMonth(Organization $organization): int
    {
        return (int) DB::table('ai_usage_logs')
            ->where('organization_id', $organization->getKey())
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum(DB::raw('input_tokens + output_tokens'));
    }

    /** توکن باقی‌ماندهٔ AI این ماه (۰ یا کمتر = مسدود). */
    public function aiTokensRemaining(Organization $organization): int
    {
        $limits = $this->limitsFor($organization);

        return $limits['max_ai_tokens_monthly'] - $this->aiTokensUsedThisMonth($organization);
    }

    /** پیام خطای سقف AI یا null اگر مجاز است. */
    public function aiQuotaError(Organization $organization): ?string
    {
        $limits = $this->limitsFor($organization);

        if ($limits['max_ai_tokens_monthly'] <= 0) {
            return 'تولید محتوا با هوش مصنوعی در پلن فعلی فعال نیست.';
        }

        return $this->aiTokensRemaining($organization) > 0
            ? null
            : "سهمیهٔ ماهانهٔ تولید محتوای پلن «{$limits['plan_key']}» به پایان رسیده است. ارتقای پلن یا ابتدای ماه بعدی آن را فعال می‌کند.";
    }

    /** خلاصه برای نمایش در UI تنظیمات/صورتحساب سازمان. */
    public function usageSummary(Organization $organization): array
    {
        $limits = $this->limitsFor($organization);

        return [
            'plan' => $limits['plan_key'],
            'sites' => ['used' => $this->siteCount($organization), 'max' => $limits['max_sites']],
            'clients' => ['used' => $this->clientCount($organization), 'max' => $limits['max_clients']],
            'ai_tokens_monthly' => [
                'used' => $this->aiTokensUsedThisMonth($organization),
                'max' => $limits['max_ai_tokens_monthly'],
            ],
        ];
    }

    /** پلن پیش‌فرض برای سازمان تازه (برای onboarding). */
    public static function defaultTrialPlan(): ?Plan
    {
        return Plan::query()->where('key', 'starter')->where('is_active', true)->first();
    }
}
