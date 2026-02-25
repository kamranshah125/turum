<?php

namespace App\Console\Commands;

use App\Services\ShopifyService;
use App\Services\TurumApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestStaleProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'turum:test-draft {sku}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test drafting a specific SKU in Shopify if it is not found in Turum';

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
        $sku = $this->argument('sku');
        $this->info("Checking SKU: {$sku}");

        // 1. Find in Shopify
        $this->info("Looking up in Shopify...");
        $shopifyProduct = $this->shopifyService->findProductBySku($sku);

        if (!$shopifyProduct) {
            $this->error("SKU {$sku} not found in Shopify. Nothing to draft.");
            return;
        }

        $productId = $shopifyProduct['product_id'];
        $this->info("Found in Shopify! Product ID: {$productId}");

        // 2. Find in Turum
        $this->info("Looking up in Turum...");
        $turumProduct = $this->turumService->getProduct($sku);

        // If returned null or empty, it means Turum doesn't have it (or it's deleted)
        if (!empty($turumProduct)) {
            $this->info("SKU {$sku} is still active in Turum. Not drafting.");
        } else {
            $this->warn("SKU {$sku} is MISSING from Turum. Drafting in Shopify...");

            $success = $this->shopifyService->updateProduct($productId, [
                'product' => [
                    'id' => $productId,
                    'status' => 'draft'
                ]
            ]);

            if ($success) {
                $this->info("Successfully drafted Product ID {$productId} in Shopify.");
            } else {
                $this->error("Failed to draft Product ID {$productId} in Shopify.");
            }
        }
    }
}
