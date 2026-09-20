<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Store;

use ilDBInterface;

/**
 * Which agent hangs on which ref_id — the only thing a page render reads.
 *
 * Filled exclusively by the cron plugin. A page must never wait on our
 * reachability, so an empty or stale table means "render nothing", never
 * "ask the backend now".
 */
final class Placements
{
    public const TABLE = 'ui_uihk_alphabees_plc';

    /**
     * How long a pulled list stays usable. The puller runs every 15 minutes;
     * three hours of tolerance covers a backend outage or a paused cron
     * without ripping the widget off every course page in the meantime.
     */
    public const MAX_AGE_SECONDS = 3 * 3600;

    private ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function forRefId(int $refId): ?array
    {
        $res = $this->db->queryF(
            'SELECT ref_id, bot_id, bot_name, course_id, primary_color, updated_at'
            . ' FROM ' . self::TABLE . ' WHERE ref_id = %s',
            ['integer'],
            [$refId]
        );
        $row = $this->db->fetchAssoc($res);
        if (!$row) {
            return null;
        }
        if ((int) $row['updated_at'] < time() - self::MAX_AGE_SECONDS) {
            return null;
        }

        return $row;
    }

    /**
     * Replace the whole list.
     *
     * A full replace rather than an upsert plus cleanup: the backend answers
     * with the complete set every time, and a placement that was removed in
     * the portal must stop rendering on the next pull, not linger.
     *
     * @param list<array<string,mixed>> $items
     */
    public function replaceAll(array $items): int
    {
        $now = time();
        $this->db->manipulate('DELETE FROM ' . self::TABLE);
        $written = 0;
        foreach ($items as $item) {
            $refId = (int) ($item['ref_id'] ?? 0);
            $botId = (string) ($item['bot_id'] ?? '');
            if ($refId <= 0 || $botId === '') {
                continue;
            }
            $this->db->insert(self::TABLE, [
                'ref_id' => ['integer', $refId],
                'bot_id' => ['text', $botId],
                'bot_name' => ['text', (string) ($item['bot_name'] ?? '')],
                'course_id' => ['text', (string) ($item['course_id'] ?? '')],
                'primary_color' => ['text', (string) ($item['primary_color'] ?? '')],
                'updated_at' => ['integer', $now],
            ]);
            $written++;
        }

        return $written;
    }

    public function count(): int
    {
        $res = $this->db->query('SELECT COUNT(*) AS c FROM ' . self::TABLE);
        $row = $this->db->fetchAssoc($res);

        return (int) ($row['c'] ?? 0);
    }

    public function newestUpdate(): ?int
    {
        $res = $this->db->query('SELECT MAX(updated_at) AS m FROM ' . self::TABLE);
        $row = $this->db->fetchAssoc($res);
        $value = $row['m'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * The ref_ids that carry a placement — the scope of every background run.
     *
     * The cron runs without a logged-in user and therefore with system
     * privileges. Limiting it to placed courses is what keeps that from
     * meaning "read the entire repository", and it mirrors the backend's
     * `ilias_auto_sync_mode = 'placements'`.
     *
     * @return list<int>
     */
    public function refIds(): array
    {
        $out = [];
        $res = $this->db->query('SELECT ref_id FROM ' . self::TABLE . ' ORDER BY ref_id');
        while ($row = $this->db->fetchAssoc($res)) {
            $out[] = (int) $row['ref_id'];
        }

        return $out;
    }
}
