<?php

namespace App\Filament\Resources\CashSessions\Pages;

use App\Exceptions\CashSessionException;
use App\Filament\Resources\CashSessions\CashSessionResource;
use App\Models\CashSession;
use App\Models\PaymentAccount;
use App\Services\CashSessionService;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCashSession extends ViewRecord
{
    protected static string $resource = CashSessionResource::class;

    protected function getHeaderActions(): array
    {
        $service = fn () => app(CashSessionService::class);
        $accounts = fn () => PaymentAccount::where('is_active', true)->orderBy('id')->pluck('name', 'id');
        $moveFields = fn () => [
            Select::make('payment_account_id')->label('Compte')->required()->options($accounts)
                ->default(fn () => PaymentAccount::where('kind', 'cash')->value('id')),
            TextInput::make('amount')->label('Montant (FCFA)')->required()->integer()->minValue(1),
            TextInput::make('reason')->label('Motif')->required()->maxLength(255),
        ];

        return [
            Action::make('deposit')->label('Apport')->icon(Heroicon::OutlinedPlusCircle)->color('gray')
                ->visible(fn (CashSession $record) => auth()->user()->can('move', $record))
                ->schema($moveFields)
                ->action(fn (array $data, CashSession $record) => $this->run(fn () => $service()->deposit(
                    $record, PaymentAccount::findOrFail($data['payment_account_id']), (int) $data['amount'], $data['reason'],
                ), 'Apport enregistré')),

            Action::make('withdraw')->label('Retrait')->icon(Heroicon::OutlinedMinusCircle)->color('gray')
                ->visible(fn (CashSession $record) => auth()->user()->can('move', $record))
                ->schema($moveFields)
                ->action(fn (array $data, CashSession $record) => $this->run(fn () => $service()->withdraw(
                    $record, PaymentAccount::findOrFail($data['payment_account_id']), (int) $data['amount'], $data['reason'],
                ), 'Retrait enregistré')),

            Action::make('close')->label('Clôturer la caisse')->icon(Heroicon::OutlinedLockClosed)->color('warning')
                ->visible(fn (CashSession $record) => auth()->user()->can('close', $record))
                ->modalDescription('Comptez les espèces du tiroir et relevez les soldes Mobile Money de la journée.')
                ->schema(fn (CashSession $record) => [
                    ...collect($service()->expected($record))->map(fn ($expected, $id) => TextInput::make("counted.$id")
                        ->label(PaymentAccount::find($id)?->name.' — compté (FCFA)')
                        ->helperText('Théorique : '.Quantity::money($expected))
                        ->required()->integer()->minValue(0))->values()->all(),
                    Textarea::make('notes')->label('Commentaire (écart, incident…)'),
                ])
                ->action(fn (array $data, CashSession $record) => $this->run(fn () => $service()->close(
                    $record, collect($data['counted'] ?? [])->map(fn ($v) => (int) $v)->all(), $data['notes'] ?? null,
                ), 'Caisse clôturée — en attente de validation')),

            Action::make('validate')->label('Valider la clôture')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (CashSession $record) => auth()->user()->can('validate', $record))
                ->requiresConfirmation()
                ->modalDescription(fn (CashSession $record) => 'Écart total : '.Quantity::money((int) $record->counts()->sum('difference'))
                    .'. Une fois validée, la clôture ne peut plus être modifiée ; les écarts seront passés en écriture.')
                ->action(fn (CashSession $record) => $this->run(fn () => $service()->validate($record), 'Clôture validée')),
        ];
    }

    private function run(callable $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->success()->title($success)->send();
            $this->record->refresh();
        } catch (CashSessionException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();
        }
    }
}
