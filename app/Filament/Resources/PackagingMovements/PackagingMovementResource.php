<?php

namespace App\Filament\Resources\PackagingMovements;

use App\Enums\PackagingMovementType;
use App\Filament\Resources\PackagingMovements\Pages\ListPackagingMovements;
use App\Models\PackagingMovement;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PackagingMovementResource extends Resource
{
    protected static ?string $model = PackagingMovement::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static string|UnitEnum|null $navigationGroup = 'Emballages vides';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = "mouvement d'emballages";

    protected static ?string $pluralModelLabel = "mouvements d'emballages";

    protected static ?string $navigationLabel = 'Mouvements de vides';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['packagingType', 'depot', 'user']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label('Type')->badge()
                    ->formatStateUsing(fn (PackagingMovementType $state) => $state->label())
                    ->color(fn (PackagingMovementType $state) => $state->isIncoming() ? 'success' : 'danger'),
                TextColumn::make('packagingType.name')->label('Emballage')->searchable(),
                TextColumn::make('depot.name')->label('Dépôt')->toggleable(),
                TextColumn::make('quantity')->label('Quantité')->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '').$state),
                TextColumn::make('quantity_after')->label('Stock après'),
                TextColumn::make('reason')->label('Motif')->placeholder('—')->wrap(),
                TextColumn::make('user.name')->label('Par')->placeholder('Système'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')->options(PackagingMovementType::options()),
                SelectFilter::make('packaging_type_id')->label('Emballage')->relationship('packagingType', 'name'),
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
        return ['index' => ListPackagingMovements::route('/')];
    }
}
