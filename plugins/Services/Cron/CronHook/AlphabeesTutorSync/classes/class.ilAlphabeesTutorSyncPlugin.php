<?php

declare(strict_types=1);

use Alphabees\Tutor\Sync\Job\ContentPushJob;
use Alphabees\Tutor\Sync\Job\PlacementPullJob;
use Alphabees\Tutor\Sync\Job\QueueDrainJob;
use Alphabees\Tutor\Sync\Job\StructurePushJob;
use ILIAS\Cron\CronHookPlugin;
use ILIAS\Cron\CronJob;

/**
 * Provides the background jobs.
 *
 * The plugin owns no tables of its own. Everything it reads and writes
 * belongs to the AlphabeesTutor plugin — pairing, placements, queue, cursor.
 * Two copies of that state would mean two answers to "which agent sits here",
 * and the renderer would sooner or later read the older one.
 */
class ilAlphabeesTutorSyncPlugin extends CronHookPlugin
{
    public const PLUGIN_ID = 'crnhkalphabees';
    public const PLUGIN_NAME = 'AlphabeesTutorSync';

    /** The plugin that owns the tables and the pairing. */
    public const COMPANION_ID = 'uihkalphabees';

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * @return list<CronJob>
     */
    public function getCronJobInstances(): array
    {
        return [
            new PlacementPullJob($this),
            new StructurePushJob($this),
            new ContentPushJob($this),
            new QueueDrainJob($this),
        ];
    }

    public function getCronJobInstance(string $jobId): CronJob
    {
        foreach ($this->getCronJobInstances() as $job) {
            if ($job->getId() === $jobId) {
                return $job;
            }
        }

        throw new OutOfBoundsException('Unknown cron job: ' . $jobId);
    }

    /**
     * Refuse to activate without the companion.
     *
     * Its tables carry the pairing; activating alone would give an
     * administrator three jobs that can only ever fail, and a failing cron
     * job in ILIAS is loud.
     */
    protected function beforeActivation(): bool
    {
        global $DIC;

        if (!$DIC['component.repository']->hasActivatedPlugin(self::COMPANION_ID)) {
            $DIC['tpl']->setOnScreenMessage('failure', $this->txt('needs_companion'), true);

            return false;
        }

        return true;
    }
}
