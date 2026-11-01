<?php

declare(strict_types=1);

/**
 * Database access for managements, groups and group assignments.
 */
class ilGrmRepository
{
    public const TABLE_DATA = "rep_robj_xgrm_data";
    public const TABLE_MGMT = "rep_robj_xgrm_mgmt";
    public const TABLE_GROUP = "rep_robj_xgrm_group";
    public const TABLE_ASSIGN = "rep_robj_xgrm_assign";

    public const TABLES = [self::TABLE_DATA, self::TABLE_MGMT, self::TABLE_GROUP, self::TABLE_ASSIGN];

    private ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Managements
    // ------------------------------------------------------------------

    /**
     * @return ilGrmManagement[]
     */
    public function getManagementsForObject(int $obj_id): array
    {
        $set = $this->db->query(
            "SELECT * FROM " . self::TABLE_MGMT .
            " WHERE obj_id = " . $this->db->quote($obj_id, "integer") .
            " ORDER BY created, id"
        );
        $list = [];
        while ($rec = $this->db->fetchAssoc($set)) {
            $list[] = $this->buildManagement($rec);
        }
        return $list;
    }

    public function getManagement(int $id): ?ilGrmManagement
    {
        $set = $this->db->query(
            "SELECT * FROM " . self::TABLE_MGMT .
            " WHERE id = " . $this->db->quote($id, "integer")
        );
        $rec = $this->db->fetchAssoc($set);
        return $rec ? $this->buildManagement($rec) : null;
    }

    /**
     * All active managements of an object (several can be active at the same time).
     *
     * @return ilGrmManagement[]
     */
    public function getActiveManagements(int $obj_id): array
    {
        $set = $this->db->query(
            "SELECT * FROM " . self::TABLE_MGMT .
            " WHERE obj_id = " . $this->db->quote($obj_id, "integer") .
            " AND is_active = " . $this->db->quote(1, "integer") .
            " ORDER BY created, id"
        );
        $list = [];
        while ($rec = $this->db->fetchAssoc($set)) {
            $list[] = $this->buildManagement($rec);
        }
        return $list;
    }

    public function saveManagement(ilGrmManagement $mgmt): void
    {
        $fields = [
            "obj_id" => ["integer", $mgmt->getObjId()],
            "method" => ["text", $mgmt->getMethod()],
            "title" => ["text", $mgmt->getTitle()],
            "is_active" => ["integer", (int) $mgmt->isActive()],
            "mgmt_status" => ["text", $mgmt->getStatus()],
            "assign_mode" => ["text", $mgmt->getAssignMode()],
            "num_groups" => ["integer", $mgmt->getNumGroups()],
            "topics" => ["clob", implode("\n", $mgmt->getTopics())],
            "show_all" => ["integer", (int) $mgmt->isShowAllGroups()],
            "created" => ["integer", $mgmt->getCreated()],
            "published" => ["integer", $mgmt->getPublished()],
        ];

        if ($mgmt->getId() === 0) {
            $mgmt->setId($this->db->nextId(self::TABLE_MGMT));
            $fields["id"] = ["integer", $mgmt->getId()];
            $this->db->insert(self::TABLE_MGMT, $fields);
            return;
        }

        $this->db->update(self::TABLE_MGMT, $fields, ["id" => ["integer", $mgmt->getId()]]);
    }

    /**
     * Activates the given management. Other managements of the same object
     * keep their state – several managements can be active at the same time.
     */
    public function activate(ilGrmManagement $mgmt): void
    {
        $this->db->manipulate(
            "UPDATE " . self::TABLE_MGMT . " SET is_active = " . $this->db->quote(1, "integer") .
            " WHERE id = " . $this->db->quote($mgmt->getId(), "integer")
        );
        $mgmt->setActive(true);
    }

    public function deactivate(ilGrmManagement $mgmt): void
    {
        $this->db->manipulate(
            "UPDATE " . self::TABLE_MGMT . " SET is_active = " . $this->db->quote(0, "integer") .
            " WHERE id = " . $this->db->quote($mgmt->getId(), "integer")
        );
        $mgmt->setActive(false);
    }

    public function deleteManagement(int $mgmt_id): void
    {
        $this->deleteGroupsAndAssignments($mgmt_id);
        $this->db->manipulate(
            "DELETE FROM " . self::TABLE_MGMT . " WHERE id = " . $this->db->quote($mgmt_id, "integer")
        );
    }

    public function deleteAllForObject(int $obj_id): void
    {
        foreach ($this->getManagementsForObject($obj_id) as $mgmt) {
            $this->deleteManagement($mgmt->getId());
        }
    }

    // ------------------------------------------------------------------
    // Groups and assignments
    // ------------------------------------------------------------------

    /**
     * @return array<int, string> group id => title, ordered by position
     */
    public function getGroups(int $mgmt_id): array
    {
        $set = $this->db->query(
            "SELECT id, title FROM " . self::TABLE_GROUP .
            " WHERE mgmt_id = " . $this->db->quote($mgmt_id, "integer") .
            " ORDER BY position, id"
        );
        $groups = [];
        while ($rec = $this->db->fetchAssoc($set)) {
            $groups[(int) $rec["id"]] = (string) $rec["title"];
        }
        return $groups;
    }

    /**
     * @return array<int, int> user id => group id
     */
    public function getAssignments(int $mgmt_id): array
    {
        $set = $this->db->query(
            "SELECT user_id, group_id FROM " . self::TABLE_ASSIGN .
            " WHERE mgmt_id = " . $this->db->quote($mgmt_id, "integer")
        );
        $assignments = [];
        while ($rec = $this->db->fetchAssoc($set)) {
            $assignments[(int) $rec["user_id"]] = (int) $rec["group_id"];
        }
        return $assignments;
    }

    /**
     * Replaces all groups and assignments of a management.
     *
     * @param string[]        $group_titles  titles in display order
     * @param array<int, int> $assignments   user id => index in $group_titles
     */
    public function replaceGroupsAndAssignments(int $mgmt_id, array $group_titles, array $assignments): void
    {
        $this->deleteGroupsAndAssignments($mgmt_id);

        $group_ids = [];
        foreach (array_values($group_titles) as $position => $title) {
            $group_id = $this->db->nextId(self::TABLE_GROUP);
            $this->db->insert(self::TABLE_GROUP, [
                "id" => ["integer", $group_id],
                "mgmt_id" => ["integer", $mgmt_id],
                "title" => ["text", $title],
                "position" => ["integer", $position],
            ]);
            $group_ids[$position] = $group_id;
        }

        $mapped = [];
        foreach ($assignments as $user_id => $index) {
            $mapped[$user_id] = $group_ids[$index];
        }
        $this->addAssignments($mgmt_id, $mapped);
    }

    /**
     * @param array<int, int> $assignments user id => group id
     */
    public function addAssignments(int $mgmt_id, array $assignments): void
    {
        foreach ($assignments as $user_id => $group_id) {
            $this->db->replace(
                self::TABLE_ASSIGN,
                [
                    "mgmt_id" => ["integer", $mgmt_id],
                    "user_id" => ["integer", $user_id],
                ],
                ["group_id" => ["integer", $group_id]]
            );
        }
    }

    public function deleteGroupsAndAssignments(int $mgmt_id): void
    {
        $this->db->manipulate(
            "DELETE FROM " . self::TABLE_ASSIGN . " WHERE mgmt_id = " . $this->db->quote($mgmt_id, "integer")
        );
        $this->db->manipulate(
            "DELETE FROM " . self::TABLE_GROUP . " WHERE mgmt_id = " . $this->db->quote($mgmt_id, "integer")
        );
    }

    /**
     * @param array<string, mixed> $rec
     */
    private function buildManagement(array $rec): ilGrmManagement
    {
        $mgmt = new ilGrmManagement((int) $rec["obj_id"], (string) $rec["method"]);
        $mgmt->setId((int) $rec["id"]);
        $mgmt->setTitle((string) $rec["title"]);
        $mgmt->setActive((bool) $rec["is_active"]);
        $mgmt->setStatus((string) $rec["mgmt_status"]);
        $mgmt->setAssignMode((string) $rec["assign_mode"]);
        $mgmt->setNumGroups((int) $rec["num_groups"]);
        $mgmt->setTopics(ilGrmManagement::parseTopics((string) ($rec["topics"] ?? "")));
        $mgmt->setShowAllGroups((bool) $rec["show_all"]);
        $mgmt->setCreated((int) $rec["created"]);
        $mgmt->setPublished($rec["published"] !== null ? (int) $rec["published"] : null);
        return $mgmt;
    }
}
