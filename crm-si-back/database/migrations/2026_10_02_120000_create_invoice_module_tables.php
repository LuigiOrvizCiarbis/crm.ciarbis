<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->foreignId('whatsapp_channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->foreignId('whatsapp_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('reminder_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->string('timezone')->default('America/Argentina/Buenos_Aires');
            $table->unsignedSmallInteger('payment_term_days')->default(10);
            $table->unsignedTinyInteger('send_hour')->default(9);
            $table->json('reminder_days')->default('[0,3,7]');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('invoice_recurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('concept', 500);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('ARS');
            $table->string('interval_unit', 10);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(10);
            $table->date('next_occurrence_on')->nullable()->index();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'contact_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_recurrence_id')->nullable()->constrained('invoice_recurrences')->nullOnDelete();
            $table->string('number', 40);
            $table->string('concept', 500);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('ARS');
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable()->index();
            $table->string('status', 20)->default('draft')->index();
            $table->string('delivery_status', 20)->default('pending')->index();
            $table->string('delivery_error')->nullable();
            $table->json('issuer_snapshot')->nullable();
            $table->json('contact_snapshot')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_reminder_at')->nullable()->index();
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'contact_id', 'status']);
            $table->unique(['invoice_recurrence_id', 'issued_on']);
        });

        Schema::create('invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->date('paid_on');
            $table->string('method', 40)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id', 'reversed_at']);
        });

        Schema::create('invoice_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_events');
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_recurrences');
        Schema::dropIfExists('invoice_settings');
    }
};
