<?php

namespace App\Console\Commands;

use App\Models\IntegrationOrder;
use App\Services\ShopifyService;
use App\Services\TurumApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckTurumReservations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'turum:check-reservations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll Turum API for reservation status updates and sync tracking to Shopify';

    /**
     * Execute the console command.
     */
    public function handle(TurumApiService $turumService, ShopifyService $shopifyService)
    {
        // Select orders that are reserved but not yet fulfilled or failed
        // Assuming 'sent' means shipped/tracking available.
        $orders = IntegrationOrder::whereIn('status', ['new', 'reserved'])
            ->whereNotNull('turum_reservation_id')
            ->get();

        $this->info("Checking " . $orders->count() . " pending reservations...");

        foreach ($orders as $order) {
            try {
                $data = $turumService->getReservation($order->turum_reservation_id);

                if (!$data) {
                    $this->error("Failed to fetch reservation {$order->turum_reservation_id}");
                    continue;
                }

                $status = $data['status'] ?? null;
                $this->line("Order {$order->id}: Status {$status}");

                // Update status if changed
                if ($status && $status !== $order->status) {
                    $order->status = $status;
                    $order->save();
                }

                // Check for tracking
                // Response format: { "status": "sent", "tracking_url": "DPD\n123456" }
                if (in_array($status, ['sent', 'delivered']) && !empty($data['tracking_url'])) {

                    // Parse tracking string provided in example: "DPD\n123456"
                    $rawTracking = $data['tracking_url'];
                    $parts = explode("\n", $rawTracking);
                    $carrier = trim($parts[0] ?? 'Unknown');
                    $trackingNumber = trim($parts[1] ?? $rawTracking); // Fallback if no newline

                    // Sometimes tracking_url is just a URL
                    $trackingUrl = $data['tracking_url'];
                    // If it's not a URL, we might construct one or leave it empty? 
                    // Shopify requires a URL usually if company is 'Other'.

                    $order->tracking_number = $trackingNumber;
                    $order->carrier = $carrier;
                    $order->tracking_url = $trackingUrl; // Save raw for reference
                    $order->save();

                    // Sync to Shopify
                    $success = $shopifyService->fulfillOrder(
                        $order->shopify_order_id,
                        $trackingNumber,
                        is_url($trackingUrl) ? $trackingUrl : 'https://www.google.com/search?q=' . $trackingNumber, // Fallback URL
                        $carrier
                    );

                    if ($success) {
                        $order->status = 'fulfilled';
                        $order->save();
                        $this->info("Order {$order->id} fulfilled in Shopify.");
                    } else {
                        $this->error("Failed to fulfill Order {$order->id} in Shopify.");
                    }
                }

            } catch (\Exception $e) {
                Log::error("Error checking reservation {$order->turum_reservation_id}: " . $e->getMessage());
                $this->error("Exception for order {$order->id}: " . $e->getMessage());
            }
        }

        $this->info("Check complete.");
    }
}

function is_url($s)
{
    return filter_var($s, FILTER_VALIDATE_URL) !== false;
}
