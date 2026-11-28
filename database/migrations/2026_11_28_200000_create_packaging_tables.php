<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emballages consignés (bouteilles vides, casiers…) : sous-système indépendant du
 * stock de boissons. Pas de caution ni de solde par client : à la vente, le client
 * échange ses vides ; le dépôt suit son propre stock de vides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');                 // Bouteille vide 65cl, Casier 12…
            $table->string('code', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        // Emballages échangés pour 1 conditionnement vendu (ex. Casier → 12 bouteilles vides + 1 casier).
        Schema::create('product_unit_packagings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('packaging_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['product_unit_id', 'packaging_type_id']);
        });

        Schema::create('packaging_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('packaging_type_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['depot_id', 'packaging_type_id']);
        });

        Schema::create('packaging_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('packaging_type_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->bigInteger('quantity');          // signé
            $table->bigInteger('quantity_after');
            $table->nullableMorphs('source');
            $table->uuid('transfer_group')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'depot_id', 'packaging_type_id', 'occurred_at'], 'pkg_mov_lookup');
        });
    }

    public function down(): void
    {
        foreach (['packaging_movements', 'packaging_levels', 'product_unit_packagings', 'packaging_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
