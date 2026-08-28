<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Ai\Services\ImageGateway;
use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Content\Models\ContentDraft;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Http\Controllers\App\Concerns\InteractsWithContentApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * API موتور تصویر (فاز A — استودیوی محتوا v2):
 *   GET  /api/content/image-provider          → سرویس‌های موجود + تنظیمات فعلی
 *   POST /api/content/image-provider          → ذخیرهٔ کلید (رمزنگاری‌شده)
 *   POST /api/content/image-provider/test     → تست اتصال
 *   GET  /api/content/images/search?q=        → جستجوی استوک (ترجمهٔ هوشمند کلیدواژه)
 *   POST /api/content/images/generate         → تولید AI (سهمیهٔ پلن)
 *   POST /api/content/images/attach           → نصب دارایی روی پیش‌نویس (کاور/بخش)
 */
class ContentImageController extends Controller
{
    use InteractsWithContentApi;

    public function __construct(
        private readonly ImageGateway $images,
    ) {}

    public function providers(CurrentOrganization $org): JsonResponse
    {
        $rows = DB::table('image_provider_settings')
            ->where('organization_id', $org->id())
            ->get(['provider', 'status']);

        return response()->json([
            'providers' => $this->images->availableProviders(),
            'configured' => $rows->map(fn ($r): array => ['provider' => $r->provider, 'status' => $r->status])->values(),
        ]);
    }

    public function store(Request $request, CurrentOrganization $org): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'provider' => ['required', 'string', 'in:openai-image,pexels,unsplash'],
            'api_key' => ['required', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $config = ['api_key' => $data['api_key']];
        if (($data['model'] ?? '') !== '') {
            $config['model'] = $data['model'];
        }

        DB::table('image_provider_settings')->updateOrInsert(
            ['organization_id' => $org->id(), 'provider' => $data['provider']],
            [
                'encrypted_config' => Crypt::encryptString(json_encode($config, JSON_UNESCAPED_UNICODE)),
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        app(RecordAuditLog::class)->handle(
            action: 'image.provider_setting_saved',
            after: ['provider' => $data['provider']],
        );

        return response()->json(['success' => true, 'message' => 'کلید سرویس تصویر ذخیره شد.']);
    }

    public function test(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'provider' => ['required', 'string'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $apiKey = (string) ($data['api_key'] ?? '');
        if ($apiKey === '') {
            $organization = app(CurrentOrganization::class)->get();
            if ($organization !== null) {
                $apiKey = (string) ($this->images->configFor($organization, (string) $data['provider'])['api_key'] ?? '');
            }
        }

        return response()->json($this->images->testConnection((string) $data['provider'], $apiKey));
    }

    public function search(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:200']]);

        return response()->json($this->images->searchStock($org->get(), (string) $data['q']));
    }

    public function generate(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
            'size' => ['nullable', 'string', 'in:1536x1024,1024x1024,1024x1536'],
            'alt' => ['nullable', 'string', 'max:300'],
            'draft_id' => ['nullable', 'integer'],
        ]);

        $result = $this->images->generate(
            $org->get(),
            (string) $data['prompt'],
            (string) ($data['size'] ?? ImageGateway::SIZE_SECTION),
            $data['alt'] ?? null,
            $data['draft_id'] ?? null,
        );

        return response()->json($result);
    }

    public function attach(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'draft_id' => ['required', 'integer'],
            'slot' => ['nullable', 'string', 'in:cover,section,gallery'],
            'section_index' => ['nullable', 'integer', 'min:0'],
        ]);

        $asset = DB::table('media_assets')
            ->where('organization_id', $org->id())
            ->where('id', (int) $data['asset_id'])
            ->first();
        if ($asset === null) {
            return response()->json(['success' => false, 'error' => 'دارایی تصویری یافت نشد.'], 404);
        }

        $draft = ContentDraft::query()
            ->whereHas('site', fn ($q) => $q->where('organization_id', $org->id()))
            ->findOrFail((int) $data['draft_id']);

        DB::table('media_assets')->where('id', $asset->id)->update([
            'draft_id' => $draft->id,
            'site_id' => $draft->site_id,
            'slot' => $data['slot'] ?? 'section',
            'section_index' => $data['section_index'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'asset_id' => $asset->id, 'draft_id' => $draft->id]);
    }

    /** پیشنهاد جای‌نگهدار برای عنوان/بخش — بدون شبکه، همیشه در دسترس. */
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'section' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['success' => true, 'suggestions' => $this->images->suggestions((string) $data['title'], (string) ($data['section'] ?? ''))]);
    }
}
