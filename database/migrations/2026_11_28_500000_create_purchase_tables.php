<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fournisseurs, achats (réception de marchandise) et règlements fournisseurs.
 *
 * Un achat enregistré est reçu : le stock monte et le CUMP se recalcule aussitôt.
 * Le reste à payer au fournisseur = total des achats validés − règlements valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('invoice_number', 60)->nullable(); // n° de la facture du fournisseur
            $table->string('status', 20)->default('validated'); // validated, cancelled
            $table->unsignedBigInteger('total');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'supplier_id', 'status']);
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('unit_name', 30);
            $table->unsignedInteger('factor');
            $table->unsignedInteger('quantity');          // dans le conditionnement acheté
            $table->unsignedBigInteger('base_quantity');  // en unité de base
            $table->unsignedBigInteger('unit_price');     // prix d'achat du conditionnement
            $table->unsignedBigInteger('line_total');
            $table->decimal('base_unit_cost', 14, 4);     // coût par unité de base
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('valid'); // valid, cancelled
            $table->string('reference')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['purchase_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['supplier_payments', 'purchase_lines', 'purchases', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
