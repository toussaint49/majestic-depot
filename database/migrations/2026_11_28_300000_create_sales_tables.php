<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventes, clients et encaissements.
 *
 * Montants en entiers FCFA. Le montant d'une vente, l'argent réellement encaissé
 * et le reste dû sont distincts : une vente à crédit n'est jamais comptée comme encaissée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('default_credit_days')->default(14)->after('tax_number');
        });

        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('kind', 20);          // cash, mtn_momo, orange_money, bank, other
            $table->string('number')->nullable(); // n° marchand, n° de compte…
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        // Grand livre des comptes de paiement : tout encaissement / décaissement, immuable.
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_account_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->bigInteger('amount');         // signé : + entrée d'argent, − sortie
            $table->nullableMorphs('source');
            $table->string('label')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'payment_account_id', 'occurred_at'], 'acct_tx_lookup');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('credit_days')->nullable(); // null = délai par défaut de l'entreprise
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('status', 20)->default('validated'); // validated, cancelled
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('paid_at_sale');   // encaissé au moment de la vente
            $table->unsignedBigInteger('credit_amount');  // reste dû à la vente
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'customer_id', 'status']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            // Instantanés : l'historique ne change pas si le catalogue change.
            $table->string('product_name');
            $table->string('unit_name', 30);
            $table->unsignedInteger('factor');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity');        // dans le conditionnement vendu
            $table->unsignedBigInteger('base_quantity'); // en unité de base
            $table->unsignedBigInteger('line_total');
            $table->decimal('unit_cost', 14, 4)->default(0); // CUMP par unité de base à la sortie
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete(); // vente d'origine si payé à la vente
            $table->foreignId('payment_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('valid'); // valid, cancelled
            $table->string('reference')->nullable();         // n° de transaction Mobile Money…
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->index(['sale_id']);
        });
    }

    public function down(): void
    {
        foreach (['payment_allocations', 'payments', 'sale_lines', 'sales', 'customers', 'account_transactions', 'payment_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('default_credit_days'));
    }
};
