<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_default']);
        });

        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->foreignId('pipeline_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->foreignId('pipeline_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
            $table->index(['tenant_id', 'pipeline_id']);
        });

        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tenantId) {
            $pipelineId = DB::table('pipelines')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => 'Ventas',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('pipeline_stages')->where('tenant_id', $tenantId)->update(['pipeline_id' => $pipelineId]);
            DB::table('opportunities')->where('tenant_id', $tenantId)->update(['pipeline_id' => $pipelineId]);
        }

        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->foreignId('pipeline_id')->nullable(false)->change();
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->foreignId('pipeline_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'pipeline_id']);
            $table->dropConstrainedForeignId('pipeline_id');
        });

        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pipeline_id');
        });

        Schema::dropIfExists('pipelines');
    }
};
