<?php

declare(strict_types=1);

/**
 * AlphaLearn Tutor — plugin entry point.
 *
 * Deliberately thin. Everything that is not ILIAS plumbing lives in the
 * namespaced classes under `classes/`, so that the day `uihk` is removed (it
 * is already marked deprecated in 11) only this file and the hook GUI have to
 * be rewritten.
 *
 * Class discovery needs no autoloader of ours: ILIAS' composer.json puts
 * `./public/Customizing/global/plugins` on the classmap. After copying the
 * plugin in, run `composer dump-autoload -o` in the ILIAS root — that is what
 * makes both `il*` and `Alphabees\Tutor\*` classes loadable.
 */
class ilAlphabeesTutorPlugin extends ilUserInterfaceHookPlugin
{
    public const PLUGIN_ID = 'uihkalphabees';
    public const PLUGIN_NAME = 'AlphabeesTutor';

    /**
     * Our own capability/version code. Monotonic by date (YYYYMMDDNN) and the
     * ONLY thing the backend may sort on: a build labelled "3.2.0" can be
     * older than one labelled "3.1.1" if it was cut first. The release string
     * is for humans.
     */
    public const VERSION_CODE = 2026092102;

    /**
     * What this build can do. The backend stops asking over SOAP for anything
     * named here — per capability, not per connection, so an installation can
     * push objects and members and still have test results fetched over SOAP.
     *
     * @return list<string>
     */
    public static function capabilities(): array
    {
        return ['objects', 'members', 'progress', 'placements', 'content'];
    }

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * Uninstalling drops our tables. ILIAS does not do that for us — it only
     * removes the language entries and its own state.
     */
    protected function afterUninstall(): void
    {
        global $DIC;

        foreach ([
            \Alphabees\Tutor\Store\Config::TABLE,
            \Alphabees\Tutor\Store\Placements::TABLE,
            \Alphabees\Tutor\Store\Queue::TABLE,
            \Alphabees\Tutor\Store\Cursor::TABLE,
        ] as $table) {
            if ($DIC->database()->tableExists($table)) {
                $DIC->database()->dropTable($table);
            }
        }
    }
}
