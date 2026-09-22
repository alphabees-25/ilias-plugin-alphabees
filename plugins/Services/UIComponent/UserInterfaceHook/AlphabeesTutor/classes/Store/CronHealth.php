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

    /** Juengster Lauf laut `cron_job` — ohne unseren gemerkten Wert. */
    private function lastRunFromCronTable(): ?int
    {
        $last = null;
        $res = $this->db->query(
            'SELECT job_result_ts FROM cron_job WHERE '
            . $this->db->like('job_id', 'text', self::JOB_PREFIX . '%')
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $ts = $row['job_result_ts'] === null ? null : (int) $row['job_result_ts'];
            if ($ts !== null && ($last === null || $ts > $last)) {
                $last = $ts;
            }
        }

        return $last;
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
        //
        // `query()`, nicht `queryF()`: `like()` liefert eine fertige
        // Bedingung ohne Platzhalter, und queryF verlangt dann ein Argument,
        // das es nicht gibt — "The arguments array must contain 1 items,
        // 0 given".
        $res = $this->db->query(
            'SELECT job_id, job_result_ts FROM cron_job WHERE '
            . $this->db->like('job_id', 'text', self::JOB_PREFIX . '%')
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $registered++;
            $ts = $row['job_result_ts'] === null ? null : (int) $row['job_result_ts'];
            if ($ts !== null && ($last === null || $ts > $last)) {
                $last = $ts;
            }
        }

        // Was `markDue()` aus `cron_job` entfernt hat, steht hier weiter.
        // Ohne diesen Rueckgriff meldete die Konfigurationsseite direkt nach
        // dem Koppeln „der Cron ist nie gelaufen" — obwohl er laeuft und nur
        // die Faelligkeitsmarke geleert wurde.
        $remembered = (int) ((new Config($this->db))->get(Config::LAST_CRON_RUN, '0') ?? '0');
        if ($remembered > 0 && ($last === null || $remembered > $last)) {
            $last = $remembered;
        }

        return [
            'registered' => $registered,
            'ever_ran' => $last !== null,
            'last_run' => $last,
            'stale' => $last === null || $last < time() - self::STALE_AFTER_SECONDS,
        ];
    }

    /**
     * Unsere Jobs sofort wieder faellig stellen.
     *
     * Gebraucht direkt nach dem Koppeln. Der Strukturlauf geht sonst alle
     * sechs Stunden — wer gerade den Code eingefuegt hat, faende im Portal
     * einen halben Tag lang keinen einzigen Kurs, dem er einen Agenten
     * zuordnen koennte. Genau der Eindruck, den das Plugin vermeiden soll.
     *
     * ILIAS entscheidet ueber `job_result_ts`, wann ein Job wieder an der
     * Reihe ist (`JobManagerImpl::runJob` -> `CronJob::isDue`); NULL heisst
     * „noch nie gelaufen" und damit faellig. Der naechste Cron-Anstoss
     * nimmt sie dann alle mit — ohne dass hier im Web-Request etwas laeuft,
     * das Minuten dauern kann.
     *
     * @return int Zeilen, die dadurch tatsaechlich umgesetzt wurden —
     *             0 heisst: sie waren ohnehin schon faellig
     */
    public function markDue(): int
    {
        // Erst merken, dann leeren: der Zeitstempel ist der einzige Beleg
        // dafuer, dass der Cron ueberhaupt laeuft.
        $seen = $this->lastRunFromCronTable();
        if ($seen !== null) {
            (new Config($this->db))->set(Config::LAST_CRON_RUN, (string) $seen);
        }

        // `manipulate()` liefert die betroffenen Zeilen selbst zurueck —
        // ein `affectedRows()` gibt es an ilDBInterface nicht.
        return $this->db->manipulate(
            'UPDATE cron_job SET job_result_ts = NULL WHERE '
            . $this->db->like('job_id', 'text', self::JOB_PREFIX . '%')
        );
    }
}
