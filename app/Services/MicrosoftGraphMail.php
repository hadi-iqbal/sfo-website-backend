<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MicrosoftGraphMail
{
    private function accessToken(bool $refresh = false): string
    {
        $tenant = (string) config('nirvana.graph_tenant');
        $client = (string) config('nirvana.graph_client');
        $secret = (string) config('nirvana.graph_secret');
        if (!$tenant || !$client || !$secret) {
            throw new RuntimeException('Microsoft Graph mail credentials are incomplete.');
        }

        $cacheKey = 'nirvana:graph-token:'.hash('sha256', $tenant.'|'.$client);
        if ($refresh) Cache::forget($cacheKey);
        if ($token = Cache::get($cacheKey)) return $token;

        $response = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])->asForm()->timeout(15)->post(
            'https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token',
            ['client_id' => $client, 'client_secret' => $secret, 'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default']
        );
        if (!$response->successful() || !is_string($response->json('access_token'))) {
            throw new RuntimeException('Microsoft Graph token request failed (HTTP '.$response->status().').');
        }
        $token = $response->json('access_token');
        $ttl = max(1, min(3000, (int) $response->json('expires_in', 3600) - 300));
        Cache::put($cacheKey, $token, $ttl);
        return $token;
    }

    public function send(string $recipient, string $subject, string $html): void
    {
        $sender = (string) config('nirvana.graph_sender');
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Microsoft Graph sender or recipient address is invalid.');
        }
        $url = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($sender).'/sendMail';
        $payload = ['message' => ['subject' => $subject,
            'body' => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => [['emailAddress' => ['address' => $recipient]]]],
            'saveToSentItems' => true];
        $response = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])
            ->withToken($this->accessToken())->acceptJson()->timeout(20)->post($url, $payload);
        if ($response->status() === 401) {
            $response = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])
                ->withToken($this->accessToken(true))->acceptJson()->timeout(20)->post($url, $payload);
        }
        if (!$response->successful()) {
            throw new RuntimeException('Microsoft Graph sendMail failed (HTTP '.$response->status().').');
        }
    }
}
