<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Sync\Job;

use Alphabees\Tutor\Client\BackendClient;
use Alphabees\Tutor\Store\Cursor;
use Alphabees\Tutor\Store\Placements;
use ILIAS\Cron\Job\JobResult;
use ILIAS\Cron\Job\Schedule\JobScheduleType;
use ilObject;
use ilObjFile;
use Throwable;

/**
 * Schickt die Dateien platzierter Kurse in die Wissensbasis.
 *
 * DAS IST DER JOB, DER SOAP UND WEBDAV UEBERFLUESSIG MACHT.
 *
 * Bisher holte sich al-filemanager Kursdateien ueber WebDAV, mit SOAP als
 * Rueckfall — und dafuer brauchte es einen technischen Benutzer, eine eigene
 * Rolle, Rechtevorgaben, eine SOAP-Freischaltung im Setup und oft eine
 * IP-Freigabe. Hier laeuft stattdessen ein Cron-Job IN ILIAS und liest die
 * Datei dort, wo sie liegt. Nichts davon ist mehr noetig.
 *
 * ZWEI SCHRITTE, DAMIT NICHT ALLES ZWEIMAL REIST
 *
 *   1. Verzeichnis: ref_id, Name, Version, Groesse, Pruefsumme — ohne Inhalt.
 *      Das Backend antwortet, welche Dateien ihm fehlen.
 *   2. Nur die schickt dieser Job, einzeln, mit einem Ticket je Datei.
 *
 * Ein Kurs mit 300 unveraenderten PDFs loest damit keinen einzigen Upload
 * aus. Die Pruefsumme kostet zwar das Lesen der Datei, aber kein Netz — und
 * sie ist es, die das Ticket an genau diese Bytes bindet.
 *
 * WARUM DIE BYTES NICHT AN AL-TUTOR GEHEN
 *
 * Der signierte Kanal endet dort, die Ablage liegt in al-filemanager. Eine
 * Datei durch beide zu reichen hiesse, sie zweimal im Speicher zu halten. Das
 * Verzeichnis geht signiert an al-tutor, die Bytes gehen mit dem Ticket
 * direkt an al-filemanager.
 */
final class ContentPushJob extends BaseJob
{
    private const CURSOR = 'content_push';

    /** Was ein Lauf hoechstens hochlaedt. Ein Cron-Slot ist nicht ewig. */
    private const MAX_UPLOADS_PER_RUN = 25;

    /** Muss zur Grenze in al-filemanager passen (200 MB). */
    private const MAX_FILE_BYTES = 200 * 1024 * 1024;

    /** Objekttypen, deren Inhalt in die Wissensbasis gehoert. */
    private const CONTENT_TYPES = ['file'];

    public function getId(): string
    {
        return 'alphabees_content_push';
    }

    public function getTitle(): string
    {
        return $this->plugin->txt('job_content_title');
    }

    public function getDescription(): string
    {
        return $this->plugin->txt('job_content_desc');
    }

    public function getDefaultScheduleType(): JobScheduleType
    {
        return JobScheduleType::IN_HOURS;
    }

    public function getDefaultScheduleValue(): ?int
    {
        return 12;
    }

    public function run(): JobResult
    {
        if (!$this->config()->isPaired()) {
            return $this->nothing('Not paired with AlphaLearn.');
        }

        $db = $this->db();
        $refIds = (new Placements($db))->refIds();
        if ($refIds === []) {
            return $this->nothing('No placed course to read files from.');
        }

        // Reihum: jeder Lauf beginnt bei einem anderen Kurs. Sonst bekaeme
        // der erste Kurs jeden Lauf seine Dateien und der letzte nie.
        $cursor = new Cursor($db);
        $state = $cursor->get(self::CURSOR);
        $start = $state === null ? 0 : $state['offset'] % count($refIds);

        $uploaded = 0;
        $skipped = 0;
        $failed = 0;
        $touched = 0;

        for ($i = 0; $i < count($refIds) && $uploaded < self::MAX_UPLOADS_PER_RUN; $i++) {
            $refId = $refIds[($start + $i) % count($refIds)];
            $touched++;
            try {
                [$up, $sk] = $this->pushCourse($refId, self::MAX_UPLOADS_PER_RUN - $uploaded);
                $uploaded += $up;
                $skipped += $sk;
            } catch (Throwable $e) {
                $failed++;
                self::note($refId, $e->getMessage());
            }
        }

        $cursor->save(self::CURSOR, 'rotate', 1, 1, $start + $touched, time());

        if ($uploaded === 0 && $failed === 0) {
            return $this->nothing(sprintf('%d course(s) checked, nothing new.', $touched));
        }
        if ($uploaded === 0 && $failed > 0) {
            return $this->result(JobResult::STATUS_FAIL, sprintf(
                '%d course(s) failed, nothing sent.',
                $failed
            ));
        }

        return $this->ok(sprintf(
            '%d file(s) sent from %d course(s), %d unchanged, %d course(s) failed.',
            $uploaded,
            $touched,
            $skipped,
            $failed
        ));
    }

    /**
     * Ein Kurs: Verzeichnis melden, dann die gewollten Dateien schicken.
     *
     * @return array{0:int,1:int} hochgeladen, uebersprungen
     */
    private function pushCourse(int $courseRefId, int $budget): array
    {
        global $DIC;

        $tree = $DIC->repositoryTree();
        if (!$tree->isInTree($courseRefId)) {
            return [0, 0];
        }

        // Verzeichnis aufnehmen. Die Pruefsumme kostet das Lesen der Datei —
        // das ist der Preis dafuer, dass nichts Unveraendertes durchs Netz
        // geht, und er faellt lokal an.
        $items = [];
        $node = $tree->getNodeData($courseRefId);
        foreach ($tree->getSubTree($node, true, self::CONTENT_TYPES) as $child) {
            $refId = (int) ($child['child'] ?? 0);
            if ($refId <= 0) {
                continue;
            }
            $meta = $this->describeFile($refId);
            if ($meta === null) {
                continue;
            }
            $items[] = $meta;
        }

        if ($items === []) {
            return [0, 0];
        }

        $client = $this->client();
        $answer = $client->post(
            $client->sitePath('content/manifest'),
            array_merge($this->envelopeBase(), [
                'batch_id' => bin2hex(random_bytes(8)),
                'course_ref_id' => $courseRefId,
                'items' => $items,
            ])
        );

        $tickets = (array) ($answer['tickets'] ?? []);
        $uploadUrl = (string) ($answer['upload_url'] ?? '');
        if ($tickets === [] || $uploadUrl === '') {
            return [0, count($items)];
        }

        $sent = 0;
        foreach ($tickets as $ticket) {
            if ($sent >= $budget) {
                break;
            }
            $refId = (int) ($ticket['ref_id'] ?? 0);
            $token = (string) ($ticket['ticket'] ?? '');
            if ($refId <= 0 || $token === '') {
                continue;
            }
            if ($this->uploadFile($uploadUrl, $token, $refId)) {
                $sent++;
            }
        }

        return [$sent, count($items) - count($tickets)];
    }

    /**
     * Was das Backend ueber eine Datei wissen muss, um zu entscheiden.
     *
     * @return array<string,mixed>|null
     */
    private function describeFile(int $refId): ?array
    {
        $content = $this->readFile($refId);
        if ($content === null) {
            return null;
        }

        $file = new ilObjFile($refId, true);

        return [
            'ref_id' => $refId,
            'name' => $file->getFileName() ?: ilObject::_lookupTitle(ilObject::_lookupObjectId($refId)),
            'version' => $file->getVersion(),
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ];
    }

    /**
     * Der Dateiinhalt, in-process.
     *
     * Zwei Wege, beide gegen ILIAS 11.4 geprueft und byte-gleich: der Pfad
     * aus `ilObjFile::getFile()` und der Datenstrom des Resource Storage
     * Service. Der Pfad zuerst, weil er die Datei nicht durch einen zweiten
     * Puffer schiebt; der Storage-Weg als Rueckfall, weil der Pfad in
     * kuenftigen Ablagen verschwinden kann.
     */
    private function readFile(int $refId): ?string
    {
        global $DIC;

        try {
            if (ilObject::_lookupType(ilObject::_lookupObjectId($refId)) !== 'file') {
                return null;
            }
            $file = new ilObjFile($refId, true);
            if ($file->getFileSize() > self::MAX_FILE_BYTES) {
                self::note($refId, 'zu gross: ' . $file->getFileSize() . ' Byte');

                return null;
            }

            $path = $file->getFile();
            if ($path !== '' && is_readable($path)) {
                $bytes = file_get_contents($path);

                return $bytes === false ? null : $bytes;
            }

            $id = $DIC->resourceStorage()->manage()->find($file->getResourceId());
            if ($id === null) {
                return null;
            }

            return (string) $DIC->resourceStorage()->consume()->stream($id)->getStream();
        } catch (Throwable $e) {
            self::note($refId, $e->getMessage());

            return null;
        }
    }

    /** Die Bytes, mit Ticket, direkt an al-filemanager. */
    private function uploadFile(string $url, string $ticket, int $refId): bool
    {
        $content = $this->readFile($refId);
        if ($content === null) {
            return false;
        }

        $file = new ilObjFile($refId, true);
        $name = $file->getFileName() ?: ('ref_' . $refId);

        $handle = curl_init($url);
        if ($handle === false) {
            return false;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'X-Alphabees-Ticket: ' . $ticket,
                // Prozentkodiert: Kopfzeilen sind latin-1, und ein
                // ILIAS-Kurs heisst gern "Einfuehrung ...". Roh uebertragen
                // stirbt der Aufruf an genau der Stelle, an der schon einmal
                // 659 Produktionsdateien hingen.
                'X-Alphabees-Filename: ' . rawurlencode($name),
            ],
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $status >= 400) {
            self::note($refId, $error !== '' ? $error : ('HTTP ' . $status));

            return false;
        }

        return true;
    }

    private static function note(int $refId, string $message): void
    {
        global $DIC;

        if ($DIC->isDependencyAvailable('logger')) {
            $DIC->logger()->root()->info(
                '[alphabees] Datei ref_id=' . $refId . ': ' . $message
            );
        }
    }
}
