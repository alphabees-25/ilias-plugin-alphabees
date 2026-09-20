<#1>
<?php
/**
 * Database steps for the AlphaLearn Tutor plugin.
 *
 * ILIAS reads this file by splitting on the `<#N>` markers, which must start
 * a line and sit OUTSIDE php tags (ilDBUpdate::…, `preg_match("/^<#…/")`).
 * The highest applied number is remembered, so: never renumber, never edit a
 * block that has shipped, always append.
 *
 * Table names carry the `ui_uihk_` prefix the UIComponent slot mandates,
 * shortened to stay inside the 30-character limit ILIAS keeps for its
 * supported databases.
 *
 * @var ilDBInterface $ilDB
 */
if (!$ilDB->tableExists('ui_uihk_alphabees_cfg')) {
    $ilDB->createTable('ui_uihk_alphabees_cfg', [
        'cfg_key' => ['type' => 'text', 'length' => 64, 'notnull' => true],
        'cfg_value' => ['type' => 'clob', 'notnull' => false],
    ]);
    $ilDB->addPrimaryKey('ui_uihk_alphabees_cfg', ['cfg_key']);
}
?>
<#2>
<?php
/**
 * Placements — the only table a page render reads.
 *
 * `ref_id` of the course or group is the primary key: one agent per
 * container, and the lookup on every page is a primary-key hit.
 */
if (!$ilDB->tableExists('ui_uihk_alphabees_plc')) {
    $ilDB->createTable('ui_uihk_alphabees_plc', [
        'ref_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'bot_id' => ['type' => 'text', 'length' => 64, 'notnull' => true],
        'bot_name' => ['type' => 'text', 'length' => 255, 'notnull' => false],
        'course_id' => ['type' => 'text', 'length' => 64, 'notnull' => false],
        'primary_color' => ['type' => 'text', 'length' => 16, 'notnull' => false],
        'updated_at' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('ui_uihk_alphabees_plc', ['ref_id']);
}
?>
<#3>
<?php
/**
 * Outbound batches that have not been accepted yet. The drain job asks
 * exactly one question — what is due now — hence the index.
 */
if (!$ilDB->tableExists('ui_uihk_alphabees_que')) {
    $ilDB->createTable('ui_uihk_alphabees_que', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'endpoint' => ['type' => 'text', 'length' => 255, 'notnull' => true],
        'payload' => ['type' => 'clob', 'notnull' => false],
        'tries' => ['type' => 'integer', 'length' => 2, 'notnull' => true, 'default' => 0],
        'next_try_at' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'last_error' => ['type' => 'text', 'length' => 255, 'notnull' => false],
        'created_at' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('ui_uihk_alphabees_que', ['id']);
    $ilDB->createSequence('ui_uihk_alphabees_que');
    $ilDB->addIndex('ui_uihk_alphabees_que', ['next_try_at'], 'i1');
}
?>
<#4>
<?php
/**
 * Where a paged background run left off. A structure push of a large
 * installation does not fit in one cron slot, and a job that runs past its
 * limit is killed without notice — the cursor lets the next run continue
 * instead of starting over, and carries the batch id so the backend sees the
 * pages as one snapshot.
 *
 * The column is `position`, not `offset`: the latter is a reserved word in
 * MySQL 8.
 */
if (!$ilDB->tableExists('ui_uihk_alphabees_cur')) {
    $ilDB->createTable('ui_uihk_alphabees_cur', [
        'cur_name' => ['type' => 'text', 'length' => 64, 'notnull' => true],
        'batch_id' => ['type' => 'text', 'length' => 64, 'notnull' => true],
        'page' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 1],
        'pages' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 1],
        'position' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'started_at' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('ui_uihk_alphabees_cur', ['cur_name']);
}
?>
