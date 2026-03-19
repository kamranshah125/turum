<?php

namespace App\Services;

class TurumPricingService
{
    /**
     * Apply the Smart Pricing logic (+ margin tier & .95 rounding).
     * 
     * Tiers:
     * €0 – €120: +2 to +5
     * €120 – €180: +5 to +10
     * €180+: +5 to +15
     * Avoid crossing: 100, 150, 200 unnecessarily.
     * 
     * @param float $basePrice The price after default Turum margin.
     * @return float
     */
    public function getPremiumPrice(float $basePrice): float
    {
        // If price is extremely low, no need to touch
        if ($basePrice <= 0) return $basePrice;

        // Determine min uplift based on tiers
        if ($basePrice <= 120) {
            $uplift = 2.0; // Client example 105.40 -> 109.95 is roughly +4.55. Let's aim for the next .95 after adding 2.
        } elseif ($basePrice <= 180) {
            $uplift = 5.0; // Minimum +5
        } else {
            $uplift = 5.0; // Minimum +5
        }

        // Add the minimum uplift
        $targetPrice = $basePrice + $uplift;

        // Round to the next .95
        $integerPart = floor($targetPrice);
        $decimalPart = $targetPrice - $integerPart;
        
        if ($decimalPart > 0.95) {
            // e.g. 104.99 -> next step is 105.95
            $finalPrice = $integerPart + 1.95;
        } else {
            // e.g. 104.20 -> next step is 104.95
            $finalPrice = $integerPart + 0.95;
        }

        // Psychological boundary protection:
        // "Avoid crossing key thresholds unnecessarily... 100, 150, 200"
        // Example: 147 -> 149.95 (Prefer). 147 -> 154.95 (Avoid).
        // If our initial $finalPrice crossed a threshold from below, but we could have stayed below it:
        $thresholds = [100, 150, 200, 250, 300];
        foreach ($thresholds as $t) {
            if ($basePrice < $t && $finalPrice > $t) {
                // We crossed a threshold. Can we stay below it and still have an uplift > 0?
                $alternativePrice = $t - 0.05; // e.g. 149.95
                if ($alternativePrice > $basePrice) {
                    $finalPrice = $alternativePrice;
                }
                break; // Handled the immediate boundary
            }
        }

        return round($finalPrice, 2);
    }
}
