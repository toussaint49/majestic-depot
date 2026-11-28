<?php

namespace App\Filament\Resources\Payments;

use App\Exceptions\SaleException;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Services\PaymentService;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'paiement';

    protected static ?string $pluralModelLabel = 'paiements';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account', 'customer', 'sale', 'user']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('Reçu')->searchable(),
                TextColumn::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('customer.name')->label('Client')->placeholder('Comptoir'),
                TextColumn::make('kind')->label('Nature')->state(fn (Payment $r) => $r->sale_id ? 'Vente '.$r->sale?->number : 'Règlement de crédit'),
                TextColumn::make('account.name')->label('Compte'),
                TextColumn::make('amount')->label('Montant')->formatStateUsing(fn ($state) => Quantity::money($state)),
                TextColumn::make('reference')->label('Référence')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn ($state) => $state === Payment::VALID ? 'Valide' : 'Annulé')
                    ->color(fn ($state) => $state === Payment::VALID ? 'success' : 'danger')
                    ->description(fn (Payment $r) => $r->cancel_reason),
                TextColumn::make('user.name')->label('Par')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('payment_account_id')->label('Compte')->options(fn () => PaymentAccount::pluck('name', 'id')),
                SelectFilter::make('status')->label('Statut')->options([Payment::VALID => 'Valide', Payment::CANCELLED => 'Annulé']),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label('Du'),
                    DatePicker::make('until')->label('Au'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d))),
            ])
            ->recordActions([
                Action::make('cancel')->label('Annuler')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    // Un encaissement fait à la vente s'annule avec la vente elle-même.
                    ->visible(fn (Payment $record) => $record->sale_id === null && auth()->user()->can('cancel', $record))
                    ->requiresConfirmation()
                    ->schema([TextInput::make('reason')->label('Motif')->required()->maxLength(255)])
                    ->action(function (array $data, Payment $record) {
                        try {
                            app(PaymentService::class)->cancel($record, $data['reason']);
                            Notification::make()->success()->title("Paiement {$record->number} annulé")->send();
                        } catch (SaleException $e) {
                            Notification::make()->danger()->title('Annulation refusée')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
