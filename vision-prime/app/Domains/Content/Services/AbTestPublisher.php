<?php

declare(strict_types=1);

namespace App\Domains\Content\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * انتشار خودکار برنده A/B تست در وردپرس.
 *
 * وقتی تست A/B عنوان تموم بشه و برنده مشخص بشه:
 *   ۱) دستور بروزرسانی عنوان پست در وردپرس ایجاد میشه
 *   ۲) دستور به وردپرس ارسال میشه
 *   ۳) نتیجه ذخیره میشه
 */
class AbTestPublisher
{
    /**
     * بروزرسانی خودکار عنوان پست در وردپرس.
     *
     * @param int $testId شناسه تست A/B
     * @param int $variantId شناسه عنوان برنده
     * @param int $postId شناسه پست وردپرس
     * @return array{ok: bool, command_id: int|null, error: string|null}
     */
    public function applyWinner(int $testId, int $variantId, int $postId): array
    {
        // دریافت اطلاعات تست
        $test = DB::table('title_ab_tests')->where('id', $testId)->first();
        if (! $test) {
            return ['ok' => false, 'command_id' => null, 'error' => 'تست پیدا نشد'];
        }

        // دریافت عنوان برنده
        $variant = DB::table('title_ab_variants')->where('id', $variantId)->first();
        if (! $variant) {
            return ['ok' => false, 'command_id' => null, 'error' => 'عنوان برنده پیدا نشد'];
        }

        // دریافت اتصال وردپرس
        $connection = DB::table('site_connections')
            ->where('site_id', $test->site_id)
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            return ['ok' => false, 'command_id' => null, 'error' => 'اتصال وردپرس برقرار نیست'];
        }

        // ایجاد دستور
        $commandId = DB::table('commands')->insertGetId([
            'site_id' => $test->site_id,
            'source_type' => 'ab_test',
            'source_id' => $testId,
            'type' => 'update_post_title',
            'risk_tier' => 'R2', // تغییر عنوان = ریسک متوسط
            'payload' => json_encode([
                'post_id' => $postId,
                'title' => $variant->title,
            ]),
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'approved', // خودکار تأیید شده
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ثبت نتیجه تست
        DB::table('title_ab_tests')
            ->where('id', $testId)
            ->update([
                'status' => 'completed',
                'winner_variant_id' => $variantId,
                'ended_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('title_ab_variants')
            ->where('id', $variantId)
            ->update(['is_winner' => true]);

        // ثبت در جدول انتشار
        DB::table('ab_test_publish_log')->insert([
            'test_id' => $testId,
            'variant_id' => $variantId,
            'command_id' => $commandId,
            'post_id' => $postId,
            'old_title' => $test->original_title,
            'new_title' => $variant->title,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['ok' => true, 'command_id' => $commandId, 'error' => null];
    }

    /**
     * بروزرسانی وضعیت انتشار (فراخوانی توسط callback وردپرس).
     */
    public function updatePublishStatus(int $commandId, string $status, array $result = []): void
    {
        DB::table('ab_test_publish_log')
            ->where('command_id', $commandId)
            ->update([
                'status' => $status,
                'result' => json_encode($result),
                'updated_at' => now(),
            ]);
    }

    /**
     * لیست انتشارهای یک تست.
     */
    public function getPublishLog(int $testId): array
    {
        return DB::table('ab_test_publish_log')
            ->where('test_id', $testId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'post_id' => $log->post_id,
                'old_title' => $log->old_title,
                'new_title' => $log->new_title,
                'status' => $log->status,
                'result' => $log->result ? json_decode($log->result, true) : null,
                'created_at' => $log->created_at,
            ])->toArray();
    }
}
