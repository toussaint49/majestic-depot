<?php

namespace App\Filament\Resources\StockLevels\Pages;

use App\Enums\StockMovementType as T;
use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Support\QuantityFields as F;
use App\Models\Depot;
use App\Services\StockService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use LogicException;

class ListStockLevels extends ListRecords
{
    protected static string $resource = StockLevelResource::class;

    protected function getHeaderActions(): array
    {
        $occurredAt = DateTimePicker::make('occurred_at')->label('Date réelle')->default(now())->maxDate(now()->addMinutes(5))->required()
            ->helperText('Modifiable pour une saisie différée (fiche papier).');

        return [
            Action::make('initial')->label('Entrée de stock')->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn () => auth()->user()->can('stocks.create'))
                ->schema([
                    F::depot(), F::product(), F::quantity(),
                    TextInput::make('unit_cost')->label('Coût d\'achat par unité de base (FCFA)')->numeric()->minValue(0)->required(),
                    Select::make('type')->label('Nature')->required()->default(T::Initial->value)
                        ->options([T::Initial->value => T::Initial->label(), T::PurchaseIn->value => T::PurchaseIn->label()]),
                    $occurredAt,
                ])
                ->action(fn (array $data) => $this->run(function (StockService $s) use ($data) {
                    [$product, $qty] = F::resolve($data);
                    $s->receive(Depot::findOrFail($data['depot_id']), $product, $qty, (float) $data['unit_cost'], T::from($data['type']), occurredAt: $data['occurred_at']);
                }, 'Entrée enregistrée')),

            ActionGroup::make([
                Action::make('loss')->label('Perte / casse')->icon(Heroicon::OutlinedExclamationTriangle)
                    ->visible(fn () => auth()->user()->can('stocks.create'))
                    ->schema([F::depot(), F::product(), F::quantity(), TextInput::make('reason')->label('Motif')->required(), $occurredAt])
                    ->action(fn (array $data) => $this->run(function (StockService $s) use ($data) {
                        [$product, $qty] = F::resolve($data);
                        $s->issue(Depot::findOrFail($data['depot_id']), $product, $qty, T::Loss, reason: $data['reason'], occurredAt: $data['occurred_at']);
                    }, 'Perte enregistrée')),

                Action::make('adjust')->label('Ajustement')->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn () => auth()->user()->can('stocks.validate'))
                    ->schema([
                        F::depot(), F::product(), F::quantity(),
                        Select::make('direction')->label('Sens')->required()->options(['in' => 'Ajouter au stock', 'out' => 'Retirer du stock']),
                        TextInput::make('reason')->label('Motif')->required(),
                        $occurredAt,
                    ])
                    ->action(fn (array $data) => $this->run(function (StockService $s) use ($data) {
                        [$product, $qty] = F::resolve($data);
                        $s->adjust(Depot::findOrFail($data['depot_id']), $product, $data['direction'] === 'in' ? $qty : -$qty, $data['reason'], occurredAt: $data['occurred_at']);
                    }, 'Ajustement enregistré')),

                Action::make('transfer')->label('Transfert entre dépôts')->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn () => auth()->user()->can('stocks.create') && Depot::count() > 1)
                    ->schema([
                        F::depot('from_depot_id', 'Dépôt de départ'), F::depot('to_depot_id', 'Dépôt d\'arrivée')->different('from_depot_id'),
                        F::product(), F::quantity(), TextInput::make('reason')->label('Commentaire'), $occurredAt,
                    ])
                    ->action(fn (array $data) => $this->run(function (StockService $s) use ($data) {
                        [$product, $qty] = F::resolve($data);
                        $s->transfer(Depot::findOrFail($data['from_depot_id']), Depot::findOrFail($data['to_depot_id']), $product, $qty, $data['reason'] ?? null, $data['occurred_at']);
                    }, 'Transfert enregistré')),
            ])->label('Autres opérations')->button()->icon(Heroicon::OutlinedEllipsisVertical),
        ];
    }

    private function run(callable $operation, string $success): void
    {
        try {
            app()->call($operation);
            Notification::make()->success()->title($success)->send();
        } catch (InsufficientStockException|InvalidArgumentException|LogicException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();
        }
    }
}
