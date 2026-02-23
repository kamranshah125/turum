<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurumAuthService
{
    protected $baseUrl = 'https://api.b2b.turum.pl/v1';
    protected $tokenKey = 'turum_api_token';

    public function getToken()
    {
        if (Cache::has($this->tokenKey)) {
            return Cache::get($this->tokenKey);
        }
        return $this->login();
    }

    public function login()
    {
        try {
            $response = Http::timeout(60)->retry(3, 2000)->post($this->baseUrl . '/account/login', [
                'username' => config('services.turum.username'),
                'password' => config('services.turum.password'),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['access_token'];
                // Cache for 23 hours (token valid for 24h usually)
                Cache::put($this->tokenKey, $token, now()->addHours(23));
                return $token;
            }

            Log::error('Turum Login Failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \Exception('Turum Login Failed');

        } catch (\Exception $e) {
            Log::error('Turum Auth Exception: ' . $e->getMessage());
            throw $e;
        }
    }
}
