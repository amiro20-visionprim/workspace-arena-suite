<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * خروجی CSV گزارش حسابرسی برای یک سازمان (F3-07 — پاسخ به RFPهای آژانسی).
 *
 *   php artisan audit:export {organization-id} {--days=90} {--action=}
 *
 * خروجی: storage/app/audit-export-org{ID}-{date}.csv
 */
class ExportAuditLog extends Command
{
    protected $signature = 'audit:export {organization} {--days=90} {--action=}';

    protected $description = 'خروجی CSV گزارش حسابرسی سازمان (برای تفتیش/انطباق/RFP)';

    public function handle(): int
    {
        $orgId = (int) $this->argument('organization');
        $exists = DB::table('organizations')->where('id', $orgId)->exists();

        if (! $exists) {
            $this->error("سازمان #{$orgId} یافت نشد.");

            return self::FAILURE;
        }

        $query = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->where('audit_logs.organization_id', $orgId)
            ->where('audit_logs.occurred_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('audit_logs.occurred_at');

        if (($action = (string) $this->option('action')) !== '') {
            $query->where('audit_logs.action', 'like', "{$action}%");
        }

        $path = storage_path("app/audit-export-org{$orgId}-".now()->format('Ymd-His').'.csv');
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error('نمی‌توان فایل خروجی نوشت (storage/app قابل نوشتن است؟).');

            return self::FAILURE;
        }

        fputcsv($handle, ['timestamp', 'user_id', 'user_email', 'action', 'subject_type', 'subject_id', 'metadata'], ',', '"', '\\');

        $count = 0;
        $query->chunk(500, function ($rows) use ($handle, &$count): void {
            foreach ($rows as $row) {
                fputcsv($handle, [
                    (string) $row->occurred_at,
                    $row->actor_id,
                    $row->email,
                    $row->action,
                    $row->subject_type,
                    $row->subject_id,
                    (string) $row->metadata,
                ], ',', '"', '\\');
                $count++;
            }
        });

        fclose($handle);
        $this->info("✅ {$count} ردیف حسابرسی در {$path}");

        return self::SUCCESS;
    }
}
