<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Exceptions\CashSessionException;
use App\Exceptions\InsufficientPackagingException;
use App\Exceptions\PurchaseException;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Services\PurchaseService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PurchaseService::class)->create($data);
        } catch (PurchaseException|InsufficientPackagingException|CashSessionException $e) {
            Notification::make()->danger()->title('Achat refusé')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "Achat {$this->getRecord()->number} enregistré, stock mis à jour";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
