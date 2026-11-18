<?php

declare(strict_types=1);

/**
 * Default permissions of the course roles on a GroupManagement object.
 *
 * ILIAS sets the permissions of a new object from the role templates. The
 * course role templates do not know the object type of a plugin installed
 * later, so course members would not even see the object. For roles without
 * any permission on the object, this class grants sensible defaults.
 * Permissions that were set by the role templates or by hand stay untouched.
 */
class ilGrmPermissions
{
    private ilTree $tree;
    private ilRbacAdmin $rbac_admin;
    private ilRbacReview $rbac_review;

    public function __construct(ilTree $tree, ilRbacAdmin $rbac_admin, ilRbacReview $rbac_review)
    {
        $this->tree = $tree;
        $this->rbac_admin = $rbac_admin;
        $this->rbac_review = $rbac_review;
    }

    /**
     * Grants the defaults to all course roles that have no permission on the object yet.
     *
     * @return int number of roles that got permissions
     */
    public function applyDefaults(int $ref_id): int
    {
        $granted = 0;
        foreach ($this->getDefaults($ref_id) as $role_id => $operations) {
            if ($this->rbac_review->getActiveOperationsOfRole($ref_id, $role_id) !== []) {
                continue;
            }
            $this->rbac_admin->grantPermission(
                $role_id,
                ilRbacReview::_getOperationIdsByName($operations),
                $ref_id
            );
            $granted++;
        }
        return $granted;
    }

    /**
     * The member role of the course has no permission on the object, so
     * course members do not see it.
     */
    public function isMemberAccessMissing(int $ref_id): bool
    {
        $member_role = $this->getCourse($ref_id)?->getDefaultMemberRole() ?? 0;
        return $member_role > 0 && $this->rbac_review->getActiveOperationsOfRole($ref_id, $member_role) === [];
    }

    /**
     * @return array<int, string[]> role id => operations
     */
    private function getDefaults(int $ref_id): array
    {
        $course = $this->getCourse($ref_id);
        if ($course === null) {
            return [];
        }
        $defaults = [
            $course->getDefaultMemberRole() => ["visible", "read"],
            $course->getDefaultTutorRole() => ["visible", "read", "write"],
            $course->getDefaultAdminRole() => ["visible", "read", "write", "delete", "edit_permission"],
        ];
        unset($defaults[0]);
        return $defaults;
    }

    private function getCourse(int $ref_id): ?ilObjCourse
    {
        if ($ref_id <= 0 || !$this->tree->isInTree($ref_id)) {
            return null;
        }
        $course_ref_id = $this->tree->checkForParentType($ref_id, "crs");
        return $course_ref_id > 0 ? new ilObjCourse($course_ref_id) : null;
    }
}
