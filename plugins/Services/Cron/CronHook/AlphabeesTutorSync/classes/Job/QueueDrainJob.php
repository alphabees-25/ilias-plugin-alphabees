<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Sync\Job;

use Alphabees\Tutor\Store\Queue;
use ILIAS\Cron\Job\JobResult;
use ILIAS\Cron\Job\Schedule\JobScheduleType;
use Throwable;

/**
 * Retries what did not get through, and sends a heartbeat.
 *
 * The queue exists because a push must survive a backend that is briefly
 * away — a deploy, a restart, a network blip. Each failure pushes the next
 * attempt further out, so a long outage does not turn into a hammering.
 *
 * The heartbeat rides along instead of being a fourth job: it is one request
 * with no body worth speaking of, and it carries the version and capability
 * report the portal shows. A separate job would only be another switch an
 * administrator can turn off by accident.
 */
final class QueueDrainJob extends BaseJob
{
    /** Per run. Enough to catch up after an outage, small enough to fit a slot. */
    private const MAX_PER_RUN = 20;

    public function getId(): string
    {
        return 'alphabees_queue_drain';
    }

    public function getTitle(): string
    {
        return $this->plugin->txt('job_drain_title');
    }

    public function getDescription(): string
    {
        return $this->plugin->txt('job_drain_desc');
    }

    public function getDefaultScheduleType(): JobScheduleType
    {
        return JobScheduleType::IN_MINUTES;
    }

    public function getDefaultScheduleValue(): ?int
    {
        return 5;
    }

    public function run(): JobResult
    {
        if (!$this->config()->isPaired()) {
            return $this->nothing('Not paired with AlphaLearn.');
        }

        $queue = new Queue($this->db());
        $client = $this->client();
        $due = $queue->due(self::MAX_PER_RUN);

        $sent = 0;
        $failed = 0;
        foreach ($due as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                // A batch we cannot read will never become readable. Dropping
                // it is better than retrying it for a day.
                $queue->done((int) $row['id']);
                continue;
            }
            try {
                $client->post((string) $row['endpoint'], $payload);
                $queue->done((int) $row['id']);
                $sent++;
            } catch (Throwable $e) {
                $queue->failed((int) $row['id'], (int) $row['tries'], $e->getMessage());
                $failed++;
                // One unreachable backend means all the rest will fail too.
                // Stop, rather than burn the whole run on timeouts.
                break;
            }
        }

        $beat = $this->heartbeat();

        if ($due === []) {
            return $this->nothing('Queue empty. ' . $beat);
        }
        if ($failed > 0 && $sent === 0) {
            return $this->result(
                JobResult::STATUS_FAIL,
                'Backend unreachable; ' . $queue->size() . ' batch(es) waiting.'
            );
        }

        return $this->ok($sent . ' batch(es) sent, ' . $queue->size() . ' waiting. ' . $beat);
    }

    private function heartbeat(): string
    {
        try {
            $client = $this->client();
            $client->post($client->sitePath('lifecycle'), array_merge($this->envelopeBase(), [
                'event' => 'heartbeat',
            ]));

            return 'Heartbeat acknowledged.';
        } catch (Throwable $e) {
            // Never queued: a heartbeat that arrives an hour late says
            // nothing true. The next run sends a fresh one.
            return 'Heartbeat failed: ' . $e->getMessage();
        }
    }
}
