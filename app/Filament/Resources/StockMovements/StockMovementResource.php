<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\StockMovementType;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\StockMovement;
use App\Support\Quantity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'mouvement';

    protected static ?string $pluralModelLabel = 'mouvements de stock';

    protected static ?string $navigationLabel = 'Mouvements';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.units', 'depot', 'user']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i')->sortable()
                    ->description(fn (StockMovement $r) => $r->created_at->diffInMinutes($r->occurred_at, true) > 60 ? 'saisi le '.$r->created_at->format('d/m/Y H:i') : null),
                TextColumn::make('type')->label('Type')->badge()
                    ->formatStateUsing(fn (StockMovementType $state) => $state->label())
                    ->color(fn (StockMovementType $state) => $state->isIncoming() ? 'success' : 'danger'),
                TextColumn::make('product.name')->label('Produit')->searchable(),
                TextColumn::make('depot.name')->label('Dépôt')->toggleable(),
                TextColumn::make('quantity')->label('Quantité')
                    ->formatStateUsing(fn ($state, StockMovement $r) => ($state > 0 ? '+' : '').Quantity::format($r->product, (int) $state)),
                TextColumn::make('quantity_after')->label('Stock après')
                    ->formatStateUsing(fn ($state, StockMovement $r) => Quantity::format($r->product, (int) $state)),
                TextColumn::make('unit_cost')->label('Coût unit.')->formatStateUsing(fn ($state) => Quantity::money($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason')->label('Motif')->placeholder('—')->wrap(),
                TextColumn::make('user.name')->label('Par')->placeholder('Système'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')->options(StockMovementType::options()),
                SelectFilter::make('product_id')->label('Produit')->relationship('product', 'name')->searchable(),
                SelectFilter::make('depot_id')->label('Dépôt')->relationship('depot', 'name'),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label('Du'),
                    DatePicker::make('until')->label('Au'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
