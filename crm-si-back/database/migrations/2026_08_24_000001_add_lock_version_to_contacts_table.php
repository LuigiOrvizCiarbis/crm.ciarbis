<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador de versión para concurrencia optimista al confirmar una
     * extracción.
     *
     * No se usa updated_at: la tabla usa timestamps() con precisión de segundo,
     * así que dos escrituras dentro del mismo segundo comparten valor y el
     * chequeo dejaría pasar una sobreescritura. Un contador cambia siempre.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('lock_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
