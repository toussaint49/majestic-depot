<?php

namespace Tests\Unit;

use App\Enums\StockMovementType as T;
use PHPUnit\Framework\TestCase;

class StockMovementTypeTest extends TestCase
{
    public function test_only_valued_entries_change_the_average_cost(): void
    {
        foreach (T::cases() as $type) {
            if ($type->affectsAverageCost()) {
                $this->assertTrue($type->isIncoming(), "{$type->value} recalcule le CUMP mais n'est pas une entrée");
            }
        }

        $this->assertFalse(T::AdjustmentIn->affectsAverageCost());
        $this->assertFalse(T::InventoryIn->affectsAverageCost());
        $this->assertTrue(T::PurchaseIn->affectsAverageCost());
    }

    public function test_every_type_has_a_french_label(): void
    {
        $this->assertCount(count(T::cases()), array_filter(T::options()));
    }
}
