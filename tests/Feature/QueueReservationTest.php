<?php

namespace Tests\Feature;

use Exception;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

/**
 * Queued buildings are paid for when they are queued, so the queue is limited by resources, not by a small fixed count.
 */
class QueueReservationTest extends IsolatedAccountTestCase
{
    public function testQueuingMoreThanFiveItemsWorksWhenAffordable(): void
    {
        $this->planetAddResources(new Resources(10000000, 10000000, 10000000, 0));
        $queue = resolve(BuildingQueueService::class);
        $metalMine = ObjectService::getObjectByMachineName('metal_mine');

        for ($i = 0; $i < 8; $i++) {
            $queue->add($this->planetService, $metalMine->id);
        }

        $this->assertCount(8, $queue->retrieveQueueItems($this->planetService));
    }

    public function testResourcesAreReservedWhenQueuing(): void
    {
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $before = Planet::findOrFail($this->currentPlanetId);
        $metalBefore = $before->metal;

        $queue = resolve(BuildingQueueService::class);
        $metalMine = ObjectService::getObjectByMachineName('metal_mine');
        $queue->add($this->planetService, $metalMine->id);
        $queue->add($this->planetService, $metalMine->id);

        $expected = ObjectService::getObjectRawPrice('metal_mine', 1)->metal->get() + ObjectService::getObjectRawPrice('metal_mine', 2)->metal->get();
        $after = Planet::findOrFail($this->currentPlanetId);

        // Production over a few seconds can add a little metal, never remove it.
        $this->assertGreaterThanOrEqual($metalBefore - $expected, $after->metal);
        $this->assertLessThan($metalBefore - $expected + 1000, $after->metal);
    }

    public function testQueuingFailsWhenNotAffordable(): void
    {
        $this->planetDeductResources(new Resources(500, 500, 0, 0));
        $queue = resolve(BuildingQueueService::class);

        $this->expectException(Exception::class);
        $queue->add($this->planetService, ObjectService::getObjectByMachineName('metal_mine')->id);
    }
}
