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

        // log::info('shopify domain: ' . $this->domain);
        // Do not log the token in production, but keeping it for debug as requested previously
        // log::info('shopify token *** set from env ***');
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
            ->timeout(60)
            ->retry(3, 2000, null, false);
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

        // Retry without images if we hit an invalid image URL error
        if ($response->status() === 422) {
            $responseBody = $response->json() ?? [];
            $errorString = json_encode($responseBody['errors'] ?? []);

            if (str_contains($errorString, 'Image URL is invalid') && !empty($data['product']['images'])) {
                Log::warning("Creating product failed due to invalid image URL. Retrying without images.", ['title' => $data['product']['title'] ?? '']);

                unset($data['product']['images']);
                $response2 = $this->getClient()->post($endpoint, $data);

                if ($response2->successful()) {
                    return $response2->json()['product'] ?? null;
                }

                Log::error("Failed to create Shopify product even without images", ['body' => $response2->body()]);
                return null;
            }
        }

        Log::error("Failed to create Shopify product", ['body' => $response->body()]);
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

    /**
     * Update multiple variants for a single product in one GraphQL request.
     * $variants mapping example:
     * [
     *   [
     *     'id' => 'gid://shopify/ProductVariant/123456',
     *     'price' => '10.99',
     *     'inventoryQuantities' => [
     *         ['availableQuantity' => 5, 'locationId' => 'gid://shopify/Location/98765']
     *     ]
     *   ], ...
     * ]
     */
    public function bulkUpdateVariants($productId, array $variants)
    {
        // GraphQL requires fully qualified GIDs
        if (!str_starts_with($productId, 'gid://')) {
            $productId = "gid://shopify/Product/{$productId}";
        }

        foreach ($variants as &$v) {
            if (isset($v['id']) && !str_starts_with($v['id'], 'gid://')) {
                $v['id'] = "gid://shopify/ProductVariant/{$v['id']}";
            }
        }

        $query = <<<'gql'
        mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkUpdate(productId: $productId, variants: $variants) {
            product {
              id
            }
            productVariants {
              id
              price
              inventoryQuantity
            }
            userErrors {
              field
              message
            }
          }
        }
        gql;

        $response = $this->graphQL($query, [
            'productId' => $productId,
            'variants' => $variants
        ]);

        $errors = $response['data']['productVariantsBulkUpdate']['userErrors'] ?? [];
        if (!empty($errors)) {
            Log::error("Shopify Bulk Variant Update Errors", ['errors' => $errors]);
            return false;
        }

        return true;
    }

    /**
     * Update inventory quantities for multiple variants in one GraphQL request.
     * $inventoryData format:
     * [
     *    ['inventoryItemId' => 'gid://shopify/InventoryItem/123', 'locationId' => 'gid://shopify/Location/456', 'quantity' => 10],
     *    ...
     * ]
     */
    public function bulkUpdateInventoryLevels(array $inventoryData)
    {
        // GraphQL requires fully qualified GIDs
        foreach ($inventoryData as &$item) {
            if (isset($item['inventoryItemId']) && !str_starts_with($item['inventoryItemId'], 'gid://')) {
                $item['inventoryItemId'] = "gid://shopify/InventoryItem/{$item['inventoryItemId']}";
            }
            if (isset($item['locationId']) && !str_starts_with($item['locationId'], 'gid://')) {
                $item['locationId'] = "gid://shopify/Location/{$item['locationId']}";
            }
        }

        $query = <<<'gql'
        mutation inventorySetOnHandQuantities($input: InventorySetOnHandQuantitiesInput!) {
          inventorySetOnHandQuantities(input: $input) {
            userErrors {
              field
              message
            }
          }
        }
        gql;

        // Ensure we pass the precise structure GraphQL expects
        $input = [
            'reason' => 'correction',
            'setQuantities' => $inventoryData
        ];

        $response = $this->graphQL($query, ['input' => $input]);

        $errors = $response['data']['inventorySetOnHandQuantities']['userErrors'] ?? [];
        if (!empty($errors)) {
            $unstockedIndices = [];
            foreach ($errors as $error) {
                if (($error['message'] ?? '') === 'The specified inventory item is not stocked at the location.') {
                    $field = $error['field'] ?? [];
                    if (isset($field[2]) && is_numeric($field[2])) {
                        $unstockedIndices[] = (int) $field[2];
                    }
                }
            }

            // If we found unstocked items, activate them and retry individually
            if (!empty($unstockedIndices)) {
                Log::warning("Found " . count($unstockedIndices) . " unstocked inventory items. Activating and retrying them individually.");
                foreach ($unstockedIndices as $index) {
                    if (isset($inventoryData[$index])) {
                        $item = $inventoryData[$index];
                        // 1. Activate tracking
                        $this->activateInventoryLocation($item['inventoryItemId'], $item['locationId']);
                        // 2. Fallback to old REST API for this specific item just once so it's fully set
                        $this->setInventoryLevel(
                            str_replace('gid://shopify/InventoryItem/', '', $item['inventoryItemId']),
                            str_replace('gid://shopify/Location/', '', $item['locationId']),
                            $item['quantity']
                        );
                    }
                }

                // If there were other errors besides the unstocked ones, log them
                if (count($errors) > count($unstockedIndices)) {
                    Log::error("Shopify Bulk Inventory Update Errors (Partial Fallback applied)", ['errors' => $errors]);
                    return false;
                }
                return true;
            }

            Log::error("Shopify Bulk Inventory Update Errors", ['errors' => $errors]);
            return false;
        }

        return true;
    }

    /**
     * Activate tracking for an inventory item at a specific location
     */
    public function activateInventoryLocation($inventoryItemId, $locationId)
    {
        if (!str_starts_with($inventoryItemId, 'gid://')) {
            $inventoryItemId = "gid://shopify/InventoryItem/{$inventoryItemId}";
        }
        if (!str_starts_with($locationId, 'gid://')) {
            $locationId = "gid://shopify/Location/{$locationId}";
        }

        $query = <<<'gql'
        mutation inventoryActivate($inventoryItemId: ID!, $locationId: ID!) {
          inventoryActivate(inventoryItemId: $inventoryItemId, locationId: $locationId) {
            inventoryLevel {
              id
            }
            userErrors {
              field
              message
            }
          }
        }
        gql;

        $response = $this->graphQL($query, [
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId
        ]);

        $errors = $response['data']['inventoryActivate']['userErrors'] ?? [];
        if (!empty($errors)) {
            Log::error("Shopify Inventory Activate Error", [
                'inventoryItemId' => $inventoryItemId,
                'locationId' => $locationId,
                'errors' => $errors
            ]);
            return false;
        }

        return true;
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

    public function draftStaleProducts(array $activeSkus)
    {
        $draftedCount = 0;
        $hasNextPage = true;
        $cursor = null;

        while ($hasNextPage) {
            $query = <<<'gql'
            query($cursor: String) {
              products(first: 250, after: $cursor, query: "vendor:Turum AND status:ACTIVE") {
                pageInfo {
                  hasNextPage
                  endCursor
                }
                edges {
                  node {
                    id
                    legacyResourceId
                    variants(first: 10) {
                      edges {
                        node {
                          sku
                        }
                      }
                    }
                  }
                }
              }
            }
gql;

            $variables = $cursor ? ['cursor' => $cursor] : [];
            $result = $this->graphQL($query, $variables);

            $productsData = $result['data']['products'] ?? null;
            if (!$productsData) {
                Log::error('Failed to fetch Shopify products for drafting.', ['result' => $result]);
                break;
            }

            $edges = $productsData['edges'] ?? [];
            foreach ($edges as $edge) {
                $node = $edge['node'];
                $productId = $node['legacyResourceId'];
                $variants = $node['variants']['edges'] ?? [];

                $productSkus = [];
                foreach ($variants as $vEdge) {
                    $sku = $vEdge['node']['sku'] ?? null;
                    if ($sku) {
                        $productSkus[] = (string) $sku;
                    }
                }

                // If none of the product's SKUs are in the active Turum feed
                $isActive = false;
                foreach ($productSkus as $sku) {
                    if (in_array($sku, $activeSkus)) {
                        $isActive = true;
                        break;
                    }
                }

                if (!$isActive && !empty($productSkus)) {
                    Log::info("Drafting stale product ID: {$productId}, SKUs: " . implode(', ', $productSkus));

                    $this->updateProduct($productId, [
                        'product' => [
                            'id' => $productId,
                            'status' => 'draft'
                        ]
                    ]);
                    $draftedCount++;
                }
            }

            $hasNextPage = $productsData['pageInfo']['hasNextPage'] ?? false;
            $cursor = $productsData['pageInfo']['endCursor'] ?? null;
        }

        return $draftedCount;
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
