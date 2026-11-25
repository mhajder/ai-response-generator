<?php

/*********************************************************************
 * AI Response Generator Ajax Controller
 *********************************************************************/

declare(strict_types=1);

if (!class_exists('AjaxController')) {
    require_once INCLUDE_DIR . 'class.ajax.php';
}
if (!class_exists('Ticket')) {
    require_once INCLUDE_DIR . 'class.ticket.php';
}
if (!class_exists('Thread')) {
    $threadFile = INCLUDE_DIR . 'class.thread.php';
    if (is_file($threadFile)) {
        require_once $threadFile;
    }
}
require_once __DIR__ . '/../api/OpenAIClient.php';
require_once __DIR__ . '/PromptBuilder.php';
require_once __DIR__ . '/TicketContextBuilder.php';

class AIAjaxController extends AjaxController
{
    public function assetJs(): string
    {
        return $this->serveAsset('main.js', 'application/javascript; charset=UTF-8');
    }

    public function assetCss(): string
    {
        return $this->serveAsset('style.css', 'text/css; charset=UTF-8');
    }

    private function serveAsset(string $file, string $contentType): string
    {
        $this->staffOnly();

        $path = __DIR__ . '/../assets/' . ($file === 'main.js' ? 'js/main.js' : 'css/style.css');
        if (!is_file($path)) {
            Http::response(404, 'Asset not found');

            return '';
        }

        $content = file_get_contents($path);
        if ($content === false) {
            Http::response(500, 'Failed to read asset');

            return '';
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Cache-Control: private, max-age=3600');
            header('X-Content-Type-Options: nosniff');
        }

        return $content;
    }

    /**
     * Generate reply, ticket summary, or rewritten draft via configured AI provider.
     */
    public function generate(): string
    {
        global $thisstaff;
        $this->staffOnly();

        $prepared = $this->prepareRequest();
        if (isset($prepared['error'])) {
            return $this->jsonError((int) $prepared['status'], (string) $prepared['error']);
        }

        $pluginCfg = $prepared['plugin_cfg'];
        $ticket = $prepared['ticket'];
        $action = $prepared['action'];
        $asHtml = $prepared['as_html'];
        $context = $prepared['context'];
        $messages = $prepared['messages'];
        $model = $prepared['model'];
        $temperature = $prepared['temperature'];
        $maxTokens = $prepared['max_tokens'];

        try {
            $client = $this->createClient($pluginCfg, $prepared['api_url'], $prepared['api_key']);
            $result = $client->generateResponseWithMeta($model, $messages, $temperature, $maxTokens);
            $reply = $this->finalizeReply($result['text'], $action, $asHtml, $pluginCfg, $context['metadata'], $thisstaff);

            $this->logDiagnostic(
                $pluginCfg,
                $ticket,
                $thisstaff,
                $action,
                $result['provider'],
                $model,
                $result['latency_ms'],
                $result['usage']
            );

            return $this->jsonResponse(200, array(
                'ok' => true,
                'action' => $action,
                'format' => $asHtml ? 'html' : 'text',
                'text' => $reply,
                'meta' => $this->buildResponseMeta($model, $result, $context, (bool) $prepared['redact_pii']),
            ));
        } catch (Throwable $e) {
            $this->logDiagnosticError($pluginCfg, $ticket, $action, $model, $e->getMessage());
            $status = ($e instanceof AIClientException && $e->getStatusCode() >= 400)
                ? 502
                : 500;

            return $this->jsonError($status, $e->getMessage());
        }
    }

    /**
     * Stream AI reply tokens as Server-Sent Events (SSE).
     */
    public function stream(): string
    {
        global $thisstaff;
        $this->staffOnly();

        $prepared = $this->prepareRequest();
        if (isset($prepared['error'])) {
            return $this->jsonError((int) $prepared['status'], (string) $prepared['error']);
        }

        $pluginCfg = $prepared['plugin_cfg'];
        if (!$pluginCfg->get('enable_streaming', false)) {
            return $this->generate();
        }

        $ticket = $prepared['ticket'];
        $action = $prepared['action'];
        $asHtml = $prepared['as_html'];
        $context = $prepared['context'];
        $messages = $prepared['messages'];
        $model = $prepared['model'];
        $temperature = $prepared['temperature'];
        $maxTokens = $prepared['max_tokens'];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=UTF-8');
            header('Cache-Control: no-cache, no-transform');
            header('Content-Encoding: none');
            header('X-Accel-Buffering: no');
        }

        try {
            $client = $this->createClient($pluginCfg, $prepared['api_url'], $prepared['api_key']);
            $result = $client->streamResponse(
                $model,
                $messages,
                static function (string $delta): void {
                    $payload = json_encode(array('delta' => $delta), JSON_UNESCAPED_UNICODE);
                    if ($payload !== false) {
                        echo "event: delta\n";
                        echo 'data: ' . $payload . "\n\n";
                        @ob_flush();
                        flush();
                    }
                },
                $temperature,
                $maxTokens
            );

            $reply = $this->finalizeReply($result['text'], $action, $asHtml, $pluginCfg, $context['metadata'], $thisstaff);
            $this->logDiagnostic(
                $pluginCfg,
                $ticket,
                $thisstaff,
                $action,
                $result['provider'],
                $model,
                $result['latency_ms'],
                $result['usage']
            );

            $donePayload = json_encode(
                array(
                    'ok' => true,
                    'action' => $action,
                    'format' => $asHtml ? 'html' : 'text',
                    'text' => $reply,
                    'meta' => $this->buildResponseMeta($model, $result, $context, (bool) $prepared['redact_pii']),
                ),
                JSON_UNESCAPED_UNICODE
            );
            echo "event: done\n";
            echo 'data: ' . ($donePayload !== false ? $donePayload : '{}') . "\n\n";
            @ob_flush();
            flush();
        } catch (Throwable $e) {
            $this->logDiagnosticError($pluginCfg, $ticket, $action, $model, $e->getMessage());
            $errPayload = json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
            echo "event: error\n";
            echo 'data: ' . ($errPayload !== false ? $errPayload : '{"ok":false}') . "\n\n";
            @ob_flush();
            flush();
        }

        if (PHP_SAPI !== 'cli') {
            exit;
        }

        return '';
    }

    /**
     * Test API connectivity for a plugin instance (Admin only).
     */
    public function testConnection(): string
    {
        global $thisstaff;
        $this->staffOnly();

        if (!$this->validateCsrf()) {
            return $this->jsonError(403, __('Valid CSRF token required.'));
        }
        if (!$thisstaff || !method_exists($thisstaff, 'isAdmin') || !$thisstaff->isAdmin()) {
            return $this->jsonError(403, __('Administrator privileges are required to test plugin connections.'));
        }

        $pluginCfg = $this->resolvePluginConfig((int) ($_POST['instance_id'] ?? $_GET['instance_id'] ?? 0));
        if (!$pluginCfg) {
            return $this->jsonError(500, __('Plugin is not configured.'));
        }

        $apiUrl = trim((string) $pluginCfg->get('api_url'));
        $apiKey = $this->resolveApiKey($pluginCfg);
        $model = trim((string) $pluginCfg->get('model'));

        if ($apiUrl === '' || $model === '') {
            return $this->jsonError(400, __('Missing API URL or model name.'));
        }

        try {
            $client = $this->createClient($pluginCfg, $apiUrl, $apiKey);
            $result = $client->testConnection($model);

            return $this->jsonResponse(200, array(
                'ok' => true,
                'provider' => $client->getProvider(),
                'latency_ms' => $result['latency_ms'],
                'reply' => $result['reply'],
            ));
        } catch (Throwable $e) {
            return $this->jsonError(502, $e->getMessage());
        }
    }

    /**
     * Validate request, permissions, and build prompt messages for generate/stream.
     *
     * @return array<string, mixed>
     */
    private function prepareRequest(): array
    {
        global $thisstaff, $cfg;

        if (!$this->validateCsrf()) {
            return array('status' => 403, 'error' => __('Valid CSRF token required. Please refresh the page.'));
        }

        $ticketId = (int) ($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        $ticket = $ticketId > 0 ? Ticket::lookup($ticketId) : null;
        if (!$ticket instanceof Ticket) {
            return array('status' => 404, 'error' => __('Unknown ticket.'));
        }

        $action = strtolower(trim((string) ($_POST['action'] ?? PromptBuilder::ACTION_REPLY)));
        $validActions = array(PromptBuilder::ACTION_REPLY, PromptBuilder::ACTION_SUMMARIZE, PromptBuilder::ACTION_REWRITE);
        if (!in_array($action, $validActions, true)) {
            return array('status' => 400, 'error' => __('Unsupported AI action.'));
        }

        // Ticket::getRole() always returns a (fallback) Role for any staff member,
        // so explicitly verify the agent can actually access this ticket.
        if (!$ticket->checkStaffPerm($thisstaff)) {
            return array('status' => 403, 'error' => __('Access denied: insufficient ticket permissions.'));
        }

        $role = $ticket->getRole($thisstaff);
        if (!$role) {
            return array('status' => 403, 'error' => __('Access denied: insufficient ticket permissions.'));
        }
        $hasReply = $role->hasPerm(Ticket::PERM_REPLY);
        if ($action !== PromptBuilder::ACTION_SUMMARIZE && !$hasReply) {
            return array('status' => 403, 'error' => __('Access denied: missing permission to reply to this ticket.'));
        }

        $pluginCfg = $this->resolvePluginConfig((int) ($_POST['instance_id'] ?? $_GET['instance_id'] ?? 0));
        if (!$pluginCfg) {
            return array('status' => 500, 'error' => __('Plugin is not configured.'));
        }

        if (!AIResponseGeneratorPlugin::isInstanceAllowedForTicket($pluginCfg, $ticket)) {
            return array('status' => 403, 'error' => __('This AI profile is not enabled for the ticket department.'));
        }

        if ($action === PromptBuilder::ACTION_SUMMARIZE && !$pluginCfg->get('enable_summarize', true)) {
            return array('status' => 403, 'error' => __('Ticket summarization is disabled for this AI profile.'));
        }
        if ($action === PromptBuilder::ACTION_REWRITE && !$pluginCfg->get('enable_rewrite', true)) {
            return array('status' => 403, 'error' => __('Draft rewriting is disabled for this AI profile.'));
        }

        $draftText = trim((string) ($_POST['draft_text'] ?? ''));
        if ($action === PromptBuilder::ACTION_REWRITE && $draftText === '') {
            return array('status' => 400, 'error' => __('Please enter some draft text in the editor before rewriting.'));
        }

        $apiUrl = trim((string) $pluginCfg->get('api_url'));
        $apiKey = $this->resolveApiKey($pluginCfg);
        $model = trim((string) $pluginCfg->get('model'));
        if ($apiUrl === '' || $model === '') {
            return array('status' => 400, 'error' => __('Missing API URL or model name in plugin configuration.'));
        }

        $richTextEnabled = is_object($cfg) && method_exists($cfg, 'isRichTextEnabled') && $cfg->isRichTextEnabled();
        $outputFormatSetting = strtolower(trim((string) ($pluginCfg->get('output_format') ?: 'auto')));
        $asHtml = match ($outputFormatSetting) {
            'html' => true,
            'text' => false,
            default => (bool) $richTextEnabled,
        };

        $context = TicketContextBuilder::build(
            $ticket,
            (bool) $pluginCfg->get('include_kb_faqs', false),
            (bool) $pluginCfg->get('include_custom_fields', true),
            (bool) $pluginCfg->get('include_canned_responses', false)
        );

        $tone = trim((string) ($_POST['tone'] ?? ''));
        if ($tone === '') {
            $tone = trim((string) ($pluginCfg->get('default_tone') ?: ''));
        }

        $redactPii = (bool) $pluginCfg->get('redact_pii', false);
        $messages = PromptBuilder::buildMessages($context, array(
            'action' => $action,
            'system_prompt' => (string) $pluginCfg->get('system_prompt'),
            'summary_prompt' => (string) $pluginCfg->get('summary_prompt'),
            'rag_content' => (string) $pluginCfg->get('rag_content'),
            'max_thread_entries' => (int) ($pluginCfg->get('max_thread_entries') ?: 20),
            'include_internal_notes' => (bool) $pluginCfg->get('include_internal_notes', false),
            'include_ticket_metadata' => (bool) $pluginCfg->get('include_ticket_metadata', true),
            'redact_pii' => $redactPii,
            'output_html' => $asHtml,
            'tone' => $tone,
            'custom_instruction' => (string) ($_POST['custom_instruction'] ?? ''),
            'draft_text' => $draftText,
            'rewrite_mode' => (string) ($_POST['rewrite_mode'] ?? 'improve'),
            'target_language' => (string) ($_POST['target_language'] ?? ''),
        ));

        $temperatureRaw = trim((string) $pluginCfg->get('temperature'));

        return array(
            'plugin_cfg' => $pluginCfg,
            'ticket' => $ticket,
            'action' => $action,
            'as_html' => $asHtml,
            'redact_pii' => $redactPii,
            'context' => $context,
            'messages' => $messages,
            'api_url' => $apiUrl,
            'api_key' => $apiKey,
            'model' => $model,
            'temperature' => $temperatureRaw !== '' ? (float) $temperatureRaw : 0.2,
            'max_tokens' => (int) ($pluginCfg->get('max_tokens') ?: 1500),
        );
    }

    /**
     * @param array<string, string> $metadata
     */
    private function finalizeReply(
        string $rawText,
        string $action,
        bool $asHtml,
        AIResponseGeneratorPluginConfig $pluginCfg,
        array $metadata,
        mixed $staff
    ): string {
        $reply = PromptBuilder::formatOutputForEditor($rawText, $asHtml);
        if ($reply === '') {
            throw new AIClientException(__('Empty response returned by the AI model.'));
        }

        if ($action === PromptBuilder::ACTION_REPLY) {
            $template = trim((string) $pluginCfg->get('response_template'));
            if ($template !== '') {
                $agentName = (is_object($staff) && method_exists($staff, 'getName'))
                    ? (string) $staff->getName()
                    : '';
                $reply = PromptBuilder::expandTemplate(
                    $template,
                    array_merge($metadata, array(
                        'ai_text' => $reply,
                        'agent_name' => $agentName,
                    )),
                    $asHtml
                );
            }
        }

        return $reply;
    }

    /**
     * @param array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null} $usage
     */
    private function logDiagnostic(
        AIResponseGeneratorPluginConfig $pluginCfg,
        Ticket $ticket,
        mixed $staff,
        string $action,
        string $provider,
        string $model,
        int $latencyMs,
        array $usage
    ): void {
        if (!$pluginCfg->get('enable_debug_log', false)) {
            return;
        }
        global $ost;
        if (!is_object($ost) || !method_exists($ost, 'logDebug')) {
            return;
        }
        $staffId = (is_object($staff) && method_exists($staff, 'getId')) ? (int) $staff->getId() : 0;
        $ost->logDebug(
            'AI Response Generator',
            sprintf(
                'ticket=%d staff=%d action=%s provider=%s model=%s latency_ms=%d in_tokens=%s out_tokens=%s',
                (int) $ticket->getId(),
                $staffId,
                $action,
                $provider,
                $model,
                $latencyMs,
                $usage['input_tokens'] !== null ? (string) $usage['input_tokens'] : 'n/a',
                $usage['output_tokens'] !== null ? (string) $usage['output_tokens'] : 'n/a'
            ),
            true
        );
    }

    private function logDiagnosticError(
        AIResponseGeneratorPluginConfig $pluginCfg,
        Ticket $ticket,
        string $action,
        string $model,
        string $error
    ): void {
        if (!$pluginCfg->get('enable_debug_log', false)) {
            return;
        }
        global $ost;
        if (!is_object($ost) || !method_exists($ost, 'logWarning')) {
            return;
        }
        $ost->logWarning(
            'AI Response Generator Error',
            sprintf(
                'ticket=%d action=%s model=%s error=%s',
                (int) $ticket->getId(),
                $action,
                $model,
                substr($error, 0, 300)
            ),
            true
        );
    }

    /**
     * @param array{
     *   provider: string,
     *   latency_ms: int,
     *   usage: array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     * } $result
     * @param array<string, mixed> $context
     *
     * @return array{
     *   model: string,
     *   provider: string,
     *   latency_ms: int,
     *   usage: array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null},
     *   redacted_pii: bool,
     *   sources: array{faqs: string[], canned: string[]}
     * }
     */
    private function buildResponseMeta(string $model, array $result, array $context, bool $redactPii): array
    {
        $faqTitles = array();
        if (!empty($context['faqs']) && is_array($context['faqs'])) {
            foreach ($context['faqs'] as $faq) {
                $q = is_array($faq) ? trim((string) ($faq['question'] ?? '')) : '';
                if ($q !== '') {
                    $faqTitles[] = $q;
                }
            }
        }

        $cannedTitles = array();
        if (!empty($context['canned']) && is_array($context['canned'])) {
            foreach ($context['canned'] as $item) {
                $t = is_array($item) ? trim((string) ($item['title'] ?? '')) : '';
                if ($t !== '') {
                    $cannedTitles[] = $t;
                }
            }
        }

        return array(
            'model' => $model,
            'provider' => $result['provider'],
            'latency_ms' => $result['latency_ms'],
            'usage' => $result['usage'],
            'redacted_pii' => $redactPii,
            'sources' => array(
                'faqs' => $faqTitles,
                'canned' => $cannedTitles,
            ),
        );
    }

    private function resolveApiKey(AIResponseGeneratorPluginConfig $pluginCfg): string
    {
        return AIResponseGeneratorPluginConfig::decryptApiKey(
            (string) $pluginCfg->get('api_key'),
            $pluginCfg->getNamespace()
        );
    }

    private function createClient(AIResponseGeneratorPluginConfig $pluginCfg, string $apiUrl, string $apiKey): OpenAIClient
    {
        return new OpenAIClient($apiUrl, $apiKey, array(
            'provider' => (string) ($pluginCfg->get('provider') ?: 'auto'),
            'timeout' => (int) ($pluginCfg->get('timeout') ?: 45),
            'reasoning_effort' => (string) ($pluginCfg->get('reasoning_effort') ?: ''),
        ));
    }

    private function resolvePluginConfig(int $instanceId): ?AIResponseGeneratorPluginConfig
    {
        if ($instanceId > 0) {
            $all = AIResponseGeneratorPlugin::getAllConfigs();
            if (isset($all[$instanceId])) {
                return $all[$instanceId];
            }
        }

        return AIResponseGeneratorPlugin::getActiveConfig();
    }

    private function validateCsrf(): bool
    {
        global $ost;
        if (!is_object($ost)) {
            return false;
        }

        if (method_exists($ost, 'validateCSRFToken')) {
            $tokenName = '__CSRFToken__';
            if (method_exists($ost, 'getCSRF')) {
                $csrf = $ost->getCSRF();
                if (is_object($csrf) && method_exists($csrf, 'getTokenName')) {
                    $tokenName = (string) $csrf->getTokenName();
                }
            }
            $token = $_POST[$tokenName] ?? $_POST['__CSRFToken__'] ?? $_SERVER['HTTP_X_CSRFTOKEN'] ?? '';

            return is_string($token) && $token !== '' && (bool) $ost->validateCSRFToken($token);
        }

        if (method_exists($ost, 'checkCSRFToken')) {
            return (bool) $ost->checkCSRFToken();
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(int $status, array $payload): string
    {
        $encoded = $this->encode($payload);
        if ($status >= 400 && class_exists('Http')) {
            Http::response($status, $encoded, 'application/json');

            return '';
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
        }

        return $encoded;
    }

    private function jsonError(int $status, string $message): string
    {
        return $this->jsonResponse($status, array(
            'ok' => false,
            'error' => $message,
        ));
    }
}
