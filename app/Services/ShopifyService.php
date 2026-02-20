<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected $domain;
    protected $token;
    protected $version = '2026-01'; // Update to latest stable

    public function __construct()
    {
        $this->domain = config('services.shopify.domain'); // e.g., shop-name.myshopify.com
        $this->token = config('services.shopify.token');

        log::info('shopify domain: ' . $this->domain);
        // Do not log the token in production, but keeping it for debug as requested previously
        log::info('shopify token *** set from env ***');

    }

    public function fulfillOrder($shopifyOrderId, $trackingNumber, $trackingUrl, $carrier = 'DPD')
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/fulfillments.json";

        $payload = [
            'fulfillment' => [
                'order_id' => $shopifyOrderId, // Some endpoints need this in URL, others in body check docs. 
                // Actually for creating fulfillment we usually need fulfillment_orders_id or just order_id if using legacy endpoints.
                // Modern Shopify fulfillment uses fulfillment_orders. Let's assume legacy for simplicity or check requirements.
                // Requirement said "Use Shopify Admin API". 
                // Let's use the standard Fulfillment endpoint which might require fulfillment_order_id since 2022-07.
                // However, finding fulfillment_order_id adds complexity.
                // Let's assume we fetch fulfillment orders first.

                'tracking_info' => [
                    'number' => $trackingNumber,
                    'url' => $trackingUrl,
                    'company' => $carrier,
                ],
                'notify_customer' => true,
            ]
        ];

        // First, get fulfillment orders for this order
        $fulfillmentOrders = $this->getFulfillmentOrders($shopifyOrderId);

        if (empty($fulfillmentOrders)) {
            Log::error("No fulfillment orders found for Shopify Order {$shopifyOrderId}");
            return false;
        }

        // Fulfill the first open fulfillment order
        $fulfillmentOrderId = $fulfillmentOrders[0]['id'];

        // POST /admin/api/2024-01/fulfillments.json
        // Payload needs line_items_by_fulfillment_order
        $fulfillmentPayload = [
            'fulfillment' => [
                'line_items_by_fulfillment_order' => [
                    [
                        'fulfillment_order_id' => $fulfillmentOrderId,
                        // If we want to fulfill specific items, we list them. 
                        // If we skip 'fulfillment_order_line_items', it attempts to fulfill all.
                    ]
                ],
                'tracking_info' => [
                    'number' => $trackingNumber,
                    'url' => $trackingUrl,
                    'company' => $carrier,
                ],
                'notify_customer' => true
            ]
        ];

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->token,
                'Content-Type' => 'application/json'
            ])->post($endpoint, $fulfillmentPayload);

            if ($response->successful()) {
                Log::info("Order {$shopifyOrderId} fulfilled successfully.");
                return true;
            }

            Log::error("Shopify Fulfillment Failed for Order {$shopifyOrderId}", ['body' => $response->body()]);
            return false;

        } catch (\Exception $e) {
            Log::error("Shopify Service Exception: " . $e->getMessage());
            throw $e;
        }
    }

    protected function getFulfillmentOrders($orderId)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/orders/{$orderId}/fulfillment_orders.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->token
            ])->get($endpoint);

            if ($response->successful()) {
                return $response->json()['fulfillment_orders'] ?? [];
            }
            return [];
        } catch (\Exception $e) {
            Log::error("Failed to get fulfillment orders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Helper to execute GraphQL queries.
     */
    protected function graphQL($query, $variables = [])
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json'
        ])->post($endpoint, [
                    'query' => $query,
                    'variables' => $variables,
                ]);

        return $response->json();
    }

    /**
     * Find a product by SKU using GraphQL.
     * REST API doesn't allow searching strictly by SKU easily without filtering list.
     */
    /**
     * Find a product by SKU using GraphQL.
     * REST API doesn't allow searching strictly by SKU easily without filtering list.
     */
    public function findProductBySku($sku)
    {
        $query = <<<'gql'
        query($sku: String!) {
          productVariants(first: 1, query: $sku) {
            edges {
              node {
                id
                sku
                inventoryItem {
                  id
                }
                product {
                  id
                  legacyResourceId
                  handle
                  title
                }
              }
            }
          }
        }
gql;
        $result = $this->graphQL($query, ['sku' => "sku:$sku"]);

        // This query finds variants. If found, we return the product and variant info.
        $edges = $result['data']['productVariants']['edges'] ?? [];

        if (!empty($edges)) {
            $node = $edges[0]['node'];
            // Double check strict SKU match because GraphQL search is fuzzy
            if ($node['sku'] === $sku) {
                return [
                    'product_id' => $node['product']['legacyResourceId'], // Numeric ID for REST
                    'variant_id' => str_replace('gid://shopify/ProductVariant/', '', $node['id']),
                    'inventory_item_id' => str_replace('gid://shopify/InventoryItem/', '', $node['inventoryItem']['id'] ?? ''),
                    'title' => $node['product']['title']
                ];
            }
        }
        return null;
    }

    public function getProductVariants($productId)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/products/{$productId}/variants.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->get($endpoint);

        if ($response->successful()) {
            return $response->json()['variants'] ?? [];
        }

        Log::error("Failed to fetch variants for Product {$productId}");
        return [];
    }

    protected $primaryLocationId = null;

    public function getPrimaryLocationId()
    {
        if ($this->primaryLocationId) {
            return $this->primaryLocationId;
        }

        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/locations.json";
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->get($endpoint);

        if ($response->successful()) {
            $locations = $response->json()['locations'] ?? [];
            foreach ($locations as $loc) {
                if ($loc['active'] && $loc['legacy'] === false) { // Simple heuristic
                    $this->primaryLocationId = $loc['id'];
                    return $this->primaryLocationId;
                }
            }
            // Fallback to first
            if (!empty($locations)) {
                $this->primaryLocationId = $locations[0]['id'];
                return $this->primaryLocationId;
            }
        }
        return null;
    }

    public function setInventoryLevel($inventoryItemId, $locationId, $quantity)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/inventory_levels/set.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->post($endpoint, [
                    'location_id' => $locationId,
                    'inventory_item_id' => $inventoryItemId,
                    'available' => $quantity
                ]);

        if ($response->successful()) {
            return true;
        }

        Log::error("Failed to set inventory level for Item {$inventoryItemId}", ['body' => $response->body()]);
        return false;
    }

    // public function createProduct($data)
    // {
    //     $endpoint = "https://{$this->domain}/admin/api/{$this->version}/products.json";

    //     // $data should be formatted for Shopify Product API
    //     // e.g. ['product' => ['title' => '...', 'variants' => [...]]]

    //     $response = Http::withHeaders([
    //         'X-Shopify-Access-Token' => $this->token
    //     ])->post($endpoint, $data);

    //     if ($response->successful()) {
    //         return $response->json()['product'];
    //     }

    //     Log::error("Failed to create Shopify product", ['body' => $response->body()]);
    //     return null;
    // }

    public function createProduct($data)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/products.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->post($endpoint, $data);

        // Log::info('Shopify Create Product Response', [
        //     'status' => $response->status(),
        //     'body' => $response->body()
        // ]);




        if ($response->successful()) {
            return $response->json()['product'] ?? null;
        }

        return null;
    }

    public function updateProduct($productId, $data)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/products/{$productId}.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->put($endpoint, $data);

        return $response->successful();
    }

    public function updateVariant($variantId, $data)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/variants/{$variantId}.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->put($endpoint, ['variant' => $data]);

        return $response->successful();
    }

    public function setVariantMetafield($variantId, $namespace, $key, $value, $type = 'single_line_text_field')
    {
        // We can create/update metafield via this endpoint
        // Note: Metafields are often created on the resource itself
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/variants/{$variantId}/metafields.json";

        $payload = [
            'metafield' => [
                'namespace' => $namespace,
                'key' => $key,
                'value' => $value,
                'type' => $type
            ]
        ];

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->post($endpoint, $payload);

        if ($response->successful()) {
            return true;
        }

        Log::error("Failed to set Metafield for Variant {$variantId}", ['body' => $response->body()]);
        return false;
    }

    public function getVariantMetafield($variantId, $namespace, $key)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/variants/{$variantId}/metafields.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->get($endpoint, [
                    'namespace' => $namespace,
                    'key' => $key
                ]);

        if ($response->successful()) {
            $metafields = $response->json()['metafields'] ?? [];
            foreach ($metafields as $mf) {
                if ($mf['namespace'] === $namespace && $mf['key'] === $key) {
                    return $mf['value'];
                }
            }
        }

        return null;
    }

    public function cancelOrder($orderId, $reason = 'inventory_shortage')
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/orders/{$orderId}/cancel.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token
        ])->post($endpoint, [
                    'reason' => $reason,
                    'email' => true // Notify customer
                ]);

        if ($response->successful()) {
            Log::info("Order {$orderId} cancelled successfully.");
            return true;
        }

        Log::error("Failed to cancel Order {$orderId}", ['body' => $response->body()]);
        return false;
    }
}
