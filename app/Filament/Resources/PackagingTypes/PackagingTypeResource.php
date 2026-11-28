<?php

namespace App\Filament\Resources\PackagingTypes;

use App\Filament\Resources\PackagingTypes\Pages\ManagePackagingTypes;
use App\Models\PackagingType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class PackagingTypeResource extends Resource
{
    protected static ?string $model = PackagingType::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 12;

    protected static ?string $modelLabel = "type d'emballage";

    protected static ?string $pluralModelLabel = "types d'emballages";

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(255)->placeholder('Bouteille vide 65cl'),
            TextInput::make('code')->label('Code')->required()->maxLength(20)->placeholder('BV65')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', filament()->getTenant()?->getKey())),
            Toggle::make('is_active')->label('Actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')->label('Code')->searchable(),
                TextColumn::make('name')->label('Nom')->searchable(),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePackagingTypes::route('/')];
    }
}
