<?php

namespace Tests\Feature\Admin;

use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI MySQL verification (2026-10-31) — every Filament resource checked
 * against its model, table and relationships with REAL rows: list pages
 * render populated tables, view/edit pages load a record, and every
 * relation manager renders for that record. Runs on SQLite and MySQL.
 */
class FilamentResourceIntegrityTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_resource_models_map_to_existing_tables_and_columns(): void
    {
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            /** @var Model $model */
            $model = new ($resource::getModel());
            $table = $model->getTable();
            $this->assertTrue(Schema::hasTable($table), "{$resource}: table {$table} is missing.");

            $columns = Schema::getColumnListing($table);
            $fillable = array_values($model->getFillable());
            $this->assertSame([], array_values(array_diff($fillable, $columns)), "{$resource}: fillable attributes without a column in {$table}.");
        }
    }

    public function test_every_resource_page_and_relation_manager_renders_with_real_rows(): void
    {
        $data = $this->seedFullDataset();
        $this->actingAsAdmin($data['admin']);

        $checked = 0;
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $this->get($resource::getUrl('index'))->assertOk();

            $record = $resource::getEloquentQuery()->first();
            $this->assertNotNull($record, "{$resource}: the full dataset has no row to render.");

            $pages = $resource::getPages();
            foreach (['view', 'edit'] as $name) {
                if (array_key_exists($name, $pages) && $resource::can($name, $record)) {
                    $this->get($resource::getUrl($name, ['record' => $record]))->assertOk();
                    $checked++;
                }
            }

            $pageClass = collect($pages)->map(fn ($page) => $page->getPage())
                ->first(fn ($class) => is_subclass_of($class, ViewRecord::class) || is_subclass_of($class, EditRecord::class));

            foreach ($resource::getRelations() as $relationManager) {
                $relationManager = is_string($relationManager) ? $relationManager : $relationManager->relationManager;
                if ($pageClass && $relationManager::canViewForRecord($record, $pageClass)) {
                    Livewire::test($relationManager, ['ownerRecord' => $record, 'pageClass' => $pageClass])->assertSuccessful();
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(30, $checked, 'Too few record pages / relation managers were exercised.');
    }
}
