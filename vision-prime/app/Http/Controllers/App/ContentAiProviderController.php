<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Ai\Services\AiGateway;
use App\Http\Controllers\App\Concerns\InteractsWithContentApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مدیریت سرویس‌های هوش مصنوعی و کتابخانه قالب پرامپت.
 *
 * (از تفکیک ContentApiController ۱۴۱۴خطی — رفتار و مسیرها بدون تغییر)
 */
class ContentAiProviderController extends Controller
{
    use InteractsWithContentApi;

    public function __construct(
        private readonly AiGateway $gateway
    ) {}

    public function providers(): JsonResponse
    {
        $this->authorizeSuperAdmin();

        return response()->json(['providers' => $this->gateway->getProviderStatus()]);
    }

    /**
     * Test AI provider connection.
     *
     * POST /api/content/test-provider
     */
    public function testProvider(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();
        $data = $request->validate([
            'provider' => 'required|string',
            'api_key' => 'nullable|string',
            'model' => 'nullable|string',
        ]);

        $result = $this->gateway->testConnection(
            $data['provider'],
            $data['api_key'] ?? '',
            $data['model'] ?? '',
        );

        return response()->json($result);
    }
}
