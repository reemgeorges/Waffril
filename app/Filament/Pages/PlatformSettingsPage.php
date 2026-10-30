<?php

namespace App\Filament\Pages;

use App\Filament\Support\BusinessAction;
use App\Services\Platform\PlatformSettingsService;
use App\Support\Auth\AdminPermissions;
use App\Support\Settings\PlatformSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * WAFFRLI (2026-10-31) — typed platform settings: general, support
 * contacts, operations, scheduled orders, delivery, catalog placeholder
 * rating, PLUS and maintenance (blocking NEW customer orders only).
 */
class PlatformSettingsPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'platform-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return AdminPermissions::currentAllows('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.title');
    }

    public function getTitle(): string
    {
        return __('admin.settings.title');
    }

    protected static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        $values = [];
        foreach (array_keys(PlatformSettings::definitions()) as $key) {
            $values[self::field($key)] = PlatformSettings::get($key);
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (collect(PlatformSettings::definitions())->groupBy('group', true) as $group => $definitions) {
            $sections[] = Section::make(__("admin.settings.groups.{$group}"))->columns(2)->schema(
                $definitions->map(fn ($definition, $key) => $this->input($key, $definition))->values()->all()
            );
        }

        return $schema->components($sections)->statePath('data');
    }

    protected function input(string $key, array $definition)
    {
        $label = __("admin.settings.keys.{$key}");
        $name = self::field($key);

        return match ($definition['type']) {
            'bool' => Toggle::make($name)->label($label),
            'int' => TextInput::make($name)->label($label)->integer()->minValue(0)->required(),
            'rating' => TextInput::make($name)->label($label)->numeric()->minValue(1)->maxValue(5)->helperText(__('admin.help.placeholder_rating')),
            'phone' => TextInput::make($name)->label($label)->tel()->regex('/^\+[1-9]\d{6,14}$/')->helperText(__('admin.help.e164')),
            'timezone' => Select::make($name)->label($label)->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->searchable()->required(),
            'translated' => Grid::make(2)->columnSpanFull()->schema([
                TextInput::make("{$name}.ar")->label($label.' (AR)')->maxLength(500)->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make("{$name}.en")->label($label.' (EN)')->maxLength(500)->extraInputAttributes(['dir' => 'ltr']),
            ]),
            default => TextInput::make($name)->label($label)->maxLength(500),
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label(__('admin.actions.save'))->submit('save')]),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $values = [];
        foreach (array_keys(PlatformSettings::definitions()) as $key) {
            $values[$key] = $state[self::field($key)] ?? null;
        }

        BusinessAction::run(fn ($admin) => app(PlatformSettingsService::class)->save($values, $admin), __('admin.notifications.saved'));
    }
}
