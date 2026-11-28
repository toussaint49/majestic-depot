<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\SettlementAction;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SettlementAction::forCustomer(),
            Action::make('statement')->label('Relevé PDF')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->url(fn (Customer $record) => route('customers.statement', ['tenant' => filament()->getTenant()->slug, 'customer' => $record]))
                ->openUrlInNewTab(),
            EditAction::make(),
        ];
    }
}
