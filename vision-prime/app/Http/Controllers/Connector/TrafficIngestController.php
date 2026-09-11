<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domains\Connector\Services\VerifyConnectorSignature;
use App\Domains\Reporting\Services\TrafficIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * قدم ۱ اکوسیستم — DataBridge: دریافت تلمتری ترافیک از پلاگین وردپرس.
 *
 * POST /connector/traffic
 * همان قرارداد امضای HMAC خود connector (X-VP-Timestamp/Nonce/Signature) که
 * command-result و health استفاده می‌کنند — هیچ مکانیزم احراز هویت جدیدی
 * معرفی نمی‌شود؛ secret همان secret جفت‌سازی است.
 *
 * بدنه:
 * {
 *   site_id: int,
 *   batch_id: string (idempotency — retry دوباره جمع نمی‌شود),
 *   day: "2026-09-10",
 *   rows: [{ page_url, views, visitors, entrances }, ...],
 *   telemetry: { wp_version, php_version, plugin_version }  // اختیاری
 * }
 */
class TrafficIngestController
{
    public function __invoke(Request $request, VerifyConnectorSignature $verify, TrafficIngestService $ingest): JsonResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'integer'],
            'batch_id' => ['required', 'string', 'max:64'],
            'day' => ['required', 'date_format:Y-m-d'],
            'rows' => ['required', 'array', 'max:1000'],
            'rows.*.page_url' => ['required', 'string', 'max:768'],
            'rows.*.views' => ['required', 'integer', 'min:0'],
            'rows.*.visitors' => ['nullable', 'integer', 'min:0'],
            'rows.*.entrances' => ['nullable', 'integer', 'min:0'],
        ]);

        $connection = DB::table('site_connections')
            ->where('site_id', $data['site_id'])
            ->where('status', 'connected')
            ->firstOrFail();
        $verify->handle($connection, $request->method(), $request->path(), $request->getContent(), (string) $request->header('X-VP-Timestamp'), (string) $request->header('X-VP-Nonce'), (string) $request->header('X-VP-Signature'));

        $rows = array_map(static fn (array $r): array => [
            'page_url' => (string) $r['page_url'],
            'date' => (string) $data['day'],
            'views' => (int) $r['views'],
            'visitors' => (int) ($r['visitors'] ?? 0),
            'entrances' => (int) ($r['entrances'] ?? 0),
        ], $data['rows']);

        $result = $ingest->ingest((int) $data['site_id'], $rows, 'plugin', (string) $data['batch_id']);

        // heartbeat اتصال هم به‌روز شود — پلاگین زنده است
        DB::table('site_connections')
            ->where('id', $connection->id)
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);

        return response()->json(['status' => 'ok'] + $result);
    }
}
