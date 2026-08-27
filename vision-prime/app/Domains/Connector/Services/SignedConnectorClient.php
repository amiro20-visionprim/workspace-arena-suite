<?php

declare(strict_types=1);

namespace App\Domains\Connector\Services;

use App\Domains\Connector\Contracts\ConnectorContentClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SignedConnectorClient implements ConnectorContentClient
{
    /** @return array<string, mixed> */
    public function get(object $connection, string $path, array $query = []): array
    {
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body = '';
        $payload = "GET\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $payload, Crypt::decryptString($connection->secret_ciphertext));
        $response = Http::timeout(30)->acceptJson()->withHeaders(['X-VP-Timestamp' => $timestamp, 'X-VP-Nonce' => $nonce, 'X-VP-Signature' => $signature])->get(rtrim($connection->platform_url, '/').'/wp-json'.$path, $query);
        $response->throw();

        return $response->json();
    }

    /** درخواست امضاشدهٔ POST به پلاگین (مثلاً فرمان‌های لحظه‌ای). */
    public function post(object $connection, string $path, array $payload = []): array
    {
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $payloadStr = "POST\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $payloadStr, Crypt::decryptString($connection->secret_ciphertext));
        $response = Http::timeout(60)->acceptJson()->withHeaders([
            'X-VP-Timestamp' => $timestamp,
            'X-VP-Nonce' => $nonce,
            'X-VP-Signature' => $signature,
        ])->withBody($body, 'application/json')->post(rtrim($connection->platform_url, '/').'/wp-json'.$path);
        $response->throw();

        return $response->json();
    }
}
