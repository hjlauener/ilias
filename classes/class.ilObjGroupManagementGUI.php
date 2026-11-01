<?php

declare(strict_types=1);

use ILIAS\HTTP\Wrapper\ArrayBasedRequestWrapper;
use ILIAS\Refinery\Factory as Refinery;
use ILIAS\UI\Component\Component;

/**
 * GUI class of the GroupManagement repository object.
 *
 * Lecturers (write permission) create one or more "Gruppenmanagements"
 * inside the object, each using one of the methods a–j. Only one of them
 * can be active. Students (read permission) see the group assignment of the
 * active management as soon as the lecturer has pressed "Go".
 *
 * @ilCtrl_isCalledBy ilObjGroupManagementGUI: ilRepositoryGUI, ilAdministrationGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjGroupManagementGUI: ilPermissionGUI, ilInfoScreenGUI, ilObjectCopyGUI, ilCommonActionDispatcherGUI
 */
class ilObjGroupManagementGUI extends ilObjectPluginGUI
{
    private const TAB_CONTENT = "content";
    private const TAB_PROPERTIES = "properties";

    private const PARAM_MGMT_ID = "mgmt_id";

    /** Commands that need write permission */
    private const WRITE_COMMANDS = [
        "editProperties",
        "updateProperties",
        "createManagement",
        "saveManagement",
        "editManagement",
        "updateManagement",
        "showManagement",
        "confirmActivateManagement",
        "activateManagement",
        "confirmDeactivateManagement",
        "deactivateManagement",
        "deleteManagement",
        "confirmDrawManagement",
        "drawManagement",
        "confirmGoManagement",
        "goManagement",
        "confirmUnpublishManagement",
        "unpublishManagement",
        "confirmAssignLatecomers",
        "assignLatecomers",
    ];

    /** Commands that need read permission */
    private const READ_COMMANDS = [
        "showContent",
        "showStudentView",
    ];

    private ilGrmRepository $grm_repo;
    private ilGrmCourseContext $grm_course;
    private Refinery $grm_refinery;
    private ArrayBasedRequestWrapper $grm_query;
    private ArrayBasedRequestWrapper $grm_post;

    protected function afterConstructor(): void
    {
        global $DIC;

        $this->grm_repo = new ilGrmRepository($DIC->database());
        $this->grm_course = new ilGrmCourseContext($DIC->repositoryTree());
        $this->grm_refinery = $DIC->refinery();
        $this->grm_query = $DIC->http()->wrapper()->query();
        $this->grm_post = $DIC->http()->wrapper()->post();
    }

    final public function getType(): string
    {
        return ilGroupManagementPlugin::ID;
    }

    public function getAfterCreationCmd(): string
    {
        return "editProperties";
    }

    public function getStandardCmd(): string
    {
        return "showContent";
    }

    public function performCommand(string $cmd): void
    {
        if (in_array($cmd, self::WRITE_COMMANDS, true)) {
            $this->checkPermission("write");
            $this->$cmd();
            return;
        }

        $this->checkPermission("read");
        if (in_array($cmd, self::READ_COMMANDS, true)) {
            $this->$cmd();
            return;
        }
        $this->showContent();
    }

    protected function setTabs(): void
    {
        $ref_id = $this->object->getRefId();

        if ($this->access->checkAccess("read", "", $ref_id)) {
            $this->tabs->addTab(
                self::TAB_CONTENT,
                $this->txt("content"),
                $this->ctrl->getLinkTarget($this, "showContent")
            );
        }

        $this->addInfoTab();

        if ($this->access->checkAccess("write", "", $ref_id)) {
            $this->tabs->addTab(
                self::TAB_PROPERTIES,
                $this->txt("properties"),
                $this->ctrl->getLinkTarget($this, "editProperties")
            );
        }

        $this->addPermissionTab();
    }

    public function addInfoItems(ilInfoScreenGUI $info): void
    {
        $active = $this->grm_repo->getActiveManagement($this->object->getId());

        $info->addSection($this->txt("group_management"));
        $info->addProperty(
            $this->txt("active_management"),
            $active !== null
                ? $this->esc($active->getTitle()) . " (" . $this->txt("method_" . $active->getMethod()) . ")"
                : $this->txt("none")
        );
    }

    // ------------------------------------------------------------------
    // Properties of the repository object
    // ------------------------------------------------------------------

    protected function editProperties(?ilPropertyFormGUI $form = null): void
    {
        $this->tabs->activateTab(self::TAB_PROPERTIES);

        if ($form === null) {
            $form = $this->initPropertiesForm();
            $form->setValuesByArray([
                "title" => $this->object->getTitle(),
                "desc" => $this->object->getDescription(),
                "online" => $this->getGrmObject()->isOnline(),
            ]);
        }

        $this->tpl->setContent($form->getHTML());
    }

    protected function updateProperties(): void
    {
        $form = $this->initPropertiesForm();
        if (!$form->checkInput()) {
            $form->setValuesByPost();
            $this->editProperties($form);
            return;
        }

        $obj = $this->getGrmObject();
        $obj->setTitle((string) $form->getInput("title"));
        $obj->setDescription((string) $form->getInput("desc"));
        $obj->setOnline((bool) $form->getInput("online"));
        $obj->update();

        $this->tpl->setOnScreenMessage("success", $this->lng->txt("msg_obj_modified"), true);
        $this->ctrl->redirect($this, "editProperties");
    }

    private function initPropertiesForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle($this->txt("obj_xgrm"));
        $form->setFormAction($this->ctrl->getFormAction($this, "updateProperties"));

        $title = new ilTextInputGUI($this->lng->txt("title"), "title");
        $title->setRequired(true);
        $title->setMaxLength(ilObject::TITLE_LENGTH);
        $form->addItem($title);

        $desc = new ilTextAreaInputGUI($this->lng->txt("description"), "desc");
        $desc->setRows(2);
        $form->addItem($desc);

        $online = new ilCheckboxInputGUI($this->txt("online"), "online");
        $online->setInfo($this->txt("online_info"));
        $form->addItem($online);

        $form->addCommandButton("updateProperties", $this->lng->txt("save"));

        return $form;
    }

    // ------------------------------------------------------------------
    // Content: overview for lecturers, group view for students
    // ------------------------------------------------------------------

    protected function showContent(): void
    {
        if ($this->access->checkAccess("write", "", $this->object->getRefId())) {
            $this->listManagements();
            return;
        }
        $this->showStudentView();
    }

    /**
     * Overview of all managements of this object (lecturer view).
     */
    private function listManagements(): void
    {
        $this->tabs->activateTab(self::TAB_CONTENT);
        $this->showContextMessages();

        $this->toolbar->addComponent($this->ui_factory->button()->primary(
            $this->txt("new_management"),
            $this->ctrl->getLinkTarget($this, "createManagement")
        ));
        $this->toolbar->addComponent($this->ui_factory->button()->standard(
            $this->txt("student_view"),
            $this->ctrl->getLinkTarget($this, "showStudentView")
        ));

        $managements = $this->grm_repo->getManagementsForObject($this->object->getId());
        if ($managements === []) {
            $this->tpl->setOnScreenMessage("info", $this->txt("no_managements"));
            return;
        }

        $items = [];
        $modals = [];
        foreach ($managements as $mgmt) {
            $actions = [
                $this->ui_factory->button()->shy(
                    $this->txt("open"),
                    $this->linkTo("showManagement", $mgmt)
                ),
            ];
            if (!$mgmt->isPublished()) {
                $actions[] = $this->ui_factory->button()->shy(
                    $this->txt("edit_settings"),
                    $this->linkTo("editManagement", $mgmt)
                );
            }
            if ($mgmt->isActive()) {
                $actions[] = $this->ui_factory->button()->shy(
                    $this->txt("deactivate"),
                    $this->linkTo("confirmDeactivateManagement", $mgmt)
                );
            } elseif (ilGrmMethods::isImplemented($mgmt->getMethod())) {
                $actions[] = $this->ui_factory->button()->shy(
                    $this->txt("activate"),
                    $this->linkTo("confirmActivateManagement", $mgmt)
                );
            }
            $delete_modal = $this->buildDeleteModal($mgmt);
            $modals[] = $delete_modal;
            $actions[] = $this->ui_factory->button()->shy($this->lng->txt("delete"), "")
                ->withOnClick($delete_modal->getShowSignal());

            $title = $this->esc($mgmt->getTitle());
            if ($mgmt->isActive()) {
                $title .= " [" . $this->txt("active") . "]";
            }

            $items[] = $this->ui_factory->item()->standard(
                $this->ui_factory->button()->shy($title, $this->linkTo("showManagement", $mgmt))
            )->withProperties($this->getManagementProperties($mgmt))
             ->withActions($this->ui_factory->dropdown()->standard($actions));
        }

        $panel = $this->ui_factory->panel()->listing()->standard(
            $this->txt("managements"),
            [$this->ui_factory->item()->group("", $items)]
        );

        $this->tpl->setContent($this->ui_renderer->render(array_merge([$panel], $modals)));
    }

    /**
     * Detail view of one management (lecturer view).
     */
    private function showManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->tabs->activateTab(self::TAB_CONTENT);
        $this->showContextMessages();

        $f = $this->ui_factory;
        $this->toolbar->addComponent($f->button()->standard(
            $this->txt("back_to_overview"),
            $this->ctrl->getLinkTarget($this, "showContent")
        ));

        if (!$mgmt->isPublished()) {
            $this->toolbar->addComponent($f->button()->standard(
                $this->txt("edit_settings"),
                $this->linkTo("editManagement", $mgmt)
            ));
            $this->toolbar->addComponent($f->button()->standard(
                $mgmt->isDrawn() ? $this->txt("redraw") : $this->txt("draw"),
                $this->linkTo("confirmDrawManagement", $mgmt)
            ));
            $this->toolbar->addComponent($f->button()->primary(
                $this->txt("go"),
                $this->linkTo("confirmGoManagement", $mgmt)
            ));
        } else {
            $this->toolbar->addComponent($f->button()->standard(
                $this->txt("unpublish"),
                $this->linkTo("confirmUnpublishManagement", $mgmt)
            ));
        }

        if ($mgmt->isActive()) {
            $this->toolbar->addComponent($f->button()->standard(
                $this->txt("deactivate"),
                $this->linkTo("confirmDeactivateManagement", $mgmt)
            ));
        } elseif (ilGrmMethods::isImplemented($mgmt->getMethod())) {
            $this->toolbar->addComponent($f->button()->standard(
                $this->txt("activate"),
                $this->linkTo("confirmActivateManagement", $mgmt)
            ));
        }

        $delete_modal = $this->buildDeleteModal($mgmt);
        $this->toolbar->addComponent(
            $f->button()->standard($this->lng->txt("delete"), "")->withOnClick($delete_modal->getShowSignal())
        );

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $assignments = $this->grm_repo->getAssignments($mgmt->getId());
        $members = $this->getCourseMembers();
        $unassigned = array_values(array_diff($members, array_keys($assignments)));

        if ($mgmt->isDrawn() && $unassigned !== []) {
            $this->tpl->setOnScreenMessage(
                "info",
                sprintf($this->txt("unassigned_members_info"), count($unassigned))
            );
            $this->toolbar->addComponent($f->button()->standard(
                $this->txt("assign_latecomers"),
                $this->linkTo("confirmAssignLatecomers", $mgmt)
            ));
        }

        if ($mgmt->isPublished() && !$mgmt->isActive()) {
            $this->tpl->setOnScreenMessage("info", $this->txt("published_but_inactive"));
        }

        $properties = $this->getManagementProperties($mgmt);
        $properties[$this->txt("course_members")] = (string) count($members);
        if ($mgmt->getAssignMode() === ilGrmManagement::MODE_TOPICS) {
            $properties[$this->txt("topics")] = implode(", ", array_map([$this, "esc"], $mgmt->getTopics()));
        }
        if ($mgmt->getPublished() !== null) {
            $properties[$this->txt("published_at")] = ilDatePresentation::formatDate(
                new ilDateTime($mgmt->getPublished(), IL_CAL_UNIX)
            );
        }

        $components = [
            $f->panel()->standard(
                $this->esc($mgmt->getTitle()),
                $f->listing()->descriptive($properties)
            ),
        ];

        if ($mgmt->isDrawn()) {
            $components = array_merge(
                $components,
                $this->buildGroupPanels($groups, $assignments, null, $members)
            );
            if ($unassigned !== []) {
                $components[] = $f->panel()->standard(
                    $this->txt("not_assigned") . " (" . count($unassigned) . ")",
                    $f->listing()->unordered($this->getSortedNames($unassigned))
                );
            }
        } else {
            $components[] = $f->messageBox()->info($this->txt("not_drawn_yet"));
        }
        $components[] = $delete_modal;

        $this->tpl->setContent($this->ui_renderer->render($components));
    }

    /**
     * What students see: the group assignment of the active management,
     * but only after "Go".
     */
    private function showStudentView(): void
    {
        $this->tabs->activateTab(self::TAB_CONTENT);
        $f = $this->ui_factory;

        $is_lecturer = $this->access->checkAccess("write", "", $this->object->getRefId());
        if ($is_lecturer) {
            $this->toolbar->addComponent($f->button()->standard(
                $this->txt("back_to_overview"),
                $this->ctrl->getLinkTarget($this, "showContent")
            ));
            $this->tpl->setOnScreenMessage("info", $this->txt("student_view_info"));
        }

        $mgmt = $this->grm_repo->getActiveManagement($this->object->getId());
        if ($mgmt === null || !$mgmt->isPublished()) {
            $this->tpl->setContent($this->ui_renderer->render(
                $f->messageBox()->info($this->txt("assignment_not_visible_yet"))
            ));
            return;
        }

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $assignments = $this->grm_repo->getAssignments($mgmt->getId());
        $my_group_id = $assignments[$this->user->getId()] ?? null;

        $components = [];
        if ($my_group_id !== null && isset($groups[$my_group_id])) {
            $components[] = $f->messageBox()->success(
                sprintf($this->txt("you_are_in_group"), $this->esc($groups[$my_group_id]))
            );
        } else {
            $components[] = $f->messageBox()->info($this->txt("you_are_not_assigned"));
        }

        if ($mgmt->isShowAllGroups()) {
            $components = array_merge(
                $components,
                $this->buildGroupPanels($groups, $assignments, $my_group_id)
            );
        } elseif ($my_group_id !== null && isset($groups[$my_group_id])) {
            $components = array_merge(
                $components,
                $this->buildGroupPanels(
                    [$my_group_id => $groups[$my_group_id]],
                    array_filter($assignments, static fn(int $g): bool => $g === $my_group_id),
                    $my_group_id
                )
            );
        }

        $this->tpl->setContent($this->ui_renderer->render($components));
    }

    // ------------------------------------------------------------------
    // Create / edit a management
    // ------------------------------------------------------------------

    private function createManagement(?ilPropertyFormGUI $form = null): void
    {
        $this->tabs->activateTab(self::TAB_CONTENT);
        $this->showContextMessages();
        $form ??= $this->initManagementForm(null);
        $this->tpl->setContent($form->getHTML());
    }

    private function saveManagement(): void
    {
        $form = $this->initManagementForm(null);
        if (!$form->checkInput()) {
            $form->setValuesByPost();
            $this->createManagement($form);
            return;
        }

        $method = (string) $form->getInput("method");
        if (!ilGrmMethods::isImplemented($method)) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("method_not_available"));
            $form->setValuesByPost();
            $this->createManagement($form);
            return;
        }

        $mgmt = new ilGrmManagement($this->object->getId(), $method);
        $mgmt->setCreated(time());
        if (!$this->applyManagementForm($form, $mgmt)) {
            $form->setValuesByPost();
            $this->createManagement($form);
            return;
        }
        $this->grm_repo->saveManagement($mgmt);

        $message = $this->txt("management_created");
        if ($this->grm_repo->getActiveManagement($this->object->getId()) === null) {
            $this->grm_repo->activate($mgmt);
            $message .= " " . $this->txt("management_auto_activated");
        }

        $this->tpl->setOnScreenMessage("success", $message, true);
        $this->redirectTo("showManagement", $mgmt);
    }

    private function editManagement(?ilPropertyFormGUI $form = null): void
    {
        $mgmt = $this->requireManagement();
        $this->tabs->activateTab(self::TAB_CONTENT);

        if ($form === null) {
            $form = $this->initManagementForm($mgmt);
            $form->setValuesByArray([
                "title" => $mgmt->getTitle(),
                "assign_mode" => $mgmt->getAssignMode(),
                "num_groups" => (string) $mgmt->getNumGroups(),
                "topics" => implode("\n", $mgmt->getTopics()),
                "show_all" => $mgmt->isShowAllGroups(),
            ]);
            if ($mgmt->isDrawn() && !$mgmt->isPublished()) {
                $this->tpl->setOnScreenMessage("info", $this->txt("edit_resets_draw_info"));
            }
        }
        $this->tpl->setContent($form->getHTML());
    }

    private function updateManagement(): void
    {
        $mgmt = $this->requireManagement();
        $form = $this->initManagementForm($mgmt);
        if (!$form->checkInput()) {
            $form->setValuesByPost();
            $this->editManagement($form);
            return;
        }

        $before = [$mgmt->getAssignMode(), $mgmt->getNumGroups(), $mgmt->getTopics()];
        if (!$this->applyManagementForm($form, $mgmt)) {
            $form->setValuesByPost();
            $this->editManagement($form);
            return;
        }
        $after = [$mgmt->getAssignMode(), $mgmt->getNumGroups(), $mgmt->getTopics()];

        $message = $this->lng->txt("msg_obj_modified");
        if ($mgmt->isDrawn() && !$mgmt->isPublished() && $before !== $after) {
            // the group structure changed: the existing draw is no longer valid
            $this->grm_repo->deleteGroupsAndAssignments($mgmt->getId());
            $mgmt->setStatus(ilGrmManagement::STATUS_DRAFT);
            $message .= " " . $this->txt("draw_reset");
        }
        $this->grm_repo->saveManagement($mgmt);

        $this->tpl->setOnScreenMessage("success", $message, true);
        $this->redirectTo("showManagement", $mgmt);
    }

    private function initManagementForm(?ilGrmManagement $mgmt): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();

        if ($mgmt === null) {
            $form->setTitle($this->txt("new_management"));
            $form->setFormAction($this->ctrl->getFormAction($this, "saveManagement"));
        } else {
            $form->setTitle($this->txt("edit_settings"));
            $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
            $form->setFormAction($this->ctrl->getFormAction($this, "updateManagement"));
            $this->ctrl->clearParameterByClass(self::class, self::PARAM_MGMT_ID);
        }

        $title = new ilTextInputGUI($this->txt("mgmt_title"), "title");
        $title->setRequired(true);
        $title->setMaxLength(255);
        $form->addItem($title);

        if ($mgmt === null) {
            // method selection a–j (only implemented methods can be chosen)
            $method = new ilRadioGroupInputGUI($this->txt("method"), "method");
            $method->setRequired(true);
            $method->setValue(ilGrmMethods::METHOD_A);
            foreach (ilGrmMethods::ALL as $key) {
                $option = new ilRadioOption(
                    $this->txt("method_" . $key),
                    $key,
                    $this->txt("method_" . $key . "_info")
                );
                if (!ilGrmMethods::isImplemented($key)) {
                    $option->setDisabled(true);
                }
                if ($key === ilGrmMethods::METHOD_A) {
                    $option->addSubItem($this->buildAssignModeInput());
                    $option->addSubItem($this->buildShowAllInput());
                }
                $method->addOption($option);
            }
            $form->addItem($method);
        } else {
            $method = new ilNonEditableValueGUI($this->txt("method"), "", true);
            $method->setValue($this->txt("method_" . $mgmt->getMethod()));
            $form->addItem($method);

            if ($mgmt->isPublished()) {
                $mode = new ilNonEditableValueGUI($this->txt("assign_mode"), "", true);
                $mode->setValue($this->getModeSummary($mgmt));
                $mode->setInfo($this->txt("settings_locked_published"));
                $form->addItem($mode);
            } else {
                $form->addItem($this->buildAssignModeInput());
            }
            $form->addItem($this->buildShowAllInput());
        }

        if ($mgmt === null) {
            $form->addCommandButton("saveManagement", $this->lng->txt("save"));
            $form->addCommandButton("showContent", $this->lng->txt("cancel"));
        } else {
            $form->addCommandButton("updateManagement", $this->lng->txt("save"));
            $form->addCommandButton("showManagement", $this->lng->txt("cancel"));
        }

        return $form;
    }

    /**
     * Method a: "fixed number of groups" or "fixed number of topics".
     */
    private function buildAssignModeInput(): ilRadioGroupInputGUI
    {
        $mode = new ilRadioGroupInputGUI($this->txt("assign_mode"), "assign_mode");
        $mode->setRequired(true);
        $mode->setValue(ilGrmManagement::MODE_GROUPS);

        $groups = new ilRadioOption(
            $this->txt("mode_groups"),
            ilGrmManagement::MODE_GROUPS,
            $this->txt("mode_groups_info")
        );
        $num = new ilNumberInputGUI($this->txt("num_groups"), "num_groups");
        $num->setRequired(true);
        $num->setDecimals(0);
        $num->setMinValue(1);
        $num->setMaxValue(ilGrmManagement::MAX_GROUPS);
        $num->setSize(4);
        $num->setValue("2");
        $groups->addSubItem($num);
        $mode->addOption($groups);

        $topics_option = new ilRadioOption(
            $this->txt("mode_topics"),
            ilGrmManagement::MODE_TOPICS,
            $this->txt("mode_topics_info")
        );
        $topics = new ilTextAreaInputGUI($this->txt("topics"), "topics");
        $topics->setRequired(true);
        $topics->setRows(6);
        $topics->setInfo($this->txt("topics_info"));
        $topics_option->addSubItem($topics);
        $mode->addOption($topics_option);

        return $mode;
    }

    private function buildShowAllInput(): ilCheckboxInputGUI
    {
        $show_all = new ilCheckboxInputGUI($this->txt("show_all_groups"), "show_all");
        $show_all->setInfo($this->txt("show_all_groups_info"));
        $show_all->setChecked(true);
        return $show_all;
    }

    /**
     * Transfers the (already checked) form values into the management.
     * Returns false if a value is invalid (alert is set in the form).
     */
    private function applyManagementForm(ilPropertyFormGUI $form, ilGrmManagement $mgmt): bool
    {
        $mgmt->setTitle(trim((string) $form->getInput("title")));
        $mgmt->setShowAllGroups((bool) $form->getInput("show_all"));

        if ($mgmt->isPublished()) {
            return true; // structure is locked
        }

        $mode = (string) $form->getInput("assign_mode");
        $mgmt->setAssignMode($mode);

        if ($mgmt->getAssignMode() === ilGrmManagement::MODE_TOPICS) {
            $topics = ilGrmManagement::parseTopics((string) $form->getInput("topics"));
            if ($topics === [] || count($topics) > ilGrmManagement::MAX_GROUPS) {
                $this->setFormAlert($form, "topics", sprintf($this->txt("topics_invalid"), ilGrmManagement::MAX_GROUPS));
                return false;
            }
            $mgmt->setTopics($topics);
        } else {
            $num = (int) $form->getInput("num_groups");
            if ($num < 1 || $num > ilGrmManagement::MAX_GROUPS) {
                $this->setFormAlert($form, "num_groups", sprintf($this->txt("num_groups_invalid"), ilGrmManagement::MAX_GROUPS));
                return false;
            }
            $mgmt->setNumGroups($num);
        }

        return true;
    }

    private function setFormAlert(ilPropertyFormGUI $form, string $post_var, string $message): void
    {
        $item = $form->getItemByPostVar($post_var);
        if ($item !== null) {
            $item->setAlert($message);
        }
        $this->tpl->setOnScreenMessage("failure", $this->lng->txt("form_input_not_valid"));
    }

    // ------------------------------------------------------------------
    // Activation (only one active management per object)
    // ------------------------------------------------------------------

    private function confirmActivateManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->showConfirmation($mgmt, $this->txt("confirm_activate"), "activateManagement", $this->txt("activate"));
    }

    private function activateManagement(): void
    {
        $mgmt = $this->requireManagement();
        if (!ilGrmMethods::isImplemented($mgmt->getMethod())) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("method_not_available"), true);
            $this->ctrl->redirect($this, "showContent");
        }
        $this->grm_repo->activate($mgmt);
        $this->tpl->setOnScreenMessage("success", $this->txt("management_activated"), true);
        $this->ctrl->redirect($this, "showContent");
    }

    private function confirmDeactivateManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->showConfirmation($mgmt, $this->txt("confirm_deactivate"), "deactivateManagement", $this->txt("deactivate"));
    }

    private function deactivateManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->grm_repo->deactivate($mgmt);
        $this->tpl->setOnScreenMessage("success", $this->txt("management_deactivated"), true);
        $this->ctrl->redirect($this, "showContent");
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    /**
     * Confirmation overlay (interruptive modal), same as when deleting a
     * repository object. Trigger it with ->withOnClick($modal->getShowSignal()).
     */
    private function buildDeleteModal(ilGrmManagement $mgmt): \ILIAS\UI\Component\Modal\Interruptive
    {
        $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
        $form_action = $this->ctrl->getFormAction($this, "deleteManagement");
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_MGMT_ID);

        return $this->ui_factory->modal()->interruptive(
            $this->txt("delete_management"),
            $this->txt("confirm_delete"),
            $form_action
        )->withAffectedItems([
            $this->ui_factory->modal()->interruptiveItem()->standard(
                (string) $mgmt->getId(),
                $this->esc($mgmt->getTitle()),
                null,
                $this->txt("method_" . $mgmt->getMethod())
            ),
        ]);
    }

    private function deleteManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->grm_repo->deleteManagement($mgmt->getId());
        $this->tpl->setOnScreenMessage("success", $this->txt("management_deleted"), true);
        $this->ctrl->redirect($this, "showContent");
    }

    // ------------------------------------------------------------------
    // Method a: random draw, "Go", unpublish, latecomers
    // ------------------------------------------------------------------

    private function confirmDrawManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->requireNotPublished($mgmt);
        $this->showConfirmation(
            $mgmt,
            $mgmt->isDrawn() ? $this->txt("confirm_redraw") : $this->txt("confirm_draw"),
            "drawManagement",
            $mgmt->isDrawn() ? $this->txt("redraw") : $this->txt("draw")
        );
    }

    private function drawManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->requireNotPublished($mgmt);

        if ($this->performDraw($mgmt)) {
            $this->tpl->setOnScreenMessage("success", $this->txt("draw_done"), true);
        }
        $this->redirectTo("showManagement", $mgmt);
    }

    private function confirmGoManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->requireNotPublished($mgmt);

        $question = $this->txt("confirm_go");
        if (!$mgmt->isDrawn()) {
            $question .= " " . $this->txt("confirm_go_draw_first");
        }
        if (!$mgmt->isActive()) {
            $question .= " " . $this->txt("confirm_go_activate");
        }
        $this->showConfirmation($mgmt, $question, "goManagement", $this->txt("go"));
    }

    /**
     * "Action-Go": the assignment becomes visible for the students.
     */
    private function goManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->requireNotPublished($mgmt);

        if (!ilGrmMethods::isImplemented($mgmt->getMethod())) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("method_not_available"), true);
            $this->redirectTo("showManagement", $mgmt);
        }

        if (!$mgmt->isDrawn() && !$this->performDraw($mgmt)) {
            $this->redirectTo("showManagement", $mgmt);
        }

        $mgmt->setStatus(ilGrmManagement::STATUS_PUBLISHED);
        $mgmt->setPublished(time());
        $this->grm_repo->saveManagement($mgmt);
        if (!$mgmt->isActive()) {
            $this->grm_repo->activate($mgmt);
        }

        $this->tpl->setOnScreenMessage("success", $this->txt("go_done"), true);
        $this->redirectTo("showManagement", $mgmt);
    }

    private function confirmUnpublishManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->showConfirmation($mgmt, $this->txt("confirm_unpublish"), "unpublishManagement", $this->txt("unpublish"));
    }

    private function unpublishManagement(): void
    {
        $mgmt = $this->requireManagement();
        if ($mgmt->isPublished()) {
            $mgmt->setStatus(ilGrmManagement::STATUS_DRAWN);
            $mgmt->setPublished(null);
            $this->grm_repo->saveManagement($mgmt);
            $this->tpl->setOnScreenMessage("success", $this->txt("unpublish_done"), true);
        }
        $this->redirectTo("showManagement", $mgmt);
    }

    private function confirmAssignLatecomers(): void
    {
        $mgmt = $this->requireManagement();
        $this->showConfirmation(
            $mgmt,
            $this->txt("confirm_assign_latecomers"),
            "assignLatecomers",
            $this->txt("assign_latecomers")
        );
    }

    /**
     * Course members who joined after the draw are added to the currently
     * smallest groups; existing assignments stay untouched.
     */
    private function assignLatecomers(): void
    {
        $mgmt = $this->requireManagement();
        if (!$mgmt->isDrawn()) {
            $this->redirectTo("showManagement", $mgmt);
        }

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $assignments = $this->grm_repo->getAssignments($mgmt->getId());
        $unassigned = array_values(array_diff($this->getCourseMembers(), array_keys($assignments)));

        if ($groups !== [] && $unassigned !== []) {
            $sizes = array_fill_keys(array_keys($groups), 0);
            foreach ($assignments as $group_id) {
                if (isset($sizes[$group_id])) {
                    $sizes[$group_id]++;
                }
            }
            $new = (new ilGrmRandomAssigner())->assignToSmallest($unassigned, $sizes);
            $this->grm_repo->addAssignments($mgmt->getId(), $new);
            $this->tpl->setOnScreenMessage(
                "success",
                sprintf($this->txt("latecomers_assigned"), count($new)),
                true
            );
        }

        $this->redirectTo("showManagement", $mgmt);
    }

    /**
     * Random assignment of all course members (method a).
     */
    private function performDraw(ilGrmManagement $mgmt): bool
    {
        if ($this->getCourseRefId() === 0) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("no_parent_course"), true);
            return false;
        }

        $members = $this->getCourseMembers();
        if ($members === []) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("no_course_members"), true);
            return false;
        }

        $titles = $mgmt->buildGroupTitles(fn(int $n): string => sprintf($this->txt("group_title_n"), $n));
        if ($titles === []) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("no_groups_configured"), true);
            return false;
        }

        $assignments = (new ilGrmRandomAssigner())->assign($members, count($titles));
        $this->grm_repo->replaceGroupsAndAssignments($mgmt->getId(), $titles, $assignments);

        if (!$mgmt->isPublished()) {
            $mgmt->setStatus(ilGrmManagement::STATUS_DRAWN);
        }
        $this->grm_repo->saveManagement($mgmt);

        if (count($members) < count($titles)) {
            $this->tpl->setOnScreenMessage("info", $this->txt("more_groups_than_members"), true);
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function getGrmObject(): ilObjGroupManagement
    {
        /** @var ilObjGroupManagement $obj */
        $obj = $this->object;
        return $obj;
    }

    private function getCourseRefId(): int
    {
        return $this->grm_course->getCourseRefId($this->object->getRefId());
    }

    /**
     * @return int[]
     */
    private function getCourseMembers(): array
    {
        return $this->grm_course->getCourseMemberIds($this->getCourseRefId());
    }

    private function showContextMessages(): void
    {
        if ($this->getCourseRefId() === 0) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("no_parent_course"));
        }
        if (!$this->getGrmObject()->isOnline()) {
            $this->tpl->setOnScreenMessage("question", $this->txt("object_offline_info"));
        }
    }

    /**
     * Reads the management id from the request and makes sure the
     * management belongs to this object.
     */
    private function getRequestedManagement(): ?ilGrmManagement
    {
        $to_int = $this->grm_refinery->kindlyTo()->int();
        $id = 0;
        if ($this->grm_query->has(self::PARAM_MGMT_ID)) {
            $id = $this->grm_query->retrieve(self::PARAM_MGMT_ID, $to_int);
        } elseif ($this->grm_post->has(self::PARAM_MGMT_ID)) {
            $id = $this->grm_post->retrieve(self::PARAM_MGMT_ID, $to_int);
        }
        if ($id <= 0) {
            return null;
        }

        $mgmt = $this->grm_repo->getManagement($id);
        if ($mgmt === null || $mgmt->getObjId() !== $this->object->getId()) {
            return null;
        }
        return $mgmt;
    }

    private function requireManagement(): ilGrmManagement
    {
        $mgmt = $this->getRequestedManagement();
        if ($mgmt === null) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("management_not_found"), true);
            $this->ctrl->redirect($this, "showContent");
        }
        return $mgmt;
    }

    private function requireNotPublished(ilGrmManagement $mgmt): void
    {
        if ($mgmt->isPublished()) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("already_published"), true);
            $this->redirectTo("showManagement", $mgmt);
        }
    }

    private function linkTo(string $cmd, ilGrmManagement $mgmt): string
    {
        $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
        $link = $this->ctrl->getLinkTarget($this, $cmd);
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_MGMT_ID);
        return $link;
    }

    private function redirectTo(string $cmd, ilGrmManagement $mgmt): void
    {
        $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
        $this->ctrl->redirect($this, $cmd);
    }

    private function showConfirmation(
        ilGrmManagement $mgmt,
        string $question,
        string $confirm_cmd,
        string $confirm_txt
    ): void {
        $this->tabs->activateTab(self::TAB_CONTENT);

        $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
        $confirmation = new ilConfirmationGUI();
        $confirmation->setFormAction($this->ctrl->getFormAction($this));
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_MGMT_ID);

        $confirmation->setHeaderText($question);
        $confirmation->addItem("mgmt_ids", (string) $mgmt->getId(), $this->esc($mgmt->getTitle()));
        $confirmation->setConfirm($confirm_txt, $confirm_cmd);
        $confirmation->setCancel($this->lng->txt("cancel"), "showManagement");

        $this->tpl->setContent($confirmation->getHTML());
    }

    /**
     * @return array<string, string>
     */
    private function getManagementProperties(ilGrmManagement $mgmt): array
    {
        return [
            $this->txt("method") => $this->txt("method_" . $mgmt->getMethod()),
            $this->txt("assign_mode") => $this->getModeSummary($mgmt),
            $this->txt("status") => $this->txt("status_" . $mgmt->getStatus()),
            $this->txt("active") => $mgmt->isActive() ? $this->lng->txt("yes") : $this->lng->txt("no"),
        ];
    }

    private function getModeSummary(ilGrmManagement $mgmt): string
    {
        if ($mgmt->getAssignMode() === ilGrmManagement::MODE_TOPICS) {
            return sprintf($this->txt("summary_topics"), count($mgmt->getTopics()));
        }
        return sprintf($this->txt("summary_groups"), $mgmt->getNumGroups());
    }

    /**
     * @param array<int, string> $groups       group id => title
     * @param array<int, int>    $assignments  user id => group id
     * @param int[]|null         $course_members if given, users that are no
     *                                           longer course members are marked
     * @return Component[]
     */
    private function buildGroupPanels(
        array $groups,
        array $assignments,
        ?int $highlight_group_id = null,
        ?array $course_members = null
    ): array {
        $members_by_group = array_fill_keys(array_keys($groups), []);
        foreach ($assignments as $user_id => $group_id) {
            if (isset($members_by_group[$group_id])) {
                $members_by_group[$group_id][] = $user_id;
            }
        }

        $panels = [];
        foreach ($groups as $group_id => $title) {
            $user_ids = $members_by_group[$group_id];
            $panel_title = $this->esc($title) . " (" . count($user_ids) . ")";
            if ($highlight_group_id === $group_id) {
                $panel_title .= " – " . $this->txt("your_group");
            }

            if ($user_ids === []) {
                // (no legacy() component here: its signature differs between ILIAS 10 and 11)
                $content = $this->ui_factory->listing()->unordered(["<em>" . $this->txt("group_empty") . "</em>"]);
            } else {
                $names = [];
                foreach ($this->sortUsersByName($user_ids) as $user_id) {
                    $name = $this->getUserName($user_id);
                    if ($course_members !== null && !in_array($user_id, $course_members, true)) {
                        $name .= " <em>(" . $this->txt("no_longer_member") . ")</em>";
                    }
                    $names[] = $name;
                }
                $content = $this->ui_factory->listing()->unordered($names);
            }

            $panels[] = $this->ui_factory->panel()->sub($panel_title, $content);
        }

        if ($panels === []) {
            return [];
        }
        return [$this->ui_factory->panel()->standard($this->txt("groups"), $panels)];
    }

    /**
     * @param int[] $user_ids
     * @return string[] escaped names
     */
    private function getSortedNames(array $user_ids): array
    {
        return array_map(fn(int $id): string => $this->getUserName($id), $this->sortUsersByName($user_ids));
    }

    /**
     * @param int[] $user_ids
     * @return int[]
     */
    private function sortUsersByName(array $user_ids): array
    {
        $names = [];
        foreach ($user_ids as $user_id) {
            $names[$user_id] = mb_strtolower($this->getUserName($user_id));
        }
        asort($names, SORT_NATURAL);
        return array_keys($names);
    }

    /**
     * @return string escaped "Lastname, Firstname"
     */
    private function getUserName(int $user_id): string
    {
        static $cache = [];
        if (!isset($cache[$user_id])) {
            $name = ilObjUser::_lookupName($user_id);
            if (($name["login"] ?? "") === "") {
                $cache[$user_id] = $this->txt("deleted_user");
            } else {
                $cache[$user_id] = $this->esc(trim(
                    ($name["lastname"] ?? "") . ", " . ($name["firstname"] ?? ""),
                    ", "
                ));
            }
        }
        return $cache[$user_id];
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, "UTF-8");
    }
}
