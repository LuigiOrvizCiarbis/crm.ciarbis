<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un grupo de WhatsApp no tiene un contacto único, así que contact_id deja
     * de ser obligatorio. Sin doctrine/dbal instalado, ->change() no está
     * disponible: se usa DB::statement en Postgres (driver real de dev/prod)
     * y se recrea la columna en SQLite (driver de los tests), que no soporta
     * ALTER COLUMN DROP NOT NULL.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE conversations ALTER COLUMN contact_id DROP NOT NULL');
        } else {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->foreignId('contact_id')->nullable()->change();
            });
        }

        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('kind')->default('direct')->after('contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE conversations ALTER COLUMN contact_id SET NOT NULL');
        } else {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->foreignId('contact_id')->nullable(false)->change();
            });
        }
    }
};
