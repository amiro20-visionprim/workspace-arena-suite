<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use App\Models\User;
use App\Notifications\AutoPublishNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * قدم ۳ اکوسیستم — اطلاع‌رسانی انتشار خودکار به اعضای سازمان.
 *
 * گیرندگان: اعضای فعال سازمانِ صاحب سایت که اجازهٔ مدیریت خودکارسازی دارند
 * (permission key: automation.manage.organization یا automation.view.organization).
 */
class NotifyAutoPublishTeam
{
    public function handle(int $commandId, string $commandType, string $riskTier, int $siteId, array $context = []): void
    {
        $site = DB::table('sites')->where('id', $siteId)->first(['organization_id', 'name']);
        if ($site === null) {
            return;
        }

        $recipients = User::query()
            ->whereHas('memberships', function ($query) use ($site): void {
                $query->where('organization_id', $site->organization_id)
                    ->where('status', 'active')
                    ->whereHas('role.permissions', fn ($permission) => $permission->whereIn('key', ['automation.manage.organization', 'automation.view.organization']));
            })
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AutoPublishNotification(
            commandId: $commandId,
            commandType: $commandType,
            riskTier: $riskTier,
            siteName: $site->name,
            context: $context,
        ));
    }
}