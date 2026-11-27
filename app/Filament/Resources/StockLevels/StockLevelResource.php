<?php

namespace App\Filament\Resources\StockLevels;

use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Models\StockLevel;
use App\Support\Quantity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** État du stock par dépôt, en lecture seule. Les opérations passent par les actions de la page. */
class StockLevelResource extends Resource
{
    protected static ?string $model = StockLevel::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'stock';

    protected static ?string $pluralModelLabel = 'état du stock';

    protected static ?string $navigationLabel = 'État du stock';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.units', 'depot']))
            ->defaultSort('product_id')
            ->columns([
                TextColumn::make('product.reference')->label('Réf.')->searchable(),
                TextColumn::make('product.name')->label('Produit')->searchable(),
                TextColumn::make('depot.name')->label('Dépôt'),
                TextColumn::make('quantity')->label('Quantité')->sortable()
                    ->formatStateUsing(fn ($state, StockLevel $r) => Quantity::format($r->product, (int) $state))
                    ->description(fn (StockLevel $r) => $r->quantity.' '.$r->product->base_unit_name.'(s)')
                    ->color(fn (StockLevel $r) => $r->quantity <= $r->product->alert_threshold ? 'danger' : null),
                TextColumn::make('avg_cost')->label('CUMP')->formatStateUsing(fn ($state) => Quantity::money($state)),
                TextColumn::make('value')->label('Valeur')->state(fn (StockLevel $r) => Quantity::money($r->value())),
            ])
            ->filters([
                SelectFilter::make('depot_id')->label('Dépôt')->relationship('depot', 'name'),
                Filter::make('alert')->label('Sous le seuil d\'alerte')
                    ->query(fn (Builder $query) => $query->whereHas('product', fn ($p) => $p->whereColumn('stock_levels.quantity', '<=', 'products.alert_threshold'))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockLevels::route('/')];
    }
}
