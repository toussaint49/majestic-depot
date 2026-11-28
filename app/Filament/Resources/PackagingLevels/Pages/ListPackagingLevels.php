<?php

namespace App\Filament\Resources\PackagingLevels\Pages;

use App\Enums\PackagingMovementType as T;
use App\Exceptions\InsufficientPackagingException;
use App\Filament\Resources\PackagingLevels\PackagingLevelResource;
use App\Filament\Support\QuantityFields as F;
use App\Models\Depot;
use App\Models\PackagingType;
use App\Services\PackagingService;
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

class ListPackagingLevels extends ListRecords
{
    protected static string $resource = PackagingLevelResource::class;

    protected function getHeaderActions(): array
    {
        $type = fn () => Select::make('packaging_type_id')->label('Emballage')->required()
            ->options(fn () => PackagingType::where('is_active', true)->orderBy('name')->pluck('name', 'id'));
        $qty = fn () => TextInput::make('quantity')->label('Quantité')->required()->integer()->minValue(1);
        $at = fn () => DateTimePicker::make('occurred_at')->label('Date réelle')->default(now())->required();
        $can = fn (string $p) => fn () => auth()->user()->can($p);

        return [
            Action::make('receive')->label('Entrée de vides')->icon(Heroicon::OutlinedArrowDownTray)
                ->visible($can('emballages.create'))
                ->schema([
                    F::depot(), $type(), $qty(),
                    Select::make('type')->label('Nature')->required()->default(T::Initial->value)
                        ->options([T::Initial->value => T::Initial->label(), T::SupplierIn->value => T::SupplierIn->label()]),
                    TextInput::make('reason')->label('Commentaire'), $at(),
                ])
                ->action(fn (array $data) => $this->run(fn (PackagingService $s) => $s->receive(
                    Depot::findOrFail($data['depot_id']), PackagingType::findOrFail($data['packaging_type_id']), (int) $data['quantity'], T::from($data['type']), $data['reason'] ?? null, $data['occurred_at'],
                ), 'Entrée enregistrée')),

            Action::make('supplier')->label('Remise au fournisseur')->icon(Heroicon::OutlinedTruck)
                ->visible($can('emballages.create'))
                ->schema([F::depot(), $type(), $qty(), TextInput::make('reason')->label('Fournisseur / bon de livraison'), $at()])
                ->action(fn (array $data) => $this->run(fn (PackagingService $s) => $s->issue(
                    Depot::findOrFail($data['depot_id']), PackagingType::findOrFail($data['packaging_type_id']), (int) $data['quantity'], T::SupplierOut, $data['reason'] ?? null, $data['occurred_at'],
                ), 'Remise enregistrée')),

            ActionGroup::make([
                Action::make('loss')->label('Casse / perte')->icon(Heroicon::OutlinedExclamationTriangle)
                    ->visible($can('emballages.create'))
                    ->schema([F::depot(), $type(), $qty(), TextInput::make('reason')->label('Motif')->required(), $at()])
                    ->action(fn (array $data) => $this->run(fn (PackagingService $s) => $s->issue(
                        Depot::findOrFail($data['depot_id']), PackagingType::findOrFail($data['packaging_type_id']), (int) $data['quantity'], T::Loss, $data['reason'], $data['occurred_at'],
                    ), 'Casse enregistrée')),

                Action::make('adjust')->label('Ajustement')->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible($can('emballages.validate'))
                    ->schema([
                        F::depot(), $type(), $qty(),
                        Select::make('direction')->label('Sens')->required()->options(['in' => 'Ajouter', 'out' => 'Retirer']),
                        TextInput::make('reason')->label('Motif')->required(), $at(),
                    ])
                    ->action(fn (array $data) => $this->run(fn (PackagingService $s) => $s->adjust(
                        Depot::findOrFail($data['depot_id']), PackagingType::findOrFail($data['packaging_type_id']),
                        $data['direction'] === 'in' ? (int) $data['quantity'] : -(int) $data['quantity'], $data['reason'], $data['occurred_at'],
                    ), 'Ajustement enregistré')),

                Action::make('transfer')->label('Transfert entre dépôts')->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn () => auth()->user()->can('emballages.create') && Depot::count() > 1)
                    ->schema([
                        F::depot('from_depot_id', 'Dépôt de départ'), F::depot('to_depot_id', "Dépôt d'arrivée")->different('from_depot_id'),
                        $type(), $qty(), TextInput::make('reason')->label('Commentaire'), $at(),
                    ])
                    ->action(fn (array $data) => $this->run(fn (PackagingService $s) => $s->transfer(
                        Depot::findOrFail($data['from_depot_id']), Depot::findOrFail($data['to_depot_id']), PackagingType::findOrFail($data['packaging_type_id']), (int) $data['quantity'], $data['reason'] ?? null, $data['occurred_at'],
                    ), 'Transfert enregistré')),
            ])->label('Autres opérations')->button()->icon(Heroicon::OutlinedEllipsisVertical),
        ];
    }

    private function run(callable $operation, string $success): void
    {
        try {
            app()->call($operation);
            Notification::make()->success()->title($success)->send();
        } catch (InsufficientPackagingException|InvalidArgumentException|LogicException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();
        }
    }
}
