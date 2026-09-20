<?php

declare(strict_types=1);

use Alphabees\Tutor\Client\BackendClient;
use Alphabees\Tutor\Client\Signer;
use Alphabees\Tutor\Store\Config;
use Alphabees\Tutor\Store\Placements;
use Alphabees\Tutor\Store\Queue;

/**
 * Administration → Plugins → AlphabeesTutor → Configure.
 *
 * Three things an administrator can do here and nothing else: paste the
 * pairing code from the portal, see whether the connection is alive, and
 * disconnect. Everything that needs deciding was decided in the portal.
 *
 * Note for whoever adds a command: the Configure action only appears in the
 * plugin's dropdown after the control structure has been reloaded, which
 * happens when `$version` in plugin.php changes and the plugin is updated.
 */
class ilAlphabeesTutorConfigGUI extends ilPluginConfigGUI
{
    private const CMD_CONFIGURE = 'configure';
    private const CMD_PAIR = 'pair';
    private const CMD_REFRESH = 'refresh';
    private const CMD_DISCONNECT = 'disconnect';

    public function performCommand(string $cmd): void
    {
        switch ($cmd) {
            case self::CMD_PAIR:
                $this->pair();
                break;
            case self::CMD_REFRESH:
                $this->refresh();
                break;
            case self::CMD_DISCONNECT:
                $this->disconnect();
                break;
            case self::CMD_CONFIGURE:
            default:
                $this->configure();
                break;
        }
    }

    private function configure(): void
    {
        global $DIC;

        $form = $this->buildForm();
        $DIC['tpl']->setContent($this->statusHtml() . $form->getHTML());
    }

    private function buildForm(): ilPropertyFormGUI
    {
        global $DIC;

        $plugin = $this->getPluginObject();
        $config = new Config($DIC->database());
        $form = new ilPropertyFormGUI();
        $form->setFormAction($DIC->ctrl()->getFormAction($this));

        if ($config->isPaired()) {
            $form->setTitle($plugin->txt('cfg_connected_title'));
            $form->addCommandButton(self::CMD_REFRESH, $plugin->txt('cfg_refresh'));
            $form->addCommandButton(self::CMD_DISCONNECT, $plugin->txt('cfg_disconnect'));

            return $form;
        }

        $form->setTitle($plugin->txt('cfg_pair_title'));

        $url = new ilTextInputGUI($plugin->txt('cfg_backend_url'), 'backend_url');
        $url->setInfo($plugin->txt('cfg_backend_url_info'));
        $url->setRequired(true);
        $url->setValue((string) $config->get(Config::BACKEND_URL, 'https://api.alphalearn.ai'));
        $form->addItem($url);

        $loader = new ilTextInputGUI($plugin->txt('cfg_loader_url'), 'loader_url');
        $loader->setInfo($plugin->txt('cfg_loader_url_info'));
        $loader->setRequired(true);
        $loader->setValue((string) $config->get(Config::LOADER_URL, 'https://chat.alphabees.de/production/chat-widget.js'));
        $form->addItem($loader);

        $token = new ilTextInputGUI($plugin->txt('cfg_token'), 'token');
        $token->setInfo($plugin->txt('cfg_token_info'));
        $token->setRequired(true);
        $form->addItem($token);

        $form->addCommandButton(self::CMD_PAIR, $plugin->txt('cfg_pair'));

        return $form;
    }

    private function pair(): void
    {
        global $DIC;

        $plugin = $this->getPluginObject();
        $form = $this->buildForm();
        if (!$form->checkInput()) {
            $form->setValuesByPost();
            $DIC['tpl']->setContent($this->statusHtml() . $form->getHTML());

            return;
        }

        $db = $DIC->database();
        $config = new Config($db);
        $backendUrl = trim((string) $form->getInput('backend_url'));
        $loaderUrl = trim((string) $form->getInput('loader_url'));
        $token = trim((string) $form->getInput('token'));

        // The installation UUID, not the host name: it survives a move from
        // ilias.uni.de to lms.uni.de, and it is the very value that stands
        // right of the @ in a learner identity. One term, two uses.
        $siteIdentifier = ilCmiXapiUser::getIliasUuid();
        $keypair = Signer::generateKeypair();

        try {
            $client = new BackendClient($db);
            $answer = $client->pair($backendUrl, $token, [
                'site_identifier' => $siteIdentifier,
                'site_url' => ILIAS_HTTP_PATH,
                'public_key' => $keypair['public'],
                'key_id' => 1,
                'display_name' => (string) ($DIC->settings()->get('short_inst_name') ?: ''),
                'plugin_version' => $this->pluginVersion(),
                'plugin_version_code' => ilAlphabeesTutorPlugin::VERSION_CODE,
                'ilias_version' => ILIAS_VERSION,
                'capabilities' => ilAlphabeesTutorPlugin::capabilities(),
            ]);
        } catch (Throwable $e) {
            $DIC['tpl']->setOnScreenMessage('failure', $plugin->txt('cfg_pair_failed') . ' ' . $e->getMessage());
            $DIC->ctrl()->redirect($this, self::CMD_CONFIGURE);

            return;
        }

        $config->set(Config::BACKEND_URL, $backendUrl);
        $config->set(Config::LOADER_URL, $loaderUrl);
        $config->set(Config::SITE_IDENTIFIER, $siteIdentifier);
        $config->set(Config::PRIVATE_KEY, $keypair['private']);
        $config->set(Config::PUBLIC_KEY, $keypair['public']);
        $config->set(Config::KEY_ID, '1');
        $config->set(Config::REGISTRATION_ID, (string) ($answer['registration_id'] ?? ''));
        $config->set(Config::BACKEND_PUBLIC_KEY, (string) ($answer['backend_public_key'] ?? ''));
        $config->set(Config::PAIRED_AT, (string) time());

        $DIC['tpl']->setOnScreenMessage('success', $plugin->txt('cfg_pair_ok'), true);
        $this->pullPlacements();
        $DIC->ctrl()->redirect($this, self::CMD_CONFIGURE);
    }

    private function refresh(): void
    {
        global $DIC;

        $plugin = $this->getPluginObject();
        try {
            $count = $this->pullPlacements();
            $DIC['tpl']->setOnScreenMessage(
                'success',
                sprintf($plugin->txt('cfg_refresh_ok'), $count),
                true
            );
        } catch (Throwable $e) {
            $DIC['tpl']->setOnScreenMessage('failure', $e->getMessage(), true);
        }
        $DIC->ctrl()->redirect($this, self::CMD_CONFIGURE);
    }

    /**
     * Disconnect: credentials go, placements stay.
     *
     * The same rule the backend follows. An administrator who disconnects by
     * mistake, or who rebuilds their ILIAS, does not lose a single agent
     * assignment — pairing again brings all of them back.
     */
    private function disconnect(): void
    {
        global $DIC;

        $plugin = $this->getPluginObject();
        $db = $DIC->database();
        $client = new BackendClient($db);

        try {
            $client->post($client->sitePath('lifecycle'), [
                'event' => 'disconnected',
                'reason' => 'Im ILIAS-Plugin getrennt.',
                'plugin_version' => $this->pluginVersion(),
                'plugin_version_code' => ilAlphabeesTutorPlugin::VERSION_CODE,
            ]);
        } catch (Throwable $e) {
            // Telling the backend is courtesy, not a precondition. An
            // administrator must be able to cut the connection even when we
            // are unreachable.
            $DIC->logger()->root()->info('[alphabees] disconnect not acknowledged: ' . $e->getMessage());
        }

        (new Config($db))->clearPairing();
        $DIC['tpl']->setOnScreenMessage('success', $plugin->txt('cfg_disconnect_ok'), true);
        $DIC->ctrl()->redirect($this, self::CMD_CONFIGURE);
    }

    private function pullPlacements(): int
    {
        global $DIC;

        $db = $DIC->database();
        $client = new BackendClient($db);
        $answer = $client->get($client->sitePath('placements'));

        $config = new Config($db);
        $config->set(Config::API_KEY, (string) ($answer['api_key'] ?? ''));

        return (new Placements($db))->replaceAll((array) ($answer['placements'] ?? []));
    }

    private function statusHtml(): string
    {
        global $DIC;

        $plugin = $this->getPluginObject();
        $db = $DIC->database();
        $config = new Config($db);
        if (!$config->isPaired()) {
            return '';
        }

        $placements = new Placements($db);
        $queue = new Queue($db);
        $newest = $placements->newestUpdate();

        $rows = [
            $plugin->txt('cfg_status_site') => (string) $config->get(Config::SITE_IDENTIFIER, '—'),
            $plugin->txt('cfg_status_registration') => (string) $config->get(Config::REGISTRATION_ID, '—'),
            $plugin->txt('cfg_status_placements') => (string) $placements->count(),
            $plugin->txt('cfg_status_updated') => $newest ? date('d.m.Y H:i', $newest) : '—',
            $plugin->txt('cfg_status_queue') => $queue->size() . ($queue->stuck() > 0 ? ' (' . $queue->stuck() . ' ' . $plugin->txt('cfg_status_stuck') . ')' : ''),
            $plugin->txt('cfg_status_error') => (string) $config->get(Config::LAST_ERROR, '—'),
        ];

        $html = '<table class="table table-striped"><tbody>';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th style="width:18rem">' . ilLegacyFormElementsUtil::prepareFormOutput($label)
                . '</th><td>' . ilLegacyFormElementsUtil::prepareFormOutput($value) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private function pluginVersion(): string
    {
        $plugin = $this->getPluginObject();

        return $plugin === null ? '0.0.0' : $plugin->getVersion();
    }
}
