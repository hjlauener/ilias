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

    private function atomic(callable $queries): void
    {
        $atom = $this->db->buildAtomQuery();
        $atom->addTableLock(ilGrmRepository::TABLE_GROUP);
        $atom->addTableLock(ilGrmRepository::TABLE_ASSIGN);
        $atom->addTableLock(ilGrmRepository::TABLE_WAIT);
        $atom->addQueryCallable(static function (ilDBInterface $db) use ($queries): void {
            $queries($db);
        });
        $atom->run();
    }
}
