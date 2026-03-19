<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$pricing = new \App\Services\TurumPricingService();
$usp = new \App\Services\TurumUspService();

echo "--- PRICING TEST ---\n";
$prices = [105.40, 139, 160, 189, 210, 147, 198];
foreach ($prices as $p) {
    echo "Base: $p -> Premium: " . $pricing->getPremiumPrice($p) . "\n";
}

echo "\n--- USP TEST ---\n";
$products = [
    'Nike Air Max 1 Parra',
    'Nike Dunk Low Retro', // Should be empty (out of scope)
    'New Balance 2002R Protection Pack',
    'Adidas Samba OG',
    'ASICS Gel-Kayano 14' // Should be empty but not error (in scope but no data)
];

foreach ($products as $prod) {
    echo "Product: $prod\n";
    $html = $usp->getUspHtml($prod, '');
    if (empty($html)) {
        echo "  [No USPs matched or out of scope]\n";
    } else {
        echo "  " . substr(str_replace(["\n", "\r"], "", strip_tags($html)), 0, 100) . "...\n";
    }
}
echo "--- END ---\n";
