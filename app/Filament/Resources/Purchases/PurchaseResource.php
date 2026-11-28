<?php

namespace App\Filament\Resources\Purchases;

use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\QuantityFields;
use App\Models\PackagingType;
use App\Models\PaymentAccount;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Supplier;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'achat';

    protected static ?string $pluralModelLabel = 'achats';

    protected static ?string $recordTitleAttribute = 'number';

    public static function unitOptions(): array
    {
        return ProductUnit::query()->where('product_units.is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->with('product')->get()
            ->sortBy(fn ($u) => $u->product->name.sprintf('%06d', $u->factor))
            ->mapWithKeys(fn ($u) => [$u->id => "{$u->product->name} — {$u->name}"])
            ->all();
    }

    /** Dernier prix d'achat connu pour ce conditionnement. */
    public static function lastPrice(?int $unitId): ?int
    {
        if (! $unitId) {
            return null;
        }
        $price = PurchaseLine::where('product_unit_id', $unitId)
            ->whereHas('purchase', fn ($q) => $q->where('status', Purchase::VALIDATED))
            ->latest('id')->value('unit_price');

        return $price === null ? null : (int) $price;
    }

    public static function total(Get $get): int
    {
        return (int) collect($get('lines') ?? [])->sum(fn ($l) => (int) ($l['quantity'] ?? 0) * (int) ($l['unit_price'] ?? 0));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Facture')->columns(4)->schema([
                Select::make('supplier_id')->label('Fournisseur')->required()->searchable()->preload()
                    ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->createOptionForm(SupplierResource::formFields())
                    ->createOptionUsing(fn (array $data) => Supplier::create($data)->getKey()),
                TextInput::make('invoice_number')->label('N° de facture')->maxLength(60),
                QuantityFields::depot(),
                DateTimePicker::make('occurred_at')->label('Date de réception')->default(now())->required()->maxDate(now()->addMinutes(5)),
            ]),

            Section::make('Marchandise reçue')->schema([
                Repeater::make('lines')->hiddenLabel()->columns(6)->minItems(1)->defaultItems(1)->live()
                    ->addActionLabel('Ajouter un produit')
                    ->schema([
                        Select::make('product_unit_id')->label('Produit')->required()->searchable()
                            ->options(fn () => self::unitOptions())->live()
                            ->afterStateUpdated(fn ($state, Set $set) => $set('unit_price', self::lastPrice((int) $state)))
                            ->columnSpan(3),
                        TextInput::make('quantity')->label('Quantité')->required()->integer()->minValue(1)->default(1)->live(debounce: 400),
                        TextInput::make('unit_price')->label('Prix d\'achat unitaire')->required()->integer()->minValue(0)->live(debounce: 400)
                            ->suffix('F'),
                        TextEntry::make('line_total')->label('Montant')
                            ->state(fn (Get $get) => Quantity::money((int) $get('quantity') * (int) $get('unit_price'))),
                    ]),
                TextEntry::make('total')->label('Total de la facture')->size('lg')->weight('bold')
                    ->state(fn (Get $get) => Quantity::money(self::total($get))),
            ]),

            Section::make('Vides remis au fournisseur')->collapsible()->schema([
                Repeater::make('empties_returned')->hiddenLabel()->columns(2)->defaultItems(0)
                    ->addActionLabel('Ajouter des vides')
                    ->schema([
                        Select::make('packaging_type_id')->label('Emballage')->required()
                            ->options(fn () => PackagingType::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        TextInput::make('quantity')->label('Quantité')->required()->integer()->minValue(1),
                    ]),
            ]),

            Section::make('Paiement')->schema([
                Repeater::make('payments')->hiddenLabel()->columns(3)->defaultItems(0)->live()
                    ->addActionLabel('Ajouter un paiement')
                    ->schema([
                        Select::make('payment_account_id')->label('Payé depuis')->required()
                            ->options(fn () => PaymentAccount::where('is_active', true)->orderBy('id')->pluck('name', 'id')),
                        TextInput::make('amount')->label('Montant (FCFA)')->required()->integer()->minValue(1)->live(debounce: 400),
                        TextInput::make('reference')->label('Référence'),
                    ]),
                Grid::make(1)->schema([
                    TextEntry::make('debt')->label('Reste dû au fournisseur')
                        ->state(fn (Get $get) => Quantity::money(max(0, self::total($get) - (int) collect($get('payments') ?? [])->sum(fn ($p) => (int) ($p['amount'] ?? 0)))))
                        ->weight('bold'),
                ]),
                Textarea::make('notes')->label('Notes'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('supplier.name')->label('Fournisseur'),
                TextEntry::make('invoice_number')->label('N° de facture')->placeholder('—'),
                TextEntry::make('occurred_at')->label('Reçu le')->dateTime('d/m/Y H:i'),
                TextEntry::make('depot.name')->label('Dépôt'),
                TextEntry::make('total')->label('Total')->formatStateUsing(fn ($state) => Quantity::money($state))->weight('bold'),
                TextEntry::make('paid')->label('Payé')->state(fn (Purchase $r) => Quantity::money($r->paidTotal())),
                TextEntry::make('balance')->label('Reste à payer')->state(fn (Purchase $r) => Quantity::money($r->balance()))
                    ->color(fn (Purchase $r) => $r->balance() > 0 ? 'warning' : 'success'),
                TextEntry::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn ($state) => Purchase::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === Purchase::CANCELLED ? 'danger' : 'success'),
                TextEntry::make('cancel_reason')->label('Motif d\'annulation')->visible(fn (Purchase $r) => $r->isCancelled())->columnSpanFull(),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]),
            RepeatableEntry::make('lines')->label('Marchandise')->columns(4)->schema([
                TextEntry::make('product_name')->label('Produit'),
                TextEntry::make('quantity')->label('Quantité')->formatStateUsing(fn ($state, PurchaseLine $r) => "$state × {$r->unit_name}"),
                TextEntry::make('unit_price')->label('Prix unitaire')->formatStateUsing(fn ($state) => Quantity::money($state)),
                TextEntry::make('line_total')->label('Montant')->formatStateUsing(fn ($state) => Quantity::money($state)),
            ]),
            RepeatableEntry::make('payments')->label('Règlements')->columns(5)
                ->visible(fn (Purchase $r) => $r->payments()->exists())
                ->schema([
                    TextEntry::make('number')->label('N°'),
                    TextEntry::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i'),
                    TextEntry::make('account.name')->label('Compte'),
                    TextEntry::make('amount')->label('Montant')->formatStateUsing(fn ($state) => Quantity::money($state)),
                    TextEntry::make('status')->label('Statut')->badge()
                        ->formatStateUsing(fn ($state) => $state === 'valid' ? 'Valide' : 'Annulé')
                        ->color(fn ($state) => $state === 'valid' ? 'success' : 'danger'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supplier')->withBalance())
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('N°')->searchable(),
                TextColumn::make('occurred_at')->label('Date')->dateTime('d/m/Y')->sortable(),
                TextColumn::make('supplier.name')->label('Fournisseur')->searchable(),
                TextColumn::make('invoice_number')->label('Facture')->searchable()->placeholder('—'),
                TextColumn::make('total')->label('Total')->formatStateUsing(fn ($state) => Quantity::money($state))->sortable(),
                TextColumn::make('balance')->label('Reste à payer')->state(fn (Purchase $r) => $r->balance())
                    ->formatStateUsing(fn ($state) => $state > 0 ? Quantity::money($state) : '—')
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn ($state) => Purchase::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === Purchase::CANCELLED ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('supplier_id')->label('Fournisseur')->relationship('supplier', 'name'),
                SelectFilter::make('status')->label('Statut')->options(Purchase::STATUSES),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label('Du'),
                    DatePicker::make('until')->label('Au'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'view' => ViewPurchase::route('/{record}'),
        ];
    }
}
