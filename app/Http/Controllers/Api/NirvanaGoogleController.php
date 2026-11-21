<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NirvanaGoogleController extends Controller
{
    public function configuration(): array
    {
        return ['client_id' => config('nirvana.google_client_id') ?: null];
    }

    public function profile(Request $request)
    {
        $clientId = config('nirvana.google_client_id');
        abort_unless($clientId, 503, 'Google sign-in is unavailable. You can enter your details above.');
        $credential = $request->validate(['credential' => 'required|string|max:8192'])['credential'];

        try {
            $certificates = Cache::get('nirvana.google-certificates');
            if ($certificates === null) {
                $response = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])
                    ->acceptJson()->connectTimeout(3)->timeout(6)
                    ->get('https://www.googleapis.com/oauth2/v1/certs')->throw();
                $entries = $response->json();
                $certificates = is_array($entries) ? array_filter($entries, fn ($certificate) => is_string($certificate)
                    && openssl_pkey_get_public($certificate) !== false) : [];
                abort_if(!$certificates, 503, 'Google sign-in is temporarily unavailable.');
                if (preg_match('/\bmax-age=(\d+)/i', $response->header('Cache-Control') ?? '', $matches)) {
                    Cache::put('nirvana.google-certificates', $certificates, (int) $matches[1]);
                }
            }
            $claims = JWT::decode($credential, array_map(fn ($certificate) => new Key($certificate, 'RS256'), $certificates));
        } catch (HttpClientException) {
            abort(503, 'Google sign-in is temporarily unavailable. You can enter your details above.');
        } catch (\DomainException | \UnexpectedValueException | \InvalidArgumentException) {
            abort(422, 'Google sign-in could not be verified. Please try again.');
        }

        abort_unless(($claims->aud ?? null) === $clientId
            && in_array($claims->iss ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && !empty($claims->sub)
            && isset($claims->exp)
            && ($claims->email_verified ?? false) === true
            && is_string($claims->email ?? null)
            && strlen($claims->email) <= 254
            && filter_var($claims->email, FILTER_VALIDATE_EMAIL),
            422, 'Google sign-in could not be verified. Please try again.');

        return response()->json([
            'name' => is_string($claims->name ?? null) ? mb_substr($claims->name, 0, 120) : '',
            'email' => $claims->email,
        ])->header('Cache-Control', 'no-store');
    }
}
