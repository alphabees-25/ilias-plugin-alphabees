<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Store;

use ilDBInterface;

/**
 * Outbound batches that have not been accepted yet.
 *
 * A push that fails must not be lost and must not be retried in a tight loop.
 * Every failure pushes `next_try_at` further out (1, 2, 4, 8 … minutes, capped
 * at an hour) and raises `tries`; a batch that never gets through is visible
 * in the plugin configuration rather than silently gone.
 */
final class Queue
{
    public const TABLE = 'ui_uihk_alphabees_que';

    /** Give up after this many attempts — roughly a day of backing off. */
    public const MAX_TRIES = 12;

    private ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    public function push(string $endpoint, array $payload): void
    {
        $this->db->insert(self::TABLE, [
            'id' => ['integer', $this->db->nextId(self::TABLE)],
            'endpoint' => ['text', $endpoint],
            'payload' => ['clob', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            'tries' => ['integer', 0],
            'next_try_at' => ['integer', time()],
            'last_error' => ['text', ''],
            'created_at' => ['integer', time()],
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function due(int $limit): array
    {
        $out = [];
        $res = $this->db->queryF(
            'SELECT id, endpoint, payload, tries FROM ' . self::TABLE
            . ' WHERE next_try_at <= %s AND tries < %s ORDER BY id LIMIT ' . $limit,
            ['integer', 'integer'],
            [time(), self::MAX_TRIES]
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $out[] = $row;
        }

        return $out;
    }

    public function done(int $id): void
    {
        $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE id = %s',
            ['integer'],
            [$id]
        );
    }

    public function failed(int $id, int $tries, string $error): void
    {
        $delay = min(3600, 60 * (2 ** min($tries, 6)));
        $this->db->manipulateF(
            'UPDATE ' . self::TABLE . ' SET tries = %s, next_try_at = %s, last_error = %s WHERE id = %s',
            ['integer', 'integer', 'text', 'integer'],
            [$tries + 1, time() + $delay, mb_substr($error, 0, 250), $id]
        );
    }

    public function size(): int
    {
        $res = $this->db->query('SELECT COUNT(*) AS c FROM ' . self::TABLE);
        $row = $this->db->fetchAssoc($res);

        return (int) ($row['c'] ?? 0);
    }

    public function stuck(): int
    {
        $res = $this->db->queryF(
            'SELECT COUNT(*) AS c FROM ' . self::TABLE . ' WHERE tries >= %s',
            ['integer'],
            [self::MAX_TRIES]
        );
        $row = $this->db->fetchAssoc($res);

        return (int) ($row['c'] ?? 0);
    }
}
