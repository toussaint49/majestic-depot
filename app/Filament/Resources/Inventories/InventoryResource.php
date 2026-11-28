<?php

namespace App\Filament\Resources\Inventories;

use App\Filament\Resources\Inventories\Pages\CreateInventory;
use App\Filament\Resources\Inventories\Pages\EditInventory;
use App\Filament\Resources\Inventories\Pages\ListInventories;
use App\Filament\Resources\Inventories\Pages\ViewInventory;
use App\Filament\Support\QuantityFields;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Product;
use App\Services\InventoryService;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'inventaire';

    protected static ?string $pluralModelLabel = 'inventaires';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                QuantityFields::depot()->disabledOn('edit'),
                DateTimePicker::make('counted_at')->label('Date du comptage')->default(now())->required(),
                Textarea::make('notes')->label('Notes')->columnSpanFull(),
            ]),
            Section::make('Quantités comptées')
                ->description('Saisissez ce qui est physiquement présent. Le stock théorique et les écarts sont calculés à la validation.')
                ->visibleOn('edit')
                ->schema([
                    Repeater::make('lines')->hiddenLabel()->relationship()->columns(3)
                        ->addActionLabel('Ajouter un produit')
                        ->schema([
                            Select::make('product_id')->label('Produit')->required()->searchable()
                                ->options(fn () => Product::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->columnSpan(1),
                            TextInput::make('counted_quantity')->label('Quantité comptée (unité de base)')->required()->integer()->minValue(0)->columnSpan(1),
                        ]),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('N°'),
                TextEntry::make('depot.name')->label('Dépôt'),
                TextEntry::make('status')->label('Statut')->badge()->formatStateUsing(fn ($state) => Inventory::STATUSES[$state] ?? $state),
                TextEntry::make('counted_at')->label('Comptage')->dateTime('d/m/Y H:i'),
                TextEntry::make('validator.name')->label('Validé par')->placeholder('—'),
                TextEntry::make('validated_at')->label('Validé le')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]),
            RepeatableEntry::make('lines')->label('Lignes')->columns(5)->schema([
                TextEntry::make('product.name')->label('Produit'),
                TextEntry::make('expected_quantity')->label('Théorique')->placeholder('—')
                    ->formatStateUsing(fn ($state, InventoryLine $r) => Quantity::format($r->product, (int) $state)),
                TextEntry::make('counted_quantity')->label('Compté')
                    ->formatStateUsing(fn ($state, InventoryLine $r) => Quantity::format($r->product, (int) $state)),
                TextEntry::make('difference')->label('Écart')->placeholder('—')
                    ->formatStateUsing(fn ($state, InventoryLine $r) => ($state > 0 ? '+' : '').Quantity::format($r->product, (int) $state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : null)),
                TextEntry::make('value')->label('Valeur de l\'écart')
                    ->state(fn (InventoryLine $r) => $r->difference === null ? null : Quantity::money($r->difference * (float) $r->unit_cost))->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('N°')->searchable(),
                TextColumn::make('depot.name')->label('Dépôt'),
                TextColumn::make('counted_at')->label('Comptage')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('lines_count')->label('Produits')->counts('lines'),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn ($state) => Inventory::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { Inventory::VALIDATED => 'success', Inventory::CANCELLED => 'gray', default => 'warning' }),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function validateAction(): Action
    {
        return Action::make('validate')->label('Valider l\'inventaire')->icon(Heroicon::OutlinedCheckBadge)->color('success')
            ->visible(fn (Inventory $record) => auth()->user()->can('validate', $record))
            ->requiresConfirmation()
            ->modalDescription('Les écarts seront passés en ajustement de stock. Un inventaire validé ne peut plus être modifié.')
            ->action(function (Inventory $record) {
                if ($record->lines()->doesntExist()) {
                    Notification::make()->warning()->title('Aucune ligne à valider')->send();

                    return;
                }
                app(InventoryService::class)->validate($record);
                Notification::make()->success()->title('Inventaire validé')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventories::route('/'),
            'create' => CreateInventory::route('/create'),
            'view' => ViewInventory::route('/{record}'),
            'edit' => EditInventory::route('/{record}/edit'),
        ];
    }
}
