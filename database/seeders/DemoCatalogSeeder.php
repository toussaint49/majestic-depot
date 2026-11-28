<?php

namespace Database\Seeders;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Depot;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\StockService;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * Catalogue de démonstration (environnement local uniquement) :
 * php artisan db:seed --class=DemoCatalogSeeder
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(StockService $stock, CurrentTenant $current): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Catalogue de démonstration ignoré en production.');

            return;
        }

        $tenant = Tenant::where('slug', 'majestic')->firstOrFail();

        $current->run($tenant, function () use ($stock) {
            $depot = Depot::orderBy('id')->firstOrFail();
            $beers = Category::firstOrCreate(['name' => 'Bières']);
            $softs = Category::firstOrCreate(['name' => 'Boissons gazeuses']);
            $water = Category::firstOrCreate(['name' => 'Eaux']);

            $items = [
                [$beers, 'Bière blonde 65cl', 'BB65', [['Bouteille', 1, 650], ['Casier', 12, 7500]], 520, 120],
                [$beers, 'Bière brune 65cl', 'BR65', [['Bouteille', 1, 700], ['Casier', 12, 8100]], 560, 48],
                [$softs, 'Soda orange 30cl', 'SO30', [['Bouteille', 1, 400], ['Casier', 24, 9000]], 310, 96],
                [$water, 'Eau minérale 1,5L', 'EM15', [['Bouteille', 1, 400], ['Pack', 6, 2200]], 280, 60],
            ];

            foreach ($items as [$category, $name, $ref, $units, $cost, $qty]) {
                if (Product::where('reference', $ref)->exists()) {
                    continue;
                }
                $product = Product::create(['category_id' => $category->id, 'name' => $name, 'reference' => $ref, 'alert_threshold' => 24]);
                foreach ($units as [$unit, $factor, $price]) {
                    $product->units()->create(['name' => $unit, 'factor' => $factor, 'price' => $price]);
                }
                $stock->receive($depot, $product, $qty, $cost, StockMovementType::Initial, reason: 'Stock de démonstration');
            }
        });
    }
}
