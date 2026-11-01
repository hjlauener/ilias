<?php

declare(strict_types=1);

/**
 * Random distribution of users into groups (method a).
 *
 * The distribution is balanced: group sizes differ by at most one. Which
 * groups receive the additional members is random as well.
 */
class ilGrmRandomAssigner
{
    /**
     * Distributes the users randomly into $num_groups groups.
     *
     * @param int[] $user_ids
     * @return array<int, int> user id => group index (0 based)
     */
    public function assign(array $user_ids, int $num_groups): array
    {
        if ($num_groups < 1) {
            throw new InvalidArgumentException("At least one group is required.");
        }

        $users = $this->shuffle(array_values(array_unique(array_map("intval", $user_ids))));
        $group_order = $this->shuffle(range(0, $num_groups - 1));

        $result = [];
        foreach ($users as $i => $user_id) {
            $result[$user_id] = $group_order[$i % $num_groups];
        }
        return $result;
    }

    /**
     * Adds users to an existing distribution: every user is put into one of
     * the currently smallest groups (random choice among ties).
     *
     * @param int[]           $user_ids
     * @param array<int, int> $group_sizes group id => current number of members
     * @return array<int, int> user id => group id
     */
    public function assignToSmallest(array $user_ids, array $group_sizes): array
    {
        if ($group_sizes === []) {
            throw new InvalidArgumentException("At least one group is required.");
        }

        $result = [];
        foreach ($this->shuffle(array_values(array_unique(array_map("intval", $user_ids)))) as $user_id) {
            $min = min($group_sizes);
            $candidates = array_keys(array_filter($group_sizes, static fn(int $size): bool => $size === $min));
            $group_id = $candidates[random_int(0, count($candidates) - 1)];
            $result[$user_id] = $group_id;
            $group_sizes[$group_id]++;
        }
        return $result;
    }

    /**
     * Fisher–Yates shuffle using a cryptographically secure random source.
     *
     * @template T
     * @param T[] $items
     * @return T[]
     */
    private function shuffle(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }
}
