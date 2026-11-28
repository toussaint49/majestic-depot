<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Exceptions\CashSessionException;
use App\Exceptions\InsufficientPackagingException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PurchaseException;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PaymentAccount;
use App\Models\Purchase;
use App\Models\SupplierPayment;
use App\Services\PurchaseService;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    private function guard(callable $callback, string $failure): void
    {
        try {
            $callback();
            $this->refreshFormData([]);
        } catch (PurchaseException|CashSessionException|InsufficientStockException|InsufficientPackagingException $e) {
            Notification::make()->danger()->title($failure)->body($e->getMessage())->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pay')->label('Payer le fournisseur')->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn (Purchase $record) => auth()->user()->can('pay', $record))
                ->schema(fn (Purchase $record) => [
                    Select::make('payment_account_id')->label('Payé depuis')->required()
                        ->options(PaymentAccount::where('is_active', true)->orderBy('id')->pluck('name', 'id'))
                        ->default(PaymentAccount::where('kind', 'cash')->where('is_active', true)->value('id')),
                    TextInput::make('amount')->label('Montant (FCFA)')->required()->integer()->minValue(1)
                        ->default($record->balance())->helperText('Reste à payer : '.Quantity::money($record->balance())),
                    TextInput::make('reference')->label('Référence'),
                ])
                ->action(fn (array $data, Purchase $record) => $this->guard(function () use ($data, $record) {
                    $p = app(PurchaseService::class)->pay($record, PaymentAccount::findOrFail($data['payment_account_id']), (int) $data['amount'], $data['reference'] ?? null);
                    Notification::make()->success()->title("Règlement {$p->number} enregistré")->send();
                }, 'Règlement refusé')),

            Action::make('cancelPayment')->label('Annuler un règlement')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn (Purchase $record) => auth()->user()->can('achats.cancel') && $record->payments()->where('status', SupplierPayment::VALID)->exists())
                ->schema(fn (Purchase $record) => [
                    Select::make('payment_id')->label('Règlement')->required()
                        ->options($record->payments()->where('status', SupplierPayment::VALID)->get()
                            ->mapWithKeys(fn ($p) => [$p->id => "{$p->number} — ".Quantity::money($p->amount)])),
                    TextInput::make('reason')->label('Motif')->required()->maxLength(255),
                ])
                ->action(fn (array $data, Purchase $record) => $this->guard(function () use ($data, $record) {
                    app(PurchaseService::class)->cancelPayment($record->payments()->findOrFail($data['payment_id']), $data['reason']);
                    Notification::make()->success()->title('Règlement annulé')->send();
                }, 'Annulation refusée')),

            Action::make('cancel')->label('Annuler l\'achat')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (Purchase $record) => auth()->user()->can('cancel', $record))
                ->requiresConfirmation()
                ->modalDescription('La marchandise ressort du stock et les vides remis reviennent au dépôt. L\'achat reste visible comme « Annulé ».')
                ->schema([TextInput::make('reason')->label('Motif')->required()->maxLength(255)])
                ->action(fn (array $data, Purchase $record) => $this->guard(function () use ($data, $record) {
                    app(PurchaseService::class)->cancel($record, $data['reason']);
                    Notification::make()->success()->title("Achat {$record->number} annulé")->send();
                }, 'Annulation refusée')),
        ];
    }
}
