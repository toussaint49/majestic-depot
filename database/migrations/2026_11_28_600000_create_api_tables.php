<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accès programmatique (Claude) : jetons par entreprise et clés d'idempotence,
 * pour qu'une même facture ou fiche envoyée deux fois ne soit enregistrée qu'une fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // les opérations sont faites en son nom
            $table->string('name');
            $table->char('token_hash', 64)->unique();  // sha256 ; le jeton en clair n'est jamais stocké
            $table->string('hint', 12);                // derniers caractères, pour l'identifier
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('key', 100);
            $table->string('route', 100);
            $table->unsignedSmallInteger('status');
            $table->longText('response');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_tokens');
    }
};
