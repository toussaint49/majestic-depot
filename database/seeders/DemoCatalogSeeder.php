<?php

namespace Database\Seeders;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Depot;
use App\Models\PackagingType;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\PackagingService;
use App\Services\StockService;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * Catalogue de démonstration (environnement local uniquement) :
 * php artisan db:seed --class=DemoCatalogSeeder
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(StockService $stock, PackagingService $packaging, CurrentTenant $current): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Catalogue de démonstration ignoré en production.');

            return;
        }

        $tenant = Tenant::where('slug', 'majestic')->firstOrFail();

        $current->run($tenant, function () use ($stock, $packaging) {
            $depot = Depot::orderBy('id')->firstOrFail();
            $beers = Category::firstOrCreate(['name' => 'Bières']);
            $softs = Category::firstOrCreate(['name' => 'Boissons gazeuses']);
            $water = Category::firstOrCreate(['name' => 'Eaux']);

            $bv65 = PackagingType::firstOrCreate(['code' => 'BV65'], ['name' => 'Bouteille vide 65cl']);
            $bv30 = PackagingType::firstOrCreate(['code' => 'BV30'], ['name' => 'Bouteille vide 30cl']);
            $ca12 = PackagingType::firstOrCreate(['code' => 'CA12'], ['name' => 'Casier 12']);
            $ca24 = PackagingType::firstOrCreate(['code' => 'CA24'], ['name' => 'Casier 24']);
            // [bouteille vide, casier] par produit consigné
            $returnable = ['BB65' => [$bv65, $ca12], 'BR65' => [$bv65, $ca12], 'SO30' => [$bv30, $ca24]];

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
                    $u = $product->units()->create(['name' => $unit, 'factor' => $factor, 'price' => $price]);
                    if (isset($returnable[$ref])) {
                        [$emptyBottle, $emptyCrate] = $returnable[$ref];
                        $u->packagings()->create(['packaging_type_id' => $emptyBottle->id, 'quantity' => $factor]);
                        if ($factor > 1) {
                            $u->packagings()->create(['packaging_type_id' => $emptyCrate->id, 'quantity' => 1]);
                        }
                    }
                }
                $stock->receive($depot, $product, $qty, $cost, StockMovementType::Initial, reason: 'Stock de démonstration');
            }

            foreach ([['Bar La Joie', '690000001'], ['Snack Le Carrefour', '670000002'], ['Mme Ngo (boutique)', '650000003']] as [$name, $phone]) {
                Customer::firstOrCreate(['name' => $name], ['phone' => $phone]);
            }

            if ($packaging->available($depot, $ca12) === 0) {
                $packaging->receive($depot, $bv65, 60, reason: 'Stock de démonstration');
                $packaging->receive($depot, $ca12, 5, reason: 'Stock de démonstration');
            }
        });
    }
}
