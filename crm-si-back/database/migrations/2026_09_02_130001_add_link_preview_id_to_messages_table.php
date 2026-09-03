<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            // Sin índice único: muchos mensajes distintos pueden compartir la
            // misma preview cacheada (link_previews está deduplicado por URL).
            $table->foreignId('link_preview_id')->nullable()->after('media_filename')
                ->constrained('link_previews')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('link_preview_id');
        });
    }
};
