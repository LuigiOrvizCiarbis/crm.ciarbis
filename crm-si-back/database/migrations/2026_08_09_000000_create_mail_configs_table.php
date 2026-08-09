<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            // Casilla conectada. También se usa como usuario IMAP/SMTP.
            // Unique por tenant: una casilla = una config dentro del equipo.
            $table->string('email_address');
            $table->string('from_name')->nullable();
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption')->default('ssl'); // ssl, tls, none
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption')->default('ssl'); // ssl, tls, none
            // Contraseña o app-password. Encriptada con Crypt.
            $table->longText('password');
            // Cursor de sync IMAP (polling). Si UIDVALIDITY cambia, se resincroniza.
            $table->unsignedBigInteger('last_uid')->nullable();
            $table->string('uidvalidity')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('ai_autoreply_default')->default(false);
            $table->text('ai_system_prompt')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_configs');
    }
};
