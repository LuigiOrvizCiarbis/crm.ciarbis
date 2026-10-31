<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desconectar un canal purga sus credenciales (ChannelDisconnector), pero las
 * cuatro columnas de token/password nacieron NOT NULL. Sin esto, un canal
 * desconectado no puede quedar sin credenciales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table): void {
            $table->longText('bussines_token')->nullable()->change();
        });

        Schema::table('instagram_configs', function (Blueprint $table): void {
            $table->longText('page_access_token')->nullable()->change();
        });

        Schema::table('messenger_configs', function (Blueprint $table): void {
            $table->longText('page_access_token')->nullable()->change();
        });

        Schema::table('mail_configs', function (Blueprint $table): void {
            $table->longText('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table): void {
            $table->longText('bussines_token')->nullable(false)->change();
        });

        Schema::table('instagram_configs', function (Blueprint $table): void {
            $table->longText('page_access_token')->nullable(false)->change();
        });

        Schema::table('messenger_configs', function (Blueprint $table): void {
            $table->longText('page_access_token')->nullable(false)->change();
        });

        Schema::table('mail_configs', function (Blueprint $table): void {
            $table->longText('password')->nullable(false)->change();
        });
    }
};
