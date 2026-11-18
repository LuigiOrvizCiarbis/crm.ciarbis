<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->json('published_version')->nullable()->after('published_at');
        });

        // Un formulario que no está en borrador corre exactamente lo guardado.
        DB::table('whatsapp_flows')
            ->whereIn('status', ['PUBLISHED', 'THROTTLED', 'BLOCKED', 'DEPRECATED'])
            ->whereNotNull('flow_json')
            ->orderBy('id')
            ->each(function (object $flow): void {
                DB::table('whatsapp_flows')->where('id', $flow->id)->update([
                    'published_version' => json_encode([
                        'flow_json' => $flow->flow_json,
                        'builder_definition' => $flow->builder_definition !== null ? json_decode($flow->builder_definition, true) : null,
                        'field_mappings' => $flow->field_mappings !== null ? json_decode($flow->field_mappings, true) : [],
                        'data_api_version' => $flow->data_api_version,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->dropColumn('published_version');
        });
    }
};
