<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Exceptions\InsufficientPackagingException;
use App\Exceptions\SaleException;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\SettlementAction;
use App\Models\Sale;
use App\Services\SaleService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ticket')->label('Ticket PDF')->icon(Heroicon::OutlinedPrinter)
                ->url(fn (Sale $record) => route('sales.ticket', ['tenant' => filament()->getTenant()->slug, 'sale' => $record]))
                ->openUrlInNewTab(),
            SettlementAction::forSale(),
            Action::make('cancel')->label('Annuler la vente')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (Sale $record) => auth()->user()->can('cancel', $record))
                ->requiresConfirmation()
                ->modalDescription('Le stock, les vides et les encaissements de la vente seront contrepassés. La vente reste visible comme « Annulée ».')
                ->schema([TextInput::make('reason')->label('Motif')->required()->maxLength(255)])
                ->action(function (array $data, Sale $record) {
                    try {
                        app(SaleService::class)->cancel($record, $data['reason']);
                        Notification::make()->success()->title("Vente {$record->number} annulée")->send();
                        $this->refreshFormData([]);
                    } catch (SaleException|InsufficientPackagingException $e) {
                        Notification::make()->danger()->title('Annulation refusée')->body($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }
}
