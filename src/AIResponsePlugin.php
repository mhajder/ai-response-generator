<?php

/*********************************************************************
 * AI Response Generator Plugin
 *
 * Adds AI-powered reply drafting, ticket summarization, and draft
 * rewriting controls to the osTicket agent ticket view.
 *********************************************************************/

declare(strict_types=1);

if (!class_exists('Plugin')) {
    require_once INCLUDE_DIR . 'class.plugin.php';
}
require_once __DIR__ . '/Config.php';

class AIResponseGeneratorPlugin extends Plugin
{
    /** @var string */
    public $config_class = 'AIResponseGeneratorPluginConfig';

    private static ?AIResponseGeneratorPluginConfig $active_config = null;

    /** @var array<int, AIResponseGeneratorPluginConfig> */
    private static array $configs = array();

    private static bool $signals_connected = false;
    private static bool $ajax_registered = false;

    public function isMultiInstance(): bool
    {
        return true;
    }

    public function bootstrap(): void
    {
        if (!self::$signals_connected) {
            self::$signals_connected = true;
            Signal::connect('ticket.view.more', array($this, 'onTicketViewMore'), 'Ticket');
            Signal::connect('object.view', array($this, 'onObjectView'), 'Ticket');
            Signal::connect('ajax.scp', array($this, 'onAjaxScp'));
        }

        $cfg = $this->getConfig();
        if ($cfg instanceof AIResponseGeneratorPluginConfig) {
            self::$active_config = $cfg;
            $inst = $cfg->getInstance();
            if ($inst && $inst->getId()) {
                self::$configs[(int) $inst->getId()] = $cfg;
            }
        }
    }

    public static function getActiveConfig(): ?AIResponseGeneratorPluginConfig
    {
        return self::$active_config;
    }

    /**
     * @return array<int, AIResponseGeneratorPluginConfig>
     */
    public static function getAllConfigs(): array
    {
        return self::$configs;
    }

    /**
     * Parse newline-separated "Label | Instruction" presets configured by admins.
     *
     * @return array<int, array{label: string, instruction: string}>
     */
    public static function parseQuickPrompts(string $raw): array
    {
        $lines = preg_split('/\R+/', trim($raw)) ?: array();
        $presets = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, '|')) {
                [$labelRaw, $instructionRaw] = explode('|', $line, 2);
                $label = trim($labelRaw);
                $instruction = trim($instructionRaw);
                if ($instruction === '') {
                    continue;
                }
                if ($label === '') {
                    $label = $instruction;
                }
            } else {
                $label = $line;
                $instruction = $line;
            }

            if (function_exists('mb_strlen') && mb_strlen($label, 'UTF-8') > 40) {
                $label = rtrim(mb_substr($label, 0, 39, 'UTF-8')) . '…';
            } elseif (strlen($label) > 40) {
                $label = rtrim(substr($label, 0, 39)) . '...';
            }

            $presets[] = array(
                'label' => $label,
                'instruction' => $instruction,
            );
            if (count($presets) >= 8) {
                break;
            }
        }

        return $presets;
    }

    public static function isInstanceAllowedForTicket(AIResponseGeneratorPluginConfig $cfg, Ticket $ticket): bool
    {
        $raw = trim((string) $cfg->get('department_ids'));
        if ($raw === '') {
            return true;
        }
        $deptId = method_exists($ticket, 'getDeptId') ? (int) $ticket->getDeptId() : 0;
        if ($deptId <= 0) {
            return true;
        }
        $allowed = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw) ?: array()));

        return $allowed === array() || in_array($deptId, $allowed, true);
    }

    /**
     * Inject AI items into the ticket "More" dropdown menu.
     *
     * @param mixed $ticket
     * @param mixed $data
     */
    public function onTicketViewMore($ticket, &$data): void
    {
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isStaff()) {
            return;
        }
        if (!$ticket instanceof Ticket || !method_exists($ticket, 'getId')) {
            return;
        }
        $role = $ticket->getRole($thisstaff);
        if (!$role) {
            return;
        }
        $canReply = $role->hasPerm(Ticket::PERM_REPLY);

        static $rendered = array();
        $ticketId = (int) $ticket->getId();
        $configs = self::getAllConfigs();
        if ($configs === array()) {
            return;
        }

        foreach ($configs as $iid => $cfg) {
            $cacheKey = $ticketId . ':' . $iid;
            if (isset($rendered[$cacheKey])) {
                continue;
            }
            $rendered[$cacheKey] = true;

            if (!self::isInstanceAllowedForTicket($cfg, $ticket)) {
                continue;
            }

            $inst = $cfg->getInstance();
            $name = $inst ? (string) $inst->getName() : ('Instance ' . $iid);

            if ($canReply) {
                echo sprintf(
                    '<li><a class="ai-generate-reply" href="#ai/generate" '
                    . 'data-action="reply" data-ticket-id="%d" data-instance-id="%d">'
                    . '<i class="icon-magic"></i> %s — %s</a></li>',
                    $ticketId,
                    (int) $iid,
                    Format::htmlchars(__('AI Reply')),
                    Format::htmlchars($name)
                );
            }

            if ($cfg->get('enable_summarize', true)) {
                echo sprintf(
                    '<li><a class="ai-generate-reply" href="#ai/summarize" '
                    . 'data-action="summarize" data-ticket-id="%d" data-instance-id="%d">'
                    . '<i class="icon-file-text-alt"></i> %s — %s</a></li>',
                    $ticketId,
                    (int) $iid,
                    Format::htmlchars(__('AI Summarize')),
                    Format::htmlchars($name)
                );
            }
        }
    }

    /**
     * Load plugin CSS/JS via scp/ajax.php (works for unpacked, renamed, and .phar installs)
     * and expose runtime configuration for the current ticket view.
     *
     * @param mixed $object
     * @param mixed $data
     */
    public function onObjectView($object, &$data): void
    {
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isStaff()) {
            return;
        }
        if (!$object instanceof Ticket || !method_exists($object, 'getId')) {
            return;
        }

        $role = $object->getRole($thisstaff);
        if (!$role) {
            return;
        }
        $canReply = $role->hasPerm(Ticket::PERM_REPLY);
        $canPostNote = true;

        $instances = array();
        foreach (self::getAllConfigs() as $iid => $cfg) {
            if (!self::isInstanceAllowedForTicket($cfg, $object)) {
                continue;
            }
            $inst = $cfg->getInstance();
            $instances[] = array(
                'id' => (int) $iid,
                'name' => $inst ? (string) $inst->getName() : ('Instance ' . $iid),
                'enableSummarize' => (bool) $cfg->get('enable_summarize', true),
                'enableRewrite' => (bool) $cfg->get('enable_rewrite', true),
                'enableStreaming' => (bool) $cfg->get('enable_streaming', false),
                'defaultTone' => (string) ($cfg->get('default_tone') ?: ''),
                'quickPrompts' => self::parseQuickPrompts((string) $cfg->get('quick_prompts')),
            );
        }

        if ($instances === array()) {
            return;
        }

        $assetVersion = $this->getAssetVersion();
        $ajaxBase = (defined('ROOT_PATH') ? ROOT_PATH : '/') . 'scp/ajax.php/ai';

        // Emit assets on PJAX navigations too: when the agent opens a ticket from a
        // queue via PJAX, the page has never loaded main.js/style.css. jquery-pjax
        // skips <script src> tags that are already present, so this is idempotent.
        static $assetsEmitted = false;
        if (!$assetsEmitted) {
            $assetsEmitted = true;
            $cssUrl = $ajaxBase . '/assets/style.css?v=' . rawurlencode($assetVersion);
            $jsUrl = $ajaxBase . '/assets/main.js?v=' . rawurlencode($assetVersion);
            echo sprintf('<link rel="stylesheet" type="text/css" href="%s"/>', Format::htmlchars($cssUrl));
            echo sprintf('<script type="text/javascript" src="%s"></script>', Format::htmlchars($jsUrl));
        }

        $bootstrapConfig = array(
            'ajaxBase' => $ajaxBase,
            'ticketId' => (int) $object->getId(),
            'canReply' => (bool) $canReply,
            'canPostNote' => (bool) $canPostNote,
            'isAdmin' => method_exists($thisstaff, 'isAdmin') ? (bool) $thisstaff->isAdmin() : false,
            'instances' => $instances,
        );
        $json = json_encode($bootstrapConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $encodedJson = $json !== false ? $json : '{}';
        echo '<script type="text/javascript">'
            . 'window.AIResponseGen = ' . $encodedJson . ';'
            . 'if (window.AIResponseGen && typeof window.AIResponseGenInit === "function") {'
            . 'window.AIResponseGenInit();'
            . '}'
            . '</script>';
    }

    /**
     * Register SCP AJAX endpoints once per request.
     *
     * @param mixed $dispatcher
     */
    public function onAjaxScp($dispatcher): void
    {
        if (self::$ajax_registered) {
            return;
        }
        self::$ajax_registered = true;

        require_once __DIR__ . '/AIAjax.php';
        $dispatcher->append(url_post('^/ai/response$', array('AIAjaxController', 'generate')));
        $dispatcher->append(url_post('^/ai/stream$', array('AIAjaxController', 'stream')));
        $dispatcher->append(url_post('^/ai/test$', array('AIAjaxController', 'testConnection')));
        $dispatcher->append(url_get('^/ai/assets/main\.js$', array('AIAjaxController', 'assetJs')));
        $dispatcher->append(url_get('^/ai/assets/style\.css$', array('AIAjaxController', 'assetCss')));
    }

    private function getAssetVersion(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }
        $manifest = __DIR__ . '/../plugin.php';
        if (is_file($manifest)) {
            $meta = include $manifest;
            if (is_array($meta) && !empty($meta['version'])) {
                $version = (string) $meta['version'];

                return $version;
            }
        }
        $version = defined('GIT_VERSION') ? (string) GIT_VERSION : '0.0.1';

        return $version;
    }
}
