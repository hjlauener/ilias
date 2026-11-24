<?php

declare(strict_types=1);

/**
 * Method c (first come – first serve): self enrollment of students with
 * limited places per group, optional waitlist and automatic move-up.
 *
 * All changes run inside a table lock (ilAtomQuery), so that two students
 * cannot get the same last place at the same time.
 */
class ilGrmEnrollment
{
    public const RESULT_ENROLLED = "enrolled";
    public const RESULT_WAITLISTED = "waitlisted";
    public const RESULT_FULL = "full";
    public const RESULT_ALREADY = "already";
    public const RESULT_NO_GROUP = "no_group";

    private ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Enrolls a user into a group – or puts him/her on the waitlist of the
     * group if it is full and the waitlist is allowed.
     *
     * @return string one of the RESULT_* constants
     */
    public function enroll(int $mgmt_id, int $group_id, int $user_id, bool $allow_waitlist): string
    {
        $result = self::RESULT_NO_GROUP;
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $group_id, $user_id, $allow_waitlist, &$result): void {
            $capacity = $this->fetchCapacity($db, $mgmt_id, $group_id);
            if ($capacity === null) {
                $result = self::RESULT_NO_GROUP;
                return;
            }
            if ($this->isEnrolledOrWaiting($db, $mgmt_id, $user_id)) {
                $result = self::RESULT_ALREADY;
                return;
            }
            if ($this->countMembers($db, $mgmt_id, $group_id) < $capacity) {
                $this->insertMember($db, $mgmt_id, $group_id, $user_id);
                $result = self::RESULT_ENROLLED;
                return;
            }
            if (!$allow_waitlist) {
                $result = self::RESULT_FULL;
                return;
            }
            $db->insert(ilGrmRepository::TABLE_WAIT, [
                "mgmt_id" => ["integer", $mgmt_id],
                "user_id" => ["integer", $user_id],
                "group_id" => ["integer", $group_id],
                "position" => ["integer", $this->nextWaitPosition($db, $mgmt_id)],
                "created" => ["integer", time()],
            ]);
            $result = self::RESULT_WAITLISTED;
        });
        return $result;
    }

    /**
     * Method e: puts the user into the first group with a free place. If all
     * groups are full, a new group with the given number of places is opened.
     *
     * @param string $title_pattern sprintf pattern for the title of a new group, e.g. "Gruppe %s"
     * @return array{0: string, 1: int} [RESULT_ENROLLED or RESULT_ALREADY, group id]
     */
    public function enrollIntoNextGroup(int $mgmt_id, int $user_id, int $capacity, string $title_pattern): array
    {
        $result = [self::RESULT_ALREADY, 0];
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $user_id, $capacity, $title_pattern, &$result): void {
            if ($this->isEnrolledOrWaiting($db, $mgmt_id, $user_id)) {
                $result = [self::RESULT_ALREADY, 0];
                return;
            }

            $capacities = $this->fetchCapacities($db, $mgmt_id);
            foreach ($capacities as $group_id => $places) {
                if ($this->countMembers($db, $mgmt_id, $group_id) < $places) {
                    $this->insertMember($db, $mgmt_id, $group_id, $user_id);
                    $result = [self::RESULT_ENROLLED, $group_id];
                    return;
                }
            }

            // all groups are full: open a new one
            $position = count($capacities);
            $group_id = $db->nextId(ilGrmRepository::TABLE_GROUP);
            $db->insert(ilGrmRepository::TABLE_GROUP, [
                "id" => ["integer", $group_id],
                "mgmt_id" => ["integer", $mgmt_id],
                "title" => ["text", sprintf($title_pattern, $position + 1)],
                "position" => ["integer", $position],
                "capacity" => ["integer", max(1, $capacity)],
            ]);
            $this->insertMember($db, $mgmt_id, $group_id, $user_id);
            $result = [self::RESULT_ENROLLED, $group_id];
        }, true);
        return $result;
    }

    /**
     * Removes a user from his/her group or from the waitlist. A place that
     * becomes free goes to the first person on the waitlist of that group.
     *
     * @return int[] users that moved up from the waitlist
     */
    public function unenroll(int $mgmt_id, int $user_id): array
    {
        $moved = [];
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $user_id, &$moved): void {
            $set = $db->query(
                "SELECT group_id FROM " . ilGrmRepository::TABLE_ASSIGN .
                " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
                " AND user_id = " . $db->quote($user_id, "integer")
            );
            $rec = $db->fetchAssoc($set);
            $group_id = $rec ? (int) $rec["group_id"] : 0;

            foreach ([ilGrmRepository::TABLE_ASSIGN, ilGrmRepository::TABLE_WAIT] as $table) {
                $db->manipulate(
                    "DELETE FROM " . $table .
                    " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
                    " AND user_id = " . $db->quote($user_id, "integer")
                );
            }

            if ($group_id > 0) {
                $moved = $this->fillFromWaitlist($db, $mgmt_id, $group_id);
            }
        });
        return $moved;
    }

    /**
     * Lecturer: moves a person into another group (also from the waitlist)
     * or removes him/her ($target_group_id = 0). The capacity is not checked,
     * lecturers may overbook a group. A place that becomes free is filled
     * from the waitlist if $fill_from_waitlist is set.
     *
     * @return array{ok: bool, moved: int[]} ok = false if the target group does not belong to the management
     */
    public function moveMember(int $mgmt_id, int $user_id, int $target_group_id, bool $fill_from_waitlist): array
    {
        $result = ["ok" => false, "moved" => []];
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $user_id, $target_group_id, $fill_from_waitlist, &$result): void {
            if ($target_group_id > 0 && $this->fetchCapacity($db, $mgmt_id, $target_group_id) === null) {
                return;
            }

            $set = $db->query(
                "SELECT group_id FROM " . ilGrmRepository::TABLE_ASSIGN .
                " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
                " AND user_id = " . $db->quote($user_id, "integer")
            );
            $rec = $db->fetchAssoc($set);
            $old_group_id = $rec ? (int) $rec["group_id"] : 0;

            foreach ([ilGrmRepository::TABLE_ASSIGN, ilGrmRepository::TABLE_WAIT] as $table) {
                $db->manipulate(
                    "DELETE FROM " . $table .
                    " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
                    " AND user_id = " . $db->quote($user_id, "integer")
                );
            }
            if ($target_group_id > 0) {
                $this->insertMember($db, $mgmt_id, $target_group_id, $user_id);
            }

            $result["ok"] = true;
            if ($fill_from_waitlist && $old_group_id > 0 && $old_group_id !== $target_group_id) {
                $result["moved"] = $this->fillFromWaitlist($db, $mgmt_id, $old_group_id);
            }
        });
        return $result;
    }

    /**
     * Replaces all assignments of a management, e.g. with the result of the
     * assignment algorithm (method d). The groups stay untouched; groups that
     * do not belong to the management are ignored.
     *
     * @param array<int, int> $assignments user id => group id
     */
    public function replaceAssignments(int $mgmt_id, array $assignments): void
    {
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $assignments): void {
            $group_ids = array_keys($this->fetchCapacities($db, $mgmt_id));

            $db->manipulate(
                "DELETE FROM " . ilGrmRepository::TABLE_ASSIGN .
                " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer")
            );
            foreach ($assignments as $user_id => $group_id) {
                if (in_array((int) $group_id, $group_ids, true)) {
                    $this->insertMember($db, $mgmt_id, (int) $group_id, (int) $user_id);
                }
            }
        });
    }

    /**
     * Lecturer: distributes persons without a group randomly to the free
     * places (the group with the most free places first). Persons for whom
     * no place is left stay without a group.
     *
     * @param int[] $user_ids
     * @return array<int, int> user id => group id of the persons that got a place
     */
    public function fillFreePlaces(int $mgmt_id, array $user_ids): array
    {
        $assigned = [];
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $user_ids, &$assigned): void {
            $assigned = [];
            $free = [];
            foreach ($this->fetchCapacities($db, $mgmt_id) as $group_id => $capacity) {
                $free[$group_id] = $capacity - $this->countMembers($db, $mgmt_id, $group_id);
            }

            $user_ids = array_values(array_unique(array_map("intval", $user_ids)));
            for ($i = count($user_ids) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$user_ids[$i], $user_ids[$j]] = [$user_ids[$j], $user_ids[$i]];
            }

            foreach ($user_ids as $user_id) {
                $most_free = $free === [] ? 0 : max($free);
                if ($most_free <= 0) {
                    break;
                }
                if ($this->isEnrolledOrWaiting($db, $mgmt_id, $user_id)) {
                    continue;
                }
                $candidates = array_keys($free, $most_free, true);
                $group_id = $candidates[random_int(0, count($candidates) - 1)];
                $this->insertMember($db, $mgmt_id, $group_id, $user_id);
                $free[$group_id]--;
                $assigned[$user_id] = $group_id;
            }
        });
        return $assigned;
    }

    /**
     * Changes the number of places per group; free places are filled from
     * the waitlist right away.
     *
     * @param array<int, int> $capacities group id => number of places
     * @return int[] users that moved up from the waitlist
     */
    public function setCapacities(int $mgmt_id, array $capacities): array
    {
        $moved = [];
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $capacities, &$moved): void {
            foreach ($capacities as $group_id => $capacity) {
                $db->manipulate(
                    "UPDATE " . ilGrmRepository::TABLE_GROUP .
                    " SET capacity = " . $db->quote(max(0, (int) $capacity), "integer") .
                    " WHERE id = " . $db->quote((int) $group_id, "integer") .
                    " AND mgmt_id = " . $db->quote($mgmt_id, "integer")
                );
            }
            foreach (array_keys($capacities) as $group_id) {
                $moved = array_merge($moved, $this->fillFromWaitlist($db, $mgmt_id, (int) $group_id));
            }
        });
        return $moved;
    }

    /**
     * @return int[] users that moved up
     */
    private function fillFromWaitlist(ilDBInterface $db, int $mgmt_id, int $group_id): array
    {
        $capacity = $this->fetchCapacity($db, $mgmt_id, $group_id);
        if ($capacity === null) {
            return [];
        }
        $free = $capacity - $this->countMembers($db, $mgmt_id, $group_id);
        if ($free <= 0) {
            return [];
        }

        $set = $db->query(
            "SELECT user_id FROM " . ilGrmRepository::TABLE_WAIT .
            " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
            " AND group_id = " . $db->quote($group_id, "integer") .
            " ORDER BY position, user_id"
        );
        $waiting = [];
        while ($rec = $db->fetchAssoc($set)) {
            $waiting[] = (int) $rec["user_id"];
        }

        $moved = [];
        foreach (array_slice($waiting, 0, $free) as $user_id) {
            $db->manipulate(
                "DELETE FROM " . ilGrmRepository::TABLE_WAIT .
                " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
                " AND user_id = " . $db->quote($user_id, "integer")
            );
            $this->insertMember($db, $mgmt_id, $group_id, $user_id);
            $moved[] = $user_id;
        }
        return $moved;
    }

    /**
     * @return array<int, int> group id => number of places
     */
    private function fetchCapacities(ilDBInterface $db, int $mgmt_id): array
    {
        $set = $db->query(
            "SELECT id, capacity FROM " . ilGrmRepository::TABLE_GROUP .
            " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
            " ORDER BY position, id"
        );
        $capacities = [];
        while ($rec = $db->fetchAssoc($set)) {
            $capacities[(int) $rec["id"]] = (int) $rec["capacity"];
        }
        return $capacities;
    }

    private function fetchCapacity(ilDBInterface $db, int $mgmt_id, int $group_id): ?int
    {
        $set = $db->query(
            "SELECT capacity FROM " . ilGrmRepository::TABLE_GROUP .
            " WHERE id = " . $db->quote($group_id, "integer") .
            " AND mgmt_id = " . $db->quote($mgmt_id, "integer")
        );
        $rec = $db->fetchAssoc($set);
        return $rec ? (int) $rec["capacity"] : null;
    }

    private function countMembers(ilDBInterface $db, int $mgmt_id, int $group_id): int
    {
        $set = $db->query(
            "SELECT COUNT(*) cnt FROM " . ilGrmRepository::TABLE_ASSIGN .
            " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
            " AND group_id = " . $db->quote($group_id, "integer")
        );
        $rec = $db->fetchAssoc($set);
        return (int) ($rec["cnt"] ?? 0);
    }

    private function isEnrolledOrWaiting(ilDBInterface $db, int $mgmt_id, int $user_id): bool
    {
        foreach ([ilGrmRepository::TABLE_ASSIGN, ilGrmRepository::TABLE_WAIT] as $table) {
            $set = $db->query(
                "SELECT user_id FROM " . $table .
                " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
                " AND user_id = " . $db->quote($user_id, "integer")
            );
            if ($db->fetchAssoc($set)) {
                return true;
            }
        }
        return false;
    }

    private function nextWaitPosition(ilDBInterface $db, int $mgmt_id): int
    {
        $set = $db->query(
            "SELECT MAX(position) maxpos FROM " . ilGrmRepository::TABLE_WAIT .
            " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer")
        );
        $rec = $db->fetchAssoc($set);
        return (int) ($rec["maxpos"] ?? 0) + 1;
    }

    private function insertMember(ilDBInterface $db, int $mgmt_id, int $group_id, int $user_id): void
    {
        $db->insert(ilGrmRepository::TABLE_ASSIGN, [
            "mgmt_id" => ["integer", $mgmt_id],
            "user_id" => ["integer", $user_id],
            "group_id" => ["integer", $group_id],
        ]);
    }

    /**
     * @param bool $with_group_sequence also lock the id sequence of the groups (needed to create groups)
     */
    private function atomic(callable $queries, bool $with_group_sequence = false): void
    {
        $atom = $this->db->buildAtomQuery();
        $atom->addTableLock(ilGrmRepository::TABLE_GROUP)->lockSequence($with_group_sequence);
        $atom->addTableLock(ilGrmRepository::TABLE_ASSIGN);
        $atom->addTableLock(ilGrmRepository::TABLE_WAIT);
        $atom->addQueryCallable(static function (ilDBInterface $db) use ($queries): void {
            $queries($db);
        });
        $atom->run();
    }
}
