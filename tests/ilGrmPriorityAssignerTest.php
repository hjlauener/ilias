<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../classes/class.ilGrmPriorityAssigner.php";

/**
 * Method d: assignment by priorities.
 */
class ilGrmPriorityAssignerTest extends TestCase
{
    public function testEverybodyGetsAPlaceIfPossible(): void
    {
        // 2 must take the 2nd wish so that 1 (who only wishes A) gets a place
        $result = (new ilGrmPriorityAssigner())->assign([1 => [10], 2 => [10, 11]], [10 => 1, 11 => 1], false);
        $this->assertSame([1 => 10, 2 => 11], $result);
    }

    public function testNobodyGetsAThirdWishToImproveAnotherSlightly(): void
    {
        $priorities = [1 => [10, 11, 12], 2 => [10, 11, 12], 3 => [10, 12, 11]];
        $result = (new ilGrmPriorityAssigner())->assign($priorities, [10 => 1, 11 => 1, 12 => 1]);

        $cost = 0;
        foreach ($result as $user_id => $group_id) {
            $cost += ilGrmPriorityAssigner::cost((int) ilGrmPriorityAssigner::priorityOf($priorities[$user_id], $group_id));
        }
        $this->assertCount(3, $result);
        $this->assertSame(9, $cost); // 1 + 4 + 4, nobody gets the 3rd wish
    }

    public function testCapacitiesAreRespected(): void
    {
        $result = (new ilGrmPriorityAssigner())->assign([1 => [10], 2 => [10], 3 => [10]], [10 => 2]);
        $this->assertCount(2, $result);
    }

    public function testUnknownGroupsAndDoubleWishesAreIgnored(): void
    {
        $result = (new ilGrmPriorityAssigner())->assign([1 => [99, 10, 10]], [10 => 1]);
        $this->assertSame([1 => 10], $result);
        $this->assertSame(3, ilGrmPriorityAssigner::priorityOf([99, 11, 10], 10));
        $this->assertNull(ilGrmPriorityAssigner::priorityOf([11], 10));
    }

    public function testTiesAreBrokenRandomly(): void
    {
        $wins = [1 => 0, 2 => 0];
        for ($i = 0; $i < 200; $i++) {
            $result = (new ilGrmPriorityAssigner())->assign([1 => [10], 2 => [10]], [10 => 1]);
            $wins[array_key_first($result)]++;
        }
        $this->assertGreaterThan(40, $wins[1]);
        $this->assertGreaterThan(40, $wins[2]);
    }
}
