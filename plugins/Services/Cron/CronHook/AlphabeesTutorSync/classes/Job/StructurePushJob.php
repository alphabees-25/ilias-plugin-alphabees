<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Sync\Job;

use Alphabees\Tutor\Store\Cursor;
use Alphabees\Tutor\Store\Placements;
use Alphabees\Tutor\Store\Queue;
use ILIAS\Cron\Job\JobResult;
use ILIAS\Cron\Job\Schedule\JobScheduleType;
use ilLPStatus;
use ilObject;
use ilObjUser;
use ilParticipants;
use Throwable;

/**
 * Sends repository structure, memberships and learning progress.
 *
 * SCOPE, in two layers.
 *
 * The courses and groups themselves always travel, all of them, without their
 * contents. The portal needs that list to offer a course to place an agent on
 * — and if only placed courses were sent, a fresh installation would send
 * nothing, the list would stay empty, and no first placement could ever be
 * made from it. Shallow, so it stays cheap: one row per course, no files, no
 * lessons.
 *
 * Everything BELOW a container travels only for placed ones. That is where
 * the volume is, and it mirrors the backend's
 * `ilias_auto_sync_mode = 'placements'`: a university with eight thousand
 * courses ships the contents of the three that have an agent.
 *
 * The job runs without a logged-in user and therefore with system privileges,
 * which is exactly why the scope has to be drawn here rather than left to
 * permissions.
 *
 * PAGING. A large installation does not fit in one cron slot, and a job that
 * runs past its limit is killed without a word. Every run does one page,
 * remembers where it stopped, and the next run continues. All pages carry the
 * same `batch_id`, so the backend treats them as one snapshot and only hides
 * what the whole run never mentioned.
 *
 * ROLES stay raw. We send the ILIAS role titles (`il_crs_member_92`) and let
 * the backend translate them. Translating here would mean the mapping exists
 * twice — and the second copy is the one that goes wrong.
 */
final class StructurePushJob extends BaseJob
{
    private const CURSOR = 'structure_push';
    private const PAGE_SIZE = 500;

    /** Was eine Platzierung tragen kann — und damit der Katalog. */
    private const CONTAINER_TYPES = ['crs', 'grp'];

    public function getId(): string
    {
        return 'alphabees_structure_push';
    }

    public function getTitle(): string
    {
        return $this->plugin->txt('job_push_title');
    }

    public function getDescription(): string
    {
        return $this->plugin->txt('job_push_desc');
    }

    public function getDefaultScheduleType(): JobScheduleType
    {
        return JobScheduleType::IN_HOURS;
    }

    public function getDefaultScheduleValue(): ?int
    {
        return 6;
    }

    public function run(): JobResult
    {
        if (!$this->config()->isPaired()) {
            return $this->nothing('Not paired with AlphaLearn.');
        }

        $db = $this->db();
        $placements = new Placements($db);
        $refIds = $placements->refIds();

        // Kein `return` bei leerer Liste: ohne Platzierung ist der Katalog das
        // Einzige, was zu senden ist — und ohne ihn kaeme nie eine zustande.
        $objects = $this->collectObjects($refIds);
        if ($objects === []) {
            return $this->nothing('No course or group in this ILIAS.');
        }

        $cursor = new Cursor($db);
        $state = $cursor->get(self::CURSOR);
        $pages = (int) ceil(count($objects) / self::PAGE_SIZE);

        if ($state === null || $state['offset'] >= count($objects)) {
            $state = [
                'batch_id' => bin2hex(random_bytes(8)),
                'page' => 1,
                'pages' => $pages,
                'offset' => 0,
                'started_at' => time(),
            ];
        }

        $slice = array_slice($objects, $state['offset'], self::PAGE_SIZE);
        $isLast = $state['offset'] + count($slice) >= count($objects);

        $payload = array_merge($this->envelopeBase(), [
            'batch_id' => $state['batch_id'],
            'page' => $state['page'],
            'pages' => max($pages, $state['page']),
            'full_snapshot' => true,
            'items' => $slice,
        ]);

        $client = $this->client();
        $queue = new Queue($db);
        $path = $client->sitePath('objects');

        try {
            $answer = $client->post($path, $payload);
        } catch (Throwable $e) {
            // Keep the page rather than losing it, and let the drain job
            // retry with a growing delay. The cursor is NOT advanced, so a
            // recovered backend continues where this run stopped.
            $queue->push($path, $payload);

            return $this->failed($e);
        }

        if ($isLast) {
            $cursor->clear(self::CURSOR);
            $members = $refIds === []
                ? 'no placement yet, so no participants'
                : $this->pushMembers($refIds, $state['batch_id']);

            return $this->ok(sprintf(
                '%d object(s) in %d page(s) sent, %s.',
                count($objects),
                max($pages, $state['page']),
                $members
            ));
        }

        $cursor->save(
            self::CURSOR,
            $state['batch_id'],
            $state['page'] + 1,
            max($pages, $state['page'] + 1),
            $state['offset'] + count($slice),
            $state['started_at']
        );

        return $this->ok(sprintf(
            'Page %d of %d sent (%d accepted, %d rejected). The next run continues.',
            $state['page'],
            max($pages, $state['page']),
            (int) ($answer['accepted'] ?? 0),
            count((array) ($answer['rejected'] ?? []))
        ));
    }

    /**
     * Der Katalog aller Kurse und Gruppen, dazu die Tiefe der platzierten.
     *
     * Categories and folders travel with the placed subtrees: they carry the
     * path an administrator uses to tell two identically named courses apart,
     * and the course picker needs them to navigate.
     *
     * @param list<int> $placedRefIds
     * @return list<array<string,mixed>>
     */
    private function collectObjects(array $placedRefIds): array
    {
        global $DIC;

        $tree = $DIC->repositoryTree();
        $seen = [];
        $out = [];

        // Schicht 1: jeder Kurs und jede Gruppe, ohne Inhalt. Der Typfilter
        // von getSubTree macht daraus eine Abfrage statt eines Baumlaufs.
        $root = $tree->getNodeData($tree->getRootId());
        foreach ($tree->getSubTree($root, true, self::CONTAINER_TYPES) as $node) {
            $child = (int) ($node['child'] ?? 0);
            if ($child <= 0 || isset($seen[$child])) {
                continue;
            }
            $seen[$child] = true;
            $out[] = $this->describe($node, $child);
        }

        // Schicht 2: alles unterhalb der platzierten Container.
        foreach ($placedRefIds as $refId) {
            if (!$tree->isInTree($refId)) {
                continue;
            }
            $node = $tree->getNodeData($refId);
            foreach (array_merge([$node], $tree->getSubTree($node)) as $sub) {
                $child = (int) ($sub['child'] ?? 0);
                if ($child <= 0 || isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $out[] = $this->describe($sub, $child);
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function describe(array $node, int $refId): array
    {
        return [
            'external_id' => $refId,
            'external_obj_id' => (int) ($node['obj_id'] ?? 0),
            'parent_external_id' => (int) ($node['parent'] ?? 0) ?: null,
            'object_type' => (string) ($node['type'] ?? 'unknown'),
            'title' => (string) ($node['title'] ?? ''),
            'path' => $this->pathOf($refId),
        ];
    }

    private function pathOf(int $refId): string
    {
        global $DIC;

        $titles = [];
        foreach ($DIC->repositoryTree()->getPathFull($refId) as $node) {
            $titles[] = (string) ($node['title'] ?? '');
        }

        return implode(' / ', array_filter($titles));
    }

    /**
     * Accounts, memberships and learning progress of the placed containers.
     *
     * One call, because ILIAS keeps all three on the same object and separate
     * calls would only mean a progress entry arriving before the membership
     * it belongs to — which the backend then has to drop.
     *
     * @param list<int> $refIds
     */
    private function pushMembers(array $refIds, string $batchId): string
    {
        global $DIC;

        $db = $this->db();
        $client = $this->client();
        $queue = new Queue($db);
        $path = $client->sitePath('members');

        /** @var array<int,array<string,mixed>> $users keyed by usr_id */
        $users = [];
        $progress = [];

        foreach ($refIds as $refId) {
            $objId = ilObject::_lookupObjectId($refId);
            if ($objId <= 0) {
                continue;
            }

            try {
                $participants = ilParticipants::getInstanceByObjId($objId);
            } catch (Throwable $e) {
                // A placement can outlive its course. That is not an error —
                // the backend keeps such an object visible but stale.
                continue;
            }

            $type = ilObject::_lookupType($objId) === 'grp' ? 'grp' : 'crs';
            $inThisContainer = [];
            foreach ([
                'admin' => $participants->getAdmins(),
                'tutor' => $participants->getTutors(),
                'member' => $participants->getMembers(),
            ] as $role => $ids) {
                foreach ($ids as $usrId) {
                    $usrId = (int) $usrId;
                    if ($usrId <= 0) {
                        continue;
                    }
                    if (!isset($users[$usrId])) {
                        $users[$usrId] = [
                            'external_user_id' => $usrId,
                            'roles' => [],
                        ] + $this->pii($usrId);
                    }
                    // Exactly the title ILIAS gives the local role. The
                    // backend parses ref_id and role out of it; anything we
                    // "improve" here it cannot read.
                    $users[$usrId]['roles'][] = 'il_' . $type . '_' . $role . '_' . $refId;
                    $inThisContainer[$usrId] = true;
                }
            }

            // Only the people of THIS container. Asking ilLPStatus for every
            // person seen so far would attach one course's progress to
            // another's object id.
            foreach (array_keys($inThisContainer) as $usrId) {
                $status = ilLPStatus::_lookupStatus($objId, (int) $usrId, false);
                if ($status === null) {
                    continue;
                }
                $progress[] = [
                    'external_user_id' => (int) $usrId,
                    'ref_ids' => [$refId],
                    // The raw ilLPStatus number. Translating it here would
                    // put the mapping in two places, and the copy out here is
                    // the one that ages — the backend owns ILIAS_LP_STATUS.
                    'status' => (int) $status,
                    'percentage' => ilLPStatus::_lookupPercentage($objId, (int) $usrId),
                ];
            }
        }

        if ($users === []) {
            return 'no participants';
        }

        $payload = array_merge($this->envelopeBase(), [
            'batch_id' => $batchId,
            'page' => 1,
            'pages' => 1,
            'full_snapshot' => false,
            'items' => array_values($users),
            'progress' => $progress,
        ]);

        try {
            $answer = $client->post($path, $payload);
        } catch (Throwable $e) {
            $queue->push($path, $payload);

            return 'participants queued (' . $e->getMessage() . ')';
        }

        return sprintf(
            '%d account(s), %d membership(s), %d progress row(s)',
            (int) ($answer['users'] ?? 0),
            (int) ($answer['memberships'] ?? 0),
            (int) ($answer['progress'] ?? 0)
        );
    }

    /**
     * Names and mail address — sent, but only stored if the tenant allows it.
     *
     * The decision sits in the portal, not here: `store_pii` on the
     * registration decides, and a tenant who wants no real names gets none
     * even though we offered them. What always travels is the usr_id, and
     * that alone is enough to attach progress and results to a person —
     * it is the value left of the @ in the learner identity.
     *
     * @return array<string,string>
     */
    private function pii(int $usrId): array
    {
        $user = new ilObjUser($usrId);

        return [
            'login' => (string) $user->getLogin(),
            'email' => (string) $user->getEmail(),
            'firstname' => (string) $user->getFirstname(),
            'lastname' => (string) $user->getLastname(),
        ];
    }
}
