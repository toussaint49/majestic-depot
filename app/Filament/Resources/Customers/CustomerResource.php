<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\SalesRelationManager;
use App\Models\Customer;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'client';

    protected static ?string $pluralModelLabel = 'clients';

    protected static ?string $recordTitleAttribute = 'name';

    public static function formFields(): array
    {
        return [
            TextInput::make('name')->label('Nom')->required()->maxLength(255),
            TextInput::make('phone')->label('Téléphone')->tel()->maxLength(30),
            TextInput::make('address')->label('Adresse')->maxLength(255),
            TextInput::make('credit_days')->label('Délai de crédit (jours)')->integer()->minValue(0)->maxValue(365)
                ->placeholder(fn () => (string) (filament()->getTenant()?->default_credit_days ?? 14))
                ->helperText('Laisser vide pour le délai par défaut de l\'entreprise.'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                ...self::formFields(),
                Toggle::make('is_active')->label('Actif')->default(true),
                Textarea::make('notes')->label('Notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('phone')->label('Téléphone')->placeholder('—'),
                TextEntry::make('address')->label('Adresse')->placeholder('—'),
                TextEntry::make('credit')->label('Délai de crédit')->state(fn (Customer $r) => $r->creditDays().' jours'),
                TextEntry::make('balance')->label('Reste dû')->state(fn (Customer $r) => Quantity::money($r->balance()))->weight('bold'),
                TextEntry::make('overdue')->label('Dont en retard')->state(fn (Customer $r) => Quantity::money($r->overdueBalance()))
                    ->color(fn (Customer $r) => $r->overdueBalance() > 0 ? 'danger' : null),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withBalances())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('phone')->label('Téléphone')->searchable(),
                TextColumn::make('balance_due')->label('Reste dû')->sortable()
                    ->formatStateUsing(fn ($state) => Quantity::money((int) $state)),
                TextColumn::make('overdue_due')->label('En retard')->sortable()
                    ->formatStateUsing(fn ($state) => (int) $state > 0 ? Quantity::money((int) $state) : '—')
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : null)->weight(fn ($state) => (int) $state > 0 ? 'bold' : null),
                IconColumn::make('is_active')->label('Actif')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('debt')->label('Avec reste dû')->query(fn (Builder $query) => $query->having('balance_due', '>', 0)),
                Filter::make('overdue')->label('Crédits en retard')->query(fn (Builder $query) => $query->having('overdue_due', '>', 0)),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [SalesRelationManager::class, PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
