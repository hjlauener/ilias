<?php

declare(strict_types=1);

use ILIAS\HTTP\Wrapper\ArrayBasedRequestWrapper;
use ILIAS\Refinery\Factory as Refinery;
use ILIAS\UI\Component\Component;
use ILIAS\UI\Component\Modal\Interruptive;

/**
 * GUI class of the GroupManagement repository object.
 *
 * Lecturers (write permission) create one or more "Gruppenmanagements"
 * inside the object, each using one of the methods a–j. Several of them can
 * be active. Students (read permission) see an overview of the active
 * managements and their group as soon as the lecturer has pressed "Los".
 *
 * @ilCtrl_isCalledBy ilObjGroupManagementGUI: ilRepositoryGUI, ilAdministrationGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjGroupManagementGUI: ilPermissionGUI, ilInfoScreenGUI, ilObjectCopyGUI, ilCommonActionDispatcherGUI
 */
class ilObjGroupManagementGUI extends ilObjectPluginGUI
{
    private const TAB_CONTENT = "content";
    private const TAB_PROPERTIES = "properties";

    private const PARAM_MGMT_ID = "mgmt_id";
    private const PARAM_GROUP_ID = "group_id";
    /** after an action: back to the detail view instead of the overview */
    private const PARAM_RETURN = "grm_return";
    private const RETURN_DETAIL = "detail";

    /** Commands that need write permission */
    private const WRITE_COMMANDS = [
        "editProperties",
        "updateProperties",
        "createManagement",
        "saveManagement",
        "editManagement",
        "updateManagement",
        "showManagement",
        "activateManagement",
        "deactivateManagement",
        "deleteManagement",
        "drawManagement",
        "goManagement",
        "unpublishManagement",
        "assignLatecomers",
        "saveCapacities",
        "moveMember",
        "openRegistration",
        "closeRegistration",
        "recalculateAssignment",
        "reopenRegistration",
        "fillFreePlaces",
        "applyDefaultPermissions",
    ];

    /** Commands that need read permission */
    private const READ_COMMANDS = [
        "showContent",
        "showStudentView",
        "showStudentManagement",
        "enrollGroup",
        "unenrollGroup",
        "saveWishes",
        "withdrawWishes",
    ];

    private ilGrmRepository $grm_repo;
    private ilGrmEnrollment $grm_enrollment;
    private ilGrmPriorityRegistration $grm_registration;
    private ilGrmCourseContext $grm_course;
    private ilGrmPermissions $grm_permissions;
    private Refinery $grm_refinery;
    private ArrayBasedRequestWrapper $grm_query;
    private ArrayBasedRequestWrapper $grm_post;

    protected function afterConstructor(): void
    {
        global $DIC;

        $this->grm_repo = new ilGrmRepository($DIC->database());
        $this->grm_enrollment = new ilGrmEnrollment($DIC->database());
        $this->grm_registration = new ilGrmPriorityRegistration($DIC->database());
        $this->grm_course = new ilGrmCourseContext($DIC->repositoryTree());
        $this->grm_permissions = new ilGrmPermissions(
            $DIC->repositoryTree(),
            $DIC->rbac()->admin(),
            $DIC->rbac()->review()
        );
        $this->grm_refinery = $DIC->refinery();
        $this->grm_query = $DIC->http()->wrapper()->query();
        $this->grm_post = $DIC->http()->wrapper()->post();
    }

    final public function getType(): string
    {
        return ilGroupManagementPlugin::ID;
    }

    public function executeCommand(): void
    {
        // Opened via ilRepositoryGUI (e.g. ilias.php?baseClass=ilRepositoryGUI&ref_id=…):
        // ilRepositoryGUI prints the page again after this GUI has printed it,
        // which ILIAS 11 aborts with "Response has already been sent". Continue
        // via ilObjPluginDispatchGUI, like the goto links of ILIAS do.
        if (!$this->getCreationMode()
            && $this->object !== null
            && strtolower($this->slot_request->getBaseClass()) === "ilrepositorygui") {
            $this->ctrl->setParameterByClass(self::class, "ref_id", $this->object->getRefId());
            $this->ctrl->redirectByClass(["ilobjplugindispatchgui", self::class], (string) $this->ctrl->getCmd());
        }
        parent::executeCommand();
    }

    /**
     * New object: course roles without permissions get the defaults, so that
     * course members can see the object (see ilGrmPermissions).
     */
    protected function afterSave(ilObject $new_object): void
    {
        $this->grm_permissions->applyDefaults($new_object->getRefId());
        parent::afterSave($new_object);
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
        $this->addPluginCss();

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
        $titles = [];
        foreach ($this->grm_repo->getActiveManagements($this->object->getId()) as $active) {
            $titles[] = $this->esc($active->getTitle()) . " (" . $this->txt("method_" . $active->getMethod()) . ")";
        }

        $info->addSection($this->txt("group_management"));
        $info->addProperty(
            $this->txt("active_managements"),
            $titles !== [] ? implode("<br>", $titles) : $this->txt("none")
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

        $warning = $this->buildPermissionWarning();
        $managements = $this->grm_repo->getManagementsForObject($this->object->getId());
        if ($managements === []) {
            $this->tpl->setOnScreenMessage("info", $this->txt("no_managements"));
            if ($warning !== []) {
                $this->tpl->setContent($this->ui_renderer->render($warning));
            }
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
            $toggle = $this->getActivationToggle($mgmt, false);
            if ($toggle !== null) {
                $actions[] = $this->ui_factory->button()->shy($toggle[0], $toggle[1]);
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

        $this->tpl->setContent($this->ui_renderer->render(array_merge($warning, [$panel], $modals)));
    }

    /**
     * Detail view of one management (lecturer view).
     */
    private function showManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->tabs->activateTab(self::TAB_CONTENT);
        $this->showContextMessages();

        $is_c = $mgmt->getMethod() === ilGrmMethods::METHOD_C;
        $is_d = $mgmt->getMethod() === ilGrmMethods::METHOD_D;
        $uses_draw = ilGrmMethods::usesRandomDraw($mgmt->getMethod());

        $f = $this->ui_factory;
        $this->toolbar->addComponent($f->button()->standard(
            $this->txt("back_to_overview"),
            $this->ctrl->getLinkTarget($this, "showContent")
        ));

        /** @var Interruptive[] $modals */
        $modals = [];

        if (!$mgmt->isPublished()) {
            $this->toolbar->addComponent($f->button()->standard(
                $this->txt("edit_settings"),
                $this->linkTo("editManagement", $mgmt)
            ));

            if ($uses_draw) {
                $draw_label = $mgmt->isDrawn() ? $this->txt("redraw") : $this->txt("draw");
                $modals[] = $modal = $this->buildConfirmModal(
                    $mgmt,
                    $mgmt->isDrawn() ? $this->txt("confirm_redraw") : $this->txt("confirm_draw"),
                    "drawManagement",
                    $draw_label
                );
                $this->toolbar->addComponent($f->button()->standard($draw_label, "")->withOnClick($modal->getShowSignal()));
            }

            if ($is_d) {
                $modals = array_merge($modals, $this->addRegistrationButtons($mgmt));
            } else {
                if ($is_c) {
                    $go_label = $this->txt("go_c");
                    $go_message = $this->txt("confirm_go_c");
                } else {
                    $go_label = $this->txt("go");
                    $go_message = $this->txt("confirm_go");
                    if (!$mgmt->isDrawn()) {
                        $go_message .= " " . $this->txt("confirm_go_draw_first");
                    }
                }
                if (!$mgmt->isActive()) {
                    $go_message .= " " . $this->txt("confirm_go_activate");
                }
                $modals[] = $modal = $this->buildConfirmModal($mgmt, $go_message, "goManagement", $go_label);
                $this->toolbar->addComponent($f->button()->primary($go_label, "")->withOnClick($modal->getShowSignal()));
            }
        } else {
            $modals[] = $modal = $this->buildConfirmModal(
                $mgmt,
                $this->txt("confirm_unpublish"),
                "unpublishManagement",
                $this->txt("unpublish")
            );
            $this->toolbar->addComponent(
                $f->button()->standard($this->txt("unpublish"), "")->withOnClick($modal->getShowSignal())
            );
        }

        $toggle = $this->getActivationToggle($mgmt, true);
        if ($toggle !== null) {
            $this->toolbar->addComponent($f->button()->standard($toggle[0], $toggle[1]));
        }

        $modals[] = $modal = $this->buildDeleteModal($mgmt);
        $this->toolbar->addComponent(
            $f->button()->standard($this->lng->txt("delete"), "")->withOnClick($modal->getShowSignal())
        );

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $assignments = $this->grm_repo->getAssignments($mgmt->getId());
        $members = $this->getCourseMembers();
        $unassigned = array_values(array_diff($members, array_keys($assignments)));

        if ($is_d) {
            $this->showPriorityManagement($mgmt, $groups, $assignments, $members, $modals);
            return;
        }

        if ($uses_draw && $mgmt->isDrawn() && $unassigned !== []) {
            $this->tpl->setOnScreenMessage(
                "info",
                sprintf($this->txt("unassigned_members_info"), count($unassigned))
            );
            $modals[] = $modal = $this->buildConfirmModal(
                $mgmt,
                $this->txt("confirm_assign_latecomers"),
                "assignLatecomers",
                $this->txt("assign_latecomers")
            );
            $this->toolbar->addComponent(
                $f->button()->standard($this->txt("assign_latecomers"), "")->withOnClick($modal->getShowSignal())
            );
        }

        if ($mgmt->isPublished() && !$mgmt->isActive()) {
            $this->tpl->setOnScreenMessage("info", $this->txt("published_but_inactive"));
        }

        $capacities = $is_c ? $this->grm_repo->getGroupCapacities($mgmt->getId()) : [];
        $waitlist = $is_c ? $this->grm_repo->getWaitlist($mgmt->getId()) : [];

        $properties = $this->getManagementProperties($mgmt);
        $properties[$this->txt("course_members")] = (string) count($members);
        if ($mgmt->usesTopics()) {
            $properties[$this->txt("topics")] = implode(", ", array_map([$this, "esc"], $mgmt->getTopics()));
        }
        if ($mgmt->getMethod() === ilGrmMethods::METHOD_B && !$mgmt->isDrawn() && $members !== []) {
            $properties[$this->txt("expected_groups")] = (string) $mgmt->getEffectiveNumberOfGroups(count($members));
        }
        if ($is_c && $mgmt->isDrawn()) {
            $properties[$this->txt("enrollments")] = sprintf(
                $this->txt("places_taken"),
                count($assignments),
                array_sum($capacities)
            );
            $properties[$this->txt("waitlist")] = (string) count($waitlist);
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
        $form_html = "";

        if ($mgmt->isDrawn()) {
            if ($is_c) {
                $components = array_merge(
                    $components,
                    $this->buildEnrollmentPanels($groups, $assignments, $capacities, $waitlist, $members)
                );
                $form_html = $this->initCapacityForm($mgmt, $groups, $capacities)->getHTML();
            } else {
                $components = array_merge(
                    $components,
                    $this->buildGroupPanels($groups, $assignments, null, $members)
                );
            }
            if ($groups !== []) {
                $form_html .= $this->initMoveForm($mgmt, $groups, $assignments, $waitlist, $members)->getHTML();
            }
            $not_in_group = array_values(array_diff($unassigned, array_keys($waitlist)));
            if ($not_in_group !== []) {
                $components[] = $f->panel()->standard(
                    ($is_c ? $this->txt("not_enrolled_yet") : $this->txt("not_assigned")) . " (" . count($not_in_group) . ")",
                    $f->listing()->unordered($this->getSortedNames($not_in_group))
                );
            }
        } else {
            $components[] = $f->messageBox()->info($is_c ? $this->txt("not_open_yet") : $this->txt("not_drawn_yet"));
        }

        $this->tpl->setContent(
            $this->ui_renderer->render($components) . $form_html . $this->ui_renderer->render($modals)
        );
    }

    /**
     * Method d (not published): toolbar buttons of the registration phases.
     *
     * @return Interruptive[] modals to render with the page
     */
    private function addRegistrationButtons(ilGrmManagement $mgmt): array
    {
        $f = $this->ui_factory;
        $modals = [];
        $activate_note = $mgmt->isActive() ? "" : " " . $this->txt("confirm_go_activate");

        if (!$mgmt->hasGroups()) {
            $modals[] = $modal = $this->buildConfirmModal(
                $mgmt,
                $this->txt("confirm_open_registration") . $activate_note,
                "openRegistration",
                $this->txt("open_registration")
            );
            $this->toolbar->addComponent(
                $f->button()->primary($this->txt("open_registration"), "")->withOnClick($modal->getShowSignal())
            );
            return $modals;
        }

        if ($mgmt->isRegistrationOpen()) {
            $modals[] = $modal = $this->buildConfirmModal(
                $mgmt,
                $this->txt("confirm_close_registration"),
                "closeRegistration",
                $this->txt("close_registration")
            );
            $this->toolbar->addComponent(
                $f->button()->primary($this->txt("close_registration"), "")->withOnClick($modal->getShowSignal())
            );
            return $modals;
        }

        // correction mode (assignment calculated, not published yet)
        $modals[] = $modal = $this->buildConfirmModal(
            $mgmt,
            $this->txt("confirm_recalculate"),
            "recalculateAssignment",
            $this->txt("recalculate")
        );
        $this->toolbar->addComponent(
            $f->button()->standard($this->txt("recalculate"), "")->withOnClick($modal->getShowSignal())
        );
        $modals[] = $modal = $this->buildConfirmModal(
            $mgmt,
            $this->txt("confirm_reopen_registration"),
            "reopenRegistration",
            $this->txt("reopen_registration")
        );
        $this->toolbar->addComponent(
            $f->button()->standard($this->txt("reopen_registration"), "")->withOnClick($modal->getShowSignal())
        );
        $modals[] = $modal = $this->buildConfirmModal(
            $mgmt,
            $this->txt("confirm_go") . $activate_note,
            "goManagement",
            $this->txt("go")
        );
        $this->toolbar->addComponent(
            $f->button()->primary($this->txt("go"), "")->withOnClick($modal->getShowSignal())
        );
        return $modals;
    }

    /**
     * Method d (lecturer view): registrations while the registration is
     * open; result, statistics and correction tools after the assignment.
     *
     * @param array<int, string> $groups
     * @param array<int, int>    $assignments user id => group id
     * @param int[]              $members     course members
     * @param Interruptive[]     $modals
     */
    private function showPriorityManagement(
        ilGrmManagement $mgmt,
        array $groups,
        array $assignments,
        array $members,
        array $modals
    ): void {
        $f = $this->ui_factory;
        $capacities = $this->grm_repo->getGroupCapacities($mgmt->getId());
        $all_wishes = $this->grm_repo->getWishes($mgmt->getId());
        $wishes = array_intersect_key($all_wishes, array_flip($members));
        $without_group = array_values(array_diff($members, array_keys($assignments)));

        if ($mgmt->isDrawn() && $without_group !== [] && array_sum($capacities) > count($assignments)) {
            $modals[] = $modal = $this->buildConfirmModal(
                $mgmt,
                $this->txt("confirm_fill_free_places"),
                "fillFreePlaces",
                $this->txt("fill_free_places")
            );
            $this->toolbar->addComponent(
                $f->button()->standard($this->txt("fill_free_places"), "")->withOnClick($modal->getShowSignal())
            );
        }
        if ($mgmt->isPublished() && !$mgmt->isActive()) {
            $this->tpl->setOnScreenMessage("info", $this->txt("published_but_inactive"));
        }

        $properties = $this->getManagementProperties($mgmt);
        $properties[$this->txt("course_members")] = (string) count($members);
        if ($mgmt->usesTopics()) {
            $properties[$this->txt("topics")] = implode(", ", array_map([$this, "esc"], $mgmt->getTopics()));
        }
        if ($mgmt->getRegistrationEnd() > 0) {
            $deadline = ilDatePresentation::formatDate(new ilDateTime($mgmt->getRegistrationEnd(), IL_CAL_UNIX));
            if ($mgmt->isRegistrationOpen() && $mgmt->isRegistrationDeadlinePassed()) {
                $deadline .= " (" . $this->txt("deadline_passed") . ")";
            }
            $properties[$this->txt("registration_end")] = $deadline;
        }
        if ($mgmt->hasGroups()) {
            $properties[$this->txt("registrations")] = sprintf(
                $this->txt("registrations_count"),
                count($wishes),
                count($members)
            );
        }
        if ($mgmt->isDrawn()) {
            $properties[$this->txt("places")] = sprintf(
                $this->txt("places_taken"),
                count($assignments),
                array_sum($capacities)
            );
            $properties[$this->txt("assignment_result")] = $this->getPriorityStatistics($assignments, $wishes, $members);
        }
        if ($mgmt->getPublished() !== null) {
            $properties[$this->txt("published_at")] = ilDatePresentation::formatDate(
                new ilDateTime($mgmt->getPublished(), IL_CAL_UNIX)
            );
        }

        $components = [
            $f->panel()->standard($this->esc($mgmt->getTitle()), $f->listing()->descriptive($properties)),
        ];
        $form_html = "";

        if (!$mgmt->hasGroups()) {
            $components[] = $f->messageBox()->info($this->txt("not_open_yet_d"));
        } elseif ($mgmt->isRegistrationOpen()) {
            $components[] = $f->messageBox()->info(
                $mgmt->isRegistrationDeadlinePassed()
                    ? $this->txt("registration_deadline_reached_info")
                    : $this->txt("registration_open_info")
            );
            $components = array_merge($components, $this->buildDemandPanels($groups, $capacities, $wishes));
            $components = array_merge($components, $this->buildRegistrationPanels($groups, $wishes, $members));
            $form_html = $this->initCapacityForm($mgmt, $groups, $capacities)->getHTML();
        } else {
            if (!$mgmt->isPublished()) {
                $components[] = $f->messageBox()->info($this->txt("correction_mode_info"));
            }
            $components = array_merge(
                $components,
                $this->buildPriorityPanels($groups, $assignments, $capacities, $wishes, $members)
            );
            $components = array_merge(
                $components,
                $this->buildWithoutPlacePanels($groups, $without_group, $wishes)
            );
            $form_html = $this->initCapacityForm($mgmt, $groups, $capacities)->getHTML()
                . $this->initMoveForm($mgmt, $groups, $assignments, [], $members, $wishes)->getHTML();
        }

        $this->tpl->setContent(
            $this->ui_renderer->render($components) . $form_html . $this->ui_renderer->render($modals)
        );
    }

    /**
     * "1st wish: 12 · 2nd wish: 4 · not wished: 1 · without place: 2"
     *
     * @param array<int, int>   $assignments user id => group id
     * @param array<int, int[]> $wishes      user id => group ids (course members only)
     * @param int[]             $members
     */
    private function getPriorityStatistics(array $assignments, array $wishes, array $members): string
    {
        $by_priority = [];
        $not_wished = 0;
        foreach ($assignments as $user_id => $group_id) {
            if (!isset($wishes[$user_id])) {
                $not_wished++;
                continue;
            }
            $priority = ilGrmPriorityAssigner::priorityOf($wishes[$user_id], $group_id);
            if ($priority === null) {
                $not_wished++;
            } else {
                $by_priority[$priority] = ($by_priority[$priority] ?? 0) + 1;
            }
        }
        ksort($by_priority);

        $parts = [];
        foreach ($by_priority as $priority => $count) {
            $parts[] = sprintf($this->txt("stat_priority"), $priority, $count);
        }
        if ($not_wished > 0) {
            $parts[] = sprintf($this->txt("stat_not_wished"), $not_wished);
        }
        $without_place = count(array_diff($members, array_keys($assignments)));
        if ($without_place > 0) {
            $parts[] = sprintf($this->txt("stat_without_place"), $without_place);
        }
        return $parts === [] ? $this->txt("none") : implode(" · ", $parts);
    }

    /**
     * Method d, registration open: places and demand per group.
     *
     * @param array<int, string> $groups
     * @param array<int, int>    $capacities
     * @param array<int, int[]>  $wishes user id => group ids
     * @return Component[]
     */
    private function buildDemandPanels(array $groups, array $capacities, array $wishes): array
    {
        $f = $this->ui_factory;
        $first = [];
        $total = [];
        foreach ($wishes as $user_wishes) {
            foreach (array_values($user_wishes) as $position => $group_id) {
                $total[$group_id] = ($total[$group_id] ?? 0) + 1;
                if ($position === 0) {
                    $first[$group_id] = ($first[$group_id] ?? 0) + 1;
                }
            }
        }

        $panels = [];
        foreach ($groups as $group_id => $title) {
            $panels[] = $f->panel()->sub($this->esc($title), $f->listing()->descriptive([
                $this->txt("places") => (string) ($capacities[$group_id] ?? 0),
                $this->txt("first_choice_of") => (string) ($first[$group_id] ?? 0),
                $this->txt("wished_by") => (string) ($total[$group_id] ?? 0),
            ]));
        }
        return $panels === [] ? [] : [$f->panel()->standard($this->txt("demand"), $panels)];
    }

    /**
     * Method d, registration open: who has registered with which wishes.
     *
     * @param array<int, string> $groups
     * @param array<int, int[]>  $wishes
     * @param int[]              $members
     * @return Component[]
     */
    private function buildRegistrationPanels(array $groups, array $wishes, array $members): array
    {
        $f = $this->ui_factory;
        $components = [];

        $lines = [];
        foreach ($this->sortUsersByName(array_keys($wishes)) as $user_id) {
            $lines[] = $this->getUserName($user_id) . ": " . $this->esc($this->formatWishes($wishes[$user_id], $groups));
        }
        $components[] = $f->panel()->standard(
            $this->txt("registrations") . " (" . count($lines) . ")",
            $lines !== []
                ? $f->listing()->unordered($lines)
                : $f->listing()->unordered(["<em>" . $this->txt("no_registrations_yet") . "</em>"])
        );

        $missing = array_values(array_diff($members, array_keys($wishes)));
        if ($missing !== []) {
            $components[] = $f->panel()->standard(
                $this->txt("not_registered") . " (" . count($missing) . ")",
                $f->listing()->unordered($this->getSortedNames($missing))
            );
        }
        return $components;
    }

    /**
     * Method d after the assignment: groups with members and the priority
     * each person got.
     *
     * @param array<int, string> $groups
     * @param array<int, int>    $assignments
     * @param array<int, int>    $capacities
     * @param array<int, int[]>  $wishes
     * @param int[]              $course_members
     * @return Component[]
     */
    private function buildPriorityPanels(
        array $groups,
        array $assignments,
        array $capacities,
        array $wishes,
        array $course_members
    ): array {
        $f = $this->ui_factory;
        $panels = [];
        foreach ($groups as $group_id => $title) {
            $member_ids = array_keys(array_filter($assignments, static fn(int $g): bool => $g === $group_id));
            $capacity = $capacities[$group_id] ?? 0;

            $names = [];
            foreach ($this->sortUsersByName($member_ids) as $user_id) {
                $priority = isset($wishes[$user_id])
                    ? ilGrmPriorityAssigner::priorityOf($wishes[$user_id], $group_id)
                    : null;
                if ($priority !== null) {
                    $label = sprintf($this->txt("priority_short"), $priority);
                } elseif (isset($wishes[$user_id])) {
                    $label = $this->txt("not_wished");
                } else {
                    $label = $this->txt("no_registration");
                }
                $name = $this->getUserName($user_id) . " – " . $label;
                if (!in_array($user_id, $course_members, true)) {
                    $name .= " <em>(" . $this->txt("no_longer_member") . ")</em>";
                }
                $names[] = $name;
            }

            $panels[] = $f->panel()->sub(
                $this->esc($title) . " (" . count($member_ids) . "/" . $capacity . ")",
                $f->listing()->descriptive([
                    $this->txt("places") => sprintf($this->txt("places_taken"), count($member_ids), $capacity),
                    $this->txt("members") => $names !== []
                        ? $f->listing()->unordered($names)
                        : "<em>" . $this->txt("group_empty") . "</em>",
                ])
            );
        }
        return $panels === [] ? [] : [$f->panel()->standard($this->txt("groups"), $panels)];
    }

    /**
     * Method d after the assignment: persons without a place (with their wishes).
     *
     * @param array<int, string> $groups
     * @param int[]              $without_group course members without a group
     * @param array<int, int[]>  $wishes
     * @return Component[]
     */
    private function buildWithoutPlacePanels(array $groups, array $without_group, array $wishes): array
    {
        $f = $this->ui_factory;
        $registered = [];
        $not_registered = [];
        foreach ($this->sortUsersByName($without_group) as $user_id) {
            if (isset($wishes[$user_id])) {
                $registered[] = $this->getUserName($user_id) . " – "
                    . sprintf($this->txt("wishes_short"), $this->esc($this->formatWishes($wishes[$user_id], $groups)));
            } else {
                $not_registered[] = $this->getUserName($user_id);
            }
        }

        $components = [];
        if ($registered !== []) {
            $components[] = $f->panel()->standard(
                $this->txt("without_place") . " (" . count($registered) . ")",
                $f->listing()->unordered($registered)
            );
        }
        if ($not_registered !== []) {
            $components[] = $f->panel()->standard(
                $this->txt("not_registered") . " (" . count($not_registered) . ")",
                $f->listing()->unordered($not_registered)
            );
        }
        return $components;
    }

    /**
     * Method c (lecturer view): groups with places, members and waitlist.
     *
     * @param array<int, string> $groups      group id => title
     * @param array<int, int>    $assignments user id => group id
     * @param array<int, int>    $capacities  group id => places
     * @param array<int, int>    $waitlist    user id => group id (in order)
     * @param int[]              $course_members
     * @return Component[]
     */
    private function buildEnrollmentPanels(
        array $groups,
        array $assignments,
        array $capacities,
        array $waitlist,
        array $course_members
    ): array {
        $f = $this->ui_factory;
        $panels = [];
        foreach ($groups as $group_id => $title) {
            $member_ids = array_keys(array_filter($assignments, static fn(int $g): bool => $g === $group_id));
            $waiting_ids = array_keys(array_filter($waitlist, static fn(int $g): bool => $g === $group_id));
            $capacity = $capacities[$group_id] ?? 0;

            $names = [];
            foreach ($this->sortUsersByName($member_ids) as $user_id) {
                $name = $this->getUserName($user_id);
                if (!in_array($user_id, $course_members, true)) {
                    $name .= " <em>(" . $this->txt("no_longer_member") . ")</em>";
                }
                $names[] = $name;
            }
            $waiting_names = [];
            foreach ($waiting_ids as $position => $user_id) {
                $waiting_names[] = ($position + 1) . ". " . $this->getUserName($user_id);
            }

            $content = [
                $this->txt("places") => sprintf($this->txt("places_taken"), count($member_ids), $capacity),
                $this->txt("members") => $names !== []
                    ? $f->listing()->unordered($names)
                    : "<em>" . $this->txt("group_empty") . "</em>",
            ];
            if ($waiting_names !== []) {
                $content[$this->txt("waitlist")] = $f->listing()->unordered($waiting_names);
            }

            $panels[] = $f->panel()->sub(
                $this->esc($title) . " (" . count($member_ids) . "/" . $capacity . ")",
                $f->listing()->descriptive($content)
            );
        }
        return $panels === [] ? [] : [$f->panel()->standard($this->txt("groups"), $panels)];
    }

    /**
     * Method c: the number of places can be changed per group.
     *
     * @param array<int, string> $groups
     * @param array<int, int>    $capacities
     */
    private function initCapacityForm(ilGrmManagement $mgmt, array $groups, array $capacities): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle($this->txt("capacities"));
        $form->setDescription($mgmt->getMethod() === ilGrmMethods::METHOD_D
            ? $this->txt("capacities_info_d")
            : $this->txt("capacities_info"));
        $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
        $form->setFormAction($this->ctrl->getFormAction($this, "saveCapacities"));
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_MGMT_ID);

        foreach ($groups as $group_id => $title) {
            $input = new ilNumberInputGUI($this->esc($title), "cap_" . $group_id);
            $input->setRequired(true);
            $input->setDecimals(0);
            $input->setMinValue(0);
            $input->setMaxValue(ilGrmManagement::MAX_GROUP_SIZE);
            $input->setSize(4);
            $input->setValue((string) ($capacities[$group_id] ?? 0));
            $form->addItem($input);
        }
        $form->addCommandButton("saveCapacities", $this->txt("save_capacities"));
        return $form;
    }

    /**
     * Lecturer: move a person into another group or remove him/her.
     *
     * @param array<int, string> $groups
     * @param array<int, int>    $assignments user id => group id
     * @param array<int, int>    $waitlist    user id => group id
     * @param int[]              $course_members
     * @param array<int, int[]>  $wishes      method d: user id => wished group ids
     */
    private function initMoveForm(
        ilGrmManagement $mgmt,
        array $groups,
        array $assignments,
        array $waitlist,
        array $course_members,
        array $wishes = []
    ): ilPropertyFormGUI {
        $form = new ilPropertyFormGUI();
        $form->setTitle($this->txt("move_member"));
        $form->setDescription(ilGrmMethods::hasPlaces($mgmt->getMethod())
            ? ($mgmt->getMethod() === ilGrmMethods::METHOD_C ? $this->txt("move_member_info_c") : $this->txt("move_member_info_d"))
            : $this->txt("move_member_info"));
        $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
        $form->setFormAction($this->ctrl->getFormAction($this, "moveMember"));
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_MGMT_ID);

        $people = array_values(array_unique(array_merge(
            $course_members,
            array_keys($assignments),
            array_keys($waitlist)
        )));
        $person_options = ["" => $this->txt("move_choose_person")];
        foreach ($this->sortUsersByName($people) as $user_id) {
            if (isset($assignments[$user_id], $groups[$assignments[$user_id]])) {
                $where = $groups[$assignments[$user_id]];
            } elseif (isset($waitlist[$user_id], $groups[$waitlist[$user_id]])) {
                $where = sprintf($this->txt("member_status_waiting"), $groups[$waitlist[$user_id]]);
            } else {
                $where = $this->txt("member_status_none");
            }
            if (isset($wishes[$user_id])) {
                $where .= " · " . sprintf($this->txt("wishes_short"), $this->formatWishes($wishes[$user_id], $groups));
            }
            $person_options[$user_id] = $this->getRawUserName($user_id) . " – " . $where;
        }
        $person = new ilSelectInputGUI($this->txt("move_person"), "move_user");
        $person->setOptions($person_options);
        $person->setRequired(true);
        $form->addItem($person);

        $target_options = [];
        foreach ($groups as $group_id => $title) {
            $target_options[$group_id] = $title;
        }
        $target_options[0] = $this->txt("move_target_none");
        $target = new ilSelectInputGUI($this->txt("move_target"), "move_group");
        $target->setOptions($target_options);
        $target->setRequired(true);
        $form->addItem($target);

        $form->addCommandButton("moveMember", $this->txt("move_submit"));
        return $form;
    }

    private function moveMember(): void
    {
        $mgmt = $this->requireManagement();
        if (!$mgmt->isDrawn()) {
            $this->redirectTo("showManagement", $mgmt);
        }

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $assignments = $this->grm_repo->getAssignments($mgmt->getId());
        $waitlist = $this->grm_repo->getWaitlist($mgmt->getId());
        $members = $this->getCourseMembers();

        $wishes = $mgmt->getMethod() === ilGrmMethods::METHOD_D ? $this->grm_repo->getWishes($mgmt->getId()) : [];
        $form = $this->initMoveForm($mgmt, $groups, $assignments, $waitlist, $members, $wishes);
        $user_id = $form->checkInput() ? (int) $form->getInput("move_user") : 0;
        $target_group_id = $user_id > 0 ? (int) $form->getInput("move_group") : 0;

        $known = in_array($user_id, $members, true) || isset($assignments[$user_id]) || isset($waitlist[$user_id]);
        if ($user_id <= 0 || !$known || ($target_group_id > 0 && !isset($groups[$target_group_id]))) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("move_invalid"), true);
            $this->redirectTo("showManagement", $mgmt);
        }
        if ($target_group_id > 0 && !in_array($user_id, $members, true)) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("move_not_member"), true);
            $this->redirectTo("showManagement", $mgmt);
        }

        $is_c = $mgmt->getMethod() === ilGrmMethods::METHOD_C;
        $result = $this->grm_enrollment->moveMember($mgmt->getId(), $user_id, $target_group_id, $is_c);
        $has_places = ilGrmMethods::hasPlaces($mgmt->getMethod());
        if (!$result["ok"]) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("move_invalid"), true);
            $this->redirectTo("showManagement", $mgmt);
        }

        $name = $this->getUserName($user_id);
        $message = $target_group_id > 0
            ? sprintf($this->txt("moved_done"), $name, $this->esc($groups[$target_group_id]))
            : sprintf($this->txt("removed_done"), $name);
        if ($result["moved"] !== []) {
            $message .= " " . sprintf($this->txt("moved_up_from_waitlist"), count($result["moved"]));
        }
        $this->tpl->setOnScreenMessage("success", $message, true);

        if ($has_places && $target_group_id > 0) {
            $capacity = $this->grm_repo->getGroupCapacities($mgmt->getId())[$target_group_id] ?? 0;
            $count = count(array_filter(
                $this->grm_repo->getAssignments($mgmt->getId()),
                static fn(int $g): bool => $g === $target_group_id
            ));
            if ($count > $capacity) {
                $this->tpl->setOnScreenMessage("info", sprintf(
                    $this->txt("group_overbooked"),
                    $this->esc($groups[$target_group_id]),
                    $count,
                    $capacity
                ), true);
            }
        }
        $this->redirectTo("showManagement", $mgmt);
    }

    private function saveCapacities(): void
    {
        $mgmt = $this->requireManagement();
        if (!ilGrmMethods::hasPlaces($mgmt->getMethod()) || !$mgmt->hasGroups()) {
            $this->redirectTo("showManagement", $mgmt);
        }

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $form = $this->initCapacityForm($mgmt, $groups, $this->grm_repo->getGroupCapacities($mgmt->getId()));
        if (!$form->checkInput()) {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("form_input_not_valid"), true);
            $this->redirectTo("showManagement", $mgmt);
        }

        $capacities = [];
        foreach (array_keys($groups) as $group_id) {
            $capacities[$group_id] = max(0, min(ilGrmManagement::MAX_GROUP_SIZE, (int) $form->getInput("cap_" . $group_id)));
        }
        $moved = $this->grm_enrollment->setCapacities($mgmt->getId(), $capacities);

        $message = $this->txt("capacities_saved");
        if ($moved !== []) {
            $message .= " " . sprintf($this->txt("moved_up_from_waitlist"), count($moved));
        }
        $this->tpl->setOnScreenMessage("success", $message, true);
        $this->redirectTo("showManagement", $mgmt);
    }

    /**
     * What students see first: an overview of all active managements.
     * A click on a management opens its group assignment.
     */
    private function showStudentView(): void
    {
        $this->tabs->activateTab(self::TAB_CONTENT);
        $f = $this->ui_factory;

        if ($this->isLecturer()) {
            $this->toolbar->addComponent($f->button()->standard(
                $this->txt("back_to_overview"),
                $this->ctrl->getLinkTarget($this, "showContent")
            ));
            $this->tpl->setOnScreenMessage("info", $this->txt("student_view_info"));
        }

        $managements = $this->grm_repo->getActiveManagements($this->object->getId());
        if ($managements === []) {
            $this->tpl->setContent($this->ui_renderer->render(
                $f->messageBox()->info($this->txt("no_active_managements"))
            ));
            return;
        }

        $items = [];
        foreach ($managements as $mgmt) {
            $label = $this->txt("your_group");
            if ($mgmt->getMethod() === ilGrmMethods::METHOD_D && $mgmt->isRegistrationOpen()) {
                $label = $this->txt("registration_status");
                $has_wishes = isset($this->grm_repo->getWishes($mgmt->getId())[$this->user->getId()]);
                if ($mgmt->isRegistrationDeadlinePassed()) {
                    $value = $this->txt("registration_closed_short");
                } else {
                    $value = $has_wishes ? $this->txt("wishes_handed_in") : $this->txt("registration_open_no_wishes");
                }
            } elseif ($mgmt->isPublished()) {
                $groups = $this->grm_repo->getGroups($mgmt->getId());
                $my_group_id = $this->grm_repo->getAssignments($mgmt->getId())[$this->user->getId()] ?? null;
                if ($my_group_id !== null && isset($groups[$my_group_id])) {
                    $value = $this->esc($groups[$my_group_id]);
                } elseif ($mgmt->getMethod() === ilGrmMethods::METHOD_C) {
                    $value = $this->getWaitlistText($mgmt, $groups) ?? $this->txt("not_enrolled_yet");
                } else {
                    $value = $this->txt("you_are_not_assigned");
                }
            } else {
                $value = $this->txt("not_published_yet");
            }

            $items[] = $f->item()->standard(
                $f->button()->shy($this->esc($mgmt->getTitle()), $this->linkTo("showStudentManagement", $mgmt))
            )->withProperties([$label => $value]);
        }

        $this->tpl->setContent($this->ui_renderer->render(
            $f->panel()->listing()->standard($this->txt("student_overview"), [$f->item()->group("", $items)])
        ));
    }

    /**
     * Group assignment of one management for students. The own group is
     * shown first and set apart from the other groups.
     */
    private function showStudentManagement(): void
    {
        $this->renderStudentManagement($this->requireStudentManagement());
    }

    /**
     * Students may only see active managements (lecturers all, for the preview).
     */
    private function requireStudentManagement(): ilGrmManagement
    {
        $mgmt = $this->requireManagement();
        if (!$mgmt->isActive() && !$this->isLecturer()) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("management_not_found"), true);
            $this->ctrl->redirect($this, "showContent");
        }
        return $mgmt;
    }

    /**
     * @param ilPropertyFormGUI|null $wishes_form method d: form with input errors to show again
     */
    private function renderStudentManagement(ilGrmManagement $mgmt, ?ilPropertyFormGUI $wishes_form = null): void
    {
        $this->tabs->activateTab(self::TAB_CONTENT);
        $f = $this->ui_factory;

        $this->toolbar->addComponent($f->button()->standard(
            $this->txt("back_to_overview"),
            $this->ctrl->getLinkTarget($this, "showStudentView")
        ));
        if ($this->isLecturer()) {
            $this->tpl->setOnScreenMessage("info", $this->txt("student_view_info"));
        }

        $this->tpl->setTitle($this->object->getTitle() . ": " . $mgmt->getTitle());

        if ($mgmt->getMethod() === ilGrmMethods::METHOD_D && !$mgmt->isPublished()) {
            $this->showStudentRegistration($mgmt, $wishes_form);
            return;
        }

        if (!$mgmt->isPublished()) {
            $this->tpl->setContent($this->ui_renderer->render(
                $f->messageBox()->info($this->txt("assignment_not_visible_yet"))
            ));
            return;
        }

        if ($mgmt->getMethod() === ilGrmMethods::METHOD_C) {
            $this->showStudentEnrollment($mgmt);
            return;
        }

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $assignments = $this->grm_repo->getAssignments($mgmt->getId());
        $my_group_id = $assignments[$this->user->getId()] ?? null;
        if ($my_group_id !== null && !isset($groups[$my_group_id])) {
            $my_group_id = null;
        }

        $components = [];
        if ($my_group_id !== null) {
            // own group first and set apart
            $message = sprintf($this->txt("you_are_in_group"), $this->esc($groups[$my_group_id]));
            if ($mgmt->getMethod() === ilGrmMethods::METHOD_D) {
                $my_wishes = $this->grm_repo->getWishes($mgmt->getId())[$this->user->getId()] ?? [];
                $priority = ilGrmPriorityAssigner::priorityOf($my_wishes, $my_group_id);
                if ($priority !== null) {
                    $message .= " " . sprintf($this->txt("you_got_priority"), $priority);
                } elseif ($my_wishes !== []) {
                    $message .= " " . $this->txt("you_got_not_wished");
                }
            }
            $components[] = $f->messageBox()->success($message);
            $components = array_merge($components, $this->buildGroupPanels(
                [$my_group_id => $groups[$my_group_id]],
                $assignments,
                null,
                null,
                $this->txt("your_group")
            ));
        } else {
            $components[] = $f->messageBox()->info($this->txt("you_are_not_assigned"));
        }

        if ($mgmt->isShowAllGroups()) {
            $other_groups = $groups;
            if ($my_group_id !== null) {
                unset($other_groups[$my_group_id]);
            }
            $components = array_merge($components, $this->buildGroupPanels(
                $other_groups,
                $assignments,
                null,
                null,
                $my_group_id !== null ? $this->txt("other_groups") : $this->txt("groups")
            ));
        }

        $this->tpl->setContent($this->ui_renderer->render($components));
    }

    /**
     * Method d (student view) before "Los": hand in, change or withdraw the
     * wishes while the registration is open.
     */
    private function showStudentRegistration(ilGrmManagement $mgmt, ?ilPropertyFormGUI $form = null): void
    {
        $f = $this->ui_factory;
        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $my_wishes = $this->grm_repo->getWishes($mgmt->getId())[$this->user->getId()] ?? [];

        $components = [];
        $modals = [];
        $form_html = "";

        if (!$mgmt->isRegistrationOpen()) {
            $components[] = $f->messageBox()->info(
                $mgmt->hasGroups() ? $this->txt("registration_closed_wait") : $this->txt("registration_not_open_yet")
            );
        } elseif ($mgmt->isRegistrationDeadlinePassed()) {
            $components[] = $f->messageBox()->info(sprintf(
                $this->txt("registration_deadline_passed"),
                ilDatePresentation::formatDate(new ilDateTime($mgmt->getRegistrationEnd(), IL_CAL_UNIX))
            ));
        } else {
            $intro = sprintf($this->txt("registration_intro"), min($mgmt->getNumPriorities(), count($groups)));
            if ($mgmt->getRegistrationEnd() > 0) {
                $intro .= " " . sprintf(
                    $this->txt("registration_deadline_info"),
                    ilDatePresentation::formatDate(new ilDateTime($mgmt->getRegistrationEnd(), IL_CAL_UNIX))
                );
            }
            $components[] = $f->messageBox()->info($intro);
            if ($my_wishes !== []) {
                $components[] = $f->messageBox()->success($this->txt("wishes_saved_info"));
                $modals[] = $modal = $this->buildConfirmModal(
                    $mgmt,
                    $this->txt("confirm_withdraw_wishes"),
                    "withdrawWishes",
                    $this->txt("withdraw_wishes")
                );
                $this->toolbar->addComponent(
                    $f->button()->standard($this->txt("withdraw_wishes"), "")->withOnClick($modal->getShowSignal())
                );
            }
            $form ??= $this->initWishesForm($mgmt, $groups, $my_wishes);
            $form_html = $form->getHTML();
        }

        if ($form_html === "" && $my_wishes !== []) {
            $items = [];
            foreach ($my_wishes as $group_id) {
                $items[] = $this->esc($groups[$group_id] ?? "?");
            }
            $components[] = $f->panel()->standard($this->txt("your_wishes"), $f->listing()->ordered($items));
        }

        $this->tpl->setContent(
            $this->ui_renderer->render($components) . $form_html . $this->ui_renderer->render($modals)
        );
    }

    /**
     * Method d: one selection per priority (1st wish required, the others optional).
     *
     * @param array<int, string> $groups
     * @param int[]              $wishes current wishes, highest priority first
     */
    private function initWishesForm(ilGrmManagement $mgmt, array $groups, array $wishes): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle($this->txt("your_wishes"));
        $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
        $form->setFormAction($this->ctrl->getFormAction($this, "saveWishes"));
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_MGMT_ID);

        $capacities = $this->grm_repo->getGroupCapacities($mgmt->getId());
        $group_options = [];
        foreach ($groups as $group_id => $title) {
            $group_options[$group_id] = $title . " (" . sprintf($this->txt("places_n"), $capacities[$group_id] ?? 0) . ")";
        }

        $count = min($mgmt->getNumPriorities(), count($groups));
        for ($priority = 1; $priority <= $count; $priority++) {
            $select = new ilSelectInputGUI(sprintf($this->txt("priority_n"), $priority), "prio_" . $priority);
            $select->setOptions(
                ["" => $priority === 1 ? $this->txt("choose_group_option") : $this->txt("no_wish_option")]
                + $group_options
            );
            if ($priority === 1) {
                $select->setRequired(true);
            }
            $select->setValue((string) ($wishes[$priority - 1] ?? ""));
            $form->addItem($select);
        }

        $form->addCommandButton("saveWishes", $this->txt("save_wishes"));
        return $form;
    }

    private function saveWishes(): void
    {
        $mgmt = $this->requireStudentManagement();
        if ($mgmt->getMethod() !== ilGrmMethods::METHOD_D || !$mgmt->isRegistrationOpen()) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("registration_not_open"), true);
            $this->redirectTo("showStudentManagement", $mgmt);
        }
        if ($mgmt->isRegistrationDeadlinePassed()) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("registration_deadline_over"), true);
            $this->redirectTo("showStudentManagement", $mgmt);
        }
        $user_id = $this->user->getId();
        if (!in_array($user_id, $this->getCourseMembers(), true)) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("registration_not_member"), true);
            $this->redirectTo("showStudentManagement", $mgmt);
        }

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $form = $this->initWishesForm($mgmt, $groups, []);
        $valid = $form->checkInput();

        $chosen = [];
        $count = min($mgmt->getNumPriorities(), count($groups));
        for ($priority = 1; $valid && $priority <= $count; $priority++) {
            $group_id = (int) $form->getInput("prio_" . $priority);
            if ($group_id <= 0) {
                continue;
            }
            if (!isset($groups[$group_id]) || in_array($group_id, $chosen, true)) {
                $item = $form->getItemByPostVar("prio_" . $priority);
                if ($item !== null) {
                    $item->setAlert($this->txt("wishes_duplicate"));
                }
                $valid = false;
                break;
            }
            $chosen[] = $group_id;
        }
        if (!$valid || $chosen === []) {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("form_input_not_valid"));
            $form->setValuesByPost();
            $this->renderStudentManagement($mgmt, $form);
            return;
        }

        if (!$this->grm_registration->saveWishes($mgmt->getId(), $user_id, $chosen)) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("registration_not_open"), true);
            $this->redirectTo("showStudentManagement", $mgmt);
        }
        // the page itself confirms the saved wishes ("wishes_saved_info")
        $this->redirectTo("showStudentManagement", $mgmt);
    }

    private function withdrawWishes(): void
    {
        $mgmt = $this->requireStudentManagement();
        if ($mgmt->getMethod() !== ilGrmMethods::METHOD_D
            || $mgmt->isRegistrationDeadlinePassed()
            || !$this->grm_registration->withdraw($mgmt->getId(), $this->user->getId())) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("registration_not_open"), true);
            $this->redirectTo("showStudentManagement", $mgmt);
        }
        $this->tpl->setOnScreenMessage("success", $this->txt("wishes_withdrawn"), true);
        $this->redirectTo("showStudentManagement", $mgmt);
    }

    /**
     * Plugin stylesheet (e.g. red titles), loaded on all pages of the plugin.
     */
    private function addPluginCss(): void
    {
        $plugin = $this->getPlugin();
        // ILIAS 10/11: web root is public/, getRelativeDirectory() is relative to it.
        // ILIAS 9: getDirectory() is already relative to the web root.
        $dir = method_exists($plugin, "getRelativeDirectory")
            ? $plugin->getRelativeDirectory()
            : $plugin->getDirectory();
        $this->tpl->addCss(rtrim($dir, "/") . "/templates/css/xgrm.css");
    }

    /**
     * Method c (student view): own group on top, then all groups with free
     * places and the buttons to enroll or to join the waitlist.
     */
    private function showStudentEnrollment(ilGrmManagement $mgmt): void
    {
        $f = $this->ui_factory;
        $user_id = $this->user->getId();

        $groups = $this->grm_repo->getGroups($mgmt->getId());
        $assignments = $this->grm_repo->getAssignments($mgmt->getId());
        $capacities = $this->grm_repo->getGroupCapacities($mgmt->getId());
        $waitlist = $this->grm_repo->getWaitlist($mgmt->getId());

        $my_group_id = $assignments[$user_id] ?? null;
        if ($my_group_id !== null && !isset($groups[$my_group_id])) {
            $my_group_id = null;
        }
        $is_waiting = isset($waitlist[$user_id]);
        $can_enroll = $my_group_id === null && !$is_waiting;

        $components = [];
        $modals = [];

        if ($my_group_id !== null) {
            $components[] = $f->messageBox()->success(
                sprintf($this->txt("you_are_in_group"), $this->esc($groups[$my_group_id]))
            );
            $components = array_merge($components, $this->buildGroupPanels(
                [$my_group_id => $groups[$my_group_id]],
                $assignments,
                null,
                null,
                $this->txt("your_group")
            ));
            $modals[] = $modal = $this->buildConfirmModal(
                $mgmt,
                $this->txt("confirm_unenroll"),
                "unenrollGroup",
                $this->txt("unenroll")
            );
            $this->toolbar->addComponent(
                $f->button()->standard($this->txt("unenroll"), "")->withOnClick($modal->getShowSignal())
            );
        } elseif ($is_waiting) {
            $components[] = $f->messageBox()->info((string) $this->getWaitlistText($mgmt, $groups));
            $modals[] = $modal = $this->buildConfirmModal(
                $mgmt,
                $this->txt("confirm_leave_waitlist"),
                "unenrollGroup",
                $this->txt("leave_waitlist")
            );
            $this->toolbar->addComponent(
                $f->button()->standard($this->txt("leave_waitlist"), "")->withOnClick($modal->getShowSignal())
            );
        } else {
            $components[] = $f->messageBox()->info($this->txt("choose_group"));
        }

        $panels = [];
        foreach ($groups as $group_id => $title) {
            if ($group_id === $my_group_id) {
                continue;
            }
            $member_ids = array_keys(array_filter($assignments, static fn(int $g): bool => $g === $group_id));
            $waiting = count(array_filter($waitlist, static fn(int $g): bool => $g === $group_id));
            $capacity = $capacities[$group_id] ?? 0;
            $free = max(0, $capacity - count($member_ids));

            $properties = [
                $this->txt("places") => $free > 0
                    ? sprintf($this->txt("places_free"), $free, $capacity)
                    : $this->txt("group_full"),
            ];
            if ($mgmt->hasWaitlist() && $waiting > 0) {
                $properties[$this->txt("waitlist")] = sprintf($this->txt("waitlist_count"), $waiting);
            }
            if ($mgmt->isShowAllGroups()) {
                $properties[$this->txt("members")] = $member_ids !== []
                    ? $f->listing()->unordered($this->getSortedNames($member_ids))
                    : "<em>" . $this->txt("group_empty") . "</em>";
            }
            $content = [$f->listing()->descriptive($properties)];

            if ($can_enroll && ($free > 0 || $mgmt->hasWaitlist())) {
                $this->ctrl->setParameter($this, self::PARAM_GROUP_ID, $group_id);
                $url = $this->linkTo("enrollGroup", $mgmt);
                $this->ctrl->clearParameterByClass(self::class, self::PARAM_GROUP_ID);
                $content[] = $free > 0
                    ? $f->button()->primary($this->txt("enroll"), $url)
                    : $f->button()->standard($this->txt("join_waitlist"), $url);
            }

            $panels[] = $f->panel()->sub($this->esc($title), $content);
        }
        if ($panels !== []) {
            $components[] = $f->panel()->standard(
                $my_group_id !== null ? $this->txt("other_groups") : $this->txt("groups"),
                $panels
            );
        }

        $this->tpl->setContent($this->ui_renderer->render(array_merge($components, $modals)));
    }

    /**
     * "On the waitlist for group X (position n)" or null if not waiting.
     *
     * @param array<int, string> $groups
     */
    private function getWaitlistText(ilGrmManagement $mgmt, array $groups): ?string
    {
        $user_id = $this->user->getId();
        $waitlist = $this->grm_repo->getWaitlist($mgmt->getId());
        if (!isset($waitlist[$user_id])) {
            return null;
        }
        $group_id = $waitlist[$user_id];
        $position = 1;
        foreach ($waitlist as $waiting_user => $waiting_group) {
            if ($waiting_user === $user_id) {
                break;
            }
            if ($waiting_group === $group_id) {
                $position++;
            }
        }
        return sprintf($this->txt("on_waitlist_for"), $this->esc($groups[$group_id] ?? "?"), $position);
    }

    private function enrollGroup(): void
    {
        $mgmt = $this->requireEnrollmentOpen();

        $group_id = $this->grm_query->has(self::PARAM_GROUP_ID)
            ? $this->grm_query->retrieve(self::PARAM_GROUP_ID, $this->grm_refinery->kindlyTo()->int())
            : 0;
        $user_id = $this->user->getId();

        if (!in_array($user_id, $this->getCourseMembers(), true)) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("enroll_not_member"), true);
            $this->redirectTo("showStudentManagement", $mgmt);
        }

        $result = $this->grm_enrollment->enroll($mgmt->getId(), $group_id, $user_id, $mgmt->hasWaitlist());
        switch ($result) {
            case ilGrmEnrollment::RESULT_ENROLLED:
            case ilGrmEnrollment::RESULT_WAITLISTED:
                // the page itself shows the group or the waitlist place
                break;
            case ilGrmEnrollment::RESULT_FULL:
                $this->tpl->setOnScreenMessage("failure", $this->txt("enroll_full"), true);
                break;
            case ilGrmEnrollment::RESULT_ALREADY:
                $this->tpl->setOnScreenMessage("failure", $this->txt("enroll_already"), true);
                break;
            default:
                $this->tpl->setOnScreenMessage("failure", $this->txt("management_not_found"), true);
        }
        $this->redirectTo("showStudentManagement", $mgmt);
    }

    private function unenrollGroup(): void
    {
        $mgmt = $this->requireEnrollmentOpen();
        $this->grm_enrollment->unenroll($mgmt->getId(), $this->user->getId());
        $this->tpl->setOnScreenMessage("success", $this->txt("unenroll_done"), true);
        $this->redirectTo("showStudentManagement", $mgmt);
    }

    /**
     * Method c: the management must be active and published ("Los").
     */
    private function requireEnrollmentOpen(): ilGrmManagement
    {
        $mgmt = $this->requireManagement();
        if ($mgmt->getMethod() !== ilGrmMethods::METHOD_C || !$mgmt->isActive() || !$mgmt->isPublished()) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("enroll_closed"), true);
            $this->ctrl->redirect($this, "showStudentView");
        }
        return $mgmt;
    }

    private function isLecturer(): bool
    {
        return $this->access->checkAccess("write", "", $this->object->getRefId());
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
        if ($this->grm_repo->getActiveManagements($this->object->getId()) === []) {
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
                "group_size" => (string) $mgmt->getGroupSize(),
                "remainder_mode" => $mgmt->getRemainderMode(),
                "c_num_groups" => (string) $mgmt->getNumGroups(),
                "c_places" => (string) $mgmt->getPlacesPerGroup(),
                "c_waitlist" => $mgmt->hasWaitlist(),
                "d_assign_mode" => $mgmt->getAssignMode(),
                "d_num_groups" => (string) $mgmt->getNumGroups(),
                "d_topics" => implode("\n", $mgmt->getTopics()),
                "d_places" => (string) $mgmt->getPlacesPerGroup(),
                "d_num_prios" => (string) $mgmt->getNumPriorities(),
                "show_all" => $mgmt->isShowAllGroups(),
            ]);
            $deadline = $form->getItemByPostVar("d_reg_end");
            if ($deadline instanceof ilDateTimeInputGUI && $mgmt->getRegistrationEnd() > 0) {
                $deadline->setDate(new ilDateTime($mgmt->getRegistrationEnd(), IL_CAL_UNIX));
            }
            if ($mgmt->isDrawn() && !$this->isStructureLocked($mgmt)) {
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

        $before = $this->getStructureSignature($mgmt);
        if (!$this->applyManagementForm($form, $mgmt)) {
            $form->setValuesByPost();
            $this->editManagement($form);
            return;
        }
        $after = $this->getStructureSignature($mgmt);

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
                foreach (array_merge($this->buildMethodInputs($key), $this->buildMethodExtraInputs($key)) as $input) {
                    $option->addSubItem($input);
                }
                $method->addOption($option);
            }
            $form->addItem($method);
            $form->addItem($this->buildShowAllInput());
        } else {
            $method = new ilNonEditableValueGUI($this->txt("method"), "", true);
            $method->setValue($this->txt("method_" . $mgmt->getMethod()));
            $form->addItem($method);

            if ($this->isStructureLocked($mgmt)) {
                $mode = new ilNonEditableValueGUI($this->txt("assign_mode"), "", true);
                $mode->setValue($this->getModeSummary($mgmt));
                $mode->setInfo(ilGrmMethods::hasPlaces($mgmt->getMethod()) && !$mgmt->isPublished()
                    ? $this->txt("settings_locked_c")
                    : $this->txt("settings_locked_published"));
                $form->addItem($mode);
            } else {
                foreach ($this->buildMethodInputs($mgmt->getMethod()) as $input) {
                    $form->addItem($input);
                }
            }
            foreach ($this->buildMethodExtraInputs($mgmt->getMethod()) as $input) {
                $form->addItem($input);
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
     * Method specific settings.
     *
     * @return ilFormPropertyGUI[]
     */
    private function buildMethodInputs(string $method): array
    {
        switch ($method) {
            case ilGrmMethods::METHOD_A:
                return [$this->buildAssignModeInput()];
            case ilGrmMethods::METHOD_B:
                return $this->buildGroupSizeInputs();
            case ilGrmMethods::METHOD_C:
                return $this->buildEnrollmentInputs();
            case ilGrmMethods::METHOD_D:
                return $this->buildPriorityInputs();
            default:
                return [];
        }
    }

    /**
     * Method d: groups or topics, places per group and number of wishes.
     *
     * @return ilFormPropertyGUI[]
     */
    private function buildPriorityInputs(): array
    {
        $places = new ilNumberInputGUI($this->txt("places_per_group"), "d_places");
        $places->setRequired(true);
        $places->setDecimals(0);
        $places->setMinValue(1);
        $places->setMaxValue(ilGrmManagement::MAX_GROUP_SIZE);
        $places->setSize(4);
        $places->setValue("5");
        $places->setInfo($this->txt("places_per_group_info_d"));

        $num_prios = new ilNumberInputGUI($this->txt("num_priorities"), "d_num_prios");
        $num_prios->setRequired(true);
        $num_prios->setDecimals(0);
        $num_prios->setMinValue(1);
        $num_prios->setMaxValue(ilGrmManagement::MAX_PRIORITIES);
        $num_prios->setSize(4);
        $num_prios->setValue("3");
        $num_prios->setInfo($this->txt("num_priorities_info"));

        return [$this->buildAssignModeInput("d_"), $places, $num_prios];
    }

    /**
     * Method specific settings that can be changed at any time.
     *
     * @return ilFormPropertyGUI[]
     */
    private function buildMethodExtraInputs(string $method): array
    {
        if ($method === ilGrmMethods::METHOD_D) {
            $deadline = new ilDateTimeInputGUI($this->txt("registration_end"), "d_reg_end");
            $deadline->setShowTime(true);
            $deadline->setInfo($this->txt("registration_end_info"));
            return [$deadline];
        }
        if ($method !== ilGrmMethods::METHOD_C) {
            return [];
        }
        $waitlist = new ilCheckboxInputGUI($this->txt("waitlist_setting"), "c_waitlist");
        $waitlist->setInfo($this->txt("waitlist_setting_info"));
        $waitlist->setChecked(true);
        return [$waitlist];
    }

    /**
     * Method c: number of groups and places per group.
     *
     * @return ilFormPropertyGUI[]
     */
    private function buildEnrollmentInputs(): array
    {
        $num = new ilNumberInputGUI($this->txt("num_groups"), "c_num_groups");
        $num->setRequired(true);
        $num->setDecimals(0);
        $num->setMinValue(1);
        $num->setMaxValue(ilGrmManagement::MAX_GROUPS);
        $num->setSize(4);
        $num->setValue("4");

        $places = new ilNumberInputGUI($this->txt("places_per_group"), "c_places");
        $places->setRequired(true);
        $places->setDecimals(0);
        $places->setMinValue(1);
        $places->setMaxValue(ilGrmManagement::MAX_GROUP_SIZE);
        $places->setSize(4);
        $places->setValue("3");
        $places->setInfo($this->txt("places_per_group_info"));

        return [$num, $places];
    }

    /**
     * Group structure can no longer be changed: after "Los", and for methods
     * with places (c, d) as soon as the groups exist (they may contain
     * enrollments or be referenced by wishes).
     */
    private function isStructureLocked(ilGrmManagement $mgmt): bool
    {
        return $mgmt->isPublished()
            || (ilGrmMethods::hasPlaces($mgmt->getMethod()) && $mgmt->hasGroups());
    }

    /**
     * Method b: size of the groups (2, 3, 4, …) and what happens if it does not add up.
     *
     * @return ilFormPropertyGUI[]
     */
    private function buildGroupSizeInputs(): array
    {
        $size = new ilNumberInputGUI($this->txt("group_size"), "group_size");
        $size->setRequired(true);
        $size->setDecimals(0);
        $size->setMinValue(ilGrmManagement::MIN_GROUP_SIZE);
        $size->setMaxValue(ilGrmManagement::MAX_GROUP_SIZE);
        $size->setSize(4);
        $size->setValue("3");
        $size->setInfo($this->txt("group_size_info"));

        $remainder = new ilRadioGroupInputGUI($this->txt("remainder_mode"), "remainder_mode");
        $remainder->setRequired(true);
        $remainder->setValue(ilGrmManagement::REMAINDER_SMALLER);
        foreach (ilGrmManagement::REMAINDER_MODES as $remainder_mode) {
            $remainder->addOption(new ilRadioOption(
                $this->txt("remainder_" . $remainder_mode),
                $remainder_mode,
                $this->txt("remainder_" . $remainder_mode . "_info")
            ));
        }

        return [$size, $remainder];
    }

    /**
     * Settings that define the groups – if they change, an existing draw is discarded.
     *
     * @return array<int, mixed>
     */
    private function getStructureSignature(ilGrmManagement $mgmt): array
    {
        return [
            $mgmt->getAssignMode(),
            $mgmt->getNumGroups(),
            $mgmt->getTopics(),
            $mgmt->getGroupSize(),
            $mgmt->getRemainderMode(),
            $mgmt->getNumPriorities(),
        ];
    }

    /**
     * Methods a and d: "fixed number of groups" or "fixed number of topics".
     *
     * @param string $prefix prefix of the post variables (each method needs its own)
     */
    private function buildAssignModeInput(string $prefix = ""): ilRadioGroupInputGUI
    {
        $mode = new ilRadioGroupInputGUI($this->txt("assign_mode"), $prefix . "assign_mode");
        $mode->setRequired(true);
        $mode->setValue(ilGrmManagement::MODE_GROUPS);

        // method d: the students choose, nobody is distributed randomly
        $info_suffix = $prefix === "d_" ? "_d" : "";
        $groups = new ilRadioOption(
            $this->txt("mode_groups"),
            ilGrmManagement::MODE_GROUPS,
            $this->txt("mode_groups_info" . $info_suffix)
        );
        $num = new ilNumberInputGUI($this->txt("num_groups"), $prefix . "num_groups");
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
            $this->txt("mode_topics_info" . $info_suffix)
        );
        $topics = new ilTextAreaInputGUI($this->txt("topics"), $prefix . "topics");
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
        if ($mgmt->getMethod() === ilGrmMethods::METHOD_C) {
            $mgmt->setWaitlist((bool) $form->getInput("c_waitlist"));
        }
        if ($mgmt->getMethod() === ilGrmMethods::METHOD_D) {
            $deadline = $form->getItemByPostVar("d_reg_end");
            $date = $deadline instanceof ilDateTimeInputGUI ? $deadline->getDate() : null;
            $mgmt->setRegistrationEnd($date !== null ? (int) $date->get(IL_CAL_UNIX) : 0);
        }

        if ($this->isStructureLocked($mgmt)) {
            return true;
        }

        if ($mgmt->getMethod() === ilGrmMethods::METHOD_D) {
            return $this->applyPriorityForm($form, $mgmt);
        }

        if ($mgmt->getMethod() === ilGrmMethods::METHOD_C) {
            $num = (int) $form->getInput("c_num_groups");
            if ($num < 1 || $num > ilGrmManagement::MAX_GROUPS) {
                $this->setFormAlert($form, "c_num_groups", sprintf($this->txt("num_groups_invalid"), ilGrmManagement::MAX_GROUPS));
                return false;
            }
            $places = (int) $form->getInput("c_places");
            if ($places < 1 || $places > ilGrmManagement::MAX_GROUP_SIZE) {
                $this->setFormAlert($form, "c_places", sprintf($this->txt("places_invalid"), ilGrmManagement::MAX_GROUP_SIZE));
                return false;
            }
            $mgmt->setNumGroups($num);
            $mgmt->setPlacesPerGroup($places);
            return true;
        }

        if ($mgmt->getMethod() === ilGrmMethods::METHOD_B) {
            $size = (int) $form->getInput("group_size");
            if ($size < ilGrmManagement::MIN_GROUP_SIZE || $size > ilGrmManagement::MAX_GROUP_SIZE) {
                $this->setFormAlert($form, "group_size", sprintf(
                    $this->txt("group_size_invalid"),
                    ilGrmManagement::MIN_GROUP_SIZE,
                    ilGrmManagement::MAX_GROUP_SIZE
                ));
                return false;
            }
            $mgmt->setGroupSize($size);
            $mgmt->setRemainderMode((string) $form->getInput("remainder_mode"));
            return true;
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

    /**
     * Method d: groups or topics, places and number of wishes.
     */
    private function applyPriorityForm(ilPropertyFormGUI $form, ilGrmManagement $mgmt): bool
    {
        $mgmt->setAssignMode((string) $form->getInput("d_assign_mode"));
        if ($mgmt->getAssignMode() === ilGrmManagement::MODE_TOPICS) {
            $topics = ilGrmManagement::parseTopics((string) $form->getInput("d_topics"));
            if ($topics === [] || count($topics) > ilGrmManagement::MAX_GROUPS) {
                $this->setFormAlert($form, "d_topics", sprintf($this->txt("topics_invalid"), ilGrmManagement::MAX_GROUPS));
                return false;
            }
            $mgmt->setTopics($topics);
        } else {
            $num = (int) $form->getInput("d_num_groups");
            if ($num < 1 || $num > ilGrmManagement::MAX_GROUPS) {
                $this->setFormAlert($form, "d_num_groups", sprintf($this->txt("num_groups_invalid"), ilGrmManagement::MAX_GROUPS));
                return false;
            }
            $mgmt->setNumGroups($num);
        }

        $places = (int) $form->getInput("d_places");
        if ($places < 1 || $places > ilGrmManagement::MAX_GROUP_SIZE) {
            $this->setFormAlert($form, "d_places", sprintf($this->txt("places_invalid"), ilGrmManagement::MAX_GROUP_SIZE));
            return false;
        }
        $mgmt->setPlacesPerGroup($places);

        $num_prios = (int) $form->getInput("d_num_prios");
        $groups = $mgmt->getEffectiveNumberOfGroups();
        if ($num_prios < 1 || $num_prios > ilGrmManagement::MAX_PRIORITIES || $num_prios > $groups) {
            $this->setFormAlert($form, "d_num_prios", sprintf(
                $this->txt("num_priorities_invalid"),
                min(ilGrmManagement::MAX_PRIORITIES, $groups)
            ));
            return false;
        }
        $mgmt->setNumPriorities($num_prios);
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

    private function activateManagement(): void
    {
        $mgmt = $this->requireManagement();
        if (!ilGrmMethods::isImplemented($mgmt->getMethod())) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("method_not_available"), true);
            $this->ctrl->redirect($this, "showContent");
        }
        $this->grm_repo->activate($mgmt);
        $this->tpl->setOnScreenMessage("success", $this->txt("management_activated"), true);
        $this->redirectAfterAction($mgmt);
    }

    private function deactivateManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->grm_repo->deactivate($mgmt);
        $this->tpl->setOnScreenMessage("success", $this->txt("management_deactivated"), true);
        $this->redirectAfterAction($mgmt);
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    private function buildDeleteModal(ilGrmManagement $mgmt): Interruptive
    {
        return $this->buildConfirmModal(
            $mgmt,
            $this->txt("confirm_delete"),
            "deleteManagement",
            $this->lng->txt("delete")
        );
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

    private function drawManagement(): void
    {
        $mgmt = $this->requireManagement();
        $this->requireNotPublished($mgmt);
        if (!ilGrmMethods::usesRandomDraw($mgmt->getMethod())) {
            $this->redirectTo("showManagement", $mgmt);
        }

        if ($this->performDraw($mgmt)) {
            $this->tpl->setOnScreenMessage("success", $this->txt("draw_done"), true);
        }
        $this->redirectTo("showManagement", $mgmt);
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

        $is_c = $mgmt->getMethod() === ilGrmMethods::METHOD_C;
        if ($mgmt->getMethod() === ilGrmMethods::METHOD_D && !$mgmt->isDrawn()) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("go_needs_assignment"), true);
            $this->redirectTo("showManagement", $mgmt);
        }
        if (!$mgmt->isDrawn()) {
            $prepared = $is_c ? $this->createEnrollmentGroups($mgmt) : $this->performDraw($mgmt);
            if (!$prepared) {
                $this->redirectTo("showManagement", $mgmt);
            }
        }

        $mgmt->setStatus(ilGrmManagement::STATUS_PUBLISHED);
        $mgmt->setPublished(time());
        $this->grm_repo->saveManagement($mgmt);
        if (!$mgmt->isActive()) {
            $this->grm_repo->activate($mgmt);
        }

        $this->tpl->setOnScreenMessage("success", $is_c ? $this->txt("go_done_c") : $this->txt("go_done"), true);
        $this->redirectTo("showManagement", $mgmt);
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

    /**
     * Course members who joined after the draw are added to the currently
     * smallest groups; existing assignments stay untouched.
     */
    private function assignLatecomers(): void
    {
        $mgmt = $this->requireManagement();
        if (!$mgmt->isDrawn() || !ilGrmMethods::usesRandomDraw($mgmt->getMethod())) {
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
     * Random assignment of all course members (methods a and b).
     */
    // ------------------------------------------------------------------
    // Method d: registration with priorities
    // ------------------------------------------------------------------

    /**
     * Creates the groups and opens the registration (students hand in their wishes).
     */
    private function openRegistration(): void
    {
        $mgmt = $this->requireManagement();
        if ($mgmt->getMethod() !== ilGrmMethods::METHOD_D || $mgmt->hasGroups()) {
            $this->redirectTo("showManagement", $mgmt);
        }
        if ($this->getCourseRefId() === 0) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("no_parent_course"), true);
            $this->redirectTo("showManagement", $mgmt);
        }
        $titles = $mgmt->buildGroupTitles(fn(int $n): string => sprintf($this->txt("group_title_n"), $n));
        if ($titles === []) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("no_groups_configured"), true);
            $this->redirectTo("showManagement", $mgmt);
        }

        $this->grm_repo->createGroups($mgmt->getId(), $titles, $mgmt->getPlacesPerGroup());
        $mgmt->setStatus(ilGrmManagement::STATUS_OPEN);
        $this->grm_repo->saveManagement($mgmt);

        $message = $this->txt("registration_opened");
        if (!$mgmt->isActive()) {
            $this->grm_repo->activate($mgmt);
            $message .= " " . $this->txt("management_activated");
        }
        $this->tpl->setOnScreenMessage("success", $message, true);
        $this->redirectTo("showManagement", $mgmt);
    }

    /**
     * Closes the registration and calculates the assignment (correction mode).
     */
    private function closeRegistration(): void
    {
        $mgmt = $this->requireManagement();
        if ($mgmt->getMethod() !== ilGrmMethods::METHOD_D || !$mgmt->isRegistrationOpen()) {
            $this->redirectTo("showManagement", $mgmt);
        }
        if (!$this->grm_registration->close($mgmt->getId())) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("registration_not_open"), true);
            $this->redirectTo("showManagement", $mgmt);
        }
        $mgmt->setStatus(ilGrmManagement::STATUS_DRAWN);

        [$assigned, $registered] = $this->runPriorityAssignment($mgmt);
        $this->tpl->setOnScreenMessage("success", sprintf($this->txt("assignment_done"), $assigned, $registered), true);
        $this->redirectTo("showManagement", $mgmt);
    }

    /**
     * Calculates the assignment again from the wishes (manual corrections are discarded).
     */
    private function recalculateAssignment(): void
    {
        $mgmt = $this->requireManagement();
        if ($mgmt->getMethod() !== ilGrmMethods::METHOD_D || !$mgmt->isDrawn() || $mgmt->isPublished()) {
            $this->redirectTo("showManagement", $mgmt);
        }
        [$assigned, $registered] = $this->runPriorityAssignment($mgmt);
        $this->tpl->setOnScreenMessage("success", sprintf($this->txt("assignment_done"), $assigned, $registered), true);
        $this->redirectTo("showManagement", $mgmt);
    }

    /**
     * Opens the registration again; the calculated assignment is discarded,
     * the wishes are kept.
     */
    private function reopenRegistration(): void
    {
        $mgmt = $this->requireManagement();
        if ($mgmt->getMethod() !== ilGrmMethods::METHOD_D || !$mgmt->isDrawn() || $mgmt->isPublished()) {
            $this->redirectTo("showManagement", $mgmt);
        }
        $this->grm_enrollment->replaceAssignments($mgmt->getId(), []);
        $mgmt->setStatus(ilGrmManagement::STATUS_OPEN);
        $this->grm_repo->saveManagement($mgmt);
        $this->tpl->setOnScreenMessage("success", $this->txt("registration_reopened"), true);
        $this->redirectTo("showManagement", $mgmt);
    }

    /**
     * Course members without a group get the free places (randomly).
     */
    private function fillFreePlaces(): void
    {
        $mgmt = $this->requireManagement();
        if ($mgmt->getMethod() !== ilGrmMethods::METHOD_D || !$mgmt->isDrawn()) {
            $this->redirectTo("showManagement", $mgmt);
        }
        $assignments = $this->grm_repo->getAssignments($mgmt->getId());
        $without_group = array_values(array_diff($this->getCourseMembers(), array_keys($assignments)));
        $filled = $this->grm_enrollment->fillFreePlaces($mgmt->getId(), $without_group);

        $message = sprintf($this->txt("free_places_filled"), count($filled));
        $left = count($without_group) - count($filled);
        if ($left > 0) {
            $message .= " " . sprintf($this->txt("still_without_place"), $left);
        }
        $this->tpl->setOnScreenMessage($filled === [] ? "info" : "success", $message, true);
        $this->redirectTo("showManagement", $mgmt);
    }

    /**
     * Runs the assignment algorithm with the wishes of the current course
     * members and the places of the groups.
     *
     * @return array{0: int, 1: int} [persons with a place, persons with wishes]
     */
    private function runPriorityAssignment(ilGrmManagement $mgmt): array
    {
        $members = array_flip($this->getCourseMembers());
        $wishes = array_intersect_key($this->grm_repo->getWishes($mgmt->getId()), $members);
        $capacities = $this->grm_repo->getGroupCapacities($mgmt->getId());

        $assignment = (new ilGrmPriorityAssigner())->assign($wishes, $capacities);
        $this->grm_enrollment->replaceAssignments($mgmt->getId(), $assignment);

        return [count($assignment), count($wishes)];
    }

    /**
     * "A > B > C" for a list of wished group ids (raw, not escaped).
     *
     * @param int[]              $wishes
     * @param array<int, string> $groups
     */
    private function formatWishes(array $wishes, array $groups): string
    {
        $titles = [];
        foreach ($wishes as $group_id) {
            $titles[] = $groups[$group_id] ?? "?";
        }
        return implode(" > ", $titles);
    }

    /**
     * Method c: creates the (empty) groups with the configured number of places.
     */
    private function createEnrollmentGroups(ilGrmManagement $mgmt): bool
    {
        if ($this->getCourseRefId() === 0) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("no_parent_course"), true);
            return false;
        }
        $titles = $mgmt->buildGroupTitles(fn(int $n): string => sprintf($this->txt("group_title_n"), $n));
        if ($titles === []) {
            $this->tpl->setOnScreenMessage("failure", $this->txt("no_groups_configured"), true);
            return false;
        }
        $this->grm_repo->createGroups($mgmt->getId(), $titles, $mgmt->getPlacesPerGroup());
        $mgmt->setStatus(ilGrmManagement::STATUS_DRAWN);
        $this->grm_repo->saveManagement($mgmt);
        return true;
    }

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

        $titles = $mgmt->buildGroupTitles(
            fn(int $n): string => sprintf($this->txt("group_title_n"), $n),
            count($members)
        );
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

    /**
     * Lecturers: course members have no permission on the object (objects
     * created before the plugin set default permissions).
     */
    private function applyDefaultPermissions(): void
    {
        $ref_id = $this->object->getRefId();
        if (!$this->access->checkAccess("edit_permission", "", $ref_id)) {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("permission_denied"), true);
            $this->ctrl->redirect($this, "showContent");
        }
        $this->grm_permissions->applyDefaults($ref_id);
        $this->tpl->setOnScreenMessage("success", $this->txt("permissions_applied"), true);
        $this->ctrl->redirect($this, "showContent");
    }

    /**
     * @return Component[] warning with button if course members cannot see the object
     */
    private function buildPermissionWarning(): array
    {
        $ref_id = $this->object->getRefId();
        if (!$this->access->checkAccess("edit_permission", "", $ref_id)
            || !$this->grm_permissions->isMemberAccessMissing($ref_id)) {
            return [];
        }
        return [
            $this->ui_factory->messageBox()->failure($this->txt("permissions_missing_info"))->withButtons([
                $this->ui_factory->button()->standard(
                    $this->txt("apply_default_permissions"),
                    $this->ctrl->getLinkTarget($this, "applyDefaultPermissions")
                ),
            ]),
        ];
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

    /**
     * Confirmation overlay (interruptive modal), same as when deleting a
     * repository object. Open it with ->withOnClick($modal->getShowSignal())
     * and render the modal together with the page content.
     */
    private function buildConfirmModal(
        ilGrmManagement $mgmt,
        string $message,
        string $cmd,
        string $action_label,
        bool $return_to_detail = false
    ): Interruptive {
        $this->ctrl->setParameter($this, self::PARAM_MGMT_ID, $mgmt->getId());
        if ($return_to_detail) {
            $this->ctrl->setParameter($this, self::PARAM_RETURN, self::RETURN_DETAIL);
        }
        $form_action = $this->ctrl->getFormAction($this, $cmd);
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_MGMT_ID);
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_RETURN);

        return $this->ui_factory->modal()->interruptive(
            $this->txt("confirm"),
            $message,
            $form_action
        )->withAffectedItems([
            $this->ui_factory->modal()->interruptiveItem()->standard(
                (string) $mgmt->getId(),
                $this->esc($mgmt->getTitle()),
                null,
                $this->txt("method_" . $mgmt->getMethod())
            ),
        ])->withActionButtonLabel($action_label);
    }

    /**
     * Toggle "activate" / "deactivate": no confirmation, the action is
     * executed immediately.
     *
     * @return array{0: string, 1: string}|null [label, url]; null if the method cannot be activated
     */
    private function getActivationToggle(ilGrmManagement $mgmt, bool $return_to_detail): ?array
    {
        if ($mgmt->isActive()) {
            $label = $this->txt("deactivate");
            $cmd = "deactivateManagement";
        } elseif (ilGrmMethods::isImplemented($mgmt->getMethod())) {
            $label = $this->txt("activate");
            $cmd = "activateManagement";
        } else {
            return null;
        }

        if ($return_to_detail) {
            $this->ctrl->setParameter($this, self::PARAM_RETURN, self::RETURN_DETAIL);
        }
        $url = $this->linkTo($cmd, $mgmt);
        $this->ctrl->clearParameterByClass(self::class, self::PARAM_RETURN);

        return [$label, $url];
    }

    private function redirectAfterAction(ilGrmManagement $mgmt): void
    {
        $return = $this->grm_query->has(self::PARAM_RETURN)
            ? $this->grm_query->retrieve(self::PARAM_RETURN, $this->grm_refinery->kindlyTo()->string())
            : "";
        if ($return === self::RETURN_DETAIL) {
            $this->redirectTo("showManagement", $mgmt);
        }
        $this->ctrl->redirect($this, "showContent");
    }

    /**
     * @return array<string, string>
     */
    private function getManagementProperties(ilGrmManagement $mgmt): array
    {
        return [
            $this->txt("method") => $this->txt("method_" . $mgmt->getMethod()),
            $this->txt("assign_mode") => $this->getModeSummary($mgmt),
            $this->txt("status") => $this->getStatusLabel($mgmt),
            $this->txt("active") => $mgmt->isActive() ? $this->lng->txt("yes") : $this->lng->txt("no"),
        ];
    }

    private function getStatusLabel(ilGrmManagement $mgmt): string
    {
        switch ($mgmt->getMethod()) {
            case ilGrmMethods::METHOD_C:
                return $this->txt("status_c_" . $mgmt->getStatus());
            case ilGrmMethods::METHOD_D:
                return $this->txt("status_d_" . $mgmt->getStatus());
            default:
                return $this->txt("status_" . $mgmt->getStatus());
        }
    }

    private function getModeSummary(ilGrmManagement $mgmt): string
    {
        if ($mgmt->getMethod() === ilGrmMethods::METHOD_D) {
            return sprintf(
                $mgmt->usesTopics() ? $this->txt("summary_d_topics") : $this->txt("summary_d_groups"),
                $mgmt->getEffectiveNumberOfGroups(),
                $mgmt->getPlacesPerGroup(),
                $mgmt->getNumPriorities()
            );
        }
        if ($mgmt->getMethod() === ilGrmMethods::METHOD_C) {
            return sprintf($this->txt("summary_c"), $mgmt->getNumGroups(), $mgmt->getPlacesPerGroup())
                . " – " . ($mgmt->hasWaitlist() ? $this->txt("waitlist_on") : $this->txt("waitlist_off"));
        }
        if ($mgmt->getMethod() === ilGrmMethods::METHOD_B) {
            return sprintf($this->txt("summary_group_size"), $mgmt->getGroupSize())
                . " – " . $this->txt("remainder_" . $mgmt->getRemainderMode() . "_short");
        }
        if ($mgmt->usesTopics()) {
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
        ?array $course_members = null,
        ?string $panel_title_text = null
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
        return [$this->ui_factory->panel()->standard($panel_title_text ?? $this->txt("groups"), $panels)];
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
        return $this->esc($this->getRawUserName($user_id));
    }

    /**
     * @return string "Lastname, Firstname" (not escaped, e.g. for select options)
     */
    private function getRawUserName(int $user_id): string
    {
        static $cache = [];
        if (!isset($cache[$user_id])) {
            $name = ilObjUser::_lookupName($user_id);
            $cache[$user_id] = ($name["login"] ?? "") === ""
                ? $this->txt("deleted_user")
                : trim(($name["lastname"] ?? "") . ", " . ($name["firstname"] ?? ""), ", ");
        }
        return $cache[$user_id];
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, "UTF-8");
    }
}
