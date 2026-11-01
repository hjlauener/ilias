<?php

declare(strict_types=1);

/**
 * List GUI of the GroupManagement repository object (presentation in the
 * repository container lists).
 */
class ilObjGroupManagementListGUI extends ilObjectPluginListGUI
{
    public function initType(): void
    {
        $this->setType(ilGroupManagementPlugin::ID);
    }

    public function getGuiClass(): string
    {
        return "ilObjGroupManagementGUI";
    }

    public function initCommands(): array
    {
        return [
            [
                "permission" => "read",
                "cmd" => "showContent",
                "default" => true,
            ],
            [
                "permission" => "write",
                "cmd" => "editProperties",
                "txt" => $this->txt("edit"),
                "default" => false,
            ],
        ];
    }

    public function getProperties(): array
    {
        $props = [];

        if (!ilObjGroupManagementAccess::checkOnline($this->obj_id)) {
            $props[] = [
                "alert" => true,
                "property" => $this->txt("status"),
                "value" => $this->txt("offline"),
            ];
        }

        return $props;
    }
}
