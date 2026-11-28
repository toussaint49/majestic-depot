<?php

namespace App\Filament\Resources\CashSessions\Pages;

use App\Exceptions\CashSessionException;
use App\Filament\Resources\CashSessions\CashSessionResource;
use App\Filament\Support\QuantityFields;
use App\Models\Depot;
use App\Services\CashSessionService;
use App\Support\Quantity;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class OpenCashSession extends CreateRecord
{
    protected static string $resource = CashSessionResource::class;

    protected static ?string $title = 'Ouvrir la caisse';

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        $suggested = app(CashSessionService::class)->suggestedFloat();

        return $schema->components([
            Section::make()->columns(2)->schema([
                QuantityFields::depot(),
                TextInput::make('opening_float')->label('Espèces comptées dans le tiroir (FCFA)')->required()->integer()->minValue(0)
                    ->default($suggested)->live(debounce: 400)
                    ->helperText('Attendu : '.Quantity::money($suggested).' (montant compté à la dernière clôture).'),
                TextInput::make('reason')->label('Motif de l\'écart')->columnSpanFull()
                    ->visible(fn (Get $get) => (int) $get('opening_float') !== $suggested)
                    ->required(fn (Get $get) => (int) $get('opening_float') !== $suggested),
            ]),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CashSessionService::class)->open(Depot::findOrFail($data['depot_id']), (int) $data['opening_float'], $data['reason'] ?? null);
        } catch (CashSessionException $e) {
            Notification::make()->danger()->title('Ouverture refusée')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "Caisse {$this->getRecord()->number} ouverte";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
