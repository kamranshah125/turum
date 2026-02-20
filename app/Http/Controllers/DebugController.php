<?php

namespace App\Http\Controllers;

use App\Models\ProductVariantMap;
use App\Services\TurumApiService;
use App\Services\TurumAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DebugController extends Controller
{
    protected $authService;
    protected $apiService;

    public function __construct(TurumAuthService $authService, TurumApiService $apiService)
    {
        $this->authService = $authService;
        $this->apiService = $apiService;
    }

    /**
     * Test Connection to Turum
     * GET /api/debug/turum-auth
     */
    public function testAuth()
    {
        try {
            // Force login to see if credentials work
            $token = $this->authService->login();
            return response()->json([
                'status' => 'success',
                'message' => 'Connected to Turum successfully',
                'token_preview' => substr($token, 0, 10) . '...',
                'expires_in' => '23 hours (cached)'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Connection failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test Fetching a Product from Turum
     * GET /api/debug/turum-product/{sku}
     */
    public function testProduct($sku)
    {
        $data = $this->apiService->getProduct($sku);

        if ($data) {
            return response()->json([
                'status' => 'success',
                'data' => $data
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Product not found on Turum or API error'
        ], 404);
    }

    /**
     * Check Local Database Mapping
     * GET /api/debug/db-mapping/{sku}
     */
    public function checkMapping($sku)
    {
        $map = ProductVariantMap::where('shopify_sku', $sku)->first();

        if ($map) {
            return response()->json([
                'status' => 'found',
                'mapping' => $map
            ]);
        }

        return response()->json([
            'status' => 'missing',
            'message' => "No mapping found for Shopify SKU: {$sku}"
        ], 404);
    }

    /**
     * Get Full Product List from Turum
     * GET /api/debug/get_all
     */
    public function getAllProducts()
    {
        // Increase memory limit for this request as the list is likely huge
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 120);

        try {
            $data = $this->apiService->getProductsFullList();
            return response()->json([
                'status' => 'success',
                'count' => is_array($data) && isset($data['data']) ? count($data['data']) : 'unknown',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get All Local Reservations (Integration Orders)
     * GET /api/debug/reservations
     */
    public function getAllReservations()
    {
        $orders = \App\Models\IntegrationOrder::orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'count' => $orders->count(),
            'data' => $orders
        ]);
    }
}
