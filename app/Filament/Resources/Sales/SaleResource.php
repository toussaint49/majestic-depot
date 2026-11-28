<?php

namespace App\Filament\Resources\Sales;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Support\QuantityFields;
use App\Models\Customer;
use App\Models\PackagingType;
use App\Models\PaymentAccount;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Services\PackagingService;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'vente';

    protected static ?string $pluralModelLabel = 'ventes';

    protected static ?string $recordTitleAttribute = 'number';

    // ---------------------------------------------------------------- Saisie

    /** Options « Produit — Conditionnement (prix) » des conditionnements en vente. */
    public static function unitOptions(): array
    {
        return ProductUnit::query()->where('product_units.is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->with('product')->get()
            ->sortBy(fn ($u) => $u->product->name.sprintf('%06d', $u->factor))
            ->mapWithKeys(fn ($u) => [$u->id => "{$u->product->name} — {$u->name} (".Quantity::money($u->price).')'])
            ->all();
    }

    /** @return array{total: int, paid: int, credit: int, empties: array<string, int>} */
    public static function preview(Get $get): array
    {
        $lines = collect($get('lines') ?? [])->filter(fn ($l) => ! empty($l['product_unit_id']) && (int) ($l['quantity'] ?? 0) > 0);
        $prices = ProductUnit::whereIn('id', $lines->pluck('product_unit_id'))->pluck('price', 'id');
        $total = (int) $lines->sum(fn ($l) => (int) $l['quantity'] * (int) ($prices[$l['product_unit_id']] ?? 0));
        $paid = (int) collect($get('payments') ?? [])->sum(fn ($p) => (int) ($p['amount'] ?? 0));

        $required = app(PackagingService::class)->required($lines->map(fn ($l) => [(int) $l['product_unit_id'], (int) $l['quantity']])->all());
        $names = PackagingType::whereIn('id', array_keys($required))->pluck('name', 'id');
        $empties = collect($required)->mapWithKeys(fn ($q, $id) => [$names[$id] => $q])->all();

        return ['total' => $total, 'paid' => $paid, 'credit' => max(0, $total - $paid), 'empties' => $empties];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vente')->columns(3)->schema([
                QuantityFields::depot(),
                Select::make('customer_id')->label('Client')->searchable()->preload()
                    ->options(fn () => Customer::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)
                        ->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%"))
                        ->limit(30)->pluck('name', 'id'))
                    ->createOptionForm(CustomerResource::formFields())
                    ->createOptionUsing(fn (array $data) => Customer::create($data)->getKey())
                    ->live()
                    ->helperText('Obligatoire pour une vente à crédit.'),
                DateTimePicker::make('occurred_at')->label('Date de la vente')->default(now())->required()->maxDate(now()->addMinutes(5))
                    ->helperText('Modifiable pour saisir une fiche papier.'),
            ]),

            Section::make('Produits')->schema([
                Repeater::make('lines')->hiddenLabel()->columns(6)->minItems(1)->defaultItems(1)->live()
                    ->addActionLabel('Ajouter un produit')
                    ->schema([
                        Select::make('product_unit_id')->label('Produit')->required()->searchable()
                            ->options(fn () => self::unitOptions())->live()->columnSpan(3),
                        TextInput::make('quantity')->label('Quantité')->required()->integer()->minValue(1)->default(1)->live(debounce: 400)->columnSpan(1),
                        TextEntry::make('line_total')->label('Montant')->columnSpan(2)
                            ->state(function (Get $get) {
                                $price = (int) ProductUnit::whereKey($get('product_unit_id'))->value('price');

                                return Quantity::money($price * (int) $get('quantity'));
                            }),
                    ]),
                TextEntry::make('total')->label('Total à payer')->size('lg')->weight('bold')
                    ->state(fn (Get $get) => Quantity::money(self::preview($get)['total'])),
            ]),

            Section::make('Emballages vides')
                ->visible(fn (Get $get) => self::preview($get)['empties'] !== [])
                ->schema([
                    TextEntry::make('empties')->label('Vides que le client doit rendre')
                        ->state(fn (Get $get) => collect(self::preview($get)['empties'])->map(fn ($q, $n) => "$q × $n")->implode(' · ')),
                    Checkbox::make('empties_returned')->label('Le client a rendu ces vides')->accepted()
                        ->validationMessages(['accepted' => 'Sans les vides, la vente est refusée.']),
                ]),

            Section::make('Encaissement')->schema([
                Repeater::make('payments')->hiddenLabel()->columns(3)->live()->defaultItems(1)
                    ->default(fn () => [['payment_account_id' => PaymentAccount::where('kind', 'cash')->where('is_active', true)->value('id'), 'amount' => null]])
                    ->addActionLabel('Ajouter un moyen de paiement')
                    ->schema([
                        Select::make('payment_account_id')->label('Compte')->required()
                            ->options(fn () => PaymentAccount::where('is_active', true)->orderBy('id')->pluck('name', 'id'))
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        TextInput::make('amount')->label('Montant gardé (FCFA)')->integer()->minValue(0)->live(debounce: 400),
                        TextInput::make('reference')->label('Référence (MoMo / OM)'),
                    ]),
                Grid::make(2)->schema([
                    TextEntry::make('paid')->label('Encaissé')->state(fn (Get $get) => Quantity::money(self::preview($get)['paid'])),
                    TextEntry::make('credit')->label('Reste à crédit')
                        ->state(fn (Get $get) => Quantity::money(self::preview($get)['credit']))
                        ->color(fn (Get $get) => self::preview($get)['credit'] > 0 ? 'warning' : null)->weight('bold'),
                ]),
                Textarea::make('notes')->label('Notes'),
            ]),
        ]);
    }

    // ---------------------------------------------------------------- Consultation

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i'),
                TextEntry::make('customer.name')->label('Client')->placeholder('Client comptoir'),
                TextEntry::make('depot.name')->label('Dépôt'),
                TextEntry::make('user.name')->label('Vendeur')->placeholder('—'),
                TextEntry::make('total')->label('Total')->formatStateUsing(fn ($state) => Quantity::money($state))->weight('bold'),
                TextEntry::make('paid_at_sale')->label('Encaissé à la vente')->formatStateUsing(fn ($state) => Quantity::money($state)),
                TextEntry::make('balance')->label('Reste dû')->state(fn (Sale $r) => Quantity::money($r->balance()))
                    ->color(fn (Sale $r) => $r->isOverdue() ? 'danger' : ($r->balance() > 0 ? 'warning' : 'success')),
                TextEntry::make('due_date')->label('Échéance')->date('d/m/Y')->placeholder('—')
                    ->color(fn (Sale $r) => $r->isOverdue() ? 'danger' : null),
                TextEntry::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn ($state) => Sale::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === Sale::CANCELLED ? 'danger' : 'success'),
                TextEntry::make('cancel_reason')->label('Motif d\'annulation')->visible(fn (Sale $r) => $r->isCancelled())->columnSpan(3),
                TextEntry::make('created_at')->label('Saisie le')->dateTime('d/m/Y H:i')
                    ->visible(fn (Sale $r) => $r->created_at->diffInMinutes($r->occurred_at, true) > 60),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]),
            RepeatableEntry::make('lines')->label('Produits')->columns(4)->schema([
                TextEntry::make('product_name')->label('Produit'),
                TextEntry::make('quantity')->label('Quantité')->formatStateUsing(fn ($state, SaleLine $r) => "$state × {$r->unit_name}"),
                TextEntry::make('unit_price')->label('Prix unitaire')->formatStateUsing(fn ($state) => Quantity::money($state)),
                TextEntry::make('line_total')->label('Montant')->formatStateUsing(fn ($state) => Quantity::money($state)),
            ]),
            RepeatableEntry::make('allocations')->label('Paiements reçus')->columns(5)
                ->visible(fn (Sale $r) => $r->allocations()->exists())
                ->schema([
                    TextEntry::make('payment.number')->label('Reçu'),
                    TextEntry::make('payment.occurred_at')->label('Date')->dateTime('d/m/Y H:i'),
                    TextEntry::make('payment.account.name')->label('Compte'),
                    TextEntry::make('amount')->label('Montant')->formatStateUsing(fn ($state) => Quantity::money($state)),
                    TextEntry::make('payment.status')->label('Statut')->badge()
                        ->formatStateUsing(fn ($state) => $state === 'valid' ? 'Valide' : 'Annulé')
                        ->color(fn ($state) => $state === 'valid' ? 'success' : 'danger'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer')->withBalance())
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('N°')->searchable(),
                TextColumn::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('customer.name')->label('Client')->placeholder('Comptoir')->searchable(),
                TextColumn::make('total')->label('Total')->formatStateUsing(fn ($state) => Quantity::money($state))->sortable(),
                TextColumn::make('paid_total')->label('Payé')->formatStateUsing(fn ($state) => Quantity::money((int) $state)),
                TextColumn::make('balance')->label('Reste dû')->state(fn (Sale $r) => $r->balance())
                    ->formatStateUsing(fn ($state) => $state > 0 ? Quantity::money($state) : '—')
                    ->color(fn (Sale $r) => $r->isOverdue() ? 'danger' : ($r->balance() > 0 ? 'warning' : null))
                    ->description(fn (Sale $r) => $r->isOverdue() ? 'En retard depuis le '.$r->due_date->format('d/m') : null),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn ($state) => Sale::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === Sale::CANCELLED ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(Sale::STATUSES),
                Filter::make('credit')->label('Avec reste dû')
                    ->query(fn (Builder $query) => $query->where('status', Sale::VALIDATED)->having('paid_total', '<', \DB::raw('sales.total'))),
                Filter::make('overdue')->label('Crédits en retard')
                    ->query(fn (Builder $query) => $query->where('status', Sale::VALIDATED)->whereDate('due_date', '<', today())->having('paid_total', '<', \DB::raw('sales.total'))),
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
            'index' => ListSales::route('/'),
            'create' => CreateSale::route('/create'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
