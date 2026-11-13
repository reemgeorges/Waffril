<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Model;

/**
 * Row actions convention (table UX overhaul, 2026-11-12):
 *
 *  - one obvious primary action per row ("Manage store", "View order",
 *    "Edit"), shown as a small outlined button with its label;
 *  - everything less frequent (related lists, activate / deactivate,
 *    delete, restore) in one "More actions" menu, each item labelled and
 *    with an icon. Dangerous items keep their confirmation.
 *
 * Only the presentation is set here; visibility and authorisation stay on
 * each action.
 */
final class RowActions
{
    public static function primary(Action $action): Action
    {
        return $action->button()->outlined()->size(Size::Small)
            ->extraAttributes(['class' => 'wf-row-primary'], merge: true);
    }

    /** @param  array<Action|ActionGroup>  $actions */
    public static function more(array $actions): ActionGroup
    {
        return ActionGroup::make($actions)
            ->label(__('admin.table.more_actions'))
            ->tooltip(__('admin.table.more_actions'))
            ->icon('heroicon-m-ellipsis-vertical')
            ->iconButton()
            ->color('gray');
    }

    /**
     * "Deactivate" / "Activate" as two explicit, confirmed menu items in
     * place of a bare switch in the row. Same field (is_active) and the same
     * permission as the edit form: $resource::can('update', $record).
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return array{0: Action, 1: Action}
     */
    public static function activation(string $resource, string $attribute = 'is_active'): array
    {
        return self::switch($resource, $attribute,
            on: ['activate', 'admin.table.activate', 'admin.table.activate_confirm', 'heroicon-m-play-circle'],
            off: ['deactivate', 'admin.table.deactivate', 'admin.table.deactivate_confirm', 'heroicon-m-pause-circle']);
    }

    /**
     * Two confirmed menu items that set a boolean $attribute on or off.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @param  array{0: string, 1: string, 2: string, 3: string}  $on  [name, label key, confirmation key, icon]
     * @param  array{0: string, 1: string, 2: string, 3: string}  $off
     * @param  (Closure(Model, bool): mixed)|null  $using  a business service to call instead of
     *                                                     writing the field (it then authorises,
     *                                                     audits and reports the result itself)
     * @return array{0: Action, 1: Action}
     */
    public static function switch(string $resource, string $attribute, array $on, array $off, ?Closure $using = null): array
    {
        $make = fn (bool $value, array $spec) => Action::make($spec[0])
            ->label(__($spec[1]))
            ->icon($spec[3])
            ->color($value ? 'success' : 'warning')
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record) => __($spec[2], ['name' => self::title($record)]))
            ->visible(fn (Model $record) => (bool) $record->getAttribute($attribute) !== $value
                && ! (method_exists($record, 'trashed') && $record->trashed())
                && $resource::can('update', $record))
            ->action(function (Model $record) use ($value, $attribute, $using): void {
                if ($using) {
                    $using($record, $value);

                    return;
                }
                $record->forceFill([$attribute => $value])->save();
                Notification::make()->title(__('admin.table.status_changed'))->success()->send();
            });

        return [$make(false, $off), $make(true, $on)];
    }

    private static function title(Model $record): string
    {
        foreach (['name', 'title', 'question', 'code'] as $key) {
            $value = $record->getAttribute($key);
            if (filled($value)) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return '#'.$record->getKey();
    }
}
