<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PostgreSQL cannot create or drop indexes concurrently inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // The contacts index serves the tenant filter and default listing order.
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contacts_tenant_updated_at_idx ON contacts (tenant_id, updated_at DESC)');

            // These are the lookup paths used by Contact::visibleTo()'s EXISTS clauses.
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_tenant_contact_idx ON conversations (tenant_id, contact_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS opportunities_tenant_contact_assigned_idx ON opportunities (tenant_id, contact_id, assigned_to)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS opportunities_tenant_id_contact_id_index');

            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->index(['tenant_id', 'updated_at'], 'contacts_tenant_updated_at_idx');
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->index(['tenant_id', 'contact_id'], 'conversations_tenant_contact_idx');
        });
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->index(['tenant_id', 'contact_id', 'assigned_to'], 'opportunities_tenant_contact_assigned_idx');
            $table->dropIndex('opportunities_tenant_id_contact_id_index');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contacts_tenant_updated_at_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_tenant_contact_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS opportunities_tenant_contact_assigned_idx');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS opportunities_tenant_id_contact_id_index ON opportunities (tenant_id, contact_id)');

            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_tenant_updated_at_idx');
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_tenant_contact_idx');
        });
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropIndex('opportunities_tenant_contact_assigned_idx');
            $table->index(['tenant_id', 'contact_id'], 'opportunities_tenant_id_contact_id_index');
        });
    }
};
