<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS products_search_spanish_idx ON products USING gin ((to_tsvector('spanish'::regconfig, coalesce(name, '') || ' ' || coalesce(description, '')) || to_tsvector('spanish'::regconfig, coalesce(custom_data, '{}'::jsonb)))) WHERE is_active = true");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS products_name_trgm_idx ON products USING gin (name gin_trgm_ops) WHERE is_active = true');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_name_trgm_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_search_spanish_idx');
    }
};
