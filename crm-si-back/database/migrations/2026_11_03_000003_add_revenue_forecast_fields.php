<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->unsignedTinyInteger('win_probability')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('revenue_goal_monthly', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->dropColumn('win_probability');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('revenue_goal_monthly');
        });
    }
};
