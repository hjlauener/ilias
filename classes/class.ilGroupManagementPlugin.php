<?php

declare(strict_types=1);

/**
 * Plugin class of the GroupManagement repository object plugin.
 */
class ilGroupManagementPlugin extends ilRepositoryObjectPlugin
{
    public const ID = "xgrm";
    public const PLUGIN_NAME = "GroupManagement";

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    protected function uninstallCustom(): void
    {
        global $DIC;
        $db = $DIC->database();

        foreach (ilGrmRepository::TABLES as $table) {
            if ($db->tableExists($table)) {
                $db->dropTable($table, false);
            }
            if ($db->sequenceExists($table)) {
                $db->dropSequence($table);
            }
        }
    }

    /**
     * The group management only makes sense below a course (it needs the
     * course members). Folders and groups are allowed as long as they are
     * located inside a course – this is checked at runtime.
     *
     * @return string[]
     */
    public function getParentTypes(): array
    {
        return ["crs", "fold", "grp"];
    }

    public function allowCopy(): bool
    {
        return false;
    }
}
