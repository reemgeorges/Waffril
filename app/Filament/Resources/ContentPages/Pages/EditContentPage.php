<?php

namespace App\Filament\Resources\ContentPages\Pages;

use App\Filament\Resources\ContentPages\ContentPageResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\TranslatableRecord;
use App\Services\Content\ContentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditContentPage extends EditRecord
{
    use TranslatableRecord;

    protected static string $resource = ContentPageResource::class;

    /** Saved by ContentService (permission, versioning, activity log). */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return BusinessAction::run(fn ($admin) => app(ContentService::class)->savePage($record->key, $data, $admin, $record), __('admin.notifications.saved'))
            ?? throw new Halt;
    }

    protected function getSavedNotification(): ?Notification
    {
        return null;
    }
}
