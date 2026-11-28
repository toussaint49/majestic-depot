<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Models\Supplier;
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

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'fournisseur';

    protected static ?string $pluralModelLabel = 'fournisseurs';

    protected static ?string $recordTitleAttribute = 'name';

    public static function formFields(): array
    {
        return [
            TextInput::make('name')->label('Nom')->required()->maxLength(255),
            TextInput::make('phone')->label('Téléphone')->tel()->maxLength(30),
            TextInput::make('address')->label('Adresse')->maxLength(255),
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
            Section::make()->columns(3)->schema([
                TextEntry::make('phone')->label('Téléphone')->placeholder('—'),
                TextEntry::make('address')->label('Adresse')->placeholder('—'),
                TextEntry::make('balance')->label('Reste à payer')->state(fn (Supplier $r) => Quantity::money($r->balance()))->weight('bold')
                    ->color(fn (Supplier $r) => $r->balance() > 0 ? 'warning' : null),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withBalance())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('phone')->label('Téléphone')->searchable(),
                TextColumn::make('balance_due')->label('Reste à payer')->sortable()
                    ->formatStateUsing(fn ($state) => (int) $state > 0 ? Quantity::money((int) $state) : '—')
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : null),
                IconColumn::make('is_active')->label('Actif')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('debt')->label('Avec reste à payer')->query(fn (Builder $query) => $query->having('balance_due', '>', 0)),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'view' => ViewSupplier::route('/{record}'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
