<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\PendingRequest;

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

    /**
     * Get a configured HTTP client with timeouts and retries for robust fetching.
     */
    protected function getClient(): PendingRequest
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json'
        ])
            ->timeout(60) // 60 seconds timeout (default was likely 10s based on the error)
            ->retry(3, 2000); // Retry 3 times, wait 2000ms (2s) between retries
    }

    public function fulfillOrder($shopifyOrderId, $trackingNumber, $trackingUrl, $carrier = 'DPD')
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/fulfillments.json";

        $fulfillmentOrders = $this->getFulfillmentOrders($shopifyOrderId);

        if (empty($fulfillmentOrders)) {
            Log::error("No fulfillment orders found for Shopify Order {$shopifyOrderId}");
            return false;
        }

        $fulfillmentOrderId = $fulfillmentOrders[0]['id'];

        $fulfillmentPayload = [
            'fulfillment' => [
                'line_items_by_fulfillment_order' => [
                    [
                        'fulfillment_order_id' => $fulfillmentOrderId,
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
            $response = $this->getClient()->post($endpoint, $fulfillmentPayload);

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
            $response = $this->getClient()->get($endpoint);

            if ($response->successful()) {
                return $response->json()['fulfillment_orders'] ?? [];
            }
            return [];
        } catch (\Exception $e) {
            Log::error("Failed to get fulfillment orders: " . $e->getMessage());
            return [];
        }
    }

    protected function graphQL($query, $variables = [])
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/graphql.json";

        $response = $this->getClient()->post($endpoint, [
            'query' => $query,
            'variables' => $variables,
        ]);

        return $response->json();
    }

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

        $edges = $result['data']['productVariants']['edges'] ?? [];

        if (!empty($edges)) {
            $node = $edges[0]['node'];
            if ($node['sku'] === $sku) {
                return [
                    'product_id' => $node['product']['legacyResourceId'],
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

        $response = $this->getClient()->get($endpoint);

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
        $response = $this->getClient()->get($endpoint);

        if ($response->successful()) {
            $locations = $response->json()['locations'] ?? [];
            foreach ($locations as $loc) {
                if ($loc['active'] && $loc['legacy'] === false) {
                    $this->primaryLocationId = $loc['id'];
                    return $this->primaryLocationId;
                }
            }
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

        $response = $this->getClient()->post($endpoint, [
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

    public function createProduct($data)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/products.json";

        $response = $this->getClient()->post($endpoint, $data);

        if ($response->successful()) {
            return $response->json()['product'] ?? null;
        }

        return null;
    }

    public function updateProduct($productId, $data)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/products/{$productId}.json";

        $response = $this->getClient()->put($endpoint, $data);

        return $response->successful();
    }

    public function updateVariant($variantId, $data)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/variants/{$variantId}.json";

        $response = $this->getClient()->put($endpoint, ['variant' => $data]);

        return $response->successful();
    }

    public function setVariantMetafield($variantId, $namespace, $key, $value, $type = 'single_line_text_field')
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/variants/{$variantId}/metafields.json";

        $payload = [
            'metafield' => [
                'namespace' => $namespace,
                'key' => $key,
                'value' => $value,
                'type' => $type
            ]
        ];

        $response = $this->getClient()->post($endpoint, $payload);

        if ($response->successful()) {
            return true;
        }

        Log::error("Failed to set Metafield for Variant {$variantId}", ['body' => $response->body()]);
        return false;
    }

    public function getVariantMetafield($variantId, $namespace, $key)
    {
        $endpoint = "https://{$this->domain}/admin/api/{$this->version}/variants/{$variantId}/metafields.json";

        $response = $this->getClient()->get($endpoint, [
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

        $response = $this->getClient()->post($endpoint, [
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
