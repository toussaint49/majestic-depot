<?php

namespace App\Filament\Support;

use App\Exceptions\SaleException;
use App\Models\Customer;
use App\Models\PaymentAccount;
use App\Models\Sale;
use App\Services\PaymentService;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Action « Encaisser un règlement » (fiche client ou fiche vente). */
final class SettlementAction
{
    public static function make(callable $resolve): Action
    {
        return Action::make('settle')->label('Encaisser un règlement')->icon(Heroicon::OutlinedBanknotes)->color('success')
            ->visible(function ($record) use ($resolve) {
                [$customer] = $resolve($record);

                return $customer && auth()->user()->can('paiements.create') && $customer->balance() > 0;
            })
            ->modalDescription(function ($record) use ($resolve) {
                [$customer, $sale] = $resolve($record);

                return $sale
                    ? 'Reste dû sur cette vente : '.Quantity::money($sale->balance()).' — total client : '.Quantity::money($customer->balance())
                    : 'Reste dû : '.Quantity::money($customer->balance()).'. Le règlement solde d\'abord les ventes les plus anciennes.';
            })
            ->schema(fn ($record) => [
                TextInput::make('amount')->label('Montant reçu (FCFA)')->required()->integer()->minValue(1)
                    ->default(function () use ($record, $resolve) {
                        [$customer, $sale] = $resolve($record);

                        return $sale?->balance() ?? $customer->balance();
                    }),
                Select::make('payment_account_id')->label('Compte')->required()
                    ->options(fn () => PaymentAccount::where('is_active', true)->orderBy('id')->pluck('name', 'id'))
                    ->default(fn () => PaymentAccount::where('is_active', true)->orderBy('id')->value('id')),
                TextInput::make('reference')->label('Référence (n° de transaction Mobile Money…)'),
                DateTimePicker::make('occurred_at')->label('Date réelle')->default(now())->required(),
            ])
            ->action(function (array $data, $record) use ($resolve) {
                [$customer, $sale] = $resolve($record);
                try {
                    $payment = app(PaymentService::class)->recordCustomerPayment(
                        $customer, PaymentAccount::findOrFail($data['payment_account_id']), (int) $data['amount'], $sale, $data['reference'] ?? null, $data['occurred_at'],
                    );
                    Notification::make()->success()->title("Règlement {$payment->number} enregistré")
                        ->body('Reste dû : '.Quantity::money($customer->balance()))->send();
                } catch (SaleException $e) {
                    Notification::make()->danger()->title('Règlement refusé')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function forCustomer(): Action
    {
        return self::make(fn (Customer $customer) => [$customer, null]);
    }

    public static function forSale(): Action
    {
        return self::make(fn (Sale $sale) => [$sale->customer, $sale->isCancelled() ? null : $sale])
            ->visible(fn (Sale $record) => $record->customer && ! $record->isCancelled() && $record->balance() > 0 && auth()->user()->can('paiements.create'));
    }
}
