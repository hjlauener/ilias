<?php

declare(strict_types=1);

/**
 * Application class of the GroupManagement repository object.
 *
 * The object itself only stores the online flag. The individual
 * "Gruppenmanagements" (one per selected method a–j) are handled by
 * ilGrmRepository.
 */
class ilObjGroupManagement extends ilObjectPlugin
{
    protected ilDBInterface $grm_db;
    protected bool $online = false;

    public function __construct(int $a_ref_id = 0)
    {
        global $DIC;
        $this->grm_db = $DIC->database();
        parent::__construct($a_ref_id);
    }

    final protected function initType(): void
    {
        $this->setType(ilGroupManagementPlugin::ID);
    }

    protected function doCreate(bool $clone_mode = false): void
    {
        $this->grm_db->insert(ilGrmRepository::TABLE_DATA, [
            "id" => ["integer", $this->getId()],
            "is_online" => ["integer", (int) $this->online],
        ]);
    }

    protected function doRead(): void
    {
        $set = $this->grm_db->query(
            "SELECT is_online FROM " . ilGrmRepository::TABLE_DATA .
            " WHERE id = " . $this->grm_db->quote($this->getId(), "integer")
        );
        if ($rec = $this->grm_db->fetchAssoc($set)) {
            $this->online = (bool) $rec["is_online"];
        }
    }

    protected function doUpdate(): void
    {
        $this->grm_db->replace(
            ilGrmRepository::TABLE_DATA,
            ["id" => ["integer", $this->getId()]],
            ["is_online" => ["integer", (int) $this->online]]
        );
    }

    protected function doDelete(): void
    {
        $repo = new ilGrmRepository($this->grm_db);
        $repo->deleteAllForObject($this->getId());

        $this->grm_db->manipulate(
            "DELETE FROM " . ilGrmRepository::TABLE_DATA .
            " WHERE id = " . $this->grm_db->quote($this->getId(), "integer")
        );
    }

    public function isOnline(): bool
    {
        return $this->online;
    }

    public function setOnline(bool $online): void
    {
        $this->online = $online;
    }
}
