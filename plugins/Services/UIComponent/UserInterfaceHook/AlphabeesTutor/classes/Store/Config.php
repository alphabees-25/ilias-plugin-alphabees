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
