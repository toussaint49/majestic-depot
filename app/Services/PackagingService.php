<?php

namespace App\Services;

use App\Enums\PackagingMovementType as T;
use App\Exceptions\InsufficientPackagingException;
use App\Exceptions\MissingPackagingException;
use App\Models\Depot;
use App\Models\PackagingLevel;
use App\Models\PackagingMovement;
use App\Models\PackagingType;
use App\Models\ProductUnitPackaging;
use App\Support\CurrentTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Stock d'emballages vides par dépôt.
 *
 * Règle du pilote : le client rapporte toujours ses vides. Une vente de produit
 * consigné exige l'échange des vides correspondants, sinon elle est refusée.
 * Il n'y a ni caution, ni solde d'emballages par client.
 */
class PackagingService
{
    /**
     * Vides à échanger pour une liste de lignes vendues.
     *
     * @param  iterable<array{0: int, 1: int}>  $items  [product_unit_id, quantité dans ce conditionnement]
     * @return array<int, int> packaging_type_id => quantité
     */
    public function required(iterable $items): array
    {
        $required = [];
        foreach ($items as [$unitId, $qty]) {
            foreach (ProductUnitPackaging::where('product_unit_id', $unitId)->get() as $rule) {
                $required[$rule->packaging_type_id] = ($required[$rule->packaging_type_id] ?? 0) + $rule->quantity * $qty;
            }
        }
        ksort($required);

        return $required;
    }

    /**
     * Vérifie que le client a rapporté au moins les vides nécessaires.
     *
     * @param  array<int, int>  $required
     * @param  array<int, int>  $provided  packaging_type_id => quantité rapportée
     */
    public function assertProvided(array $required, array $provided): void
    {
        $missing = [];
        foreach ($required as $typeId => $qty) {
            $short = $qty - (int) ($provided[$typeId] ?? 0);
            if ($short > 0) {
                $missing[PackagingType::findOrFail($typeId)->name] = $short;
            }
        }

        if ($missing !== []) {
            throw new MissingPackagingException($missing);
        }
    }

    /**
     * Enregistre les vides reçus du client lors d'une vente.
     *
     * @param  iterable<array{0: int, 1: int}>  $items
     * @param  array<int, int>|null  $provided  quantités déclarées par le caissier ; null = vides conformes
     * @return array<int, int> vides enregistrés
     */
    public function collectForSale(Depot $depot, iterable $items, Model $source, ?array $provided = null, DateTimeInterface|string|null $occurredAt = null): array
    {
        $required = $this->required($items);
        if ($provided !== null) {
            $this->assertProvided($required, $provided);
        }

        DB::transaction(function () use ($depot, $required, $source, $occurredAt) {
            foreach ($required as $typeId => $qty) {
                $this->apply($depot, PackagingType::findOrFail($typeId), $qty, T::CustomerExchange, $source, null, $occurredAt);
            }
        });

        return $required;
    }

    /** Annulation d'une vente : rend au client les vides reçus pour cette vente. */
    public function reverseSaleExchange(Model $source, ?string $reason = null): void
    {
        DB::transaction(function () use ($source, $reason) {
            $movements = PackagingMovement::where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())->get();

            foreach ($movements->groupBy(fn ($m) => $m->depot_id.'-'.$m->packaging_type_id) as $group) {
                $net = (int) $group->sum('quantity');
                if ($net > 0) {
                    $first = $group->first();
                    $this->apply($first->depot, $first->packagingType, -$net, T::CustomerExchangeCancel, $source, $reason, null);
                }
            }
        });
    }

    public function receive(Depot $depot, PackagingType $type, int $qty, T $movementType = T::Initial, ?string $reason = null, DateTimeInterface|string|null $occurredAt = null, ?Model $source = null): PackagingMovement
    {
        if (! $movementType->isIncoming()) {
            throw new InvalidArgumentException("Le type {$movementType->value} n'est pas une entrée.");
        }

        return $this->apply($depot, $type, $qty, $movementType, $source, $reason, $occurredAt);
    }

    public function issue(Depot $depot, PackagingType $type, int $qty, T $movementType = T::SupplierOut, ?string $reason = null, DateTimeInterface|string|null $occurredAt = null, ?Model $source = null): PackagingMovement
    {
        if ($movementType->isIncoming()) {
            throw new InvalidArgumentException("Le type {$movementType->value} n'est pas une sortie.");
        }

        return $this->apply($depot, $type, -$qty, $movementType, $source, $reason, $occurredAt);
    }

    public function adjust(Depot $depot, PackagingType $type, int $delta, string $reason, DateTimeInterface|string|null $occurredAt = null): ?PackagingMovement
    {
        if ($delta === 0) {
            return null;
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Un ajustement doit être motivé.');
        }

        return $this->apply($depot, $type, $delta, $delta > 0 ? T::AdjustmentIn : T::AdjustmentOut, null, $reason, $occurredAt);
    }

    /** @return array{0: PackagingMovement, 1: PackagingMovement} */
    public function transfer(Depot $from, Depot $to, PackagingType $type, int $qty, ?string $reason = null, DateTimeInterface|string|null $occurredAt = null): array
    {
        if ($from->is($to)) {
            throw new InvalidArgumentException("Les dépôts de départ et d'arrivée doivent être différents.");
        }

        return DB::transaction(function () use ($from, $to, $type, $qty, $reason, $occurredAt) {
            $group = (string) Str::uuid();

            return [
                $this->apply($from, $type, -$qty, T::TransferOut, null, $reason, $occurredAt, $group),
                $this->apply($to, $type, $qty, T::TransferIn, null, $reason, $occurredAt, $group),
            ];
        });
    }

    public function available(Depot $depot, PackagingType $type): int
    {
        return (int) PackagingLevel::where('depot_id', $depot->id)->where('packaging_type_id', $type->id)->value('quantity');
    }

    private function apply(Depot $depot, PackagingType $type, int $signedQty, T $movementType, ?Model $source, ?string $reason, DateTimeInterface|string|null $occurredAt, ?string $transferGroup = null): PackagingMovement
    {
        if ($signedQty === 0) {
            throw new InvalidArgumentException('La quantité doit être différente de zéro.');
        }
        if ($signedQty > 0 !== $movementType->isIncoming()) {
            throw new InvalidArgumentException('Le sens de la quantité ne correspond pas au type de mouvement.');
        }

        $tenantId = app(CurrentTenant::class)->id();
        if ($tenantId === null || $depot->tenant_id !== $tenantId || $type->tenant_id !== $tenantId) {
            throw new LogicException("Dépôt et emballage doivent appartenir à l'entreprise active.");
        }

        return DB::transaction(function () use ($depot, $type, $signedQty, $movementType, $source, $reason, $occurredAt, $transferGroup) {
            $find = fn () => PackagingLevel::where('depot_id', $depot->id)->where('packaging_type_id', $type->id)->lockForUpdate()->first();
            $level = $find();
            if (! $level) {
                PackagingLevel::create(['depot_id' => $depot->id, 'packaging_type_id' => $type->id]);
                $level = $find();
            }

            $after = $level->quantity + $signedQty;
            if ($after < 0) {
                throw new InsufficientPackagingException($type, $level->quantity, -$signedQty);
            }

            $level->quantity = $after;
            $level->save();

            return PackagingMovement::create([
                'depot_id' => $depot->id,
                'packaging_type_id' => $type->id,
                'type' => $movementType,
                'quantity' => $signedQty,
                'quantity_after' => $after,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'transfer_group' => $transferGroup,
                'reason' => $reason,
                'user_id' => auth()->id(),
                'occurred_at' => $occurredAt ? Carbon::parse($occurredAt) : now(),
            ]);
        });
    }
}
