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
    protected $signature = 'turum:sync-products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync products from Turum B2B to Shopify and update metadata';
    protected $turumService;
    protected $shopifyService;

    public function __construct(TurumApiService $turumService, ShopifyService $shopifyService)
    {
        parent::__construct();
        $this->turumService = $turumService;
        $this->shopifyService = $shopifyService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Product Sync...');
        Log::info('--- Turum Product Sync Started ---');

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
        $activeTurumSkus = [];

        Log::info('Fetched ' . count($items) . ' products from Turum. Processing updates...');

        foreach ($items as $tProduct) {

            $sku = $tProduct['sku'] ?? null;
            if (!$sku)
                continue;

            $activeTurumSkus[] = (string) $sku;

            $this->info("Processing SKU: $sku");

            // 2. Find in Shopify
            $shopifyProduct = $this->shopifyService->findProductBySku($sku);

            if ($shopifyProduct) {
                // Determine Variant ID from findProductBySku result if possible (it returns single variant match though)
                // We need ALL variants to sync properly.
                $allVariants = $this->shopifyService->getProductVariants($shopifyProduct['product_id']);

                $this->info("  [UPDATE] Found existing product in Shopify (ID: {$shopifyProduct['product_id']}). Updating variants...");
                $this->syncVariants($shopifyProduct['product_id'], $tProduct['variants'], $sku, $allVariants, false);
            } else {
                $this->info("  [NEW] Product not found in Shopify. Creating...");
                $this->createAndSyncProduct($tProduct);
            }
        }

        $this->info("Checking for stale products to draft...");
        $draftedCount = $this->shopifyService->draftStaleProducts($activeTurumSkus);
        $this->info("Drafted {$draftedCount} stale product(s).");

        Log::info("--- Turum Product Sync Complete --- [Drafted: {$draftedCount} stale products]");
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
            $finalPrice = round($calculatedPrice, 2);

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

        $payload = [
            'product' => [
                'title' => $tProduct['name'],
                'body_html' => $tProduct['description'] ?? '', // Full description
                'vendor' => $tProduct['brand'] ?? 'Turum',
                'product_type' => $tProduct['category'] ?? $tProduct['type'] ?? 'Shoes', // Dynamic Category
                'variants' => $variantsPayload,
                'images' => $imagesPayload
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

        foreach ($turumVariants as $tVariant) {
            $size = $tVariant['eu_size'] ?? $tVariant['size'] ?? '';
            $originalPrice = $tVariant['price'] ?? 0;

            $calculatedPrice = $originalPrice;
            if ($margin > 0 && $margin < 1) {
                $calculatedPrice = $originalPrice / (1 - $margin);
            }
            $finalPrice = round($calculatedPrice, 2);
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
}
