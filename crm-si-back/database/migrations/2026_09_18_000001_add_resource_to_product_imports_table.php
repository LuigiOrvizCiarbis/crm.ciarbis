<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_imports', function (Blueprint $table): void {
            $table->string('resource', 30)->default('products')->after('tenant_id');
            $table->index(['tenant_id', 'resource', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('product_imports', function (Blueprint $table): void {
            $table->dropIndex(['product_imports_tenant_id_resource_status_index']);
            $table->dropColumn('resource');
        });
    }
};
