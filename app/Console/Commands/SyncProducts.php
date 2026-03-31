<?php

namespace App\Console\Commands;

use App\Services\ShopifyService;
use App\Services\TurumApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'turum:sync-products {--sku= : Sync only a specific SKU for debugging}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync products from Turum B2B to Shopify and update metadata';
    protected $turumService;
    protected $shopifyService;
    protected $pricingService;
    protected $uspService;

    public function __construct(TurumApiService $turumService, ShopifyService $shopifyService, \App\Services\TurumPricingService $pricingService, \App\Services\TurumUspService $uspService)
    {
        parent::__construct();
        $this->turumService = $turumService;
        $this->shopifyService = $shopifyService;
        $this->pricingService = $pricingService;
        $this->uspService = $uspService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Product Sync...');
        Log::info('--- Turum Product Sync Started ---');

        $this->info("Counting active Turum products on Shopify...");
        $activeShopifyProductsCount = $this->shopifyService->getActiveTurumProductsCount();
        $this->info("-> Found {$activeShopifyProductsCount} active Turum products on Shopify.");

        // 1. Fetch full product list from Turum
        // Expecting structure: [ { "id": "...", "sku": "...", "variants": [...], "name": "..." }, ... ]
        // Note: Check actual structure from previous logs if possible or assume standard.
        // Previous log showed: "variants":[{"variant_id":"...","size":"...","price":80}]

        $products = $this->turumService->getProductsFullList(); // Adjusted method in service earlier

        if (empty($products) || isset($products['error'])) {
            $this->error('Failed to fetch products from Turum.');
            return;
        }

        // If returned in a wrapper key, adjust. Assuming array of products or ['products' => [...]]
        // Let's assume root array based on previous discussions, or check details.
        // If it's paginated, we loop. For now assume all valid array.
        // If it is ['data' => [...]], handle it. 
        // Let's assume raw array for demo logic or wrapper.

        // Check for common wrapper keys
        $items = $products['products'] ?? $products['data'] ?? $products;
        $targetSku = $this->option('sku');
        if ($targetSku) {
            $this->info("Filtering for SKU: {$targetSku}");
            $items = array_filter($items, function ($item) use ($targetSku) {
                return ($item['sku'] ?? '') === $targetSku;
            });
            if (empty($items)) {
                $this->error("SKU {$targetSku} not found in Turum feed.");
                return;
            }
        }

        $activeTurumSkus = [];
        $totalItems = count($items);

        $startTimeReadable = now()->format('d M Y, h:i A');
        Log::info("--- Turum Product Sync Started at {$startTimeReadable} ---");
        Log::info("Fetched {$totalItems} products from Turum. Processing updates...");

        $processedCount = 0;
        $lastLogPercentage = 0;

        foreach ($items as $tProduct) {

            $sku = $tProduct['sku'] ?? null;
            if (!$sku)
                continue;

            $activeTurumSkus[] = (string) $sku;
            $processedCount++;
            $percentage = ($totalItems > 0) ? round(($processedCount / $totalItems) * 100, 2) : 100;

            $this->info("Processing SKU: $sku ({$processedCount}/{$totalItems}) - {$percentage}%");

            $currentDecile = floor($percentage / 10) * 10;
            if ($currentDecile > $lastLogPercentage && $currentDecile <= 100) {
                Log::info("{$currentDecile}% completed");
                $lastLogPercentage = $currentDecile;
            }

            // 2. Find in Shopify
            $shopifyProduct = $this->shopifyService->findProductBySku($sku);

            if ($shopifyProduct) {
                // Determine Variant ID from findProductBySku result if possible (it returns single variant match though)
                // We need ALL variants to sync properly.
                $allVariants = $this->shopifyService->getProductVariants($shopifyProduct['product_id']);

                $this->info("  [UPDATE] Found existing product in Shopify (ID: {$shopifyProduct['product_id']}). Updating variants...");

                // Smartly extract type and colors for existing products to ensure filters work
                $smartType = $this->extractProductType($tProduct['name'] ?? '', $tProduct['category'] ?? $tProduct['type'] ?? '');
                $extractedColors = $this->extractColors($tProduct['name'] ?? '');
                $tags = empty($extractedColors) ? '' : implode(', ', $extractedColors);

                // Determine Category GID based on smart type
                $categoryGid = $this->getTaxonomyGid($smartType);
                $metafields = $this->getProductFilters($tProduct, $smartType);

                // Update the product level properties (Brand, Type, Tags, Body)
                $existingBodyHtml = $tProduct['description'] ?? '';
                $uspHtml = $this->uspService->getUspHtml($tProduct['name'] ?? '', $tProduct['brand'] ?? '');
                $finalBodyHtml = $existingBodyHtml . $uspHtml;

                $productUpdatePayload = [
                    'product' => [
                        'id' => $shopifyProduct['product_id'],
                        'vendor' => $tProduct['brand'] ?? 'Turum',
                        'product_type' => $smartType,
                        'tags' => $tags,
                        'body_html' => $finalBodyHtml,
                        'status' => 'ACTIVE',
                        'productCategory' => $categoryGid,
                        'metafields' => $metafields
                    ]
                ];

                $this->shopifyService->updateProduct($shopifyProduct['product_id'], $productUpdatePayload);

                $this->syncVariants($shopifyProduct['product_id'], $tProduct['variants'], $sku, $allVariants, false);
            } else {
                $this->info("  [NEW] Product not found in Shopify. Creating...");
                $this->createAndSyncProduct($tProduct);
            }
        }

        $this->info("Checking for stale products to draft...");
        $draftResult = $this->shopifyService->draftStaleProducts($activeTurumSkus);

        $draftedCount = $draftResult['drafted'] ?? 0;
        $totalChecked = $draftResult['checked'] ?? 0;
        $skippedInstore = $draftResult['skipped_instore'] ?? 0;

        $this->info("-> Verified {$totalChecked} active products from Shopify.");
        $this->info("-> Skipped {$skippedInstore} 'In Store' products from drafting.");
        $this->info("-> Drafted {$draftedCount} stale product(s).");

        Log::info("Sync Completed: {$totalChecked} checked, {$skippedInstore} 'In Store' skipped, {$draftedCount} drafted.");

        Log::info("--- Turum Product Sync Complete --- [Verified: {$totalChecked}, Drafted: {$draftedCount} stale products]");
        $this->info('Product Sync Complete.');
    }

    protected function createAndSyncProduct($tProduct)
    {

        Log::info('--- Turum Product Create and  Sync Started ---');

        // Map Turum Product to Shopify Payload
        // Variants formatting
        $variantsPayload = [];
        $marginPercentage = config('services.turum.price_markup_percentage', 0);
        $margin = $marginPercentage / 100;
        if ($margin >= 1)
            $margin = 0.99; // Prevent division by zero or negative price

        foreach ($tProduct['variants'] as $tVariant) {
            // User requested EU Size (e.g. 38, 42) instead of US (5.5, 7)
            $sizeOption = $tVariant['eu_size'] ?? $tVariant['size'] ?? 'Default';

            $originalPrice = $tVariant['price'] ?? 0;

            $calculatedPrice = $originalPrice;
            if ($margin > 0 && $margin < 1) {
                $calculatedPrice = $originalPrice / (1 - $margin);
            }
            $finalPrice = $this->pricingService->getPremiumPrice($calculatedPrice);

            $variantsPayload[] = [
                'option1' => $sizeOption,
                'price' => $finalPrice,
                'sku' => $tProduct['sku'],
                'inventory_management' => 'shopify',
                'inventory_quantity' => $tVariant['stock'] ?? 0
            ];
        }

        // Prepare Images
        $imagesPayload = [];

        // 1. Check for 'images' array (strings or objects)
        if (!empty($tProduct['images']) && is_array($tProduct['images'])) {
            foreach ($tProduct['images'] as $img) {
                // If it's a string URL
                if (is_string($img)) {
                    $imagesPayload[] = ['src' => $img];
                }
                // If it's an object with 'src' or 'url'
                elseif (is_array($img)) {
                    $src = $img['src'] ?? $img['url'] ?? null;
                    if ($src) {
                        $imagesPayload[] = ['src' => $src];
                    }
                }
            }
        }
        // 2. Fallback to single 'image' key
        elseif (!empty($tProduct['image'])) {
            $imagesPayload[] = ['src' => $tProduct['image']];
        }

        $smartType = $this->extractProductType($tProduct['name'] ?? '', $tProduct['category'] ?? $tProduct['type'] ?? '');
        $extractedColors = $this->extractColors($tProduct['name'] ?? '');
        $tags = empty($extractedColors) ? '' : implode(', ', $extractedColors);

        $categoryGid = $this->getTaxonomyGid($smartType);

        $existingBodyHtml = $tProduct['description'] ?? '';
        $uspHtml = $this->uspService->getUspHtml($tProduct['name'] ?? '', $tProduct['brand'] ?? '');
        $finalBodyHtml = $existingBodyHtml . $uspHtml;

        $payload = [
            'product' => [
                'title' => $tProduct['name'],
                'body_html' => $finalBodyHtml, // Full description + USPs
                'vendor' => $tProduct['brand'] ?? 'Turum',
                'product_type' => $smartType,
                'tags' => $tags,
                'options' => [
                    [
                        'name' => 'Size'
                    ]
                ],
                'variants' => $variantsPayload,
                'images' => $imagesPayload,
                'productCategory' => $categoryGid,
                'metafields' => $this->getProductFilters($tProduct, $smartType)
            ]
        ];

        $createdProduct = $this->shopifyService->createProduct($payload);

        if ($createdProduct) {
            $this->info("    -> Product created successfully (ID: {$createdProduct['id']})");

            // Use the created variants to map Metafields
            // Match by 'option1' (size)
            $this->syncVariants($createdProduct['id'], $tProduct['variants'], $tProduct['sku'], $createdProduct['variants'], true);
        }
    }

    protected function syncVariants($shopifyProductId, $turumVariants, $productSku, $existingShopifyVariants = null, $isNew = false)
    {
        // If we didn't pass existing variants, fetch them
        if (!$existingShopifyVariants) {
            $this->warn("    - Syncing existing variants requires fetching all variants first. Skipped for this step.");
            return;
        }

        // Get Location for Inventory Update
        $locationId = $this->shopifyService->getPrimaryLocationId();
        if ($locationId && !str_starts_with($locationId, 'gid://')) {
            $locationId = "gid://shopify/Location/{$locationId}";
        }

        $actionType = $isNew ? '[NEW]' : '[UPDATE]';
        $marginPercentage = config('services.turum.price_markup_percentage', 0);
        $margin = $marginPercentage / 100;
        if ($margin >= 1)
            $margin = 0.99; // Prevent division by zero or negative price

        $bulkVariantsPayload = [];
        $bulkInventoryPayload = [];
        $matchedInventoryItemIds = [];

        foreach ($turumVariants as $tVariant) {
            $size = $tVariant['eu_size'] ?? $tVariant['size'] ?? '';
            $originalPrice = $tVariant['price'] ?? 0;

            $calculatedPrice = $originalPrice;
            if ($margin > 0 && $margin < 1) {
                $calculatedPrice = $originalPrice / (1 - $margin);
            }
            $finalPrice = $this->pricingService->getPremiumPrice($calculatedPrice);
            $stock = $tVariant['stock'] ?? 0;
            $turumId = $tVariant['variant_id'];

            // Find matching Shopify Variant
            $shopifyVariant = null;
            foreach ($existingShopifyVariants as $sv) {
                if (($sv['option1'] ?? '') == $size || ($sv['title'] ?? '') == $size) {
                    $shopifyVariant = $sv;
                    break;
                }
            }

            if ($shopifyVariant) {
                $variantData = [
                    'id' => "gid://shopify/ProductVariant/{$shopifyVariant['id']}",
                    'price' => (string) $finalPrice,
                    'inventoryItem' => [
                        'tracked' => true
                    ],
                    'metafields' => [
                        [
                            'namespace' => 'turum',
                            'key' => 'variant_id',
                            'value' => (string) $turumId,
                            'type' => 'single_line_text_field'
                        ]
                    ]
                ];

                if ($locationId && isset($shopifyVariant['inventory_item_id'])) {
                    $inventoryItemId = $shopifyVariant['inventory_item_id'];
                    $matchedInventoryItemIds[] = $inventoryItemId;

                    // Shopify requires activating inventory tracking at a location for newly created variants
                    // before trying to set their on-hand quantities via bulk operations.
                    if ($isNew) {
                        $this->shopifyService->activateInventoryLocation($inventoryItemId, $locationId);
                    }

                    $bulkInventoryPayload[] = [
                        'inventoryItemId' => "gid://shopify/InventoryItem/{$inventoryItemId}",
                        'locationId' => $locationId,
                        'quantity' => (int) $stock
                    ];
                }

                $bulkVariantsPayload[] = $variantData;
                $this->info("    - {$actionType} Prepared Variant Size {$size} (Cost: {$originalPrice} | Price: {$finalPrice} | Stock: {$stock})");
            }
        }

        if (!$isNew && $locationId) {
            foreach ($existingShopifyVariants as $sv) {
                if (isset($sv['inventory_item_id'])) {
                    $invId = $sv['inventory_item_id'];
                    if (!in_array($invId, $matchedInventoryItemIds)) {
                        $bulkInventoryPayload[] = [
                            'inventoryItemId' => "gid://shopify/InventoryItem/{$invId}",
                            'locationId' => $locationId,
                            'quantity' => 0
                        ];
                        $staleTitle = $sv['title'] ?? 'Unknown Size';
                        $this->info("    - [STALE] Marking Variant Size '{$staleTitle}' as out of stock (removed from feed).");
                    }
                }
            }
        }

        if (!empty($bulkVariantsPayload)) {
            // Shopify allows up to 100 variants per bulk mutation
            $chunks = array_chunk($bulkVariantsPayload, 100);
            foreach ($chunks as $chunk) {
                $success = $this->shopifyService->bulkUpdateVariants($shopifyProductId, $chunk);
                if ($success) {
                    $this->info("    -> Successfully bulk updated " . count($chunk) . " variants (Price/Metafields).");
                } else {
                    $this->error("    -> Failed to bulk update variants (Price/Metafields). See logs for details.");
                }

                usleep(300000);
            }
        }

        if (!empty($bulkInventoryPayload)) {
            // Shopify allows up to 250 inventory adjustments per bulk mutation
            $inventoryChunks = array_chunk($bulkInventoryPayload, 250);
            foreach ($inventoryChunks as $chunk) {
                $success = $this->shopifyService->bulkUpdateInventoryLevels($chunk);
                if ($success) {
                    $this->info("    -> Successfully bulk updated " . count($chunk) . " inventory levels.");
                } else {
                    $this->error("    -> Failed to bulk update inventory levels. See logs for details.");
                }
                usleep(300000);
            }
        }
    }

    protected function extractProductType($name, $fallbackType)
    {
        $nameLower = strtolower($name);

        $apparelKeywords = ['t-shirt', 't shirt', 'shirt', 'hoodie', 'jacket', 'pants', 'shorts', 'sweatshirt', 'tracksuit', 'joggers', 'bra', 'leggings', 'jersey', 'sweater', 'top', 'vest', 'tank', 'pullover', 'crewneck', 'puffer', 'fleece', 'tights'];
        $accessoriesKeywords = ['bag', 'backpack', 'hat', 'cap', 'socks', 'beanie', 'gloves', 'belt', 'wallet', 'scarf', 'eyewear', 'sunglasses', 'slippers', 'slides'];

        foreach ($apparelKeywords as $keyword) {
            if (str_contains($nameLower, $keyword))
                return 'Apparel';
        }

        foreach ($accessoriesKeywords as $keyword) {
            if (str_contains($nameLower, $keyword))
                return 'Accessories';
        }

        // If generic type was provided by Turum
        if (!empty($fallbackType)) {
            $fallbackLower = strtolower($fallbackType);
            if (str_contains($fallbackLower, 'apparel') || str_contains($fallbackLower, 'clothing') || str_contains($fallbackLower, 'wear')) {
                return 'Apparel';
            }
            if (str_contains($fallbackLower, 'accessory') || str_contains($fallbackLower, 'accessories')) {
                return 'Accessories';
            }
            if (str_contains($fallbackLower, 'shoe') || str_contains($fallbackLower, 'sneaker') || str_contains($fallbackLower, 'footwear')) {
                return 'Sneakers';
            }
        }

        return 'Sneakers'; // Default
    }

    protected function extractColors($name)
    {
        $nameLower = strtolower($name);

        // Comprehensive list of colors
        $knownColors = [
            'black',
            'white',
            'grey',
            'gray',
            'red',
            'blue',
            'green',
            'yellow',
            'orange',
            'purple',
            'pink',
            'brown',
            'beige',
            'navy',
            'olive',
            'maroon',
            'teal',
            'gold',
            'silver',
            'bronze',
            'cream',
            'magenta',
            'cyan',
            'khaki',
            'burgundy',
            'sail',
            'bone'
        ];

        $foundColors = [];
        foreach ($knownColors as $color) {
            if (preg_match('/\b' . $color . '\b/i', $nameLower)) {
                $foundColors[] = ucfirst($color);
            }
        }

        return $foundColors; // Returns simple color names like ["Black", "White"]
    }

    protected $colorMapCache = null;

    protected $sizeMapCache = null;

    protected function getProductFilters($tProduct, $productType)
    {
        $metafields = [];
        $smartType = $productType;

        // 1. COLORS
        $extractedColors = $this->extractColors($tProduct['name'] ?? '');
        if (!empty($extractedColors)) {
            $colorGids = $this->getColorGids($extractedColors);
            if (!empty($colorGids)) {
                $metafields[] = [
                    'namespace' => 'shopify',
                    'key' => 'color_pattern',
                    'value' => json_encode($colorGids),
                    'type' => 'list.metaobject_reference'
                ];
            }
        }

        // 2. SIZES based on product type
        $sizeGids = $this->getSizeGids($tProduct['variants'] ?? [], $smartType);
        if (!empty($sizeGids)) {
            $key = 'shoe_size'; // Default
            if ($smartType === 'Apparel')
                $key = 'size';
            if ($smartType === 'Accessories')
                $key = 'accessory_size';

            $metafields[] = [
                'namespace' => 'shopify',
                'key' => $key,
                'value' => json_encode($sizeGids),
                'type' => 'list.metaobject_reference'
            ];
        }

        return $metafields;
    }

    protected function getColorGids($colorNames)
    {
        if ($this->colorMapCache === null) {
            $this->colorMapCache = [];
            $this->info("Fetching Color Metaobjects from Shopify...");

            $query = <<<'gql'
            {
              metaobjects(first: 250, type: "shopify--color-pattern") {
                edges {
                  node {
                    id
                    displayName
                  }
                }
              }
            }
            gql;

            $result = $this->callShopifyGQL($query);
            $edges = $result['data']['metaobjects']['edges'] ?? [];
            foreach ($edges as $edge) {
                $node = $edge['node'];
                $this->colorMapCache[strtolower($node['displayName'])] = $node['id'];
            }

            $translationMap = [
                'black' => 'zwart',
                'white' => 'wit',
                'red' => 'rood',
                'blue' => 'blauw',
                'grey' => 'grijs',
                'gray' => 'grijs',
                'green' => 'groen',
                'pink' => 'roze',
                'yellow' => 'geel',
            ];

            foreach ($translationMap as $en => $target) {
                if (isset($this->colorMapCache[$target]) && !isset($this->colorMapCache[$en])) {
                    $this->colorMapCache[$en] = $this->colorMapCache[$target];
                }
            }
        }

        $gids = [];
        foreach ($colorNames as $name) {
            $nameLower = strtolower($name);
            if (isset($this->colorMapCache[$nameLower])) {
                $gids[] = $this->colorMapCache[$nameLower];
            }
        }

        return array_unique($gids);
    }

    protected function getSizeGids($turumVariants, $productType)
    {
        if ($this->sizeMapCache === null) {
            $this->sizeMapCache = [
                'Sneakers' => [],
                'Apparel' => [],
                'Accessories' => []
            ];

            $this->info("Fetching Size Metaobjects from Shopify...");

            // Fetch Clothing Sizes
            $resA = $this->callShopifyGQL('{ metaobjects(first: 250, type: "shopify--size") { edges { node { id displayName } } } }');
            foreach ($resA['data']['shopifySize']['edges'] ?? $resA['data']['metaobjects']['edges'] ?? [] as $edge) {
                $this->sizeMapCache['Apparel'][strtolower($edge['node']['displayName'])] = $edge['node']['id'];
            }

            // Fetch Shoe Sizes
            $resS = $this->callShopifyGQL('{ metaobjects(first: 250, type: "shopify--shoe-size") { edges { node { id displayName } } } }');
            foreach ($resS['data']['shoeSize']['edges'] ?? $resS['data']['metaobjects']['edges'] ?? [] as $edge) {
                $name = str_replace(',', '.', $edge['node']['displayName']);
                $this->sizeMapCache['Sneakers'][$name] = $edge['node']['id'];
                $this->sizeMapCache['Sneakers'][$edge['node']['displayName']] = $edge['node']['id'];
            }

            // Fetch Accessory Sizes
            $resAcc = $this->callShopifyGQL('{ metaobjects(first: 250, type: "shopify--accessory-size") { edges { node { id displayName } } } }');
            foreach ($resAcc['data']['accSize']['edges'] ?? $resAcc['data']['metaobjects']['edges'] ?? [] as $edge) {
                $this->sizeMapCache['Accessories'][strtolower($edge['node']['displayName'])] = $edge['node']['id'];
            }
        }

        $gids = [];
        $typeKey = $productType;
        if (!isset($this->sizeMapCache[$typeKey]))
            $typeKey = 'Sneakers';

        foreach ($turumVariants as $v) {
            $sz = $v['eu_size'] ?? $v['size'] ?? '';
            $szLower = strtolower($sz);
            $szClean = str_replace(',', '.', $sz);

            if (isset($this->sizeMapCache[$typeKey][$szLower])) {
                $gids[] = $this->sizeMapCache[$typeKey][$szLower];
            } elseif (isset($this->sizeMapCache[$typeKey][$szClean])) {
                $gids[] = $this->sizeMapCache[$typeKey][$szClean];
            }
        }

        return array_unique($gids);
    }

    protected function callShopifyGQL($query)
    {
        $ref = new \ReflectionClass($this->shopifyService);
        $method = $ref->getMethod('graphQL');
        $method->setAccessible(true);
        return $method->invoke($this->shopifyService, $query);
    }

    protected function getTaxonomyGid($type)
    {
        // Mapping simple types to Shopify Standard Taxonomy GIDs
        // Full list: https://help.shopify.com/en/manual/products/details/product-taxonomy
        $map = [
            'Sneakers' => 'gid://shopify/TaxonomyCategory/aa-8', // Shoes
            'Apparel' => 'gid://shopify/TaxonomyCategory/aa-1', // Clothing
            'Accessories' => 'gid://shopify/TaxonomyCategory/aa-2', // Clothing Accessories
        ];

        return $map[$type] ?? 'gid://shopify/TaxonomyCategory/aa'; // Fallback to broad Apparel & Accessories
    }
}
