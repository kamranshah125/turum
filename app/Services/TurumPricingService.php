<?php

namespace App\Services;

class TurumPricingService
{
    /**
     * Apply the Smart Pricing logic (Clean premium price endings).
     * 
     * Core Rules:
     * - Always end prices in clean tiers: .4.95 or .9.95
     * - Never show random decimals. MUST feel intentional and premium.
     * 
     * Tiers:
     * €0 – €120: +2 to +5 (e.g., 105.40 -> 109.95)
     * €120 – €180: +5 to +10 (e.g., 139 -> 144.95, 160 -> 169.95)
     * €180+: +5 to +15 (e.g., 189 -> 199.95, 210 -> 219.95)
     * 
     * @param float $basePrice The price after default Turum margin.
     * @return float
     */
    public function getPremiumPrice(float $basePrice): float
    {
        if ($basePrice <= 0) return $basePrice;

        // 1. Determine minimum uplift based on client tiers
        if ($basePrice <= 120) {
            $uplift = 2.0; 
        } elseif ($basePrice <= 180) {
            $uplift = 5.0; 
        } else {
            // For 180+, ensuring we hit the +10 mark effectively for numbers like 189->199.95
            $uplift = 6.0; 
        }

        // Feature: If the base price already ends in something like .95, 
        // the client prefers a smaller, more natural jump (e.g., 171.95 -> 174.95 is +3)
        $decimalPart = $basePrice - floor($basePrice);
        if ($decimalPart >= 0.90) {
            $uplift = max(2.0, $uplift - 2.0); // Reduce uplift to prefer smaller jumps for already clean prices
        }

        $targetPrice = $basePrice + $uplift;

        // 2. Map to the next clean tier (ending in 4.95 or 9.95)
        $baseInt = floor($targetPrice);
        $remainder = $baseInt % 5;
        $addition = (4 - $remainder);
        
        if ($addition < 0) {
            $addition += 5;
        }

        $cleanInt = $baseInt + $addition; 
        $finalPrice = $cleanInt + 0.95;

        // Safeguard: Ensure final price is strictly >= targetPrice
        if ($finalPrice < $targetPrice) {
            $finalPrice += 5;
        }

        // 3. Psychological boundary protection (IMPORTANT)
        // Avoid crossing key thresholds unnecessarily.
        $thresholds = [100, 150, 200, 250, 300, 400, 500];
        foreach ($thresholds as $t) {
            if ($basePrice < $t && $finalPrice > $t) {
                // We crossed a threshold. Pull back just below it (e.g. 150 -> 149.95)
                $alternativePrice = $t - 0.05; 
                
                // Only pull back if it still guarantees an uplift (no loss vs base price)
                if ($alternativePrice > $basePrice) {
                    $finalPrice = $alternativePrice;
                }
                break;
            }
        }

        return round($finalPrice, 2);
    }
}
