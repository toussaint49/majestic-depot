<?php

namespace App\Filament\Resources\CashSessions;

use App\Filament\Resources\CashSessions\Pages\ListCashSessions;
use App\Filament\Resources\CashSessions\Pages\OpenCashSession;
use App\Filament\Resources\CashSessions\Pages\ViewCashSession;
use App\Models\AccountTransaction;
use App\Models\CashSession;
use App\Models\PaymentAccount;
use App\Services\CashSessionService;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CashSessionResource extends Resource
{
    protected static ?string $model = CashSession::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Trésorerie';

    protected static ?int $navigationSort = 0;

    protected static ?string $modelLabel = 'session de caisse';

    protected static ?string $pluralModelLabel = 'caisses';

    protected static ?string $navigationLabel = 'Caisse';

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationBadge(): ?string
    {
        return CashSession::where('status', CashSession::OPEN)->exists() ? 'ouverte' : null;
    }

    /** Tableau « théorique / compté / écart » par compte. */
    public static function summary(CashSession $session): array
    {
        $names = PaymentAccount::pluck('name', 'id');

        if ($session->isOpen()) {
            return collect(app(CashSessionService::class)->expected($session))
                ->map(fn ($expected, $id) => ['account' => $names[$id] ?? '?', 'expected' => $expected, 'counted' => null, 'difference' => null])
                ->values()->all();
        }

        return $session->counts->map(fn ($c) => [
            'account' => $names[$c->payment_account_id] ?? '?',
            'expected' => $c->expected, 'counted' => $c->counted, 'difference' => $c->difference,
        ])->all();
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state) => $state === null ? '—' : Quantity::money($state);

        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('depot.name')->label('Dépôt'),
                TextEntry::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn ($state) => CashSession::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { CashSession::OPEN => 'info', CashSession::CLOSED => 'warning', default => 'success' }),
                TextEntry::make('opened_at')->label('Ouverte le')
                    ->state(fn (CashSession $r) => $r->opened_at->format('d/m/Y H:i').' par '.($r->opener?->name ?? '—')),
                TextEntry::make('opening_float')->label('Fond de caisse')->formatStateUsing(fn ($state) => Quantity::money($state)),
                TextEntry::make('closed_at')->label('Clôturée le')->placeholder('—')
                    ->state(fn (CashSession $r) => $r->closed_at ? $r->closed_at->format('d/m/Y H:i').' par '.($r->closer?->name ?? '—') : null),
                TextEntry::make('validated_at')->label('Validée le')->placeholder('—')
                    ->state(fn (CashSession $r) => $r->validated_at ? $r->validated_at->format('d/m/Y H:i').' par '.($r->validator?->name ?? '—') : null),
                TextEntry::make('notes')->label('Notes de clôture')->placeholder('—')->columnSpan(2),
            ]),
            RepeatableEntry::make('summary')->label(fn (CashSession $r) => $r->isOpen() ? 'Solde théorique en cours' : 'Clôture')
                ->state(fn (CashSession $r) => self::summary($r))
                ->columns(4)
                ->schema([
                    TextEntry::make('account')->label('Compte'),
                    TextEntry::make('expected')->label('Théorique')->formatStateUsing($money),
                    TextEntry::make('counted')->label('Compté')->formatStateUsing($money)->placeholder('—'),
                    TextEntry::make('difference')->label('Écart')->placeholder('—')
                        ->formatStateUsing(fn ($state) => $state === null ? '—' : ($state > 0 ? '+' : '').Quantity::money($state))
                        ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'warning' : 'success')),
                ]),
            RepeatableEntry::make('transactions')->label('Opérations de la session')->columns(5)
                ->state(fn (CashSession $r) => $r->transactions()->with(['account', 'user'])->latest('id')->get())
                ->schema([
                    TextEntry::make('occurred_at')->label('Date')->dateTime('d/m H:i'),
                    TextEntry::make('type')->label('Type')->formatStateUsing(fn ($state) => AccountTransaction::TYPES[$state] ?? $state),
                    TextEntry::make('label')->label('Libellé'),
                    TextEntry::make('account.name')->label('Compte'),
                    TextEntry::make('amount')->label('Montant')->formatStateUsing(fn ($state) => ($state > 0 ? "+" : "").Quantity::money($state))
                        ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['depot', 'opener'])->withSum('counts as total_difference', 'difference'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('N°'),
                TextColumn::make('depot.name')->label('Dépôt'),
                TextColumn::make('opened_at')->label('Ouverture')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('opener.name')->label('Par'),
                TextColumn::make('closed_at')->label('Clôture')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('total_difference')->label('Écart')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : Quantity::money((int) $state))
                    ->color(fn ($state) => (int) $state < 0 ? 'danger' : ((int) $state > 0 ? 'warning' : null)),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn ($state) => CashSession::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { CashSession::OPEN => 'info', CashSession::CLOSED => 'warning', default => 'success' }),
            ])
            ->filters([SelectFilter::make('status')->label('Statut')->options(CashSession::STATUSES)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSessions::route('/'),
            'create' => OpenCashSession::route('/ouvrir'),
            'view' => ViewCashSession::route('/{record}'),
        ];
    }
}
