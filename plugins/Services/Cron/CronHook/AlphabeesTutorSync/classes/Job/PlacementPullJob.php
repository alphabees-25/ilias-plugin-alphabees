<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Sync\Job;

use Alphabees\Tutor\Store\Config;
use Alphabees\Tutor\Store\Placements;
use ILIAS\Cron\Job\JobResult;
use ILIAS\Cron\Job\Schedule\JobScheduleType;
use Throwable;

/**
 * Fetches the agent placements and the widget key.
 *
 * This is the job that makes the widget appear. Everything else is optional;
 * without this one the local table stays empty and no course shows anything.
 *
 * Fifteen minutes is the compromise between "an administrator places an agent
 * in the portal and wants to see it" and "do not knock on the backend for
 * every ILIAS installation in the world every minute". A refresh button in
 * the plugin configuration covers the impatient case.
 */
final class PlacementPullJob extends BaseJob
{
    public function getId(): string
    {
        return 'alphabees_placement_pull';
    }

    public function getTitle(): string
    {
        return $this->plugin->txt('job_pull_title');
    }

    public function getDescription(): string
    {
        return $this->plugin->txt('job_pull_desc');
    }

    public function getDefaultScheduleType(): JobScheduleType
    {
        return JobScheduleType::IN_MINUTES;
    }

    public function getDefaultScheduleValue(): ?int
    {
        return 15;
    }

    public function run(): JobResult
    {
        if (($blocked = $this->blockedBy()) !== null) {
            return $blocked;
        }
        $config = $this->config();

        try {
            $client = $this->client();
            $answer = $client->get($client->sitePath('placements'));
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        // The key can change in the portal (a key revoked, a new one issued).
        // Taking it from the same answer keeps the widget working without a
        // second round trip — and without an administrator having to notice.
        $this->applyState($answer);

        // Eine nicht aktive Verbindung antwortet mit einer LEEREN Liste und
        // ihrem Zustand — der Endpunkt bleibt absichtlich erreichbar, sonst
        // erfuehre ein pausiertes Plugin nie vom Fortsetzen. Diese Leere ist
        // aber keine Aussage ueber die Zuordnungen: das Backend behaelt sie.
        //
        // Wer sie hier trotzdem uebernimmt, loescht die lokale Kopie und
        // verliert auch den API-Schluessel. Das Widget verschwindet dadurch
        // nicht frueher — dafuer sorgt `isActive()` im Renderer —, aber nach
        // dem Fortsetzen bleibt der Tutor bis zum naechsten Abruf weg. Genau
        // das war auf der Testinstanz zu sehen: pausieren, fortsetzen, und
        // der Kurs stand ohne Agenten da.
        $state = (string) ($answer['state'] ?? 'active');
        if ($state !== 'active') {
            return $this->nothing('Connection ' . $state . ' in the AlphaLearn portal.');
        }

        $config->set(Config::API_KEY, (string) ($answer['api_key'] ?? ''));

        try {
            $written = (new Placements($this->db()))->replaceAll((array) ($answer['placements'] ?? []));
        } catch (Throwable $e) {
            // Ein Schreibfehler ist unser Problem, kein Absturz des
            // Cron-Laufs: ILIAS zeigt STATUS_FAIL in der Verwaltung an, der
            // naechste Lauf versucht es erneut, und alle anderen Jobs der
            // Installation laufen weiter.
            return $this->failed($e);
        }

        if ($written === 0) {
            return $this->result(
                JobResult::STATUS_NO_ACTION,
                'No agent is placed in any ILIAS course yet.'
            );
        }

        return $this->ok($written . ' placement(s) fetched.');
    }
}
