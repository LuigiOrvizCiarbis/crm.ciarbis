# Nuevas etapas por defecto del embudo predeterminado

## Context
Las etapas por defecto del embudo predeterminado ("Ventas") se reemplazan por 11 etapas nuevas, cada una con su color. Las reciben los workspaces nuevos y también los existentes que todavía tienen las 6 etapas originales sin tocar. Los workspaces que personalizaron sus etapas no cambian. La etapa 3 se llama "Contactar <mes siguiente>" y se renombra sola el día 1 a las 00:00, hora de Argentina. Si el usuario la renombra a mano, deja de actualizarse.

Etapas nuevas, en orden (`sort_order` 1..11). La entrada (`is_default=true`) es solo la 1:

| # | name | color |
|---|------|-------|
| 1 | Lead/Prospecto | #3beaf7 |
| 2 | Interesado | #e6fd72 |
| 3 | Contactar {mes_siguiente} → p.ej. "Contactar Diciembre" | #e6fd72 |
| 4 | 1era Reunión | #fcdc69 |
| 5 | 2da Reunión | #dfc35d |
| 6 | Negociación | #e4992f |
| 7 | Cierre | #9dff8a |
| 8 | Oferta/Adelanto/Reserva | #78e25a |
| 9 | Cliente Convertido/Ganado | #5be17d |
| 10 | Partner/Colega/Proveedor | #bb3bf7 |
| 11 | Perdido/No le interesa | #fe165b |

## Approach

### 1. Schema: columna `name_template`
- New migration `crm-si-back/database/migrations/2026_10_31_000000_add_name_template_to_pipeline_stages_table.php` (anonymous class, same style as `2026_06_11_000000_add_color_to_pipeline_stages_table.php`):
  - up: `$table->string('name_template')->nullable()->after('name');`
  - down: `$table->dropColumn('name_template');`
- Semantics: `null` means a static name. A non-null value is a template that contains the literal placeholder `{mes_siguiente}`, and the stage's `name` is regenerated from it.

### 2. Una sola fuente para las etapas por defecto: `App\Models\PipelineStage`
Edit `crm-si-back/app/Models/PipelineStage.php`:
- Add `'name_template'` to `$fillable`.
- Add constants:
  - `public const NEXT_MONTH_PLACEHOLDER = '{mes_siguiente}';`
  - `private const MONTH_NAMES = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];` These are hardcoded so the names don't depend on Carbon locale data. The codebase has no existing month-name helper.
- Add `public static function renderNameTemplate(string $template, ?CarbonInterface $now = null): string`:
  - `$now ??= CarbonImmutable::now();` This uses the app timezone, `America/Argentina/Buenos_Aires` (`config/app.php:80`). Do NOT use `tenants.timezone`: it defaults to `'UTC'` and no UI sets it.
  - Next month = `$now->startOfMonth()->addMonth()->month`. Call `startOfMonth()` first to avoid overflow on the 31st. For CarbonImmutable use the returned value; for a mutable Carbon call `->copy()` first.
  - Return `str_replace(self::NEXT_MONTH_PLACEHOLDER, self::MONTH_NAMES[$month], $template)`.
- Add `public static function defaultBlueprint(): array`. It returns `list<array{name: string, name_template: ?string, color: string, sort_order: int, is_default: bool}>` in the table order above:
  - Source list: `[label, color]` pairs. Row 3's label is `'Contactar '.self::NEXT_MONTH_PLACEHOLDER`.
  - For each pair at index `$i`, `name_template` is the label when it contains the placeholder, otherwise `null`. `name` is `renderNameTemplate(label)` for templated rows and the label otherwise. `sort_order = $i + 1` and `is_default = $i === 0`.
  - Every row has the same 5 keys, because `PipelineStage::insert` needs uniform rows.
- Imports: `Carbon\CarbonImmutable`, `Carbon\CarbonInterface`.

### 3. Workspaces nuevos usan el blueprint
- `crm-si-back/app/Models/Tenant.php` `booted()` → `static::created` (lines ~42-66): delete the local `$stages` array. Keep the `Pipeline::create([... 'name' => 'Ventas', 'is_default' => true])` call. Insert with `PipelineStage::insert(array_map(fn (array $stage) => [...$stage, 'tenant_id' => $tenant->id, 'pipeline_id' => $pipeline->id, 'created_at' => $now, 'updated_at' => $now], PipelineStage::defaultBlueprint()));`. This also sets the colors, which the current hook doesn't.
- `crm-si-back/database/seeders/PipelineStageSeeder.php`: replace the `$stages` literal (lines 32-70) with `$stages = PipelineStage::defaultBlueprint();`. Change the loop body to `PipelineStage::create([...$stage, 'tenant_id' => $tenant->id, 'pipeline_id' => $pipelineId]);`. Keep the early-skip when the tenant already has stages, and keep the explicit `$pipelineId` comment.

### 4. Workspaces existentes "sin tocar": migración de datos
New migration `crm-si-back/database/migrations/2026_10_31_000001_upgrade_untouched_default_pipeline_stages.php`. It runs after step 1 because of the timestamp order.
- The 11-stage list, the month names and the render are **frozen inside the migration** (not read from `PipelineStage`), so a future blueprint change doesn't alter this historical migration.
- `private const LEGACY_TO_SORT_ORDER = ['Capturados' => 1, 'Calificados' => 2, 'Negociación' => 6, 'Cierre' => 7, 'Ganado' => 9, 'Perdido' => 11];` The order of the keys is the original order.
- `FACTORY_PALETTES`: the hook palette (all `#3B82F6`, column default) and the seeder palette (`#3B82F6 #EAB308 #F97316 #8B5CF6 #22C55E #EF4444`), compared case-insensitively.
- `up()`: iterate the ids of `pipelines` with `is_default = true`. Per pipeline, inside `DB::transaction`:
  - `lockForUpdate()` the pipeline row (also blocks concurrent stage inserts through the FK) and its stages, ordered by `sort_order, id`.
  - Untouched = exactly the 6 legacy names in order, `is_default` only on the first, and colors equal to one factory palette. Otherwise return.
  - Update each old stage in place (keeps ids, so `conversations`/`opportunities` keep their stage) and insert the 5 missing rows (sort_order 3, 4, 5, 8, 10).
- `down()`: empty, with a comment that the data change is irreversible. The schema rollback lives in the step 1 migration.
- Idempotent: once converted, the names no longer match, so a second run does nothing.

### 5. Renombrado mensual automático
- New command `crm-si-back/app/Console/Commands/RefreshPipelineStageMonthNames.php`. Commands in that folder are auto-discovered; follow the style of the other commands there.
  - `protected $signature = 'pipeline-stages:refresh-month-names';`
  - `protected $description = 'Actualiza el nombre de las etapas con plantilla de mes (ej. "Contactar <mes siguiente>")';`
  - `handle()`: `PipelineStage::withoutGlobalScopes()->whereNotNull('name_template')->chunkById(500, ...)`. For each stage whose rendered name differs, run a compare-and-set `UPDATE ... WHERE id = ? AND name_template = ? AND name = ?` and add the affected rows to the counter, so a concurrent manual rename wins. Print `$this->info("{$count} etapas renombradas.")` and return `self::SUCCESS`.
- `crm-si-back/routes/console.php`: add after the `billing:roll-cycle` block:
  ```php
  // Etapas "Contactar <mes siguiente>": el nombre se recalcula con la hora de
  // la app (America/Argentina/Buenos_Aires). Hourly en vez de monthlyOn(1) para
  // que, si el scheduler no corrió justo el 01 a las 00:00, se corrija en la hora.
  Schedule::command('pipeline-stages:refresh-month-names')->hourly()->withoutOverlapping(10);
  ```
  `hourly()` fires at minute 0, so it covers 00:00 on day 1. The command is idempotent.

### 6. Renombrar a mano corta la actualización automática
- `crm-si-back/app/Http/Controllers/Api/PipelineStageController.php` `update()` (lines ~54-69): after `validate`, before `$stage->update($validated)`, add:
  ```php
  // Un nombre elegido por el usuario gana sobre la plantilla mensual.
  if (array_key_exists('name', $validated) && $validated['name'] !== $stage->name) {
      $validated['name_template'] = null;
  }
  ```
  Compare against the current name, not just whether the key is present.
- Frontend `crm-si-front/components/pipeline-stages-manager.tsx` `persistStage`: send only the fields that changed against the saved snapshot, and refresh the snapshot/local state from the response. Otherwise a manager opened before day 1 would send the previous month's name with a color-only change and cut the template.
- Don't touch: `name_template` is not in the validation rules, so a user can't set it through the API. Copying a pipeline in the frontend (`new-pipeline-dialog.tsx`) copies only `name`/`color`, so the copy keeps the fixed name. That is acceptable.
- `DashboardController`: remove `KPI_STAGES_LIMIT` from `stageBreakdown()` and `dailySeries()`, so the funnel keeps showing Cierre, Ganado and Perdido.

### 7. Tests
- `crm-si-back/tests/Feature/Api/DashboardMetricsTest.php:72`: change `'Capturados'` to `'Lead/Prospecto'`.
- New file `crm-si-back/tests/Feature/Api/DefaultPipelineStagesTest.php`. Use `RefreshDatabase` and `TestCase::createTenantWithRoles()`. No `actingAs` in `setUp`: `TenantScope` filters by the authenticated user, so tenants are created after `travelTo` and queried with `withoutGlobalScopes()`/`DB::table`; only API tests authenticate. Wrap each `travelTo` in `CarbonImmutable::parse(..., 'America/Argentina/Buenos_Aires')`. Tests:
  1. `test_new_tenant_gets_the_eleven_default_stages`: names, colors, sort_order, `name_template` only on row 3, entry stage `Lead/Prospecto`.
  2. `test_month_stage_renames_on_first_day_of_month`: tenant created at `2026-12-31 23:00` → `Contactar Enero`; at `2027-01-01 00:00` the command prints `1 etapas renombradas.` → `Contactar Febrero`. A stage without template on another tenant doesn't change. Second run prints `0`.
  3. `test_manual_rename_stops_monthly_update`.
  4. `test_color_only_update_keeps_template_after_monthly_rename`: after the command renames, a `PUT` with only `color` keeps the template and returns the current name; resending the current name also keeps it.
  5. `test_upgrade_migration_converts_only_untouched_default_pipelines`: hook palette (with a conversation on Calificados) and lowercase seeder palette are converted keeping ids; a pipeline with an extra stage and one with a recolored stage stay as they are; second `up()` is a no-op.

## Critical files & anchors
- `crm-si-back/app/Models/PipelineStage.php`: new `defaultBlueprint()`/`renderNameTemplate()`, the only source of the list.
- `crm-si-back/app/Models/Tenant.php` `booted()` `static::created`: where new workspaces get their stages.
- `crm-si-back/database/migrations/2026_10_31_000001_upgrade_untouched_default_pipeline_stages.php`: in-place update that keeps FKs.
- `crm-si-back/app/Http/Controllers/Api/PipelineStageController.php::update`: clears `name_template` on a real rename.
- `crm-si-back/routes/console.php`: hourly schedule.

## Verification
Run in Docker from `crm-si-back/`, using a temporary stack with only `app db redis`:
1. `docker compose -p stages -f docker-compose.yml -f docker-compose.worktree.yml up -d app db redis`
2. `docker compose -p stages -f docker-compose.yml -f docker-compose.worktree.yml exec app php artisan test --filter='DefaultPipelineStagesTest|DashboardMetricsTest|MultiPipelineTest|PipelineStageAuthorizationTest'`: everything green.
3. `... exec app php artisan test`: full suite green.
4. Postgres: `... exec app php vendor/bin/phpunit -c phpunit.postgres.xml --group default --filter=DefaultPipelineStagesTest` (needs database `si_crm_test`). Manual smoke on the dev database: `migrate`, create tenants, `migrate:rollback --step=2`, replace their stages with the 6 legacy ones (one with a custom color) plus a conversation/opportunity, `migrate` again and check ids kept, 11 stages and the custom one untouched. Then force `UPDATE pipeline_stages SET name='Contactar X' WHERE name_template IS NOT NULL`, run `pipeline-stages:refresh-month-names` → `N etapas renombradas.` (N > 0); again → `0 etapas renombradas.`
5. `... exec app php artisan schedule:list`: shows `pipeline-stages:refresh-month-names` hourly.
6. Teardown: `docker compose -p stages -f docker-compose.yml -f docker-compose.worktree.yml down -v`.

## Assumptions & contingencies
- "Untouched" = the default pipeline has exactly the 6 original names, in the original order, nothing extra, the entry stage first, and a factory color palette. Reordering, renaming, recoloring, adding or removing a stage counts as personalized, and that pipeline isn't migrated.
- The monthly rename only changes the name. Leads in that stage stay there; nothing moves them.
- The month is computed with the app timezone (Buenos Aires) for every tenant.
- If `travelTo` with a timezone causes problems in the tests (Carbon converts to the app TZ), fallback: pass `$now` explicitly to `renderNameTemplate` in the unit asserts, and keep `travelTo('YYYY-MM-DD HH:MM')` in the app timezone, which is already Buenos Aires.
