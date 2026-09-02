<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            // null = nunca se evaluó ("unknown"); 'granted'/'denied' son los
            // únicos estados definitivos, ver App\Enums\MarketingConsentStatus.
            $table->string('marketing_consent_status', 16)->nullable()->index();
            // inbound_message | form | paper | phone | sms | whatsapp_optin_campaign | manual
            $table->string('marketing_consent_source', 32)->nullable();
            $table->timestampTz('marketing_consent_at')->nullable();
            $table->text('marketing_consent_evidence')->nullable();
        });

        // Backfill: un mensaje INBOUND es evidencia de que el contacto inició
        // la conversación, así que cuenta como consentimiento ya obtenido.
        // A propósito NO se usa "tiene conversación": hay contactos con
        // conversación creada sin que la persona haya escrito nunca, y esos
        // no tienen evidencia real de opt-in.
        //
        // Query builder en vez de UPDATE...FROM crudo: la sintaxis de subquery
        // correlacionada difiere entre Postgres y SQLite (usado en tests), y
        // este UPDATE corre una sola vez por instalación, no en caliente.
        DB::table('conversations as cv')
            ->join('messages as m', function ($join): void {
                $join->on('m.conversation_id', '=', 'cv.id')->where('m.direction', 'inbound');
            })
            ->selectRaw('cv.contact_id, MIN(m.created_at) as first_inbound')
            ->groupBy('cv.contact_id')
            ->orderBy('cv.contact_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('contacts')->where('id', $row->contact_id)->update([
                        'marketing_consent_status' => 'granted',
                        'marketing_consent_source' => 'inbound_message',
                        'marketing_consent_at' => $row->first_inbound,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn([
                'marketing_consent_status',
                'marketing_consent_source',
                'marketing_consent_at',
                'marketing_consent_evidence',
            ]);
        });
    }
};
