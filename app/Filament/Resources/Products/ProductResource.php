<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\PackagingType;
use App\Models\Product;
use App\Support\Quantity;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static bool $isScopedToTenant = false; // filtrage assuré par BelongsToTenant

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'produit';

    protected static ?string $pluralModelLabel = 'produits';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $tenantUnique = fn (Unique $rule) => $rule->where('tenant_id', filament()->getTenant()?->getKey());

        return $schema->components([
            Section::make('Produit')->columns(2)->schema([
                TextInput::make('name')->label('Nom')->required()->maxLength(255),
                TextInput::make('reference')->label('Référence')->required()->maxLength(50)
                    ->unique(ignoreRecord: true, modifyRuleUsing: $tenantUnique),
                Select::make('category_id')->label('Catégorie')->relationship('category', 'name')->searchable()->preload()
                    ->createOptionForm([TextInput::make('name')->label('Nom')->required()]),
                Select::make('brand_id')->label('Marque')->relationship('brand', 'name')->searchable()->preload()
                    ->createOptionForm([TextInput::make('name')->label('Nom')->required()]),
                TextInput::make('barcode')->label('Code-barres')->maxLength(50),
                TextInput::make('base_unit_name')->label('Unité de base du stock')->required()->default('Bouteille')->maxLength(30)
                    ->helperText('Le stock est toujours compté dans cette unité.'),
                TextInput::make('alert_threshold')->label("Seuil d'alerte (en unité de base)")->numeric()->minValue(0)->default(0),
                Toggle::make('is_active')->label('Actif')->default(true),
            ]),
            Section::make('Conditionnements et prix de vente')->schema([
                Repeater::make('units')->hiddenLabel()
                    ->relationship()
                    ->columns(3)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->default([['name' => 'Bouteille', 'factor' => 1, 'price' => null]])
                    ->addActionLabel('Ajouter un conditionnement')
                    ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                        $factors = collect($value)->pluck('factor')->map(fn ($f) => (int) $f);
                        if ($factors->filter(fn ($f) => $f === 1)->count() !== 1) {
                            $fail("Il faut exactement un conditionnement de facteur 1 (l'unité de base).");
                        }
                        $names = collect($value)->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)));
                        if ($names->unique()->count() !== $names->count()) {
                            $fail('Deux conditionnements portent le même nom.');
                        }
                    })
                    ->schema([
                        TextInput::make('name')->label('Conditionnement')->required()->maxLength(30)->placeholder('Casier'),
                        TextInput::make('factor')->label("Nombre d'unités de base")->required()->integer()->minValue(1)->placeholder('12'),
                        TextInput::make('price')->label('Prix de vente (FCFA)')->required()->integer()->minValue(0),
                        Repeater::make('packagings')->label('Vides à échanger pour 1 unité de ce conditionnement')
                            ->relationship()
                            ->columnSpanFull()
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Ajouter un emballage consigné')
                            ->helperText('Laisser vide pour un produit non consigné (ex. eau en bouteille plastique).')
                            ->schema([
                                Select::make('packaging_type_id')->label('Emballage')->required()
                                    ->options(fn () => PackagingType::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextInput::make('quantity')->label('Quantité')->required()->integer()->minValue(1),
                            ]),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['units', 'category'])->withSum('stockLevels as stock_total', 'quantity'))
            ->columns([
                TextColumn::make('reference')->label('Réf.')->searchable()->sortable(),
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Catégorie')->toggleable(),
                TextColumn::make('prices')->label('Prix')
                    ->state(fn (Product $r) => $r->units->map(fn ($u) => $u->name.' : '.Quantity::money($u->price))->implode(' · ')),
                TextColumn::make('stock_total')->label('Stock')
                    ->formatStateUsing(fn ($state, Product $r) => Quantity::format($r, (int) $state))
                    ->color(fn ($state, Product $r) => (int) $state <= $r->alert_threshold ? 'danger' : null),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Catégorie')->relationship('category', 'name'),
                TernaryFilter::make('is_active')->label('Actif'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
