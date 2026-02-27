<?php
use Illuminate\Support\Facades\Http;

$domain = config('services.shopify.domain');
$token = config('services.shopify.token');

function graphQL($query, $variables = [], $domain, $token)
{
    $endpoint = "https://{$domain}/admin/api/2024-01/graphql.json";
    $response = Http::withHeaders([
        'X-Shopify-Access-Token' => $token,
        'Content-Type' => 'application/json'
    ])->post($endpoint, [
        'query' => $query,
        'variables' => $variables,
    ]);
    return $response->json();
}

$locQuery = <<<'GRAPHQL'
query {
  locations(first: 1) {
    edges {
      node {
        id
        name
      }
    }
  }
}
GRAPHQL;

$locResult = graphQL($locQuery, [], $domain, $token);
$locationId = $locResult['data']['locations']['edges'][0]['node']['id'] ?? null;
echo "Location ID: {$locationId}\n";

$productId = "gid://shopify/Product/15497492824394"; // From logs
$mockVariantId = "gid://shopify/ProductVariant/61145711640906";

$mutation = <<<'GRAPHQL'
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
GRAPHQL;

$variables = [
    "productId" => $productId,
    "variants" => [
        [
            "id" => $mockVariantId,
            "price" => "99.99",
            "inventoryItem" => [
                "tracked" => true
            ],
            "inventoryQuantities" => [
                [
                    "availableQuantity" => 5,
                    "locationId" => $locationId
                ]
            ],
            "metafields" => [
                [
                    "namespace" => "turum",
                    "key" => "variant_id",
                    "value" => "test-bulk-123",
                    "type" => "single_line_text_field"
                ]
            ]
        ]
    ]
];

$result = graphQL($mutation, $variables, $domain, $token);
echo "Bulk Update Result:\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n";
