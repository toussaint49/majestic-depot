<?php

namespace App\Filament\Resources\PaymentAccounts;

use App\Filament\Resources\PaymentAccounts\Pages\ManagePaymentAccounts;
use App\Models\PaymentAccount;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class PaymentAccountResource extends Resource
{
    protected static ?string $model = PaymentAccount::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Trésorerie';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'compte de paiement';

    protected static ?string $pluralModelLabel = 'comptes de paiement';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', filament()->getTenant()?->getKey())),
            Select::make('kind')->label('Type')->required()->options(PaymentAccount::KINDS),
            TextInput::make('number')->label('Numéro (marchand, compte…)')->maxLength(255),
            Toggle::make('is_active')->label('Actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('transactions as balance', 'amount'))
            ->columns([
                TextColumn::make('name')->label('Compte'),
                TextColumn::make('kind')->label('Type')->formatStateUsing(fn ($state) => PaymentAccount::KINDS[$state] ?? $state),
                TextColumn::make('number')->label('Numéro')->placeholder('—'),
                TextColumn::make('balance')->label('Solde')->formatStateUsing(fn ($state) => Quantity::money((int) $state))->placeholder(Quantity::money(0)),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentAccounts::route('/')];
    }
}
