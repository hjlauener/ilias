<?php

declare(strict_types=1);

/**
 * Determines the parent course of a GroupManagement object and its members.
 */
class ilGrmCourseContext
{
    private ilTree $tree;

    public function __construct(ilTree $tree)
    {
        $this->tree = $tree;
    }

    /**
     * @return int ref id of the next course above the given node, 0 if none
     */
    public function getCourseRefId(int $ref_id): int
    {
        if ($ref_id <= 0 || !$this->tree->isInTree($ref_id)) {
            return 0;
        }
        return $this->tree->checkForParentType($ref_id, "crs");
    }

    /**
     * Course members (role "member"; course admins and tutors are excluded).
     *
     * @return int[]
     */
    public function getCourseMemberIds(int $crs_ref_id): array
    {
        if ($crs_ref_id <= 0) {
            return [];
        }
        $participants = ilCourseParticipants::_getInstanceByObjId(ilObject::_lookupObjId($crs_ref_id));
        return array_values(array_map("intval", $participants->getMembers()));
    }
}
