<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Context;

use ilTree;

/**
 * Which course or group the page the learner is looking at belongs to.
 *
 * A placement sits on a course (`crs`) or a group (`grp`), but a learner
 * spends most of their time on things inside it — a file, a learning module,
 * a test, a forum posting. Walking up the repository tree is what turns
 * "ref_id 412, a PDF" into "this is course 92, and course 92 has an agent".
 *
 * The walk is cached in the session for the lifetime of that page's container:
 * `ilTree::getPathFull()` is a query per level, and a learner clicking through
 * a course hits the same answer dozens of times.
 */
final class CourseResolver
{
    private const SESSION_KEY = 'alphabees_container';
    private const CONTAINER_TYPES = ['crs', 'grp'];

    private ilTree $tree;

    public function __construct(ilTree $tree)
    {
        $this->tree = $tree;
    }

    /**
     * ref_id of the nearest course/group above (or at) $refId, or null.
     */
    public function containerRefId(int $refId): ?int
    {
        if ($refId <= 0) {
            return null;
        }

        $cached = $_SESSION[self::SESSION_KEY][$refId] ?? null;
        if ($cached !== null) {
            // A miss is cached as 0. Re-walking the tree on every page of a
            // course that has no placement would be the common case, not the
            // exception.
            return $cached === 0 ? null : (int) $cached;
        }

        $found = $this->walk($refId);
        $_SESSION[self::SESSION_KEY][$refId] = $found ?? 0;

        return $found;
    }

    private function walk(int $refId): ?int
    {
        if (!$this->tree->isInTree($refId)) {
            return null;
        }

        $type = $this->tree->getNodeData($refId)['type'] ?? '';
        if (in_array($type, self::CONTAINER_TYPES, true)) {
            return $refId;
        }

        // getPathFull() returns root first, so walk it backwards: the nearest
        // container wins, not the outermost. A group inside a course must
        // resolve to the group.
        $path = $this->tree->getPathFull($refId);
        for ($i = count($path) - 1; $i >= 0; $i--) {
            if (in_array($path[$i]['type'] ?? '', self::CONTAINER_TYPES, true)) {
                return (int) $path[$i]['child'];
            }
        }

        return null;
    }
}
