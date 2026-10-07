<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class IpCountryResolver
{
    public function resolve(Request $request): ?string
    {
        $code = strtoupper((string) $request->header('CF-IPCountry'));

        if (preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX') {
            return $code;
        }

        $ip = $request->ip();

        if (! $ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        try {
            $response = Http::connectTimeout(2)->timeout(3)->get('https://ipwho.is/' . $ip, ['fields' => 'success,country_code']);
        } catch (Throwable) {
            return null;
        }

        $code = strtoupper((string) $response->json('country_code'));

        return $response->json('success') === true && preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }
}
