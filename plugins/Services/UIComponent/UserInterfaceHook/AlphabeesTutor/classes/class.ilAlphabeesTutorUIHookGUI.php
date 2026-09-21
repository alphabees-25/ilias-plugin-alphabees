<?php

declare(strict_types=1);

use Alphabees\Tutor\Context\CourseResolver;
use Alphabees\Tutor\Store\Config;
use Alphabees\Tutor\Store\Placements;

/**
 * Puts the widget on the page.
 *
 * ILIAS calls `getHTML("", "template_show", …)` once per rendered template
 * (`ilGlobalTemplate::…`, the loop over `getActivePluginsInSlot("uihk")`).
 * That includes partial and asynchronous renders, so the first thing this
 * does is insist on the main page template.
 *
 * APPEND, never REPLACE. REPLACE hands ILIAS whatever string we return, so a
 * single exception in here would blank the page. Appending can at worst add
 * nothing.
 *
 * NOTHING in this path talks to the backend. The placement comes from a local
 * indexed SELECT, the course from a session-cached tree walk. An ILIAS page
 * must render at full speed whether or not we are reachable.
 */
class ilAlphabeesTutorUIHookGUI extends ilUIHookPluginGUI
{
    /** Name der Hauptvorlage, nur noch als Gegenprobe — siehe mayAppendTo(). */
    private const MAIN_TEMPLATE = 'tpl.main.html';

    /**
     * Whether we may append to this render.
     *
     * Three assumptions about this interface turned out wrong on ILIAS 11.4,
     * each measured only after the widget failed to appear:
     *
     *   tpl_id === 'tpl.main.html'   -> matched nothing; the identifier
     *                                   carries a leading slash in some paths
     *   basename(tpl_id) === …       -> a real course page passes tpl_id=''
     *   html contains '</body>'      -> it does not. What arrives is the
     *                                   CONTENT FRAGMENT, starting at
     *                                   <div id="mainspacekeeper">, ~20-50 KB,
     *                                   with no <html> and no </body>
     *
     * So `template_show` itself is the signal, and nothing else is. It fires
     * two to three times per page load (against ~1500 `template_load`), each
     * time with a fragment that ends up in the document — appending a script
     * tag to any of them puts it on the page.
     *
     * Firing more than once is handled where it belongs, in the snippet: the
     * `window.__alphabeesTutorLoaded` guard makes a second injection a no-op.
     * That is deliberately more robust than picking "the right" one of the
     * fragments, because which of them survives is once again an assumption
     * about ILIAS internals.
     *
     * A non-empty identifier still has to look like the main template, so a
     * component rendering its own document does not collect our widget.
     */
    private function mayAppendTo(string $tplId): bool
    {
        return $tplId === '' || basename($tplId) === self::MAIN_TEMPLATE;
    }

    public function getHTML(string $a_comp, string $a_part, array $a_par = []): array
    {
        $keep = ['mode' => ilUIHookPluginGUI::KEEP, 'html' => ''];

        try {
            $snippet = $this->buildSnippet();
        } catch (Throwable $e) {
            // A page must not break because our widget could not be built.
            // Logged at debug because this fires on every page of every
            // request once something is wrong, and a flooded log helps nobody.
            global $DIC;
            if ($DIC->isDependencyAvailable('logger')) {
                $DIC->logger()->root()->debug('[alphabees] widget skipped: ' . $e->getMessage());
            }

            return $keep;
        }

        if ($snippet === null) {
            return $keep;
        }

        return ['mode' => ilUIHookPluginGUI::APPEND, 'html' => $snippet];
    }

    private function buildSnippet(): ?string
    {
        global $DIC;

        $user = $DIC->user();
        // An anonymous visitor has no identity to carry into a conversation,
        // and the widget would open a session that can never be matched to a
        // learner. Nothing is rendered for them.
        if ($user === null || ilObjUser::_isAnonymous($user->getId())) {
            return null;
        }
        $usrId = (int) $user->getId();

        $db = $DIC->database();
        $config = new Config($db);
        if (!$config->isPaired()) {
            return null;
        }

        $apiKey = (string) $config->get(Config::API_KEY, '');
        $loaderUrl = (string) $config->get(Config::LOADER_URL, '');
        if ($apiKey === '' || $loaderUrl === '') {
            return null;
        }

        $refId = $this->currentRefId();
        if ($refId === null) {
            return null;
        }

        $containerRefId = (new CourseResolver($DIC->repositoryTree()))->containerRefId($refId);
        if ($containerRefId === null) {
            return null;
        }

        // Read permission on the container, not on the page: a learner who may
        // not enter the course has no business getting its agent, even if some
        // other object inside it happens to be readable.
        //
        // checkAccessOfUser, not checkAccess: the access service answers for
        // the user it was CONSTRUCTED with, not for whoever $DIC->user()
        // returns at call time. During a normal page render those are the
        // same, so both would work here — but naming the user makes the
        // intent explicit and immune to that difference. The same trap cost
        // us a day on the SOAP path, where ilRbacSystem kept answering as
        // 'anonymous' no matter which user we set afterwards.
        if (!$DIC->access()->checkAccessOfUser($usrId, 'read', '', $containerRefId)) {
            return null;
        }

        $placement = (new Placements($db))->forRefId($containerRefId);
        if ($placement === null) {
            return null;
        }

        return $this->renderSnippet($placement, $loaderUrl, $apiKey, $containerRefId, $usrId);
    }

    /**
     * ref_id of whatever the learner is looking at.
     *
     * Read from the request rather than from `$DIC->http()->request()` alone
     * because ILIAS still routes a good part of the repository through plain
     * GET parameters, and `ref_id` is the one constant across all of them.
     */
    private function currentRefId(): ?int
    {
        global $DIC;

        $params = $DIC->http()->request()->getQueryParams();
        $raw = $params['ref_id'] ?? ($params['target'] ?? null);
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) && str_contains($raw, '_')) {
            // goto targets look like `crs_92`.
            $parts = explode('_', $raw);
            $raw = end($parts);
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $refId = (int) $raw;

        return $refId > 0 ? $refId : null;
    }

    /**
     * @param array<string,mixed> $placement
     */
    private function renderSnippet(
        array $placement,
        string $loaderUrl,
        string $apiKey,
        int $containerRefId,
        int $usrId
    ): string {
        // The identity ILIAS itself would hand an xAPI tool at privacy level
        // "ILIAS user ID": `{usr_id}@{installation-uuid}.ilias`
        // (ilCmiXapiUser::getIdent). Rebuilding it rather than inventing one
        // means a learner arriving through LTI and the same learner arriving
        // through this plugin are one person to the backend, with no mapping
        // table in between. Never hard-code the UUID — it is generated per
        // installation and read here at runtime.
        $identity = $usrId . '@' . ilCmiXapiUser::getIliasUuid() . '.ilias';

        $courseId = (string) ($placement['course_id'] ?? '');
        $config = [
            'apiKey' => $apiKey,
            'botId' => (string) $placement['bot_id'],
            'platform' => 'ilias',
            // `additionalUserIds`, not `userIds`. The widget builds its list
            // itself from the anonymous browser id plus this field
            // (socket.service.ts); `userIds` is not a state field and is
            // discarded without a word. That mistake kept every ILIAS learner
            // anonymous on the LTI path until it was found.
            'additionalUserIds' => [['id' => $identity, 'name' => 'lti']],
            'context' => [
                'courseId' => $courseId,
                // The ref_id of the container. The backend resolves a
                // placement from it exactly as it does for an LTI launch —
                // same column, same lookup, no extra code over there.
                'ltiContextId' => (string) $containerRefId,
                'ltiResourceLinkId' => '',
            ],
        ];

        $color = (string) ($placement['primary_color'] ?? '');
        if ($color !== '') {
            $config['primaryColor'] = $color;
        }

        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return <<<HTML
<script>
(function () {
  if (window.__alphabeesTutorLoaded) { return; }
  window.__alphabeesTutorLoaded = true;
  var cfg = {$json};
  var s = document.createElement('script');
  s.src = {$this->jsString($loaderUrl)};
  s.async = true;
  s.onload = function () {
    if (typeof window._loadAlChat === 'function') { window._loadAlChat(cfg); }
  };
  document.body.appendChild(s);
})();
</script>
HTML;
    }

    private function jsString(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
