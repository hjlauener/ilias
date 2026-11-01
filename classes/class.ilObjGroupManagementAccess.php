<?php

declare(strict_types=1);

/**
 * Access class of the GroupManagement repository object.
 */
class ilObjGroupManagementAccess extends ilObjectPluginAccess
{
    public function _checkAccess(string $cmd, string $permission, int $ref_id, int $obj_id, ?int $user_id = null): bool
    {
        if ($user_id === null) {
            $user_id = $this->user->getId();
        }

        // Offline objects are only accessible for users with write permission.
        if ($permission === "read" || $permission === "visible") {
            if (!self::checkOnline($obj_id)
                && !$this->access->checkAccessOfUser($user_id, "write", "", $ref_id)) {
                return false;
            }
        }

        return true;
    }

    public static function checkOnline(int $obj_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        $set = $db->query(
            "SELECT is_online FROM " . ilGrmRepository::TABLE_DATA .
            " WHERE id = " . $db->quote($obj_id, "integer")
        );
        $rec = $db->fetchAssoc($set);

        return $rec !== null && (bool) $rec["is_online"];
    }
}
