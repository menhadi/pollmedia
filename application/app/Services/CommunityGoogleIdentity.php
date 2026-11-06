<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CommunityGoogleIdentity
{
    public function verify(string $token, string $nonce): array
    {
        $parts = explode('.', $token);
        abort_unless(count($parts) === 3, 422, 'Invalid Google identity.');
        $header = json_decode($this->decode($parts[0]), true);
        $claims = json_decode($this->decode($parts[1]), true);
        abort_unless(is_array($header) && is_array($claims) && ($header['alg'] ?? '') === 'RS256' && is_string($header['kid'] ?? null), 422, 'Invalid Google identity.');
        $certificates = Cache::remember('community.google.certificates', 3600, function (): array {
            return Http::timeout(10)->get('https://www.googleapis.com/oauth2/v1/certs')->throw()->json();
        });
        if (! isset($certificates[$header['kid']])) {
            Cache::forget('community.google.certificates');
            $certificates = Http::timeout(10)->get('https://www.googleapis.com/oauth2/v1/certs')->throw()->json();
        }
        $certificate = $certificates[$header['kid']] ?? null;
        $audience = app(CommunityAuthSettings::class)->values()['google_client_id'];
        abort_unless(is_string($certificate) && openssl_verify($parts[0].'.'.$parts[1], $this->decode($parts[2]), $certificate, OPENSSL_ALGO_SHA256) === 1
            && in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
            && ($claims['aud'] ?? null) === $audience && (! isset($claims['azp']) || $claims['azp'] === $audience)
            && is_numeric($claims['exp'] ?? null) && $claims['exp'] > time()
            && is_numeric($claims['iat'] ?? null) && $claims['iat'] <= time() + 60
            && is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce'])
            && ($claims['email_verified'] ?? false) === true && filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL)
            && is_string($claims['sub'] ?? null) && strlen($claims['sub']) <= 255, 422, 'Google identity could not be verified.');

        return $claims;
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        abort_if($decoded === false, 422, 'Invalid Google identity.');

        return $decoded;
    }
}
