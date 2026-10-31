<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_template_provisionings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('whatsapp_config_id')->constrained('whatsapp_configs')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('state')->default('queued');
            $table->foreignId('invoice_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('reminder_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->text('invoice_error')->nullable();
            $table->text('reminder_error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'whatsapp_config_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_template_provisionings');
    }
};
