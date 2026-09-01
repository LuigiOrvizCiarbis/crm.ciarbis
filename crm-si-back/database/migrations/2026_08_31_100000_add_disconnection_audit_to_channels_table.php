<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->timestamp('disconnected_at')->nullable()->after('status');
            $table->foreignId('disconnected_by')->nullable()->after('disconnected_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('disconnected_by');
            $table->dropColumn('disconnected_at');
        });
    }
};
