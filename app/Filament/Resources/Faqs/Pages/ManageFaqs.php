<?php

namespace App\Filament\Resources\Faqs\Pages;

use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Support\BusinessAction;
use App\Services\Content\ContentService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;

class ManageFaqs extends ManageRecords
{
    protected static string $resource = FaqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modalWidth(Width::FourExtraLarge)
                ->using(fn (array $data) => BusinessAction::run(fn ($admin) => app(ContentService::class)->saveFaq($data, $admin)) ?? throw new Halt),
        ];
    }
}
