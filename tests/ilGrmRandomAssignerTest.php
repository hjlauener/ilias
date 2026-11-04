<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../classes/class.ilGrmRandomAssigner.php";
require_once __DIR__ . "/../classes/class.ilGrmManagement.php";
require_once __DIR__ . "/../classes/class.ilGrmMethods.php";

/**
 * Run with the PHPUnit shipped with ILIAS, e.g.
 *   ./libs/composer/vendor/bin/phpunit Customizing/global/plugins/Services/Repository/RepositoryObject/GroupManagement/tests
 */
class ilGrmRandomAssignerTest extends TestCase
{
    public function testEveryUserIsAssignedExactlyOnce(): void
    {
        $users = range(100, 136);
        $result = (new ilGrmRandomAssigner())->assign($users, 5);

        $this->assertCount(count($users), $result);
        $this->assertEqualsCanonicalizing($users, array_keys($result));
        foreach ($result as $group) {
            $this->assertGreaterThanOrEqual(0, $group);
            $this->assertLessThan(5, $group);
        }
    }

    public function testDistributionIsBalanced(): void
    {
        for ($run = 0; $run < 50; $run++) {
            $result = (new ilGrmRandomAssigner())->assign(range(1, 23), 4);
            $sizes = array_count_values($result);
            $this->assertCount(4, $sizes);
            $this->assertLessThanOrEqual(1, max($sizes) - min($sizes));
        }
    }

    public function testMoreGroupsThanUsers(): void
    {
        $result = (new ilGrmRandomAssigner())->assign([1, 2], 5);
        $this->assertCount(2, $result);
        $this->assertNotSame($result[1], $result[2]);
    }

    public function testDuplicatesAreIgnored(): void
    {
        $result = (new ilGrmRandomAssigner())->assign([1, 1, 2, 2, 3], 2);
        $this->assertCount(3, $result);
    }

    public function testZeroGroupsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ilGrmRandomAssigner())->assign([1, 2], 0);
    }

    public function testLatecomersGoToSmallestGroups(): void
    {
        $result = (new ilGrmRandomAssigner())->assignToSmallest([7, 8, 9], [10 => 3, 11 => 1, 12 => 2]);

        $this->assertCount(3, $result);
        $sizes = [10 => 3, 11 => 1, 12 => 2];
        foreach ($result as $group_id) {
            $sizes[$group_id]++;
        }
        $this->assertSame([10 => 3, 11 => 3, 12 => 3], $sizes);
    }

    public function testTopicsAreParsedLineByLine(): void
    {
        $this->assertSame(
            ["Thema A", "Thema B", "Thema C"],
            ilGrmManagement::parseTopics("Thema A\r\n\n  Thema B  \nThema C\n")
        );
    }

    public function testGroupTitles(): void
    {
        $mgmt = new ilGrmManagement(1, ilGrmMethods::METHOD_A);
        $mgmt->setNumGroups(3);
        $this->assertSame(["G1", "G2", "G3"], $mgmt->buildGroupTitles(fn(int $n): string => "G" . $n));

        $mgmt->setAssignMode(ilGrmManagement::MODE_TOPICS);
        $mgmt->setTopics(["X", "Y"]);
        $this->assertSame(["X", "Y"], $mgmt->buildGroupTitles(fn(int $n): string => "G" . $n));
        $this->assertSame(2, $mgmt->getEffectiveNumberOfGroups());
    }

    /**
     * Method b: number of groups for 11 people in groups of 4.
     */
    public function testMethodBNumberOfGroups(): void
    {
        $mgmt = new ilGrmManagement(1, ilGrmMethods::METHOD_B);
        $mgmt->setGroupSize(4);

        $mgmt->setRemainderMode(ilGrmManagement::REMAINDER_NEAREST);
        $this->assertSame(3, $mgmt->getEffectiveNumberOfGroups(11));   // 4, 4, 3

        $mgmt->setRemainderMode(ilGrmManagement::REMAINDER_SMALLER);
        $this->assertSame(3, $mgmt->getEffectiveNumberOfGroups(11));   // 4, 4, 3

        $mgmt->setRemainderMode(ilGrmManagement::REMAINDER_DISTRIBUTE);
        $this->assertSame(2, $mgmt->getEffectiveNumberOfGroups(11));   // 6, 5

        $this->assertSame(1, $mgmt->getEffectiveNumberOfGroups(3));    // fewer people than the group size
        $this->assertSame(0, $mgmt->getEffectiveNumberOfGroups(0));
    }

    public function testMethodBGroupSizesStayWithinLimits(): void
    {
        $mgmt = new ilGrmManagement(1, ilGrmMethods::METHOD_B);
        $mgmt->setGroupSize(3);
        $mgmt->setRemainderMode(ilGrmManagement::REMAINDER_SMALLER);

        $groups = $mgmt->getEffectiveNumberOfGroups(10);
        $sizes = array_count_values((new ilGrmRandomAssigner())->assign(range(1, 10), $groups));
        $this->assertLessThanOrEqual(3, max($sizes));
        $this->assertSame(10, array_sum($sizes));
    }
}
