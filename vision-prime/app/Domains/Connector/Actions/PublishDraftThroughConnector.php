<?php

declare(strict_types=1);

namespace App\Domains\Connector\Actions;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Automation\Actions\ExecuteCommand;
use App\Domains\Content\Models\ContentDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * انتشار پیش‌نویس از طریق «کانکتور امضاشده» — مسیر واحد و امن.
 *
 * پیش از این، انتشار دستیِ پیش‌نویس‌ها از مسیر موازی REST با
 * Application Password انجام می‌شد (فرم دشوار برای کاربر). این اکشن همان
 * مسیر مطمئنِ خط انتشار خودکار را برای دکمه‌های دستی هم باز می‌کند:
 *
 *   content_draft → commands(publish_new_article, approved) → ExecuteCommand
 *   → امضای HMAC → پلاگین → ثبت نتیجه از طریق command-result
 *
 * هیچ رمز وردپرسی ذخیره یا ارسال نمی‌شود؛ فقط توکن جفت‌سازی کافی است.
 */
class PublishDraftThroughConnector
{
    public function __construct(
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  string  $status  publish|draft|pending — وضعیت پست در وردپرس
     * @return array{success: bool, command_id?: int, error?: string}
     */
    public function handle(ContentDraft $draft, string $status = 'publish'): array
    {
        $connection = DB::table('site_connections')
            ->where('site_id', $draft->site_id)
            ->where('status', 'connected')
            ->first();

        if ($connection === null) {
            return [
                'success' => false,
                'error' => 'پلاگین وردپرس برای این سایت جفت نشده است. از صفحهٔ اتصال، فقط با یک توکن وصل کنید.',
                'needs_setup' => true,
                'setup_url' => "/app/sites/{$draft->site_id}/connector",
            ];
        }

        $payload = [
            'type' => 'publish_new_article',
            'idempotency_key' => (string) Str::uuid(),
            'payload' => [
                'title' => (string) $draft->title,
                'content' => (string) $draft->content,
                'slug' => $draft->slug ?: Str::slug((string) $draft->title),
                'status' => in_array($status, ['draft', 'publish', 'pending'], true) ? $status : 'publish',
                'content_type' => $draft->subtype === 'product' ? 'product' : 'article',
                'meta_title' => $draft->meta_title,
                'meta_description' => $draft->meta_description,
            ],
        ];

        $commandId = DB::table('commands')->insertGetId([
            'site_id' => $draft->site_id,
            'source_type' => 'content_draft',
            'source_id' => $draft->id,
            'type' => 'publish_new_article',
            'content_type' => $payload['payload']['content_type'],
            'risk_tier' => 'R3',
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'idempotency_key' => $payload['idempotency_key'],
            'status' => 'approved',
            'decision_source' => 'manual',
            'expires_at' => now()->addDay(),
            'policy_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(ExecuteCommand::class)->handle($commandId);
        } catch (Throwable $e) {
            DB::table('commands')->where('id', $commandId)->update(['status' => 'failed', 'updated_at' => now()]);

            return ['success' => false, 'command_id' => $commandId, 'error' => 'ارسال فرمان به وردپرس ناموفق بود: '.$e->getMessage()];
        }

        $draft->update([
            'status' => $status === 'draft' ? 'draft' : 'published',
            'audit_log' => array_merge($draft->audit_log ?? [], [
                'connector_command_id' => $commandId,
                'dispatched_at' => now()->toISOString(),
                'requested_status' => $status,
            ]),
        ]);

        $this->audit->handle(
            action: 'content.published_via_connector',
            subject: $draft,
            after: ['command_id' => $commandId, 'status' => $status],
        );

        return ['success' => true, 'command_id' => $commandId];
    }
}
