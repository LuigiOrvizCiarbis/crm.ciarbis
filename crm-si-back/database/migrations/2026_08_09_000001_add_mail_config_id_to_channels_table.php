<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('mail_config_id')
                ->nullable()
                ->after('instagram_config_id')
                ->constrained('mail_configs')
                ->nullOnDelete();
        });

        // Message-ID crudo del email (RFC 5322). Va aparte de `external_id`
        // porque ese lleva unique global y el mismo Message-ID puede llegar a
        // dos casillas conectadas (un mail dirigido a soporte@ y a ventas@).
        // Sin esta separación la segunda copia no se podría guardar.
        // No es único: la repetición entre casillas es justamente lo esperado.
        Schema::table('messages', function (Blueprint $table) {
            $table->string('mail_message_id')->nullable()->after('external_id');
            $table->index('mail_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['mail_message_id']);
            $table->dropColumn('mail_message_id');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['mail_config_id']);
            $table->dropColumn('mail_config_id');
        });
    }
};
