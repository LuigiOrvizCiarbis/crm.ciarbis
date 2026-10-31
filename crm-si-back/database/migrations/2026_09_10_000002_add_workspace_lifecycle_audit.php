<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('status')->default('active')->index();
            $table->timestamp('deletion_scheduled_at')->nullable()->index();
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::create('workspace_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_audit_events');
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['status', 'deletion_scheduled_at', 'deactivated_at']);
        });
    }
};
