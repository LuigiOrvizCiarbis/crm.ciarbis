<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sin FK ni índice a propósito: la cita se lee por PK (messages.id) vía
     * eager-load, y una self-FK en esta tabla grande exigiría un índice extra
     * para que los hard deletes de mensajes no hagan seq-scan. Un id colgado
     * simplemente no renderiza la cita.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('reply_to_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('reply_to_message_id');
        });
    }
};
