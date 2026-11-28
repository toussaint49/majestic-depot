<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\MissingPackagingException;
use App\Exceptions\SaleException;
use App\Filament\Resources\Sales\SaleResource;
use App\Services\SaleService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SaleService::class)->create($data);
        } catch (SaleException|MissingPackagingException|InsufficientStockException $e) {
            Notification::make()->danger()->title('Vente refusée')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "Vente {$this->getRecord()->number} enregistrée";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
