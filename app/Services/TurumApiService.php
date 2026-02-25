<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurumApiService
{
    protected $baseUrl = 'https://api.b2b.turum.pl/v1';
    protected $authService;

    public function __construct(TurumAuthService $authService)
    {
        $this->authService = $authService;
    }

    protected function getClient()
    {
        return Http::withoutVerifying()->withToken($this->authService->getToken())
            ->timeout(60) // 60 seconds timeout
            ->retry(3, 2000, null, false) // Retry 3 times, wait 2000ms
            ->acceptJson();
    }

    public function createReservation(array $variants)
    {
        Log::info('Creating Turum Reservation', ['variants' => $variants]);

        // Mocking response as per user request to not run actual logic/login
        return ['reservation_id' => 'mock-reservation-' . uniqid()];
        // $variants format: [['variant_id' => 'UUID', 'quantity' => 1], ...]
        // try {
        //     $response = $this->getClient()->post($this->baseUrl . '/reservations', [
        //         'variants' => $variants
        //     ]);
        //     log::info('response from reservation ' . ' ' . $response);

        //     if ($response->successful()) {
        //         return $response->json(); // Expected: { "reservation_id": "UUID" }
        //     }

        //     // If 401, maybe token expired? could retry once.
        //     if ($response->status() === 401) {
        //         $this->authService->login(); // Force refresh
        //         $response = $this->getClient()->post($this->baseUrl . '/reservations', [
        //             'variants' => $variants
        //         ]);
        //         if ($response->successful())
        //             return $response->json();
        //     }

        //     Log::error('Turum Reservation Failed', ['status' => $response->status(), 'body' => $response->body()]);
        //     throw new \Exception('Turum Reservation Failed: ' . $response->body());

        // } catch (\Exception $e) {
        //     Log::error('Turum API Reservation Exception: ' . $e->getMessage());
        //     throw $e;
        // }
    }

    public function getReservation($reservationId)
    {
        try {
            $response = $this->getClient()->get($this->baseUrl . '/reservation/' . $reservationId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Turum Get Reservation Failed', ['id' => $reservationId, 'status' => $response->status()]);
            return null; // Handle gracefully

        } catch (\Exception $e) {
            Log::error('Turum API Get Reservation Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getProduct($sku)
    {
        try {
            $response = $this->getClient()->get($this->baseUrl . '/product/' . $sku);
            Log::info('Turum Get Product' . json_encode($response->json()));
            if ($response->successful()) {
                return $response->json();
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Turum API Get Product Exception: ' . $e->getMessage());
            return null;
        }
    }

    public function getProductsFullList()
    {
        try {
            // Note: This endpoint might return a HUGE JSON. 
            // In a real app, we might want to stream or paginate if supported, 
            // but for debug we just return it.
            $response = $this->getClient()->get($this->baseUrl . '/products_full_list_new');

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Turum Get Full List Failed', ['status' => $response->status()]);
            return ['error' => 'Failed to fetch list', 'status' => $response->status()];

        } catch (\Exception $e) {
            Log::error('Turum API Get Full List Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getAccountAddress()
    {
        try {
            $response = $this->getClient()->get($this->baseUrl . '/account/address');

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Turum Get Account Address Failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;

        } catch (\Exception $e) {
            Log::error('Turum API Get Account Address Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateAddress(array $data)
    {
        try {
            $response = $this->getClient()->post($this->baseUrl . '/account/address', $data);

            if ($response->successful()) {
                return ['success' => true];
            }

            Log::error('Turum Update Address Failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \Exception('Turum Update Address Failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('Turum API Update Address Exception: ' . $e->getMessage());
            throw $e;
        }
    }
}
