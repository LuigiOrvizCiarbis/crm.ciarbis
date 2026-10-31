<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sin actingAs en setUp: TenantScope filtra por el usuario autenticado y estos
 * tests crean varios tenants después de viajar en el tiempo. Las consultas van
 * sin global scopes; solo los tests que pegan a la API autentican.
 */
class DefaultPipelineStagesTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        ['Lead/Prospecto', '#3beaf7'],
        ['Interesado', '#e6fd72'],
        ['Contactar Diciembre', '#e6fd72'],
        ['1era Reunión', '#fcdc69'],
        ['2da Reunión', '#dfc35d'],
        ['Negociación', '#e4992f'],
        ['Cierre', '#9dff8a'],
        ['Oferta/Adelanto/Reserva', '#78e25a'],
        ['Cliente Convertido/Ganado', '#5be17d'],
        ['Partner/Colega/Proveedor', '#bb3bf7'],
        ['Perdido/No le interesa', '#fe165b'],
    ];

    private const TEMPLATE = 'Contactar {mes_siguiente}';

    private const LEGACY_NAMES = ['Capturados', 'Calificados', 'Negociación', 'Cierre', 'Ganado', 'Perdido'];

    public function test_new_tenant_gets_the_eleven_default_stages(): void
    {
        $this->travelToArgentina('2026-11-15 12:00');
        $tenant = $this->createTenantWithRoles();

        $stages = $this->stagesOf($tenant);

        $this->assertSame(
            self::EXPECTED,
            $stages->map(fn (PipelineStage $stage) => [$stage->name, $stage->color])->all(),
        );
        $this->assertSame(range(1, 11), $stages->pluck('sort_order')->all());
        $this->assertSame(
            [null, null, self::TEMPLATE, null, null, null, null, null, null, null, null],
            $stages->pluck('name_template')->all(),
        );
        $this->assertSame('Lead/Prospecto', $this->defaultPipeline($tenant)->entryStage()->name);
        $this->assertSame(1, $stages->filter(fn (PipelineStage $stage) => (bool) $stage->is_default)->count());
    }

    public function test_month_stage_renames_on_first_day_of_month(): void
    {
        $this->travelToArgentina('2026-12-31 23:00');
        $tenant = $this->createTenantWithRoles('A');
        $other = $this->createTenantWithRoles('B');
        $this->assertSame('Contactar Enero', $this->monthStage($tenant)->name);

        // En B el usuario ya la había renombrado: sin plantilla, no se toca.
        $this->monthStage($other)->update(['name_template' => null]);

        $this->travelToArgentina('2027-01-01 00:00');
        $this->artisan('pipeline-stages:refresh-month-names')
            ->expectsOutput('1 etapas renombradas.')
            ->assertSuccessful();

        $this->assertSame('Contactar Febrero', $this->monthStage($tenant)->name);
        $this->assertSame(self::TEMPLATE, $this->monthStage($tenant)->name_template);
        $this->assertSame('Contactar Enero', $this->stagesOf($other)->firstWhere('sort_order', 3)->name);

        $this->artisan('pipeline-stages:refresh-month-names')
            ->expectsOutput('0 etapas renombradas.')
            ->assertSuccessful();
    }

    public function test_manual_rename_stops_monthly_update(): void
    {
        $this->travelToArgentina('2026-11-15 12:00');
        $tenant = $this->createTenantWithRoles();
        $stage = $this->monthStage($tenant);
        $this->actingAsOwnerOf($tenant);

        $this->putJson("/api/pipeline-stages/{$stage->id}", ['name' => 'Llamar más adelante', 'color' => '#e6fd72'])
            ->assertOk();
        $this->assertNull($stage->fresh()->name_template);

        $this->travelToArgentina('2026-12-01 00:00');
        $this->artisan('pipeline-stages:refresh-month-names')->assertSuccessful();

        $this->assertSame('Llamar más adelante', $stage->fresh()->name);
    }

    public function test_color_only_update_keeps_template_after_monthly_rename(): void
    {
        $this->travelToArgentina('2026-12-31 23:00');
        $tenant = $this->createTenantWithRoles();
        $stage = $this->monthStage($tenant);
        $this->actingAsOwnerOf($tenant);

        // El gestor quedó abierto con "Contactar Enero" y el comando renombró
        // a "Contactar Febrero": el frontend manda solo el color.
        $this->travelToArgentina('2027-01-01 00:00');
        $this->artisan('pipeline-stages:refresh-month-names')->assertSuccessful();

        $this->putJson("/api/pipeline-stages/{$stage->id}", ['color' => '#123456'])
            ->assertOk()
            ->assertJsonPath('name', 'Contactar Febrero');

        $stage->refresh();
        $this->assertSame(self::TEMPLATE, $stage->name_template);
        $this->assertSame('#123456', $stage->color);

        // Reenviar el nombre vigente tampoco cuenta como renombre.
        $this->putJson("/api/pipeline-stages/{$stage->id}", ['name' => 'Contactar Febrero', 'color' => '#654321'])
            ->assertOk();
        $this->assertSame(self::TEMPLATE, $stage->fresh()->name_template);
    }

    public function test_upgrade_migration_converts_only_untouched_default_pipelines(): void
    {
        $this->travelToArgentina('2026-11-15 12:00');

        // A: etapas del hook viejo (sin color => default de la columna).
        $hook = $this->createTenantWithRoles('Hook');
        $hookIds = $this->replaceWithLegacyStages($hook, array_fill(0, 6, '#3B82F6'));
        $conversation = $this->conversationIn($hook, $hookIds['Calificados']);

        // Paleta del seeder viejo, en minúsculas: sigue siendo de fábrica.
        $seeded = $this->createTenantWithRoles('Seeder');
        $seededIds = $this->replaceWithLegacyStages($seeded, ['#3b82f6', '#eab308', '#f97316', '#8b5cf6', '#22c55e', '#ef4444']);

        // B: una etapa extra.
        $extra = $this->createTenantWithRoles('Extra');
        $this->replaceWithLegacyStages($extra, array_fill(0, 6, '#3B82F6'));
        DB::table('pipeline_stages')->insert([
            'tenant_id' => $extra->id,
            'pipeline_id' => $this->defaultPipeline($extra)->id,
            'name' => 'Seguimiento',
            'color' => '#3B82F6',
            'sort_order' => 7,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // C: mismos nombres, pero recoloreada a mano.
        $recolored = $this->createTenantWithRoles('Recolored');
        $this->replaceWithLegacyStages($recolored, ['#3B82F6', '#3B82F6', '#ABCDEF', '#3B82F6', '#3B82F6', '#3B82F6']);

        $migration = require database_path('migrations/2026_10_31_000001_upgrade_untouched_default_pipeline_stages.php');
        $migration->up();

        foreach ([$hook, $seeded] as $tenant) {
            $stages = $this->stagesOf($tenant);
            $this->assertSame(self::EXPECTED, $stages->map(fn (PipelineStage $stage) => [$stage->name, $stage->color])->all());
            $this->assertSame(range(1, 11), $stages->pluck('sort_order')->all());
            $this->assertSame(self::TEMPLATE, $stages->firstWhere('sort_order', 3)->name_template);
            $this->assertSame('Lead/Prospecto', $this->defaultPipeline($tenant)->entryStage()->name);
        }

        // Ids conservados: lo que apuntaba a una etapa vieja sigue en su sucesora.
        $this->assertSame(
            [1, 2, 6, 7, 9, 11],
            array_map(fn (int $id) => PipelineStage::withoutGlobalScopes()->findOrFail($id)->sort_order, array_values($seededIds)),
        );
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
        $this->assertSame($hookIds['Calificados'], $conversation->pipeline_stage_id);
        $this->assertSame('Interesado', PipelineStage::withoutGlobalScopes()->findOrFail($hookIds['Calificados'])->name);

        $this->assertSame([...self::LEGACY_NAMES, 'Seguimiento'], $this->stagesOf($extra)->pluck('name')->all());
        $this->assertSame(self::LEGACY_NAMES, $this->stagesOf($recolored)->pluck('name')->all());
        $this->assertSame('#ABCDEF', $this->stagesOf($recolored)->firstWhere('sort_order', 3)->color);

        // Idempotente.
        $migration->up();
        $this->assertCount(11, $this->stagesOf($hook));
        $this->assertCount(11, $this->stagesOf($seeded));
    }

    private function travelToArgentina(string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at, 'America/Argentina/Buenos_Aires'));
    }

    private function actingAsOwnerOf(Tenant $tenant): void
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');
        Sanctum::actingAs($user);
    }

    private function defaultPipeline(Tenant $tenant): Pipeline
    {
        return Pipeline::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_default', true)
            ->sole();
    }

    private function stagesOf(Tenant $tenant)
    {
        return PipelineStage::withoutGlobalScopes()
            ->where('pipeline_id', $this->defaultPipeline($tenant)->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function monthStage(Tenant $tenant): PipelineStage
    {
        return PipelineStage::withoutGlobalScopes()
            ->where('pipeline_id', $this->defaultPipeline($tenant)->id)
            ->where('sort_order', 3)
            ->sole();
    }

    /**
     * Reemplaza las etapas del pipeline predeterminado por las 6 originales.
     *
     * @param  list<string>  $colors
     * @return array<string, int> nombre => id
     */
    private function replaceWithLegacyStages(Tenant $tenant, array $colors): array
    {
        $pipelineId = $this->defaultPipeline($tenant)->id;
        DB::table('pipeline_stages')->where('pipeline_id', $pipelineId)->delete();

        $ids = [];
        foreach (self::LEGACY_NAMES as $i => $name) {
            $ids[$name] = DB::table('pipeline_stages')->insertGetId([
                'tenant_id' => $tenant->id,
                'pipeline_id' => $pipelineId,
                'name' => $name,
                'color' => $colors[$i],
                'sort_order' => $i + 1,
                'is_default' => $i === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function conversationIn(Tenant $tenant, int $stageId): Conversation
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana Martinez',
            'source' => 'whatsapp',
        ]);

        return Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'pipeline_stage_id' => $stageId,
        ]);
    }
}
