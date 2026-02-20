<?php

namespace App\Jobs;

use App\Models\IntegrationOrder;
use App\Models\ProductVariantMap;
use App\Services\TurumApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessShopifyOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(TurumApiService $turumService, \App\Services\ShopifyService $shopifyService): void
    {
        $orderId = $this->payload['id'];
        $lineItems = $this->payload['line_items'] ?? [];

        Log::info("Processing Shopify Order: {$orderId}");

        // Idempotency Check
        if (IntegrationOrder::where('shopify_order_id', $orderId)->exists()) {
            Log::info("Order {$orderId} already processed. Skipping.");
            return;
        }

        $turumVariants = [];
        $errors = [];

        foreach ($lineItems as $item) {
            $sku = $item['sku'] ?? null;
            $shopifyVariantTitle = $item['variant_title'] ?? '';

            if (!$sku) {
                $errors[] = "Item {$item['id']} has no SKU.";
                continue;
            }

            $shopifyVariantId = $item['variant_id'] ?? null;
            $matchedVariantId = null;

            // 1. Try to get Turum Variant ID from Shopify Metafield (Best Practice)
            if ($shopifyVariantId) {
                $metafieldId = $shopifyService->getVariantMetafield($shopifyVariantId, 'turum', 'variant_id');
                if ($metafieldId) {
                    $matchedVariantId = $metafieldId;
                    Log::info("Metafield Match Found! Shopify Variant ID: {$shopifyVariantId} -> Turum Variant ID: {$matchedVariantId}");
                }
            }

            // 2. Fallback: Lookup via Turum API (Name Matching)
            if (!$matchedVariantId) {
                Log::warning("Metafield not found for Variant {$shopifyVariantId}. Falling back to Name Matching.");

                // Reverted to Dynamic Lookup:
                // 1. Fetch Product from Turum
                $turumProduct = $turumService->getProduct($sku);

                if ($turumProduct && !empty($turumProduct['variants'])) {
                    // Normalize Shopify title for comparison
                    $normalizedShopifyTitle = strtolower((string) $shopifyVariantTitle);

                    foreach ($turumProduct['variants'] as $tVariant) {
                        $isMatch = false;

                        // Check 'size' directly on the variant object
                        if (isset($tVariant['size'])) {
                            $turumSize = strtolower((string) $tVariant['size']);

                            // Check for exact match
                            if ($turumSize === $normalizedShopifyTitle) {
                                $isMatch = true;
                            }
                        }

                        // Fallback for simple products (empty variant title)
                        if (!$isMatch && empty($shopifyVariantTitle)) {
                            $isMatch = true;
                        }

                        if ($isMatch) {
                            $matchedVariantId = $tVariant['variant_id'] ?? $tVariant['id'] ?? null;
                            Log::info("Dynamic Match Found! Shopify: '{$shopifyVariantTitle}' -> Turum Variant ID: {$matchedVariantId}");
                            break;
                        }
                    }
                } else {
                    Log::warning("Turum Product not found or has no variants for SKU: {$sku}");
                }
            }

            if (!$matchedVariantId) {
                $errors[] = "No matching Turum Variant found for SKU {$sku} / Size '{$shopifyVariantTitle}'";
                continue;
            }

            // 3. Stock Check
            // We need to fetch the fresh product data to check stock.
            // Even if we matched via Metafield, we must verify availability.
            $productData = $turumProduct ?? $turumService->getProduct($sku);
            $stockAvailable = 0;
            $variantFoundInFeed = false;

            if ($productData && !empty($productData['variants'])) {
                foreach ($productData['variants'] as $v) {
                    // Check if this variant matches our matched ID
                    $vId = $v['variant_id'] ?? $v['id'] ?? null;
                    if ($vId == $matchedVariantId) {
                        $stockAvailable = $v['stock'] ?? 0;
                        $variantFoundInFeed = true;
                        break;
                    }
                }
            }

            if (!$variantFoundInFeed) {
                // Critical: We have an ID (maybe from Metafield) but it's not in the defined product anymore?
                $errors[] = "Variant ID {$matchedVariantId} not found in Turum Product Feed for SKU {$sku}.";
                continue;
            }

            if ($stockAvailable < $item['quantity']) {
                $errors[] = "Insufficient Stock for SKU {$sku} (Variant {$matchedVariantId}). Requested: {$item['quantity']}, Available: {$stockAvailable}";
                continue;
            }

            $turumVariants[] = [
                'variant_id' => $matchedVariantId,
                'quantity' => $item['quantity']
            ];
        }

        if (!empty($errors)) {
            Log::warning("Order {$orderId} Validation Failed. Errors: " . implode(', ', $errors));

            // Cancel Order in Shopify
            Log::info("Cancelling Shopify Order {$orderId} due to validation errors.");
            $shopifyService->cancelOrder($orderId, 'inventory_shortage');

            // Create record with failed status
            IntegrationOrder::create([
                'shopify_order_id' => $orderId,
                'status' => 'cancelled',
                'payload' => $this->payload,
                'error_message' => implode(', ', $errors)
            ]);
            return;
        }

        if (empty($turumVariants)) {
            // Should be covered by !empty($errors) usually, but safe fallback
            Log::warning("No valid Turum variants found for Order {$orderId}.");
            return;
        }

        Log::info("Turum Variant ID: " . json_encode($turumVariants));
        // return;

        $shippingAddress = $this->payload['shipping_address'] ?? null;
        if ($shippingAddress) {
            Log::info("Updating Turum Address for Order {$orderId}", ['address' => $shippingAddress]);
            try {
                // 1. Get Current Address to preserve Billing Company Info
                $currentAddress = $turumService->getAccountAddress();
                $existingBilling = $currentAddress['billing_address'] ?? null;

                // Defaults if not found
                $companyName = $existingBilling['company_name'] ?? 'MoutonKoevoet B.V.';
                $vatId = $existingBilling['vat_id'] ?? 'NL867599819B01';

                // Construct Billing Address: Preserved Company details + New Order Location
                $newBillingAddress = [
                    'company_name' => $companyName,
                    'vat_id' => $vatId,
                    'street' => $shippingAddress['address1'] ?? '',
                    'city' => $shippingAddress['city'] ?? '',
                    'zip_code' => $shippingAddress['zip'] ?? '',
                    'country' => $shippingAddress['country_code'] ?? $shippingAddress['country'] ?? 'NL',
                ];

                // 2. Map Shopify address to Turum Shipping Address Schema
                // Schema: name, street, street_2, city, zip_code, country, state, phone_number
                $newShippingAddress = [
                    'name' => ($shippingAddress['first_name'] ?? '') . ' ' . ($shippingAddress['last_name'] ?? ''),
                    'street' => $shippingAddress['address1'] ?? '',
                    'street_2' => $shippingAddress['address2'] ?? '',
                    'city' => $shippingAddress['city'] ?? '',
                    'zip_code' => $shippingAddress['zip'] ?? '',
                    'country' => $shippingAddress['country_code'] ?? $shippingAddress['country'] ?? 'NL', // Prefer code, default NL
                    'state' => $shippingAddress['province_code'] ?? $shippingAddress['province'] ?? '',
                    'phone_number' => $shippingAddress['phone'] ?: '0613443913', // User-provided default
                ];

                // 3. Prepare Payload
                $addressPayload = [
                    'shipping_address' => $newShippingAddress,
                    'billing_address' => $newBillingAddress
                ];

                $turumService->updateAddress($addressPayload);
                Log::info("Turum Address Updated Successfully for Order {$orderId}");

            } catch (\Exception $e) {
                Log::error("Failed to update Turum Address for Order {$orderId}: " . $e->getMessage());
                // Decide if we should blocking the reservation if address update fails.
                // For now, let's log and proceed, or throw if it's critical.
                // throw $e; // Uncomment to stop reservation on address fail
            }
        } else {
            Log::warning("No shipping address found in Shopify payload for Order {$orderId}");
        }
        try {
            $reservation = $turumService->createReservation($turumVariants);
            $reservationId = $reservation['reservation_id'] ?? null;

            if ($reservationId) {
                IntegrationOrder::create([
                    'shopify_order_id' => $orderId,
                    'turum_reservation_id' => $reservationId,
                    'status' => 'reserved', // or 'new'
                    'payload' => $this->payload,
                ]);
                Log::info("Order {$orderId} reserved in Turum. ID: {$reservationId}");
            } else {
                throw new \Exception("No reservation ID returned from Turum.");
            }

        } catch (\Exception $e) {
            Log::error("Failed to reserve Order {$orderId} in Turum: " . $e->getMessage());
            IntegrationOrder::create([
                'shopify_order_id' => $orderId,
                'status' => 'failed',
                'payload' => $this->payload,
                'error_message' => $e->getMessage()
            ]);
            // Re-throw to retry job via Queue?
            // If it's a permanent error (e.g. mapping), we shouldn't retry.
            // If it's API connection, we should.
            // For now, let's fail it and log.
        }
    }
}
