<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformAiController extends Controller
{
    private const PROVIDERS = [
        'groq' => ['name' => 'Groq', 'endpoint' => 'https://api.groq.com/openai/v1/chat/completions', 'default_model' => 'llama-3.3-70b-versatile'],
        'deepseek' => ['name' => 'DeepSeek', 'endpoint' => 'https://api.deepseek.com/v1/chat/completions', 'default_model' => 'deepseek-chat'],
        'openrouter' => ['name' => 'OpenRouter', 'endpoint' => 'https://openrouter.ai/api/v1/chat/completions', 'default_model' => 'mistralai/mistral-small-3.1-24b-instruct:free'],
        'openai' => ['name' => 'OpenAI', 'endpoint' => 'https://api.openai.com/v1/chat/completions', 'default_model' => 'gpt-4o-mini'],
        'anthropic' => ['name' => 'Anthropic', 'endpoint' => 'https://api.anthropic.com/v1/messages', 'default_model' => 'claude-sonnet-4-20250514'],
        'google' => ['name' => 'Google Gemini', 'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models', 'default_model' => 'gemini-2.0-flash'],
        'together' => ['name' => 'Together AI', 'endpoint' => 'https://api.together.xyz/v1/chat/completions', 'default_model' => 'meta-llama/Llama-3-70b-chat-hf'],
        'fireworks' => ['name' => 'Fireworks AI', 'endpoint' => 'https://api.fireworks.ai/inference/v1/chat/completions', 'default_model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct'],
        'mistral' => ['name' => 'Mistral AI', 'endpoint' => 'https://api.mistral.ai/v1/chat/completions', 'default_model' => 'mistral-small-latest'],
        'cohere' => ['name' => 'Cohere', 'endpoint' => 'https://api.cohere.ai/v2/chat', 'default_model' => 'command-r-plus'],
        'deepinfra' => ['name' => 'DeepInfra', 'endpoint' => 'https://api.deepinfra.com/v1/openai/chat/completions', 'default_model' => 'meta-llama/Meta-Llama-3-70B-Instruct'],
        'novita' => ['name' => 'Novita AI', 'endpoint' => 'https://api.novita.ai/v3/openai/chat/completions', 'default_model' => 'meta-llama/llama-3.1-70b-instruct'],
        'gapgpt' => ['name' => 'GapGPT', 'endpoint' => 'https://api.gapgpt.ir/v1/chat/completions', 'default_model' => 'gpt-3.5-turbo'],
        'samani' => ['name' => 'Samani', 'endpoint' => 'https://api.samani.ai/v1/chat/completions', 'default_model' => 'samani-chat'],
        'parstech' => ['name' => 'ParsTech', 'endpoint' => 'https://api.parstech.ai/v1/chat/completions', 'default_model' => 'parstech-chat'],
        'ayez' => ['name' => 'Ayez', 'endpoint' => 'https://api.ayez.ai/v1/chat/completions', 'default_model' => 'ayez-chat'],
        'fal' => ['name' => 'Fal.ai', 'endpoint' => 'https://api.fal.ai/v1/chat/completions', 'default_model' => 'fal-small'],
    ];
    public function index(Request $request): \Inertia\Response
    {
        $keys = DB::table('ai_provider_settings')->select('id','provider','status','created_at')->orderByDesc('id')->get()->map(fn($row) => ['id'=>(int)$row->id,'provider'=>$row->provider,'status'=>$row->status,'created_at'=>(string)$row->created_at]);
        $usageToday = DB::table('ai_usage_logs')->whereDate('occurred_at', today())->count();
        $usageWeek = DB::table('ai_usage_logs')->where('occurred_at', '>=', now()->subWeek())->count();
        $usageMonth = DB::table('ai_usage_logs')->where('occurred_at', '>=', now()->subMonth())->count();
        $recentLogs = DB::table('ai_usage_logs')->latest('id')->limit(20)->get(['id','provider','model','input_tokens','output_tokens','occurred_at']);
        $allProviders = array_map(fn($p) => ['name'=>$p['name'],'category'=>'AI'], self::PROVIDERS);
        return inertia('Platform/AiManagement', ['keys'=>$keys,'usage'=>['today'=>$usageToday,'week'=>$usageWeek,'month'=>$usageMonth],'recentLogs'=>$recentLogs,'allProviders'=>$allProviders]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['provider'=>'required|string|max:50','api_key'=>'required|string|max:500','model'=>'nullable|string|max:100']);
        $existing = DB::table('ai_provider_settings')->where('provider', $validated['provider'])->first();
        if ($existing) {
            DB::table('ai_provider_settings')->where('id', $existing->id)->update(['encrypted_config'=>json_encode(['api_key'=>$validated['api_key'],'model'=>$validated['model']??null]),'status'=>'active','updated_at'=>now()]);
            $id = $existing->id;
        } else {
            $id = DB::table('ai_provider_settings')->insertGetId(['organization_id'=>1,'provider'=>$validated['provider'],'encrypted_config'=>json_encode(['api_key'=>$validated['api_key'],'model'=>$validated['model']??null]),'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        }
        return response()->json(['ok'=>true,'id'=>$id]);
    }

    public function toggle(int $id): JsonResponse
    {
        $key = DB::table('ai_provider_settings')->where('id', $id)->first();
        if (!$key) return response()->json(['error'=>'not found'], 404);
        $newStatus = $key->status === 'active' ? 'inactive' : 'active';
        DB::table('ai_provider_settings')->where('id', $id)->update(['status'=>$newStatus,'updated_at'=>now()]);
        return response()->json(['ok'=>true,'status'=>$newStatus]);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('ai_provider_settings')->where('id', $id)->delete();
        return response()->json(['ok'=>true]);
    }

    public function testKey(int $id): JsonResponse
    {
        $key = DB::table('ai_provider_settings')->where('id', $id)->first();
        if (!$key) return response()->json(['ok'=>false,'error'=>'not found']);
        $config = json_decode((string)$key->encrypted_config, true);
        $apiKey = $config['api_key'] ?? null;
        if (!$apiKey) return response()->json(['ok'=>false,'error'=>'No API key']);
        $providerInfo = self::PROVIDERS[$key->provider] ?? null;
        if (!$providerInfo) return response()->json(['ok'=>false,'error'=>'Unknown provider']);
        $endpoint = $providerInfo['endpoint'];
        $model = $config['model'] ?? $providerInfo['default_model'];
        $start = microtime(true);
        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['model'=>$model,'messages'=>[['role'=>'user','content'=>'Say hello in one word.']],'max_tokens'=>10])]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $elapsed = round((microtime(true)-$start)*1000);
            curl_close($ch);
            $data = json_decode((string)$response, true);
            if ($httpCode === 200 && isset($data['choices'][0]['message']['content'])) {
                return response()->json(['ok'=>true,'latency_ms'=>$elapsed,'model'=>$model,'response'=>$data['choices'][0]['message']['content']]);
            }
            $errorMsg = $data['error']['message'] ?? 'HTTP '.$httpCode;
            return response()->json(['ok'=>false,'error'=>$errorMsg,'latency_ms'=>$elapsed]);
        } catch (\Throwable $e) {
            return response()->json(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }

    public function usageStats(): JsonResponse
    {
        $byModel = DB::table('ai_usage_logs')->select('model', DB::raw('count(*) as count'))->where('occurred_at','>=',now()->subMonth())->groupBy('model')->orderByDesc('count')->get();
        $byProvider = DB::table('ai_usage_logs')->select('provider', DB::raw('count(*) as count'))->where('occurred_at','>=',now()->subMonth())->groupBy('provider')->orderByDesc('count')->get();
        return response()->json(['by_model'=>$byModel,'by_provider'=>$byProvider]);
    }
}
