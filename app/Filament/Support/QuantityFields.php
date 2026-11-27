<?php

namespace App\Filament\Support;

use App\Models\Depot;
use App\Models\Product;
use App\Models\ProductUnit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Champs réutilisables « dépôt / produit / quantité + conditionnement ».
 * La quantité saisie est convertie en unités de base avec toBase().
 */
final class QuantityFields
{
    public static function depot(string $name = 'depot_id', string $label = 'Dépôt'): Select
    {
        return Select::make($name)->label($label)->required()
            ->options(fn () => Depot::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
            ->default(fn () => Depot::where('is_active', true)->orderBy('id')->value('id'));
    }

    public static function product(): Select
    {
        return Select::make('product_id')->label('Produit')->required()->searchable()->live()
            ->options(fn () => Product::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
            ->afterStateUpdated(fn ($set) => $set('product_unit_id', null));
    }

    public static function quantity(): Grid
    {
        return Grid::make(2)->schema([
            TextInput::make('quantity')->label('Quantité')->required()->integer()->minValue(1),
            Select::make('product_unit_id')->label('Conditionnement')->required()
                ->options(fn (Get $get) => ProductUnit::where('product_id', $get('product_id'))->orderBy('factor')
                    ->get()->mapWithKeys(fn ($u) => [$u->id => $u->factor > 1 ? "{$u->name} ({$u->factor})" : $u->name]))
                ->default(fn (Get $get) => ProductUnit::where('product_id', $get('product_id'))->where('factor', 1)->value('id')),
        ]);
    }

    /** @return array{0: Product, 1: int, 2: ProductUnit} produit, quantité en unités de base, conditionnement */
    public static function resolve(array $data): array
    {
        $product = Product::findOrFail($data['product_id']);
        $unit = ProductUnit::where('product_id', $product->id)->findOrFail($data['product_unit_id']);

        return [$product, (int) $data['quantity'] * $unit->factor, $unit];
    }
}
