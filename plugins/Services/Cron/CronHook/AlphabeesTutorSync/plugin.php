<?php

declare(strict_types=1);

/**
 * AlphaLearn Tutor Sync — CronHook plugin.
 *
 * The background half of the AlphaLearn integration. It exists as a second
 * plugin only because ILIAS allows one slot per plugin: `uihk` renders,
 * `crnhk` runs jobs. Both ship from one repository and move in lockstep —
 * install one without the other and neither does anything useful.
 *
 * Keep $version in step with the AlphabeesTutor plugin. The version code the
 * backend sorts capabilities on lives in ilAlphabeesTutorPlugin.
 */

$id = 'crnhkalphabees';

$version = '1.2.1';

$ilias_min_version = '11.0';
$ilias_max_version = '11.999';

$responsible = 'Alphabees GbR';
$responsible_mail = 'support@alphabees.de';

$supports_cli_setup = true;
