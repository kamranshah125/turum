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

        $this->info('Product Sync Complete.');
    }

    protected function createAndSyncProduct($tProduct)
    {
        log::info($tProduct);
        // Map Turum Product to Shopify Payload
        // Variants formatting
        $variantsPayload = [];
        $markupPercentage = config('services.turum.price_markup_percentage', 0);

        foreach ($tProduct['variants'] as $tVariant) {
            // User requested EU Size (e.g. 38, 42) instead of US (5.5, 7)
            $sizeOption = $tVariant['eu_size'] ?? $tVariant['size'] ?? 'Default';

            $originalPrice = $tVariant['price'] ?? 0;
            $markedUpPrice = $originalPrice + ($originalPrice * ($markupPercentage / 100));

            $variantsPayload[] = [
                'option1' => $sizeOption,
                'price' => round($markedUpPrice, 2),
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
        // If we didn't pass existing variants, fetch them (though we only have product ID)
        if (!$existingShopifyVariants) {
            $this->warn("    - Syncing existing variants requires fetching all variants first. Skipped for this step.");
            return;
        }

        // Get Location for Inventory Update
        $locationId = $this->shopifyService->getPrimaryLocationId();

        $actionType = $isNew ? '[NEW]' : '[UPDATE]';
        $markupPercentage = config('services.turum.price_markup_percentage', 0);

        foreach ($turumVariants as $tVariant) {
            // Use EU size for matching if that's what we used to create it
            $size = $tVariant['eu_size'] ?? $tVariant['size'] ?? '';

            $originalPrice = $tVariant['price'] ?? 0;
            $markedUpPrice = $originalPrice + ($originalPrice * ($markupPercentage / 100));
            $finalPrice = round($markedUpPrice, 2);

            // Find matching Shopify Variant
            $shopifyVariant = null;
            foreach ($existingShopifyVariants as $sv) {
                // Check option1 or title. Shopify titles usually "Option1 / Option2" or just "Option1"
                if (($sv['option1'] ?? '') == $size || ($sv['title'] ?? '') == $size) {
                    $shopifyVariant = $sv;
                    break;
                }
            }

            if ($shopifyVariant) {
                // 1. Update Inventory / Price
                $this->shopifyService->updateVariant($shopifyVariant['id'], [
                    'price' => $finalPrice
                ]);

                // 2. Update Inventory Level
                if ($locationId && isset($shopifyVariant['inventory_item_id'])) {
                    $stock = $tVariant['stock'] ?? 0;
                    $this->shopifyService->setInventoryLevel($shopifyVariant['inventory_item_id'], $locationId, $stock);
                    $this->info("    - {$actionType} Variant (Size {$size}): Price set to {$finalPrice} (Original: {$originalPrice}) | Stock set to {$stock}");
                }

                // 3. Set Metafield
                $turumId = $tVariant['variant_id'];
                $this->shopifyService->setVariantMetafield($shopifyVariant['id'], 'turum', 'variant_id', $turumId);
                if ($isNew) {
                    $this->info("    - [LINKED] New Variant Size {$size} linked to Turum ID {$turumId}");
                }
            }
        }
    }
}
