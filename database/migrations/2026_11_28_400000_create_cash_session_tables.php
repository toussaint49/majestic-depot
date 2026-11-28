<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions de caisse et clôture journalière.
 *
 * Tout encaissement est rattaché à la session ouverte du dépôt. À la clôture, le
 * solde théorique par compte est figé, comparé au comptage réel, et l'écart validé
 * est passé en écriture : le grand livre reste égal à l'argent réellement détenu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('require_cash_session')->default(true)->after('default_credit_days');
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('status', 20)->default('open'); // open, closed, validated
            $table->unsignedBigInteger('opening_float');    // espèces dans le tiroir à l'ouverture
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'depot_id', 'status']);
        });

        Schema::create('cash_session_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('expected');   // théorique, figé à la clôture
            $table->bigInteger('counted');    // compté / relevé
            $table->bigInteger('difference'); // compté − théorique
            $table->timestamps();
            $table->unique(['cash_session_id', 'payment_account_id']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_account_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);       // deposit (apport), withdrawal (retrait)
            $table->unsignedBigInteger('amount');
            $table->string('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::table('account_transactions', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('payment_account_id')->constrained()->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('depot_id')->nullable()->after('tenant_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('depot_id'));
        Schema::table('account_transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('cash_session_id'));
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_session_counts');
        Schema::dropIfExists('cash_sessions');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('require_cash_session'));
    }
};
