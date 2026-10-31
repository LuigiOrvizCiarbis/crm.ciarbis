<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->unique();
            $table->boolean('enabled')->default(false);
            // Keys de ContactField, no columnas propias: cada tenant nombra
            // sus campos distinto (ver Fase 3 del plan de cobranzas). Se
            // valida contra ContactField en escritura (BillingConfigRequest),
            // no acá — un guard de FK sobre una columna string no aplica.
            $table->string('due_date_field_key');
            $table->string('status_field_key');
            $table->string('overdue_cycles_field_key');
            // Nullable: si es null, el roll-cycle no excluye a nadie por
            // gestión externa (WebhookContactUpsertService::L129 escribe
            // source='webhook' fijo para todos los webhooks del tenant, así
            // que excluir por source dejaría también fuera los contactos de
            // un webhook de leads — la marca va por contacto, en un campo
            // Boolean propio).
            $table->string('externally_managed_field_key')->nullable();
            $table->string('cycle_unit')->default('months');
            $table->unsignedInteger('cycle_length')->default(1);
            $table->string('timezone');
            // Días de gracia antes de que billing:roll-cycle avance el
            // vencimiento: tiene que ser mayor que el offset del reclamo
            // `after` más tardío que se provisione, o ese reclamo se cancela
            // antes de salir (ver Fase 5, EvaluateAutomationEventJob::L77-79).
            $table->unsignedInteger('grace_days')->default(3);
            $table->timestampTz('last_rolled_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_configs');
    }
};
