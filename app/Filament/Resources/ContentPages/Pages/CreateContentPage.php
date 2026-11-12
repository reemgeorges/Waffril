<?php

namespace App\Filament\Resources\ContentPages\Pages;

use App\Filament\Resources\ContentPages\ContentPageResource;
use App\Filament\Support\BusinessAction;
use App\Services\Content\ContentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateContentPage extends CreateRecord
{
    protected static string $resource = ContentPageResource::class;

    /** Saved by ContentService (permission, versioning, activity log). */
    protected function handleRecordCreation(array $data): Model
    {
        return BusinessAction::run(fn ($admin) => app(ContentService::class)->savePage($data['key'], $data, $admin), __('admin.notifications.saved'))
            ?? throw new Halt;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
