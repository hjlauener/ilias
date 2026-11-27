<?php

declare(strict_types=1);

/**
 * Methods b and f: swap market for fixed groups.
 *
 * A person (A, in group 1) hands in a swap request: "I would like to change to
 * group 2 (or 3)". A person B in one of these groups can accept it; then A and
 * B swap their places. If B has handed in a matching request himself/herself
 * ("from 2 to 1"), the places are swapped automatically. The group sizes never
 * change. Every swap is logged.
 *
 * All changes run inside a table lock (ilAtomQuery), so that a request cannot
 * be accepted twice and nobody is moved by two swaps at the same time.
 */
class ilGrmSwapMarket
{
    public const RESULT_PLACED = "placed";
    public const RESULT_SWAPPED = "swapped";
    public const RESULT_NOT_IN_GROUP = "not_in_group";
    public const RESULT_INVALID = "invalid";
    public const RESULT_GONE = "gone";

    private ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Hands in (or replaces) the swap request of a person. If somebody in one
     * of the target groups wants into the person's group, the places are
     * swapped right away (oldest matching request first).
     *
     * @param int[] $target_group_ids groups the person would like to change to
     * @return array{0: string, 1: int, 2: int, 3: int} [RESULT_PLACED | RESULT_SWAPPED | RESULT_NOT_IN_GROUP |
     *         RESULT_INVALID, new group of the person, other person and his/her new group (if swapped)]
     */
    public function placeRequest(int $mgmt_id, int $user_id, array $target_group_ids, string $note, int $now): array
    {
        $result = [self::RESULT_INVALID, 0, 0, 0];
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $user_id, $target_group_ids, $note, $now, &$result): void {
            $from_group = $this->currentGroup($db, $mgmt_id, $user_id);
            if ($from_group === null) {
                $result = [self::RESULT_NOT_IN_GROUP, 0, 0, 0];
                return;
            }
            $targets = array_values(array_diff(
                array_intersect(array_unique(array_map("intval", $target_group_ids)), $this->groupIds($db, $mgmt_id)),
                [$from_group]
            ));
            if ($targets === []) {
                $result = [self::RESULT_INVALID, 0, 0, 0];
                return;
            }

            $this->deleteRequestsOf($db, $mgmt_id, [$user_id]);

            // matching counter request: somebody in a target group wants into my group
            foreach ($this->fetchRequests($db, $mgmt_id) as $request) {
                if (in_array($request["from_group"], $targets, true)
                    && in_array($from_group, $request["targets"], true)
                    && $this->currentGroup($db, $mgmt_id, $request["user_id"]) === $request["from_group"]) {
                    $this->swap($db, $mgmt_id, $user_id, $from_group, $request["user_id"], $request["from_group"], $now);
                    $result = [self::RESULT_SWAPPED, $request["from_group"], $request["user_id"], $from_group];
                    return;
                }
            }

            $db->insert(ilGrmRepository::TABLE_SWAP_REQ, [
                "id" => ["integer", $db->nextId(ilGrmRepository::TABLE_SWAP_REQ)],
                "mgmt_id" => ["integer", $mgmt_id],
                "user_id" => ["integer", $user_id],
                "from_group" => ["integer", $from_group],
                "targets" => ["text", implode(",", $targets)],
                "note" => ["text", mb_substr(trim($note), 0, 255)],
                "created" => ["integer", $now],
            ]);
            $result = [self::RESULT_PLACED, 0, 0, 0];
        });
        return $result;
    }

    public function withdrawRequest(int $mgmt_id, int $user_id): void
    {
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $user_id): void {
            $this->deleteRequestsOf($db, $mgmt_id, [$user_id]);
        });
    }

    /**
     * A person in one of the target groups accepts a request: the two swap places.
     *
     * @return array{0: string, 1: int, 2: int, 3: int} [RESULT_SWAPPED | RESULT_GONE | RESULT_INVALID,
     *         new group of the acceptor, requester and his/her new group (if swapped)]
     */
    public function acceptRequest(int $mgmt_id, int $acceptor_id, int $request_id, int $now): array
    {
        $result = [self::RESULT_GONE, 0, 0, 0];
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $acceptor_id, $request_id, $now, &$result): void {
            $request = null;
            foreach ($this->fetchRequests($db, $mgmt_id) as $candidate) {
                if ($candidate["id"] === $request_id) {
                    $request = $candidate;
                    break;
                }
            }
            if ($request === null) {
                $result = [self::RESULT_GONE, 0, 0, 0];
                return;
            }
            if ($request["user_id"] === $acceptor_id) {
                $result = [self::RESULT_INVALID, 0, 0, 0];
                return;
            }
            if ($this->currentGroup($db, $mgmt_id, $request["user_id"]) !== $request["from_group"]) {
                // the requester has been moved meanwhile: the request is outdated
                $this->deleteRequestsOf($db, $mgmt_id, [$request["user_id"]]);
                $result = [self::RESULT_GONE, 0, 0, 0];
                return;
            }
            $acceptor_group = $this->currentGroup($db, $mgmt_id, $acceptor_id);
            if ($acceptor_group === null || !in_array($acceptor_group, $request["targets"], true)) {
                $result = [self::RESULT_INVALID, 0, 0, 0];
                return;
            }

            $this->swap($db, $mgmt_id, $request["user_id"], $request["from_group"], $acceptor_id, $acceptor_group, $now);
            $result = [self::RESULT_SWAPPED, $request["from_group"], $request["user_id"], $acceptor_group];
        });
        return $result;
    }

    /**
     * Open requests whose requester is still in the group he/she wants to leave
     * (oldest first).
     *
     * @return array<int, array{id: int, user_id: int, from_group: int, targets: int[], note: string, created: int}>
     */
    public function getOpenRequests(int $mgmt_id, array $assignments): array
    {
        $open = [];
        foreach ($this->fetchRequests($this->db, $mgmt_id) as $request) {
            if (($assignments[$request["user_id"]] ?? null) === $request["from_group"]) {
                $open[$request["id"]] = $request;
            }
        }
        return $open;
    }

    /**
     * Completed swaps, newest first.
     *
     * @return array<int, array{user_a: int, group_a: int, user_b: int, group_b: int, created: int}>
     */
    public function getLog(int $mgmt_id): array
    {
        $set = $this->db->query(
            "SELECT user_a, group_a, user_b, group_b, created FROM " . ilGrmRepository::TABLE_SWAP_LOG .
            " WHERE mgmt_id = " . $this->db->quote($mgmt_id, "integer") .
            " ORDER BY created DESC, id DESC"
        );
        $log = [];
        while ($rec = $this->db->fetchAssoc($set)) {
            $log[] = array_map("intval", $rec);
        }
        return $log;
    }

    /**
     * user A (in group A) and user B (in group B) swap their places.
     */
    private function swap(
        ilDBInterface $db,
        int $mgmt_id,
        int $user_a,
        int $group_a,
        int $user_b,
        int $group_b,
        int $now
    ): void {
        foreach ([[$user_a, $group_b], [$user_b, $group_a]] as [$user_id, $group_id]) {
            $db->manipulate(
                "UPDATE " . ilGrmRepository::TABLE_ASSIGN .
                " SET group_id = " . $db->quote($group_id, "integer") .
                " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
                " AND user_id = " . $db->quote($user_id, "integer")
            );
        }
        $this->deleteRequestsOf($db, $mgmt_id, [$user_a, $user_b]);
        $db->insert(ilGrmRepository::TABLE_SWAP_LOG, [
            "id" => ["integer", $db->nextId(ilGrmRepository::TABLE_SWAP_LOG)],
            "mgmt_id" => ["integer", $mgmt_id],
            "user_a" => ["integer", $user_a],
            "group_a" => ["integer", $group_a],
            "user_b" => ["integer", $user_b],
            "group_b" => ["integer", $group_b],
            "created" => ["integer", $now],
        ]);
    }

    /**
     * @return array<int, array{id: int, user_id: int, from_group: int, targets: int[], note: string, created: int}>
     */
    private function fetchRequests(ilDBInterface $db, int $mgmt_id): array
    {
        $set = $db->query(
            "SELECT id, user_id, from_group, targets, note, created FROM " . ilGrmRepository::TABLE_SWAP_REQ .
            " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
            " ORDER BY created, id"
        );
        $requests = [];
        while ($rec = $db->fetchAssoc($set)) {
            $requests[] = [
                "id" => (int) $rec["id"],
                "user_id" => (int) $rec["user_id"],
                "from_group" => (int) $rec["from_group"],
                "targets" => array_values(array_filter(array_map("intval", explode(",", (string) $rec["targets"])))),
                "note" => (string) ($rec["note"] ?? ""),
                "created" => (int) $rec["created"],
            ];
        }
        return $requests;
    }

    private function currentGroup(ilDBInterface $db, int $mgmt_id, int $user_id): ?int
    {
        $set = $db->query(
            "SELECT group_id FROM " . ilGrmRepository::TABLE_ASSIGN .
            " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
            " AND user_id = " . $db->quote($user_id, "integer")
        );
        $rec = $db->fetchAssoc($set);
        return $rec ? (int) $rec["group_id"] : null;
    }

    /**
     * @return int[]
     */
    private function groupIds(ilDBInterface $db, int $mgmt_id): array
    {
        $set = $db->query(
            "SELECT id FROM " . ilGrmRepository::TABLE_GROUP .
            " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer")
        );
        $ids = [];
        while ($rec = $db->fetchAssoc($set)) {
            $ids[] = (int) $rec["id"];
        }
        return $ids;
    }

    /**
     * @param int[] $user_ids
     */
    private function deleteRequestsOf(ilDBInterface $db, int $mgmt_id, array $user_ids): void
    {
        foreach ($user_ids as $user_id) {
            $db->manipulate(
                "DELETE FROM " . ilGrmRepository::TABLE_SWAP_REQ .
                " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
                " AND user_id = " . $db->quote($user_id, "integer")
            );
        }
    }

    private function atomic(callable $queries): void
    {
        $atom = $this->db->buildAtomQuery();
        $atom->addTableLock(ilGrmRepository::TABLE_GROUP);
        $atom->addTableLock(ilGrmRepository::TABLE_ASSIGN);
        $atom->addTableLock(ilGrmRepository::TABLE_SWAP_REQ)->lockSequence(true);
        $atom->addTableLock(ilGrmRepository::TABLE_SWAP_LOG)->lockSequence(true);
        $atom->addQueryCallable(static function (ilDBInterface $db) use ($queries): void {
            $queries($db);
        });
        $atom->run();
    }
}
