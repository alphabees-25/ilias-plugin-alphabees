<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Sync\Job;

use Alphabees\Tutor\Client\BackendClient;
use Alphabees\Tutor\Store\Config;
use ILIAS\Cron\CronJob;
use ILIAS\Cron\Job\JobResult;
use ilAlphabeesTutorSyncPlugin;
use ilDBInterface;
use Throwable;

/**
 * What the three jobs share.
 *
 * Above all the result convention, because ILIAS shows it to an
 * administrator in Administration → Cron Jobs:
 *
 *   NO_ACTION  nothing to do. An unpaired site, an empty queue. Not a fault.
 *   OK         work happened.
 *   FAIL       we could not reach the backend or it refused. Expected from
 *              time to time, visible, retried on the next run.
 *   CRASHED    never used here. ILIAS treats it as "this job is broken" and
 *              can deactivate it — a network hiccup must not do that.
 */
abstract class BaseJob extends CronJob
{
    protected ilAlphabeesTutorSyncPlugin $plugin;

    public function __construct(ilAlphabeesTutorSyncPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function hasAutoActivation(): bool
    {
        // The administrator paired the site on purpose; having to switch on
        // three more jobs afterwards is a step that only produces support
        // tickets when it is forgotten.
        return true;
    }

    public function hasFlexibleSchedule(): bool
    {
        return true;
    }

    protected function db(): ilDBInterface
    {
        global $DIC;

        return $DIC->database();
    }

    protected function config(): Config
    {
        return new Config($this->db());
    }

    protected function client(): BackendClient
    {
        return new BackendClient($this->db());
    }

    /**
     * Was das Backend ueber die Verbindung meldet, uebernehmen.
     *
     * Kommt aus jeder Antwort, die es mitschickt (Lebenszeichen und
     * Platzierungs-Abruf). Ein pausiertes Plugin erfaehrt so von der Pause,
     * statt sich an 401ern abzuarbeiten.
     *
     * @param array<string,mixed> $answer
     */
    protected function applyState(array $answer): void
    {
        $state = $answer['state'] ?? null;
        if (is_string($state) && $state !== '') {
            $this->config()->applyState($state, isset($answer['reason']) ? (string) $answer['reason'] : null);
        }
    }

    /**
     * Gemeinsame Eingangspruefung aller Jobs.
     *
     * Gibt ein Ergebnis zurueck, wenn der Job NICHT laufen soll — pausiert
     * oder gar nicht gekoppelt. Sonst null.
     */
    protected function blockedBy(): ?JobResult
    {
        $config = $this->config();
        if (!$config->isPaired()) {
            return $this->nothing('Not paired with AlphaLearn.');
        }
        if ($config->state() === 'paused') {
            return $this->nothing('Connection paused in the AlphaLearn portal.');
        }

        return null;
    }

    protected function result(int $status, string $message): JobResult
    {
        $result = new JobResult();
        $result->setStatus($status);
        $result->setMessage(mb_substr($message, 0, 400));

        return $result;
    }

    protected function ok(string $message): JobResult
    {
        return $this->result(JobResult::STATUS_OK, $message);
    }

    protected function nothing(string $message): JobResult
    {
        return $this->result(JobResult::STATUS_NO_ACTION, $message);
    }

    protected function failed(Throwable $e): JobResult
    {
        return $this->result(JobResult::STATUS_FAIL, $e->getMessage());
    }

    /**
     * What every job reports about itself, so the backend can keep the portal
     * honest without a call of its own. Harvested from every signed request.
     *
     * @return array<string,mixed>
     */
    protected function envelopeBase(): array
    {
        return [
            'plugin_version' => $this->plugin->getVersion(),
            'plugin_version_code' => \ilAlphabeesTutorPlugin::VERSION_CODE,
            'ilias_version' => ILIAS_VERSION,
            'capabilities' => \ilAlphabeesTutorPlugin::capabilities(),
        ];
    }
}
