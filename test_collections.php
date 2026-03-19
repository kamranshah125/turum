<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$shopify = app(\App\Services\ShopifyService::class);

$query = <<<'GQL'
{
  collections(first: 100) {
    edges {
      node {
        id
        title
        handle
      }
    }
  }
}
GQL;

try {
    $ref = new \ReflectionClass($shopify);
    $method = $ref->getMethod('graphQL');
    $method->setAccessible(true);
    $result = $method->invoke($shopify, $query);
    
    $edges = $result['data']['collections']['edges'] ?? [];
    echo "--- SHOPIFY COLLECTIONS ---\n";
    foreach ($edges as $edge) {
        $node = $edge['node'];
        echo "Title: {$node['title']} | Handle: {$node['handle']}\n";
    }
    echo "--- END ---\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
