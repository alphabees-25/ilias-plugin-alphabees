<?php

declare(strict_types=1);

/**
 * AlphaLearn Tutor — UserInterfaceHook plugin.
 *
 * Renders the AlphaLearn chat widget on ILIAS pages that belong to a course
 * with a placement. Everything it needs at render time comes from its own
 * tables; the backend is only ever contacted by the companion cron plugin.
 *
 * $ilias_min_version / $ilias_max_version are a hard binding in ILIAS 11 —
 * ilPluginInfo::isCompliantToILIAS() has no override, so a branch per ILIAS
 * major version is the only way. See README.
 */

$id = 'uihkalphabees';

$version = '1.3.3';

$ilias_min_version = '11.0';
$ilias_max_version = '11.999';

$responsible = 'Alphabees UG (haftungsbeschränkt)';
$responsible_mail = 'support@alphabees.de';

// Without this the plugin cannot be installed or updated from cli/setup.php,
// which is the only way on a containerised ILIAS.
$supports_cli_setup = true;
