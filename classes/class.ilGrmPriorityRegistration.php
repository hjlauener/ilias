<?php

declare(strict_types=1);

/**
 * Method d (registration with priorities): the wishes of the students.
 *
 * Wishes can only be changed while the registration is open. Saving the
 * wishes and closing the registration run inside the same table lock
 * (ilAtomQuery), so that no wish can slip in after the registration has been
 * closed and the assignment is calculated.
 */
class ilGrmPriorityRegistration
{
    private ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Saves the wishes of a person (replaces earlier wishes).
     *
     * @param int[] $group_ids highest priority first
     * @return bool false if the registration is not open (nothing is saved)
     */
    public function saveWishes(int $mgmt_id, int $user_id, array $group_ids): bool
    {
        $saved = false;
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $user_id, $group_ids, &$saved): void {
            $saved = false;
            if (!$this->isOpen($db, $mgmt_id)) {
                return;
            }
            $this->deleteWishes($db, $mgmt_id, $user_id);
            foreach (array_values($group_ids) as $position => $group_id) {
                $db->insert(ilGrmRepository::TABLE_PRIO, [
                    "mgmt_id" => ["integer", $mgmt_id],
                    "user_id" => ["integer", $user_id],
                    "prio" => ["integer", $position + 1],
                    "group_id" => ["integer", (int) $group_id],
                ]);
            }
            $saved = true;
        });
        return $saved;
    }

    /**
     * Withdraws the registration of a person.
     *
     * @return bool false if the registration is not open (nothing is changed)
     */
    public function withdraw(int $mgmt_id, int $user_id): bool
    {
        $withdrawn = false;
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, $user_id, &$withdrawn): void {
            $withdrawn = false;
            if (!$this->isOpen($db, $mgmt_id)) {
                return;
            }
            $this->deleteWishes($db, $mgmt_id, $user_id);
            $withdrawn = true;
        });
        return $withdrawn;
    }

    /**
     * Closes the registration (status "open" → "drawn"). As soon as this
     * method returns, the wishes cannot change anymore.
     *
     * @return bool false if the registration was not open
     */
    public function close(int $mgmt_id): bool
    {
        $closed = false;
        $this->atomic(function (ilDBInterface $db) use ($mgmt_id, &$closed): void {
            $closed = false;
            if (!$this->isOpen($db, $mgmt_id, false)) {
                return;
            }
            $db->manipulate(
                "UPDATE " . ilGrmRepository::TABLE_MGMT .
                " SET mgmt_status = " . $db->quote(ilGrmManagement::STATUS_DRAWN, "text") .
                " WHERE id = " . $db->quote($mgmt_id, "integer")
            );
            $closed = true;
        });
        return $closed;
    }

    /**
     * Registration is open and (for changes by students) the deadline has not passed.
     */
    private function isOpen(ilDBInterface $db, int $mgmt_id, bool $check_deadline = true): bool
    {
        $set = $db->query(
            "SELECT mgmt_status, reg_end FROM " . ilGrmRepository::TABLE_MGMT .
            " WHERE id = " . $db->quote($mgmt_id, "integer")
        );
        $rec = $db->fetchAssoc($set);
        if (!$rec || $rec["mgmt_status"] !== ilGrmManagement::STATUS_OPEN) {
            return false;
        }
        $deadline = (int) $rec["reg_end"];
        return !$check_deadline || $deadline === 0 || time() <= $deadline;
    }

    private function deleteWishes(ilDBInterface $db, int $mgmt_id, int $user_id): void
    {
        $db->manipulate(
            "DELETE FROM " . ilGrmRepository::TABLE_PRIO .
            " WHERE mgmt_id = " . $db->quote($mgmt_id, "integer") .
            " AND user_id = " . $db->quote($user_id, "integer")
        );
    }

    private function atomic(callable $queries): void
    {
        $atom = $this->db->buildAtomQuery();
        $atom->addTableLock(ilGrmRepository::TABLE_MGMT);
        $atom->addTableLock(ilGrmRepository::TABLE_PRIO);
        $atom->addQueryCallable(static function (ilDBInterface $db) use ($queries): void {
            $queries($db);
        });
        $atom->run();
    }
}
