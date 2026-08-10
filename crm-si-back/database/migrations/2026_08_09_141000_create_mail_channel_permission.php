<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('channels.connect_mail', 'web');
    }

    public function down(): void
    {
        Permission::where('name', 'channels.connect_mail')->where('guard_name', 'web')->delete();
    }
};
