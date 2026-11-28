<?php

namespace App\Filament\Resources\PackagingLevels;

use App\Filament\Resources\PackagingLevels\Pages\ListPackagingLevels;
use App\Models\PackagingLevel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PackagingLevelResource extends Resource
{
    protected static ?string $model = PackagingLevel::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Emballages vides';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'stock de vides';

    protected static ?string $pluralModelLabel = 'stock de vides';

    protected static ?string $navigationLabel = 'Stock de vides';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['packagingType', 'depot']))
            ->defaultSort('packaging_type_id')
            ->columns([
                TextColumn::make('packagingType.code')->label('Code'),
                TextColumn::make('packagingType.name')->label('Emballage')->searchable(),
                TextColumn::make('depot.name')->label('Dépôt'),
                TextColumn::make('quantity')->label('Quantité')->numeric(thousandsSeparator: ' ')->sortable(),
            ])
            ->filters([
                SelectFilter::make('depot_id')->label('Dépôt')->relationship('depot', 'name'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPackagingLevels::route('/')];
    }
}
