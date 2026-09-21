<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Store;

use ilDBInterface;

/**
 * Läuft der ILIAS-Cron überhaupt?
 *
 * WARUM DAS EINE EIGENE KLASSE IST
 *
 * ILIAS startet seine Cron-Jobs nicht selbst — es wartet darauf, dass von
 * aussen jemand `cli/cron.php` aufruft. Fehlt dieser Anstoss, stehen unsere
 * Jobs auf „aktiv" und laufen trotzdem nie. Nach aussen sieht das aus wie ein
 * kaputtes Plugin: der Administrator ordnet im Portal einen Agenten zu, und in
 * ILIAS passiert nichts. Genau so ist es auf unserer eigenen Testinstanz
 * gewesen, und es hat einen halben Tag gekostet, bis jemand auf die Idee kam,
 * den Cron zu prüfen.
 *
 * Deshalb sagt das Plugin es selbst, an der einzigen Stelle, an der ein
 * ILIAS-Administrator hinschaut: auf seiner Konfigurationsseite.
 *
 * Die meisten Produktivinstallationen haben den Cron laengst — ILIAS braucht
 * ihn auch fuer Benachrichtigungen und Lernfortschritt. Betroffen ist vor
 * allem, wer eine frische Instanz aufgesetzt hat.
 */
final class CronHealth
{
    /** Unsere Jobs, so wie sie in `cron_job` stehen. */
    private const JOB_PREFIX = 'alphabees_';

    /**
     * Ab wann ein Lauf als ausgeblieben gilt. Der haeufigste unserer Jobs
     * laeuft alle fuenf Minuten; zwei Stunden lassen einem Wartungsfenster
     * Luft, ohne einen wirklich toten Cron tagelang zu verschweigen.
     */
    public const STALE_AFTER_SECONDS = 2 * 3600;

    private ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{registered:int,ever_ran:bool,last_run:?int,stale:bool}
     */
    public function state(): array
    {
        $registered = 0;
        $last = null;

        // `cron_job` gehoert ILIAS. Rohes SQL, weil es dafuer kein Modell von
        // uns gibt und die Spalten seit Jahren stabil sind.
        $res = $this->db->queryF(
            'SELECT job_id, job_result_ts FROM cron_job WHERE ' . $this->db->like('job_id', 'text', self::JOB_PREFIX . '%'),
            [],
            []
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $registered++;
            $ts = $row['job_result_ts'] === null ? null : (int) $row['job_result_ts'];
            if ($ts !== null && ($last === null || $ts > $last)) {
                $last = $ts;
            }
        }

        return [
            'registered' => $registered,
            'ever_ran' => $last !== null,
            'last_run' => $last,
            'stale' => $last === null || $last < time() - self::STALE_AFTER_SECONDS,
        ];
    }
}
