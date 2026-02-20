<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    public function handleOrderCreate(Request $request)
    {

        // Log::info('Received Shopify Order Webhook', ['payload' => $request->all()]);
        // return ;
        // 1. Verify HMAC Signature
        $signature = $request->header('X-Shopify-Hmac-Sha256');
        $data = $request->getContent();
        $secret = config('services.shopify.webhook_secret');

        if (!$this->verifyWebhook($data, $signature, $secret)) {
            // Enhanced Debugging
            Log::warning('Shopify Webhook HMAC Verification Failed.', [
                'ip' => $request->ip(),
                'header_hmac' => $signature,
                'calculated_hmac' => base64_encode(hash_hmac('sha256', $data, $secret, true)),
                'secret_preview' => substr($secret, 0, 5) . '...',
                'data_length' => strlen($data)
            ]);

            // Temporary Bypass for Debugging if needed. 
            // Uncomment next line to bypass:
            // return response()->json(['message' => 'Unauthorized'], 401);
            Log::warning('Bypassing HMAC check for testing.');
        }

        // 2. Dispatch Job
        $payload = json_decode($data, true);


        Log::info('Received Shopify Order Webhook: ' . json_encode($payload, JSON_PRETTY_PRINT));
        

        if (!$payload) {
            return response()->json(['message' => 'Invalid Payload'], 400);
        }

        Log::info('Received Shopify Order Webhook', ['id' => $payload['id'] ?? 'unknown']);

        // return;
        // Dispatch to queue
        ProcessShopifyOrder::dispatch($payload);

        // 3. Respond 200 OK immediately
        return response()->json(['message' => 'Webhook received']);
    }

    protected function verifyWebhook($data, $hmac, $secret)
    {
        $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $secret, true));
        return hash_equals($hmac, $calculatedHmac);
    }
}
