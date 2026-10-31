<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_comments', function (Blueprint $table): void {
            $table->timestampTz('private_reply_claimed_at')->nullable()->after('private_reply_deadline');
            $table->index('private_reply_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_comments', function (Blueprint $table): void {
            $table->dropIndex(['private_reply_claimed_at']);
            $table->dropColumn('private_reply_claimed_at');
        });
    }
};
