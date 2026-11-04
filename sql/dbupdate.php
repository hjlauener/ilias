<#1>
<?php
/** @var ilDBInterface $ilDB */
if (!$ilDB->tableExists('rep_robj_xgrm_data')) {
    $ilDB->createTable('rep_robj_xgrm_data', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'is_online' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xgrm_data', ['id']);
}
?>
<#2>
<?php
/** @var ilDBInterface $ilDB */
if (!$ilDB->tableExists('rep_robj_xgrm_mgmt')) {
    $ilDB->createTable('rep_robj_xgrm_mgmt', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'method' => ['type' => 'text', 'length' => 1, 'notnull' => true],
        'title' => ['type' => 'text', 'length' => 255, 'notnull' => false],
        'is_active' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0],
        'mgmt_status' => ['type' => 'text', 'length' => 16, 'notnull' => true, 'default' => 'draft'],
        'assign_mode' => ['type' => 'text', 'length' => 16, 'notnull' => true, 'default' => 'groups'],
        'num_groups' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 2],
        'topics' => ['type' => 'clob', 'notnull' => false],
        'show_all' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 1],
        'created' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'published' => ['type' => 'integer', 'length' => 4, 'notnull' => false],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xgrm_mgmt', ['id']);
    $ilDB->createSequence('rep_robj_xgrm_mgmt');
    $ilDB->addIndex('rep_robj_xgrm_mgmt', ['obj_id'], 'i1');
}
?>
<#3>
<?php
/** @var ilDBInterface $ilDB */
if (!$ilDB->tableExists('rep_robj_xgrm_group')) {
    $ilDB->createTable('rep_robj_xgrm_group', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'mgmt_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'title' => ['type' => 'text', 'length' => 255, 'notnull' => false],
        'position' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xgrm_group', ['id']);
    $ilDB->createSequence('rep_robj_xgrm_group');
    $ilDB->addIndex('rep_robj_xgrm_group', ['mgmt_id'], 'i1');
}
?>
<#4>
<?php
/** @var ilDBInterface $ilDB */
if (!$ilDB->tableExists('rep_robj_xgrm_assign')) {
    $ilDB->createTable('rep_robj_xgrm_assign', [
        'mgmt_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'user_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'group_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xgrm_assign', ['mgmt_id', 'user_id']);
    $ilDB->addIndex('rep_robj_xgrm_assign', ['group_id'], 'i1');
}
?>
<#5>
<?php
/** @var ilDBInterface $ilDB */
// method b: fixed group size
if (!$ilDB->tableColumnExists('rep_robj_xgrm_mgmt', 'group_size')) {
    $ilDB->addTableColumn('rep_robj_xgrm_mgmt', 'group_size', [
        'type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 3,
    ]);
}
if (!$ilDB->tableColumnExists('rep_robj_xgrm_mgmt', 'remainder_mode')) {
    $ilDB->addTableColumn('rep_robj_xgrm_mgmt', 'remainder_mode', [
        'type' => 'text', 'length' => 16, 'notnull' => true, 'default' => 'nearest',
    ]);
}
?>
