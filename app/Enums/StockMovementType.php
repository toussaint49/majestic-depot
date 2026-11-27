<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Initial = 'initial';                 // stock de départ
    case PurchaseIn = 'purchase_in';          // réception d'achat
    case SaleOut = 'sale_out';                // vente
    case SaleReturn = 'sale_return';          // annulation / retour de vente
    case SupplierReturn = 'supplier_return';  // retour au fournisseur
    case Loss = 'loss';                       // perte, casse, péremption
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case InventoryIn = 'inventory_in';        // écart positif d'inventaire
    case InventoryOut = 'inventory_out';      // écart négatif d'inventaire
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Stock initial',
            self::PurchaseIn => 'Réception achat',
            self::SaleOut => 'Vente',
            self::SaleReturn => 'Retour / annulation vente',
            self::SupplierReturn => 'Retour fournisseur',
            self::Loss => 'Perte / casse',
            self::AdjustmentIn => 'Ajustement (+)',
            self::AdjustmentOut => 'Ajustement (−)',
            self::InventoryIn => 'Écart inventaire (+)',
            self::InventoryOut => 'Écart inventaire (−)',
            self::TransferIn => 'Transfert reçu',
            self::TransferOut => 'Transfert envoyé',
        };
    }

    public function isIncoming(): bool
    {
        return in_array($this, [self::Initial, self::PurchaseIn, self::SaleReturn, self::AdjustmentIn, self::InventoryIn, self::TransferIn], true);
    }

    /**
     * Les entrées « valorisées » recalculent le CUMP avec leur propre coût.
     * Les autres entrées (ajustement, écart d'inventaire) entrent au CUMP courant
     * et ne le modifient donc pas.
     */
    public function affectsAverageCost(): bool
    {
        return in_array($this, [self::Initial, self::PurchaseIn, self::SaleReturn, self::TransferIn], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
