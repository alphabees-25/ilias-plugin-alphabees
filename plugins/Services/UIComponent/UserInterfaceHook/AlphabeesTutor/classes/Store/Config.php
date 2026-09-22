<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Store;

use ilDBInterface;

/**
 * Key/value settings of the pairing, in the plugin's own table.
 *
 * Not `ilSetting`: the private key lives here, and a dedicated table keeps it
 * out of the global settings screen where any administrator browsing settings
 * would see it. The table is also what the cron plugin reads — it has no
 * access to this plugin's objects, only to the database.
 */
final class Config
{
    public const TABLE = 'ui_uihk_alphabees_cfg';

    // Pairing
    public const BACKEND_URL = 'backend_url';
    public const REGISTRATION_ID = 'registration_id';
    public const SITE_IDENTIFIER = 'site_identifier';
    public const KEY_ID = 'key_id';
    public const PRIVATE_KEY = 'private_key';
    public const PUBLIC_KEY = 'public_key';
    public const BACKEND_PUBLIC_KEY = 'backend_public_key';
    public const PAIRED_AT = 'paired_at';

    // Widget
    public const API_KEY = 'api_key';
    public const LOADER_URL = 'loader_url';

    // Verbindungszustand, wie ihn das Backend im Lebenszeichen meldet:
    // 'active' | 'paused' | 'disconnected'. Ohne ihn liefe ein pausiertes
    // Plugin munter weiter und bekaeme auf jeden Aufruf eine nackte 401 —
    // von aussen nicht von einem kaputten Schluessel zu unterscheiden.
    public const STATE = 'connection_state';
    public const STATE_REASON = 'connection_reason';

    // Wann unsere Cron-Jobs zuletzt tatsaechlich liefen.
    //
    // Gebraucht, weil `markDue()` `cron_job.job_result_ts` auf NULL setzt, um
    // die Jobs sofort faellig zu stellen — und genau daran erkennt ILIAS wie
    // auch `CronHealth`, ob je ein Lauf stattfand. Ohne diese Kopie behauptet
    // das Plugin unmittelbar nach dem Koppeln, der Cron sei nie gelaufen, und
    // zeigt dem Kunden eine Warnung im Moment seines Erfolgs.
    public const LAST_CRON_RUN = 'last_cron_run';

    // Health
    public const LAST_ERROR = 'last_error';
    public const LAST_ERROR_AT = 'last_error_at';
    public const LAST_SUCCESS_AT = 'last_success_at';

    private ilDBInterface $db;

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->load();

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->db->manipulateF(
                'DELETE FROM ' . self::TABLE . ' WHERE cfg_key = %s',
                ['text'],
                [$key]
            );
        } else {
            $this->db->replace(
                self::TABLE,
                ['cfg_key' => ['text', $key]],
                ['cfg_value' => ['clob', $value]]
            );
        }
        $this->cache = null;
    }

    public function isPaired(): bool
    {
        return $this->get(self::REGISTRATION_ID) !== null
            && $this->get(self::PRIVATE_KEY) !== null
            && $this->get(self::BACKEND_URL) !== null;
    }

    /**
     * Gekoppelt UND nicht pausiert.
     *
     * Massgeblich fuer alles, was nach aussen wirkt: das Widget rendern, Daten
     * schicken. Wer pausiert, will dass der Tutor verschwindet — nicht, dass
     * er weiterlaeuft und still Fehler sammelt.
     */
    public function isActive(): bool
    {
        return $this->isPaired() && $this->state() === 'active';
    }

    /** 'active' | 'paused' | 'disconnected'. Unbekannt gilt als aktiv:
     *  eine frische Kopplung hat noch kein Lebenszeichen gesehen. */
    public function state(): string
    {
        $value = (string) $this->get(self::STATE, 'active');

        return $value !== '' ? $value : 'active';
    }

    /**
     * Uebernimmt, was das Backend ueber die Verbindung meldet.
     *
     * `disconnected` raeumt die Kopplung hier gleich mit ab: die Gegenseite
     * hat die Zugangsdaten geloescht, unsere sind damit wertlos. Die
     * Zuordnungen bleiben — genau wie drueben —, damit ein erneutes
     * Verbinden alles wiederherstellt.
     */
    public function applyState(?string $state, ?string $reason = null): void
    {
        $state = (string) ($state ?: '');
        if ($state === '') {
            return;
        }
        $previous = $this->state();
        $this->set(self::STATE, $state);
        $this->set(self::STATE_REASON, $reason ?: null);

        // Zurueck aus der Pause: die eigenen Jobs sofort wieder faellig
        // stellen. Erfahren wird das Fortsetzen nur ueber das Lebenszeichen,
        // und das laeuft als LETZTER der vier Jobs — der Zuordnungs-Abruf
        // desselben Laufs war also noch geblockt und wartet danach auf seinen
        // eigenen Viertelstundentakt. Der Tutor bliebe nach dem Fortsetzen
        // bis zu zwanzig Minuten weg, obwohl im Portal alles gruen steht.
        if ($state === 'active' && $previous !== 'active') {
            (new CronHealth($this->db))->markDue();
        }

        if ($state === 'disconnected') {
            // Reihenfolge zaehlt: clearPairing() raeumt die Zugangsdaten,
            // danach den Zustand erneut setzen — sonst stuende er wieder auf
            // dem Vorgabewert 'active' und das Widget kaeme zurueck.
            $this->clearPairing();
            $this->set(self::STATE, 'disconnected');
            $this->set(self::STATE_REASON, $reason ?: null);
        }
    }

    /**
     * Everything the pairing needs, cleared in one go.
     *
     * The placements survive on purpose — the same rule the backend follows
     * when a connection is disconnected. Pairing again brings the site back
     * without losing which agent sat where.
     */
    public function clearPairing(): void
    {
        foreach ([
            self::REGISTRATION_ID,
            self::PRIVATE_KEY,
            self::PUBLIC_KEY,
            self::BACKEND_PUBLIC_KEY,
            self::PAIRED_AT,
            self::API_KEY,
        ] as $key) {
            $this->set($key, null);
        }
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        $res = $this->db->query('SELECT cfg_key, cfg_value FROM ' . self::TABLE);
        while ($row = $this->db->fetchAssoc($res)) {
            $this->cache[(string) $row['cfg_key']] = (string) $row['cfg_value'];
        }
    }
}
