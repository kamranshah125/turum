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
      }
    }
  }
}
GRAPHQL;

$locResult = graphQL($locQuery, [], $domain, $token);
$locationId = $locResult['data']['locations']['edges'][0]['node']['id'] ?? null;
echo "Location ID: {$locationId}\n";

$mutation = <<<'GRAPHQL'
mutation inventorySetOnHandQuantities($input: InventorySetOnHandQuantitiesInput!) {
  inventorySetQuantities(input: $input) {
    userErrors {
      field
      message
    }
    inventoryAdjustmentGroup {
      id
    }
  }
}
GRAPHQL;

$inventoryItemId = "gid://shopify/InventoryItem/63148901499210"; // From the previous rest api error logs

$variables = [
    "input" => [
        "reason" => "correction",
        "name" => "available",
        "setQuantities" => [
            [
                "inventoryItemId" => $inventoryItemId,
                "locationId" => $locationId,
                "quantity" => 10
            ]
        ]
    ]
];

$result = graphQL($mutation, $variables, $domain, $token);
echo "Bulk Inventory Update Result:\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n";
