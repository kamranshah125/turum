<?php

namespace App\Services;

class TurumUspService
{
    protected $usps = [
        'new balance' => [
            'general' => [
                'Premium comfort & demping',
                'Hoogwaardige materialen',
                'Perfect voor dagelijks gebruik',
                'Populaire lifestyle modellen'
            ],
            'models' => [
                '2002r' => ['Beste prijs-kwaliteit NB', 'Premium suede & mesh', 'All-day comfort', 'Veelzijdige sneaker'],
                '9060'  => ['Futuristisch design', 'Chunky silhouette', 'Ultiem draagcomfort', 'Statement sneaker'],
                '1906'  => ['Performance geïnspireerd', 'Ademend & lichtgewicht', 'Technische look', 'Comfort voor lange dagen'],
                '1000'  => ['Y2K runner stijl', 'Retro comeback model', 'Licht en flexibel', 'Uniek design'],
                '740'   => ['Minimalistisch design', 'Clean uitstraling', 'Makkelijk te combineren', 'Everyday sneaker'],
                '550'   => ['Basketball heritage', 'Tijdloos design', 'Stevige constructie', 'Streetwear essential']
            ]
        ],
        'adidas' => [
            'general' => [
                'Tijdloze designs',
                'Hoogwaardige afwerking',
                'Makkelijk te combineren',
                'Streetwear favorieten'
            ],
            'models' => [
                'samba'            => ['Iconisch model', 'Minimalistische look', 'Veelzijdig te stylen', 'Populaire keuze'],
                'gazelle'          => ['Premium suede upper', 'Klassieke uitstraling', 'Casual & clean', 'Comfortabele fit'],
                'handball spezial' => ['Vintage uitstraling', 'Unieke colorways', 'Premium materialen', 'Trending model'],
                'campus'           => ['Skater geïnspireerd', 'Extra comfort padding', 'Robuuste build', 'Streetwear staple'],
                'sl72'             => ['Lichtgewicht design', 'Retro runner', 'Slanke silhouette', 'Unieke look'],
                'sl-72'            => ['Lichtgewicht design', 'Retro runner', 'Slanke silhouette', 'Unieke look'],
                'yeezy'            => ['Innovatief design', 'Premium comfort', 'Limited modellen', 'High demand']
            ]
        ],
        'nike' => [
            'general' => [
                'Iconische designs',
                'Comfort & performance',
                'Hoogwaardige materialen',
                'Streetwear favorieten'
            ],
            'models' => [
                'p-6000'       => ['Tech runner stijl', 'Ademend mesh', 'Sportieve look', 'Lichtgewicht comfort'],
                'air max 95'   => ['Iconische demping', 'Gelaagd design', 'Premium afwerking', 'Statement sneaker'],
                'air max 1'    => ['Culturele waarde', 'Tijdloos design', 'Zichtbare Air unit', 'Dagelijks comfort'],
                'air max 90'   => ['Culturele waarde', 'Tijdloos design', 'Zichtbare Air unit', 'Dagelijks comfort']
            ]
        ],
        'asics' => [
            'general' => [
                'Bekend om ultiem comfort & support',
                'Geavanceerde GEL™ demping',
                'Performance meets lifestyle',
                'Sterk in retro runner designs'
            ],
            'models' => [
                'gel-nyc'    => ['Moderne retro runner', 'GEL™ demping voor comfort', 'Mix van heritage & tech', 'Perfect voor dagelijks gebruik'],
                'gel-kayano' => ['Premium stability sneaker', 'Maximale ondersteuning', 'High-end comfort technologie', 'Ideaal voor lange dagen'],
                'gel-1130'   => ['Retro running aesthetic', 'Lichtgewicht & ademend', 'Populaire streetwear keuze', 'Comfortabele allrounder'],
                'gt-2160'    => ['Y2K running design', 'Strakke, technische look', 'GEL™ cushioning', 'Trendgevoelig model'],
                'skyhand'    => ['Vintage court style', 'Clean & minimalistisch', 'Suede & premium feel', 'Makkelijk te stylen']
            ]
        ]
    ];

    /**
     * Get HTML formatted USPs for a product based on its name and brand.
     */
    public function getUspHtml(string $productName, string $brandInput = ''): string
    {
        $nameLower = strtolower(trim($productName));
        $brandLower = strtolower(trim($brandInput));

        // Determine brand if not strictly provided
        $detectedBrand = '';
        foreach (array_keys($this->usps) as $brandKey) {
            if (str_contains($nameLower, $brandKey) || str_contains($brandLower, $brandKey)) {
                $detectedBrand = $brandKey;
                break;
            }
        }

        // If not a target brand, or if Nike but not a target model, return empty
        if (!$detectedBrand) {
            return '';
        }

        // Nike strict scope check: Only Air Max 1, P-6000, Air Max 95
        if ($detectedBrand === 'nike') {
            $allowedNikeModels = ['p-6000', 'air max 95', 'air max 1', 'air max 90']; // Allowed 1/90 combo
            $isAllowed = false;
            foreach ($allowedNikeModels as $m) {
                if (str_contains($nameLower, $m)) {
                    $isAllowed = true;
                    break;
                }
            }
            if (!$isAllowed) {
                return ''; // Not in scope for Nike
            }
        }

        // Determine specific model
        $detectedModel = '';
        foreach (array_keys($this->usps[$detectedBrand]['models']) as $modelKey) {
            if (str_contains($nameLower, $modelKey)) {
                $detectedModel = $modelKey;
                break;
            }
        }

        // Fetch 2 General, 2 Specific
        $generalPool = $this->usps[$detectedBrand]['general'];
        $specificPool = $detectedModel ? $this->usps[$detectedBrand]['models'][$detectedModel] : [];

        $selectedUsps = [];

        // If we have specific USPs, take up to 2
        if (!empty($specificPool)) {
            $selectedUsps = array_slice($specificPool, 0, 2);
        }

        // Fill the rest (up to 4 total) with general USPs
        if (!empty($generalPool)) {
            $needed = 4 - count($selectedUsps);
            if ($needed > 0) {
                $selectedUsps = array_merge($selectedUsps, array_slice($generalPool, 0, $needed));
            }
        }

        if (empty($selectedUsps)) {
            return '';
        }

        return $this->formatHtml($selectedUsps);
    }

    protected function formatHtml(array $lines): string
    {
        // SVG Checkmark, #777 text, small text
        $html = '<div class="turum-dynamic-usps" style="margin-top: 15px; margin-bottom: 15px;">';
        $html .= '<ul style="list-style: none; padding-left: 0; color: #777; font-size: 0.9em;">';
        
        $svg = '<svg style="width: 14px; height: 14px; margin-right: 8px; vertical-align: middle; fill: #777;" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
        
        foreach ($lines as $line) {
            $html .= '<li style="margin-bottom: 6px; display: flex; align-items: center;">' . $svg . '<span>' . htmlspecialchars($line) . '</span></li>';
        }
        
        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }
}
