<?php

namespace App\Filament\Resources\PackagingTypes\Pages;

use App\Filament\Resources\PackagingTypes\PackagingTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePackagingTypes extends ManageRecords
{
    protected static string $resource = PackagingTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
