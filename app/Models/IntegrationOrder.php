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
}
