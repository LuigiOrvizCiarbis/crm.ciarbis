<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groups API exige Official Business Account y rechaza números en
     * coexistencia (is_on_biz_app=true). Se persiste en la config (no cache
     * de Laravel) porque se comparte entre N canales y sobrevive a un flush,
     * mismo patrón que contact_sync_* / meta_app_usage_*.
     */
    public function up(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table): void {
            $table->string('groups_eligibility_status')->nullable();
            $table->boolean('groups_is_oba')->nullable();
            $table->string('groups_platform_type')->nullable();
            $table->timestampTz('groups_eligibility_checked_at')->nullable();
            $table->text('groups_eligibility_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table): void {
            $table->dropColumn([
                'groups_eligibility_status',
                'groups_is_oba',
                'groups_platform_type',
                'groups_eligibility_checked_at',
                'groups_eligibility_error',
            ]);
        });
    }
};
