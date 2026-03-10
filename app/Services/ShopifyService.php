<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\PendingRequest;

class ShopifyService
{
  protected $domain;
  protected $token;
  protected $version = '2025-01'; // Update to latest stable

  protected function formatGid($id, $type)
  {
    if (str_starts_with($id, 'gid://')) {
      return $id;
    }
    return "gid://shopify/{$type}/{$id}";
  }

  protected function unformatGid($gid)
  {
    if (!str_starts_with($gid, 'gid://')) {
      return $gid;
    }
    $parts = explode('/', $gid);
    return end($parts);
  }

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
    $fulfillmentOrders = $this->getFulfillmentOrders($shopifyOrderId);

    if (empty($fulfillmentOrders)) {
      Log::error("No fulfillment orders found for Shopify Order {$shopifyOrderId}");
      return false;
    }

    $fulfillmentOrderId = $fulfillmentOrders[0]['id'];

    $query = <<<'gql'
        mutation fulfillmentCreateV2($fulfillment: FulfillmentV2Input!) {
          fulfillmentCreateV2(fulfillment: $fulfillment) {
            fulfillment {
              id
            }
            userErrors {
              field
              message
            }
          }
        }
        gql;

    // In GraphQL fulfillmentCreateV2, we need the line items from the fulfillment order
    // For simplicity and since we usually fulfill everything, we'll fetch them.
    $foQuery = <<<'gql'
        query($id: ID!) {
          fulfillmentOrder(id: $id) {
            lineItems(first: 250) {
              edges {
                node {
                  id
                  totalQuantity
                }
              }
            }
          }
        }
        gql;

    $foRes = $this->graphQL($foQuery, ['id' => $fulfillmentOrderId]);
    $lineItems = $foRes['data']['fulfillmentOrder']['lineItems']['edges'] ?? [];

    $payloadLineItems = array_map(function ($edge) {
      return [
        'fulfillmentOrderLineItemId' => $edge['node']['id'],
        'quantity' => $edge['node']['totalQuantity']
      ];
    }, $lineItems);

    $variables = [
      'fulfillment' => [
        'lineItemsByFulfillmentOrder' => [
          [
            'fulfillmentOrderId' => $fulfillmentOrderId,
            'fulfillmentOrderLineItems' => $payloadLineItems
          ]
        ],
        'trackingInfo' => [
          'number' => $trackingNumber,
          'url' => $trackingUrl,
          'company' => $carrier,
        ],
        'notifyCustomer' => true
      ]
    ];

    $response = $this->graphQL($query, $variables);

    $errors = $response['data']['fulfillmentCreateV2']['userErrors'] ?? [];
    if (!empty($errors)) {
      Log::error("Shopify Fulfillment Failed for Order {$shopifyOrderId}", ['errors' => $errors]);
      return false;
    }

    return true;
  }

  protected function getFulfillmentOrders($orderId)
  {
    $query = <<<'gql'
        query($id: ID!) {
          order(id: $id) {
            fulfillmentOrders(first: 10) {
              edges {
                node {
                  id
                  status
                }
              }
            }
          }
        }
        gql;

    $response = $this->graphQL($query, ['id' => $this->formatGid($orderId, 'Order')]);
    $edges = $response['data']['order']['fulfillmentOrders']['edges'] ?? [];

    return array_map(function ($edge) {
      return [
        'id' => $edge['node']['id'],
        'status' => $edge['node']['status']
      ];
    }, $edges);
  }

  protected function graphQL($query, $variables = [])
  {
    $endpoint = "https://{$this->domain}/admin/api/{$this->version}/graphql.json";

    $response = $this->getClient()->post($endpoint, [
      'query' => $query,
      'variables' => empty($variables) ? (object) [] : $variables,
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
    $query = <<<'gql'
        query($id: ID!) {
          product(id: $id) {
            variants(first: 250) {
              edges {
                node {
                  id
                  legacyResourceId
                  title
                  sku
                  price
                  inventoryItem {
                    id
                  }
                }
              }
            }
          }
        }
        gql;

    $response = $this->graphQL($query, ['id' => $this->formatGid($productId, 'Product')]);
    $edges = $response['data']['product']['variants']['edges'] ?? [];

    return array_map(function ($edge) {
      $node = $edge['node'];
      return [
        'id' => $node['legacyResourceId'],
        'product_id' => $this->unformatGid($node['id']), // This is a bit weird in REST, but usually not needed
        'title' => $node['title'],
        'sku' => $node['sku'],
        'price' => $node['price'],
        'inventory_item_id' => $this->unformatGid($node['inventoryItem']['id'] ?? '')
      ];
    }, $edges);
  }

  protected $primaryLocationId = null;

  public function getPrimaryLocationId()
  {
    if ($this->primaryLocationId) {
      return $this->primaryLocationId;
    }

    $query = <<<'gql'
        {
          locations(first: 10) {
            edges {
              node {
                id
                legacyResourceId
                isActive
              }
            }
          }
        }
        gql;

    $response = $this->graphQL($query);
    $edges = $response['data']['locations']['edges'] ?? [];

    foreach ($edges as $edge) {
      if ($edge['node']['isActive']) {
        $this->primaryLocationId = $edge['node']['legacyResourceId'];
        return $this->primaryLocationId;
      }
    }

    if (!empty($edges)) {
      $this->primaryLocationId = $edges[0]['node']['legacyResourceId'];
      return $this->primaryLocationId;
    }

    return null;
  }

  public function setInventoryLevel($inventoryItemId, $locationId, $quantity)
  {
    // Use the existing bulk method but for a single item for consistency
    return $this->bulkUpdateInventoryLevels([
      [
        'inventoryItemId' => $this->formatGid($inventoryItemId, 'InventoryItem'),
        'locationId' => $this->formatGid($locationId, 'Location'),
        'quantity' => (int) $quantity
      ]
    ]);
  }

  public function createProduct($data)
  {
    $input = [
      'title' => $data['product']['title'] ?? '',
      'bodyHtml' => $data['product']['body_html'] ?? '',
      'vendor' => $data['product']['vendor'] ?? '',
      'productType' => $data['product']['product_type'] ?? '',
      'tags' => array_map('trim', explode(',', $data['product']['tags'] ?? '')),
      'status' => strtoupper($data['product']['status'] ?? 'ACTIVE'),
    ];

    // Map Options
    if (!empty($data['product']['options'])) {
      $input['productOptions'] = array_map(function ($opt) {
        return ['name' => $opt['name']];
      }, $data['product']['options']);
    }

    // Map Variants
    if (!empty($data['product']['variants'])) {
      $input['variants'] = array_map(function ($v) {
        $variantInput = [
          'price' => $v['price'] ?? 0,
          'sku' => $v['sku'] ?? '',
          'inventoryItem' => [
            'tracked' => ($v['inventory_management'] ?? '') === 'shopify'
          ]
        ];
        // Map options (legacy option1, option2...)
        $opts = [];
        if (isset($v['option1']))
          $opts[] = $v['option1'];
        if (isset($v['option2']))
          $opts[] = $v['option2'];
        if (isset($v['option3']))
          $opts[] = $v['option3'];

        if (!empty($opts)) {
          $variantInput['options'] = $opts;
        }

        // If quantity is provided, we can use inventoryQuantities if supported by the API version
        // but usually it's safer to handle inventory via bulkUpdateInventoryLevels later if needed.
        // However, for creation, we can try to set it.
        return $variantInput;
      }, $data['product']['variants']);
    }

    // Map Images
    if (!empty($data['product']['images'])) {
      $input['media'] = array_map(function ($img) {
        return [
          'mediaContentType' => 'IMAGE',
          'alt' => '',
          'originalSource' => $img['src']
        ];
      }, $data['product']['images']);
    }

    $query = <<<'gql'
        mutation productCreate($input: ProductInput!) {
          productCreate(input: $input) {
            product {
              id
              legacyResourceId
              variants(first: 100) {
                edges {
                  node {
                    id
                    legacyResourceId
                    title
                  }
                }
              }
            }
            userErrors {
              field
              message
            }
          }
        }
        gql;

    $response = $this->graphQL($query, ['input' => $input]);

    $errors = $response['data']['productCreate']['userErrors'] ?? [];
    if (!empty($errors)) {
      Log::error("Shopify Product Create Errors", ['errors' => $errors, 'input' => $input]);
      return null;
    }

    $product = $response['data']['productCreate']['product'] ?? null;
    if ($product) {
      // Return in a format compatible with the rest of the app (legacy IDs)
      $formatted = [
        'id' => $product['legacyResourceId'],
        'variants' => array_map(function ($edge) {
          return [
            'id' => $edge['node']['legacyResourceId'],
            'title' => $edge['node']['title']
          ];
        }, $product['variants']['edges'] ?? [])
      ];
      return $formatted;
    }

    return null;
  }

  public function updateProduct($productId, $data)
  {
    $payload = $data['product'] ?? $data;
    $input = [
      'id' => $this->formatGid($productId, 'Product')
    ];

    if (isset($payload['title']))
      $input['title'] = $payload['title'];
    if (isset($payload['body_html']))
      $input['bodyHtml'] = $payload['body_html'];
    if (isset($payload['vendor']))
      $input['vendor'] = $payload['vendor'];
    if (isset($payload['product_type']))
      $input['productType'] = $payload['product_type'];
    if (isset($payload['status']))
      $input['status'] = strtoupper($payload['status']);

    if (isset($payload['tags'])) {
      $input['tags'] = array_map('trim', explode(',', $payload['tags']));
    }

    $query = <<<'gql'
        mutation productUpdate($input: ProductInput!) {
          productUpdate(input: $input) {
            product {
              id
            }
            userErrors {
              field
              message
            }
          }
        }
        gql;

    $response = $this->graphQL($query, ['input' => $input]);

    $errors = $response['data']['productUpdate']['userErrors'] ?? [];
    if (!empty($errors)) {
      Log::error("Shopify Product Update Errors", ['errors' => $errors, 'id' => $productId]);
      return false;
    }

    return true;
  }

  public function updateProductCategory($productId, $categoryGid)
  {
    $query = <<<'gql'
        mutation productUpdate($input: ProductInput!) {
          productUpdate(input: $input) {
            product {
              id
              category {
                id
                name
              }
            }
            userErrors {
              field
              message
            }
          }
        }
        gql;

    $input = [
      'id' => str_starts_with($productId, 'gid://') ? $productId : "gid://shopify/Product/{$productId}",
      'category' => $categoryGid
    ];

    $response = $this->graphQL($query, ['input' => $input]);

    $errors = $response['data']['productUpdate']['userErrors'] ?? [];
    if (!empty($errors)) {
      Log::error("Shopify Product Category Update Errors", ['errors' => $errors]);
      return false;
    }

    return true;
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

  public function setMetafield(string $ownerId, string $namespace, string $key, string $value, string $type = 'single_line_text_field')
  {
    // GraphQL version is more robust for Standard Metafields
    return $this->setMetafields([
      [
        'ownerId' => str_starts_with($ownerId, 'gid://') ? $ownerId : "gid://shopify/Product/{$ownerId}",
        'namespace' => $namespace,
        'key' => $key,
        'value' => $value,
        'type' => $type
      ]
    ]);
  }

  /**
   * Set multiple metafields in one GraphQL call.
   * $metafields = [['ownerId' => '...', 'namespace' => '...', 'key' => '...', 'value' => '...', 'type' => '...'], ...]
   */
  public function setMetafields(array $metafields)
  {
    $query = <<<'gql'
        mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields {
              id
              key
              value
            }
            userErrors {
              field
              message
            }
          }
        }
        gql;

    foreach ($metafields as &$m) {
      if (isset($m['ownerId']) && !str_starts_with($m['ownerId'], 'gid://')) {
        // Default to Product if not specified
        $m['ownerId'] = "gid://shopify/Product/{$m['ownerId']}";
      }
    }

    $response = $this->graphQL($query, ['metafields' => $metafields]);

    $errors = $response['data']['metafieldsSet']['userErrors'] ?? [];
    if (!empty($errors)) {
      Log::error("Shopify Metafields Set Errors", ['errors' => $errors]);
      return false;
    }

    return true;
  }

  public function getVariantMetafield($variantId, $namespace, $key)
  {
    $query = <<<'gql'
        query($id: ID!, $ns: String!, $key: String!) {
          productVariant(id: $id) {
            metafield(namespace: $ns, key: $key) {
                value
            }
          }
        }
        gql;

    $response = $this->graphQL($query, [
      'id' => $this->formatGid($variantId, 'ProductVariant'),
      'ns' => $namespace,
      'key' => $key
    ]);

    return $response['data']['productVariant']['metafield']['value'] ?? null;
  }

  public function getActiveTurumProductsCount(): int
  {
    $query = <<<'gql'
        query {
            productsCount(query: "status:active") {
                count
            }
        }
gql;
    $result = $this->graphQL($query);
    return $result['data']['productsCount']['count'] ?? 0;
  }

  public function draftStaleProducts(array $activeSkus)
  {
    $draftedCount = 0;
    $skippedInStoreCount = 0;
    $totalActiveChecked = 0;
    $hasNextPage = true;
    $cursor = null;

    while ($hasNextPage) {
      $query = <<<'gql'
            query($cursor: String) {
              products(first: 250, after: $cursor, query: "status:active") {
                pageInfo {
                  hasNextPage
                  endCursor
                }
                edges {
                  node {
                    id
                    legacyResourceId
                    collections(first: 5) {
                      edges {
                        node {
                          handle
                        }
                      }
                    }
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
      $totalActiveChecked += count($edges);

      if (!$cursor) {
        Log::info("Shopify returned " . count($edges) . " initial active products to check against active SKUs.");
      }
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

        // ADDITIONAL CHECK: Skip if in 'in-store' collection
        $collections = $node['collections']['edges'] ?? [];
        $isInstore = false;
        foreach ($collections as $cEdge) {
          if (($cEdge['node']['handle'] ?? '') === 'in-store') {
            $isInstore = true;
            break;
          }
        }

        if ($isInstore) {
          $skippedInStoreCount++;
          // Log::info("Skipping draft check for product {$productId} as it is in 'in-store' collection.");
          continue;
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

    return [
      'drafted' => $draftedCount,
      'checked' => $totalActiveChecked,
      'skipped_instore' => $skippedInStoreCount
    ];
  }

  public function getProductCollections($productId)
  {
    $query = <<<'gql'
        query($id: ID!) {
          product(id: $id) {
            collections(first: 20) {
              edges {
                node {
                  handle
                }
              }
            }
          }
        }
        gql;

    $response = $this->graphQL($query, ['id' => $this->formatGid($productId, 'Product')]);
    $edges = $response['data']['product']['collections']['edges'] ?? [];

    return array_map(function ($edge) {
      return $edge['node']['handle'];
    }, $edges);
  }

  public function cancelOrder($orderId, $reason = 'inventory_shortage')
  {
    $query = <<<'gql'
        mutation orderCancel($id: ID!, $reason: OrderCancelReason!) {
          orderCancel(id: $id, reason: $reason) {
            job {
              id
            }
            userErrors {
              field
              message
            }
          }
        }
        gql;

    // Map internal reasons to GraphQL OrderCancelReason enum
    $reasonMap = [
      'inventory_shortage' => 'INVENTORY',
      'customer' => 'CUSTOMER',
      'fraud' => 'FRAUD',
      'declined' => 'DECLINED',
      'other' => 'OTHER'
    ];
    $gqlReason = $reasonMap[$reason] ?? 'OTHER';

    $response = $this->graphQL($query, [
      'id' => $this->formatGid($orderId, 'Order'),
      'reason' => $gqlReason
    ]);

    $errors = $response['data']['orderCancel']['userErrors'] ?? [];
    if (!empty($errors)) {
      Log::error("Failed to cancel Order {$orderId}", ['errors' => $errors]);
      return false;
    }

    return true;
  }
}
