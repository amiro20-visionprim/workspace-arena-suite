<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

/**
 * امتیاز اطمینان (۰–۱۰۰) برای انتشار مبتنی بر دادهٔ تصمیم‌ساز (D-013).
 *
 * ترکیب وزنی چهار عامل:
 *  - data_quality: کیفیت/تازگی دادهٔ GSC (۰–۱)
 *  - signal_strength: قدرت سیگنال (شکاف CTR، رتبه، intent، ارزش تجاری) (۰–۱)
 *  - source_agreement: اتفاق‌نظر منابع پیش‌نویس (rule_based / ai)
 *  - history: نرخ موفقیت همان نوع تغییر از impact_events (خنثی در نبود سابقه)
 *
 * خروجی صرفاً عدد + عوامل قابل بازبینی است؛ مقایسه با آستانهٔ پروفایل در
 * PolicyEvaluator انجام می‌شود (فاز ۲).
 */
class ConfidenceScorer
{
    /** @var array<string, float> */
    private array $weights;

    /** @param  array<string, float>  $weights */
    public function __construct(array $weights = [])
    {
        $this->weights = array_merge([
            'data_quality' => 0.30,
            'signal_strength' => 0.35,
            'source_agreement' => 0.20,
            'history' => 0.15,
        ], $weights);
    }

    /**
     * @param  array{data_quality?: float, signal_strength?: float, sources?: string[], history?: array{total: int, successful: int}}  $context
     * @return array{score: int, factors: array<string, float|int>}
     */
    public function score(array $context): array
    {
        $dataQuality = $this->clamp($context['data_quality'] ?? 0.0);
        $signalStrength = $this->clamp($context['signal_strength'] ?? 0.0);
        $agreement = $this->sourceAgreement($context['sources'] ?? []);
        $history = $this->historyRate($context['history'] ?? null);

        $raw = $this->weights['data_quality'] * $dataQuality
            + $this->weights['signal_strength'] * $signalStrength
            + $this->weights['source_agreement'] * $agreement
            + $this->weights['history'] * $history;

        $score = (int) round($raw * 100);

        return [
            'score' => max(0, min(100, $score)),
            'factors' => [
                'data_quality' => round($dataQuality, 3),
                'signal_strength' => round($signalStrength, 3),
                'source_agreement' => round($agreement, 3),
                'history_rate' => round($history, 3),
            ],
        ];
    }

    /** @param  string[]  $sources */
    private function sourceAgreement(array $sources): float
    {
        $hasRule = in_array('rule_based', $sources, true);
        $hasAi = in_array('ai', $sources, true);
        $hasHuman = in_array('human_review', $sources, true);

        // دو منبع ماشینیِ هم‌نظر → توافق کامل
        if ($hasRule && $hasAi) {
            return 1.0;
        }

        // پیش‌نویس ماشینی (rule_based یا ai) + تأیید انسانی = دو منبع مستقلِ هم‌نظر.
        // نکته: پیش از این human_review نادیده گرفته می‌شد و امتیاز انتشارِ
        // تأییدشدهٔ انسانی روی لبهٔ آستانه (۸۴ در برابر ۸۵) می‌ماند.
        if (($hasRule || $hasAi) && $hasHuman) {
            return 0.85;
        }

        if ($hasRule || $hasAi) {
            return 0.6;
        }

        return 0.3;
    }

    /** @param  array{total: int, successful: int}|null  $history */
    private function historyRate(?array $history): float
    {
        $total = (int) ($history['total'] ?? 0);
        if ($total <= 0) {
            return 0.5; // بدون سابقه: خنثی
        }
        $successful = (int) ($history['successful'] ?? 0);

        return $this->clamp($successful / $total);
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
