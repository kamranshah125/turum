<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariantMap extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_sku',
        'shopify_size',
        'turum_variant_id',
        'turum_sku',
    ];
}
