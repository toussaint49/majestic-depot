<?php

namespace App\Filament\Resources\Inventories\Pages;

use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\Inventory;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditInventory extends EditRecord
{
    protected static string $resource = InventoryResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['depot_id']); // le dépôt d'un inventaire ne change pas

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            InventoryResource::validateAction()->after(fn () => $this->redirect($this->getResource()::getUrl('view', ['record' => $this->getRecord()]))),
            Action::make('cancel')->label('Annuler l\'inventaire')->color('gray')->requiresConfirmation()
                ->visible(fn (Inventory $record) => $record->isDraft())
                ->action(function (Inventory $record) {
                    app(InventoryService::class)->cancel($record);
                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
