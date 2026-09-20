<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Store;

use ilDBInterface;

/**
 * Where a paged background run left off.
 *
 * A structure push of a large installation does not fit in one cron slot, and
 * a cron job that runs past its limit is killed without notice. The cursor
 * lets the next run continue instead of starting over — and it carries the
 * `batch_id`, so the backend recognises the pages as one snapshot and only
 * hides what the whole run never mentioned.
 */
final class Cursor
{
    public const TABLE = 'ui_uihk_alphabees_cur';

    private ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{batch_id:string,page:int,pages:int,offset:int,started_at:int}|null
     */
    public function get(string $name): ?array
    {
        $res = $this->db->queryF(
            'SELECT batch_id, page, pages, position, started_at FROM ' . self::TABLE . ' WHERE cur_name = %s',
            ['text'],
            [$name]
        );
        $row = $this->db->fetchAssoc($res);
        if (!$row) {
            return null;
        }

        return [
            'batch_id' => (string) $row['batch_id'],
            'page' => (int) $row['page'],
            'pages' => (int) $row['pages'],
            'offset' => (int) $row['position'],
            'started_at' => (int) $row['started_at'],
        ];
    }

    public function save(string $name, string $batchId, int $page, int $pages, int $offset, int $startedAt): void
    {
        $this->db->replace(
            self::TABLE,
            ['cur_name' => ['text', $name]],
            [
                'batch_id' => ['text', $batchId],
                'page' => ['integer', $page],
                'pages' => ['integer', $pages],
                'position' => ['integer', $offset],
                'started_at' => ['integer', $startedAt],
            ]
        );
    }

    public function clear(string $name): void
    {
        $this->db->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE cur_name = %s',
            ['text'],
            [$name]
        );
    }
}
