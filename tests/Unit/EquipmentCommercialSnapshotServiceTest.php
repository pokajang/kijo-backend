<?php

namespace Tests\Unit;

use App\Services\Equipment\EquipmentCommercialSnapshotService;
use PHPUnit\Framework\TestCase;

class EquipmentCommercialSnapshotServiceTest extends TestCase
{
    public function test_missing_remarks_follow_item_identity_when_rows_are_reordered(): void
    {
        $service = new EquipmentCommercialSnapshotService;

        $items = $service->preserveMissingItemRemarks(
            [
                ['item_description' => 'Respirator'],
                ['item_description' => 'Gas   Detector'],
            ],
            [
                (object) ['item_description' => 'Gas Detector', 'item_remarks' => 'Navy enclosure'],
                (object) ['item_description' => 'Respirator', 'item_remarks' => 'Size XXL'],
            ],
        );

        $this->assertSame('Size XXL', $items[0]['item_remarks']);
        $this->assertSame('Navy enclosure', $items[1]['item_remarks']);
    }

    public function test_explicit_blank_remark_is_not_replaced(): void
    {
        $service = new EquipmentCommercialSnapshotService;

        $items = $service->preserveMissingItemRemarks(
            [['item_name' => 'Gas Detector', 'item_remarks' => '']],
            [['item_name' => 'Gas Detector', 'item_remarks' => 'Original remark']],
        );

        $this->assertSame('', $items[0]['item_remarks']);
    }
}
