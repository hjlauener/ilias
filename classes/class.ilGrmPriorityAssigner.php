<?php

declare(strict_types=1);

/**
 * Method d: assignment by priorities.
 *
 * Every person hands in an ordered list of wishes (1st, 2nd, 3rd … priority)
 * and every group has a limited number of places. The assigner finds an
 * assignment that
 *
 *   1. gives a place to as many persons as possible and, among all those
 *      solutions,
 *   2. has the smallest sum of squared priorities (1, 4, 9, …): many good
 *      priorities, and a bad priority for one person is not accepted just to
 *      improve the priority of another person a little.
 *
 * This is a min-cost max-flow problem (source → person → wished groups →
 * sink). It is solved exactly with successive shortest augmenting paths.
 * Ties are broken randomly; the time of the registration never matters.
 */
class ilGrmPriorityAssigner
{
    /** @var int[] target node of each edge */
    private array $edge_to = [];
    /** @var int[] remaining capacity of each edge */
    private array $edge_cap = [];
    /** @var int[] cost of each edge */
    private array $edge_cost = [];
    /** @var array<int, int[]> node => ids of the outgoing edges */
    private array $adjacent = [];

    /**
     * @param array<int, int[]> $priorities user id => group ids, highest priority first
     * @param array<int, int>   $capacities group id => number of places
     * @param bool              $randomize  break ties randomly (switch off for reproducible tests only)
     * @return array<int, int> user id => group id; persons that could not get a place are missing
     */
    public function assign(array $priorities, array $capacities, bool $randomize = true): array
    {
        $user_ids = array_keys($priorities);
        if ($randomize) {
            $user_ids = $this->shuffle($user_ids);
        }

        // nodes: 0 = source, 1 … U = persons, U+1 … U+G = groups, U+G+1 = sink
        $group_nodes = [];
        $node = count($user_ids);
        foreach ($capacities as $group_id => $capacity) {
            $node++;
            if ((int) $capacity > 0) {
                $group_nodes[(int) $group_id] = $node;
            }
        }
        $source = 0;
        $sink = $node + 1;

        $this->edge_to = [];
        $this->edge_cap = [];
        $this->edge_cost = [];
        $this->adjacent = array_fill(0, $sink + 1, []);

        /** @var array<int, array<int, int>> $wish_edges user id => [edge id => group id] */
        $wish_edges = [];
        foreach ($user_ids as $index => $user_id) {
            $user_node = $index + 1;
            $this->addEdge($source, $user_node, 1, 0);

            $wish_edges[$user_id] = [];
            $seen = [];
            foreach (array_values($priorities[$user_id]) as $position => $group_id) {
                $group_id = (int) $group_id;
                if (isset($seen[$group_id]) || !isset($group_nodes[$group_id])) {
                    continue; // double wish, unknown group or group without places
                }
                $seen[$group_id] = true;
                $edge = $this->addEdge($user_node, $group_nodes[$group_id], 1, self::cost($position + 1));
                $wish_edges[$user_id][$edge] = $group_id;
            }
        }
        foreach ($group_nodes as $group_id => $group_node) {
            $this->addEdge($group_node, $sink, (int) $capacities[$group_id], 0);
        }

        while ($this->augment($source, $sink)) {
            // one more person gets a place in every round
        }

        $result = [];
        foreach ($priorities as $user_id => $unused) {
            foreach ($wish_edges[$user_id] as $edge => $group_id) {
                if ($this->edge_cap[$edge] === 0) {
                    $result[$user_id] = $group_id;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Priority (1 = first wish) a person got, null if the group was not among the wishes.
     *
     * @param int[] $wishes group ids, highest priority first
     */
    public static function priorityOf(array $wishes, int $group_id): ?int
    {
        foreach (array_values($wishes) as $position => $wished_group_id) {
            if ((int) $wished_group_id === $group_id) {
                return $position + 1;
            }
        }
        return null;
    }

    /**
     * Cost of an assignment with the given priority (1 = first wish).
     */
    public static function cost(int $priority): int
    {
        return $priority * $priority;
    }

    /**
     * Sends one more unit of flow along a cheapest path from source to sink
     * (Bellman-Ford with a queue; the residual graph contains negative costs).
     */
    private function augment(int $source, int $sink): bool
    {
        $nodes = count($this->adjacent);
        $distance = array_fill(0, $nodes, PHP_INT_MAX);
        $previous_edge = array_fill(0, $nodes, -1);
        $in_queue = array_fill(0, $nodes, false);

        $distance[$source] = 0;
        $queue = [$source];
        $head = 0;
        while ($head < count($queue)) {
            $node = $queue[$head++];
            $in_queue[$node] = false;
            $node_distance = $distance[$node];
            foreach ($this->adjacent[$node] as $edge) {
                if ($this->edge_cap[$edge] <= 0) {
                    continue;
                }
                $target = $this->edge_to[$edge];
                $new_distance = $node_distance + $this->edge_cost[$edge];
                if ($new_distance < $distance[$target]) {
                    $distance[$target] = $new_distance;
                    $previous_edge[$target] = $edge;
                    if (!$in_queue[$target]) {
                        $in_queue[$target] = true;
                        $queue[] = $target;
                    }
                }
            }
        }

        if ($distance[$sink] === PHP_INT_MAX) {
            return false;
        }

        // all paths start with a "source → person" edge of capacity 1
        $node = $sink;
        while ($node !== $source) {
            $edge = $previous_edge[$node];
            $this->edge_cap[$edge]--;
            $this->edge_cap[$edge ^ 1]++;
            $node = $this->edge_to[$edge ^ 1];
        }
        return true;
    }

    /**
     * Adds an edge and its reverse edge (ids 2n and 2n+1).
     *
     * @return int id of the forward edge
     */
    private function addEdge(int $from, int $to, int $capacity, int $cost): int
    {
        $edge = count($this->edge_to);

        $this->edge_to[] = $to;
        $this->edge_cap[] = $capacity;
        $this->edge_cost[] = $cost;
        $this->adjacent[$from][] = $edge;

        $this->edge_to[] = $from;
        $this->edge_cap[] = 0;
        $this->edge_cost[] = -$cost;
        $this->adjacent[$to][] = $edge + 1;

        return $edge;
    }

    /**
     * Fisher–Yates shuffle with a cryptographically secure random source.
     *
     * @param int[] $items
     * @return int[]
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
