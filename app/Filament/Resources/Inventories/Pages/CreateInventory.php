<?php

namespace App\Filament\Resources\Inventories\Pages;

use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\Depot;
use App\Services\InventoryService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInventory extends CreateRecord
{
    protected static string $resource = InventoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(InventoryService::class)->start(Depot::findOrFail($data['depot_id']), $data['counted_at'], $data['notes'] ?? null);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
