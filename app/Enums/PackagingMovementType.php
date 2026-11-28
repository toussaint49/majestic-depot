<?php

namespace App\Enums;

enum PackagingMovementType: string
{
    case Initial = 'initial';                     // stock de départ
    case CustomerExchange = 'customer_exchange';  // vides rendus par le client lors d'une vente
    case CustomerExchangeCancel = 'customer_exchange_cancel'; // annulation d'une vente : vides rendus au client
    case SupplierIn = 'supplier_in';              // emballages reçus du fournisseur (achat de consignes)
    case SupplierOut = 'supplier_out';            // vides remis au fournisseur
    case Loss = 'loss';                           // casse, perte
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Stock initial',
            self::CustomerExchange => 'Échange client (vente)',
            self::CustomerExchangeCancel => 'Annulation échange client',
            self::SupplierIn => 'Reçu du fournisseur',
            self::SupplierOut => 'Remis au fournisseur',
            self::Loss => 'Casse / perte',
            self::AdjustmentIn => 'Ajustement (+)',
            self::AdjustmentOut => 'Ajustement (−)',
            self::TransferIn => 'Transfert reçu',
            self::TransferOut => 'Transfert envoyé',
        };
    }

    public function isIncoming(): bool
    {
        return in_array($this, [self::Initial, self::CustomerExchange, self::SupplierIn, self::AdjustmentIn, self::TransferIn], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
