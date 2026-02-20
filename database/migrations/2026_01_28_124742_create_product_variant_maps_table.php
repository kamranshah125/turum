<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_variant_maps', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_sku')->index();
            $table->string('shopify_size')->nullable();
            $table->string('turum_variant_id');
            $table->string('turum_sku')->nullable();
            $table->timestamps();

            // Unique composite index to prevent duplicates
            $table->unique(['shopify_sku', 'shopify_size']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variant_maps');
    }
};
