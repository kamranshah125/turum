<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_order_id',
        'turum_reservation_id',
        'status',
        'tracking_number',
        'tracking_url',
        'carrier',
        'payload',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function getProductNameAttribute()
    {
        $payload = $this->payload;
        if (empty($payload['line_items'])) return 'N/A';

        $firstItem = $payload['line_items'][0]['name'] ?? 'Unknown Product';
        $count = count($payload['line_items']);

        return $count > 1 ? "{$firstItem} + " . ($count - 1) . " more" : $firstItem;
    }

    public function getTotalPriceAttribute()
    {
        $payload = $this->payload;
        if (isset($payload['total_price'])) return $payload['total_price'];

        // Fallback: calculate from line items
        $total = 0;
        foreach ($payload['line_items'] ?? [] as $item) {
            $total += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }
        return number_format($total, 2);
    }
}
