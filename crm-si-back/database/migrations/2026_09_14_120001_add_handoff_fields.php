<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_notification_phone', 40)->nullable()->after('phone');
            $table->string('whatsapp_notification_phone_normalized', 32)->nullable()->after('whatsapp_notification_phone')->index();
            $table->timestamp('whatsapp_notification_verified_at')->nullable()->after('whatsapp_notification_phone_normalized');
            $table->timestamp('whatsapp_notification_opted_in_at')->nullable()->after('whatsapp_notification_verified_at');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('handoff_responsible_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('whatsapp_configs', function (Blueprint $table) {
            $table->boolean('is_on_biz_app')->nullable()->after('groups_platform_type');
            $table->timestamp('is_on_biz_app_checked_at')->nullable()->after('is_on_biz_app');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table) {
            $table->dropColumn(['is_on_biz_app', 'is_on_biz_app_checked_at']);
        });
        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handoff_responsible_user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_notification_phone',
                'whatsapp_notification_phone_normalized',
                'whatsapp_notification_verified_at',
                'whatsapp_notification_opted_in_at',
            ]);
        });
    }
};
