<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue (catégories, marques, produits, conditionnements) et stocks
 * (soldes par dépôt, mouvements, inventaires).
 *
 * Toutes les quantités sont des entiers exprimés en unité de base (ex. bouteille).
 * Les prix sont des entiers en FCFA ; le coût moyen (CUMP) garde 4 décimales
 * pour éviter la dérive des arrondis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('reference', 50);
            $table->string('barcode', 50)->nullable();
            $table->string('base_unit_name', 30)->default('Bouteille');
            $table->unsignedInteger('alert_threshold')->default(0); // en unité de base
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'barcode']);
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);              // Bouteille, Casier, Pack…
            $table->unsignedInteger('factor');       // nb d'unités de base (Bouteille = 1, Casier = 12)
            $table->unsignedBigInteger('price')->default(0); // prix de vente FCFA pour ce conditionnement
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'name']);
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity')->default(0);           // unité de base
            $table->decimal('avg_cost', 14, 4)->default(0);       // CUMP par unité de base
            $table->timestamps();
            $table->unique(['depot_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->bigInteger('quantity');                       // signé : + entrée, − sortie
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->bigInteger('quantity_after');
            $table->decimal('avg_cost_after', 14, 4);
            $table->nullableMorphs('source');                     // vente, achat, inventaire…
            $table->uuid('transfer_group')->nullable();           // relie les deux côtés d'un transfert
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');                     // date réelle (saisie différée possible)
            $table->timestamp('created_at')->useCurrent();        // date de saisie
            $table->index(['tenant_id', 'depot_id', 'product_id', 'occurred_at']);
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('status', 20)->default('draft');      // draft, validated, cancelled
            $table->timestamp('counted_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('inventory_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->bigInteger('counted_quantity');               // unité de base
            $table->bigInteger('expected_quantity')->nullable();  // figé à la validation
            $table->bigInteger('difference')->nullable();         // compté − attendu
            $table->decimal('unit_cost', 14, 4)->nullable();      // CUMP au moment de la validation
            $table->timestamps();
            $table->unique(['inventory_id', 'product_id']);
        });

        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('key', 30);
            $table->string('prefix', 20);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['sequences', 'inventory_lines', 'inventories', 'stock_movements', 'stock_levels', 'product_units', 'products', 'brands', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
