<?php

namespace App\Filament\Resources\ApiTokens\Pages;

use App\Filament\Resources\ApiTokens\ApiTokenResource;
use App\Models\ApiToken;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ListApiTokens extends ListRecords
{
    protected static string $resource = ApiTokenResource::class;

    protected ?string $subheading = 'Un jeton permet à Claude d\'enregistrer achats et ventes en votre nom. Il n\'est affiché qu\'une fois : copiez-le aussitôt.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')->label('Créer un jeton')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('create', ApiToken::class))
                ->schema([TextInput::make('name')->label('Nom')->default('Claude')->required()->maxLength(255)])
                ->action(function (array $data) {
                    [, $plain] = ApiToken::issue(auth()->user(), $data['name']);
                    Notification::make()->success()->persistent()
                        ->title('Jeton créé : copiez-le maintenant')
                        ->body(new HtmlString("<code style=\"user-select:all;word-break:break-all\">$plain</code><br>Il ne sera plus affiché."))
                        ->send();
                }),
        ];
    }
}
