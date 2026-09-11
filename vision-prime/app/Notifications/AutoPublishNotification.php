<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * قدم ۳ اکوسیستم — اطلاع‌رسانی انتشار خودکار (T2/T3).
 *
 * بعد از هر انتشار خودکار (تصمیم policy)، اعضای سازمان با دسترسی مدیریت خودکارسازی
 * از طریق اعلان درون‌برنامه‌ای مطلع می‌شوند تا حلقهٔ «خودکار + اطلاع‌رسانی» بسته شود.
 */
class AutoPublishNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $commandId,
        public readonly string $commandType,
        public readonly string $riskTier,
        public readonly ?string $siteName,
        public readonly array $context = [],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'auto_publish',
            'command_id' => $this->commandId,
            'command_type' => $this->commandType,
            'risk_tier' => $this->riskTier,
            'site_name' => $this->siteName,
            'message' => 'یک تغییر به‌صورت خودکار منتشر شد ('.$this->commandType.'، ریسک '.$this->riskTier.($this->siteName !== null ? '، سایت '.$this->siteName : '').').',
            'context' => $this->context,
            'published_at' => now()->toIso8601String(),
        ];
    }
}