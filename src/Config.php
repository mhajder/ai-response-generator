<?php

/*********************************************************************
 * AI Response Generator Plugin - Configuration
 *********************************************************************/

declare(strict_types=1);

if (!class_exists('FormField')) {
    require_once INCLUDE_DIR . 'class.forms.php';
}
require_once __DIR__ . '/../api/OpenAIClient.php';

class AIResponseGeneratorPluginConfig extends PluginConfig
{
    private const CRYPTO_NAMESPACE = 'ai-response-generator:osticket';

    public function getFormOptions(): array
    {
        return array(
            'title' => __('AI Response Generator Settings'),
            'instructions' => __(
                'Configure your AI provider, model parameters, prompt templates, and knowledge base context. '
                . 'Leave the API Key blank when updating settings to preserve the currently saved key.'
            ),
        );
    }

    public function getOptions(): array
    {
        return array(
            'section_provider' => new SectionBreakField(array(
                'label' => __('Provider & Connection'),
            )),
            'provider' => new ChoiceField(array(
                'label' => __('API Provider'),
                'default' => 'auto',
                'choices' => array(
                    'auto' => __('Auto-detect from API URL'),
                    'openai_chat' => __('OpenAI Chat Completions / Compatible (OpenRouter, vLLM, LM Studio)'),
                    'openai_responses' => __('OpenAI Responses API (/v1/responses)'),
                    'anthropic' => __('Anthropic Messages API (/v1/messages)'),
                    'ollama' => __('Ollama Native Chat (/api/chat)'),
                    'azure_openai' => __('Azure OpenAI Chat Completions'),
                ),
                'hint' => __('Select the wire protocol for your AI endpoint.'),
            )),
            'api_url' => new TextboxField(array(
                'label' => __('API URL'),
                'required' => true,
                'default' => 'https://api.openai.com/v1',
                'hint' => __(
                    'Base URL or full endpoint (e.g., https://api.openai.com/v1, '
                    . 'https://openrouter.ai/api/v1, http://localhost:11434, or https://api.anthropic.com).'
                ),
                'configuration' => array('size' => 80, 'length' => 512),
            )),
            'api_key' => new TextboxField(array(
                'widget' => 'PasswordWidget',
                'validator' => 'noop',
                'label' => __('API Key'),
                'required' => false,
                'hint' => __('API key or bearer token. Leave blank to keep the existing saved key.'),
                'configuration' => array('size' => 80, 'length' => 512),
            )),
            'clear_api_key' => new BooleanField(array(
                'label' => __('Clear Saved API Key'),
                'default' => false,
                'hint' => __('Check and save to remove the stored API key (useful for local unauthenticated Ollama).'),
            )),
            'model' => new TextboxField(array(
                'label' => __('Model Name'),
                'required' => true,
                'hint' => __('Model identifier (e.g., gpt-5-nano, gpt-4o-mini, claude-sonnet-4-5, llama3.3).'),
                'configuration' => array('size' => 80, 'length' => 255),
            )),
            'test_connection_on_save' => new BooleanField(array(
                'label' => __('Test Connection on Save'),
                'default' => false,
                'hint' => __('Check before saving to verify the API URL, key, and model directly from this settings page.'),
            )),

            'section_params' => new SectionBreakField(array(
                'label' => __('Model Parameters'),
            )),
            'temperature' => new TextboxField(array(
                'label' => __('Temperature'),
                'required' => false,
                'default' => '0.2',
                'hint' => __('Sampling temperature (0.0 - 2.0). Automatically omitted for GPT-5 / o-series reasoning models.'),
                'configuration' => array('size' => 12, 'length' => 10),
            )),
            'max_tokens' => new TextboxField(array(
                'label' => __('Max Output Tokens'),
                'required' => false,
                'default' => '1500',
                'hint' => __(
                    'Maximum tokens per response (64 - 32768). '
                    . 'Automatically mapped to max_completion_tokens on GPT-5 / o-series models.'
                ),
                'configuration' => array('size' => 12, 'length' => 10),
            )),
            'reasoning_effort' => new ChoiceField(array(
                'label' => __('Reasoning Effort'),
                'default' => '',
                'choices' => array(
                    '' => __('Provider Default'),
                    'low' => __('Low'),
                    'medium' => __('Medium'),
                    'high' => __('High'),
                ),
                'hint' => __('Optional reasoning effort hint for OpenAI GPT-5 / o-series models.'),
            )),
            'timeout' => new TextboxField(array(
                'label' => __('Request Timeout (seconds)'),
                'required' => false,
                'default' => '45',
                'hint' => __('HTTP timeout in seconds (5 - 300). Increase for reasoning models or large local LLMs.'),
                'configuration' => array('size' => 12, 'length' => 10),
            )),
            'output_format' => new ChoiceField(array(
                'label' => __('Output Format'),
                'default' => 'auto',
                'choices' => array(
                    'auto' => __('Auto (Match osTicket Rich Text setting)'),
                    'html' => __('HTML (for Redactor rich-text editor)'),
                    'text' => __('Plain Text'),
                ),
                'hint' => __('Controls how generated responses are formatted before insertion into the editor.'),
            )),
            'default_tone' => new ChoiceField(array(
                'label' => __('Default Reply Tone'),
                'default' => '',
                'choices' => array(
                    '' => __('Neutral / System Prompt Default'),
                    'professional' => __('Professional'),
                    'friendly' => __('Friendly'),
                    'concise' => __('Concise'),
                    'technical' => __('Technical'),
                ),
                'hint' => __('Preselected reply tone in the ticket toolbar.'),
            )),
            'enable_streaming' => new BooleanField(array(
                'label' => __('Enable Streaming Responses'),
                'default' => false,
                'hint' => __(
                    'Stream reply tokens live into the preview modal via SSE '
                    . '(disable if your reverse proxy buffers AJAX responses).'
                ),
            )),

            'section_context' => new SectionBreakField(array(
                'label' => __('Prompts, Context & Features'),
            )),
            'system_prompt' => new TextareaField(array(
                'label' => __('AI System Prompt'),
                'required' => false,
                'hint' => __('Optional system instruction sent to the model to steer tone, structure, and policy.'),
                'configuration' => array(
                    'rows' => 5,
                    'html' => false,
                    'placeholder' => __('You are a helpful support agent. Draft a concise, professional reply...'),
                ),
            )),
            'summary_prompt' => new TextareaField(array(
                'label' => __('Custom Summarize Prompt'),
                'required' => false,
                'hint' => __('Optional instructions used specifically when summarizing a ticket into an internal note.'),
                'configuration' => array(
                    'rows' => 4,
                    'html' => false,
                    'placeholder' => __('Summarize issue, root cause, customer impact, and next steps in bullet points.'),
                ),
            )),
            'response_template' => new TextareaField(array(
                'label' => __('Response Template'),
                'required' => false,
                'hint' => __(
                    'Optional template applied to generated replies. Use {ai_text} for the AI output. '
                    . 'Supported tokens: {ticket_number}, {ticket_subject}, {ticket_department}, '
                    . '{ticket_priority}, {ticket_status}, {user_name}, {user_email}, {user_org}, {agent_name}.'
                ),
                'configuration' => array(
                    'rows' => 5,
                    'html' => false,
                    'placeholder' => "Hello {user_name},\n\n{ai_text}\n\nBest regards,\n{agent_name}",
                ),
            )),
            'rag_content' => new TextareaField(array(
                'label' => __('Custom Knowledge Base / RAG Content'),
                'required' => false,
                'hint' => __('Paste static reference documentation, SOPs, or policy notes (up to 20,000 characters).'),
                'configuration' => array(
                    'rows' => 8,
                    'html' => false,
                    'placeholder' => __('Paste your RAG content here...'),
                ),
            )),
            'max_thread_entries' => new TextboxField(array(
                'label' => __('Max Recent Thread Entries'),
                'required' => false,
                'default' => '20',
                'hint' => __('Number of most recent thread entries sent to the AI (1 - 100).'),
                'configuration' => array('size' => 12, 'length' => 10),
            )),
            'include_ticket_metadata' => new BooleanField(array(
                'label' => __('Include Ticket Metadata'),
                'default' => true,
                'hint' => __('Include subject, department, help topic, priority, status, and customer organization.'),
            )),
            'include_custom_fields' => new BooleanField(array(
                'label' => __('Include Custom Form Fields'),
                'default' => true,
                'hint' => __('Include non-empty answers from custom osTicket ticket forms in the AI context.'),
            )),
            'include_internal_notes' => new BooleanField(array(
                'label' => __('Include Internal Notes'),
                'default' => false,
                'hint' => __('Include internal staff notes in context (clearly marked as internal-only so they are not quoted).'),
            )),
            'include_kb_faqs' => new BooleanField(array(
                'label' => __('Include Matching osTicket FAQs'),
                'default' => false,
                'hint' => __('Search published osTicket Knowledge Base FAQs by ticket keywords and inject top matches.'),
            )),
            'include_canned_responses' => new BooleanField(array(
                'label' => __('Include Matching Canned Responses'),
                'default' => false,
                'hint' => __('Search enabled osTicket Canned Responses by ticket keywords and inject top matches.'),
            )),
            'enable_summarize' => new BooleanField(array(
                'label' => __('Enable Ticket Summarization'),
                'default' => true,
                'hint' => __('Allow agents to generate a structured ticket summary for internal notes.'),
            )),
            'enable_rewrite' => new BooleanField(array(
                'label' => __('Enable Draft Rewrite & Translate'),
                'default' => true,
                'hint' => __('Allow agents to polish, shorten, change tone, or translate an existing draft.'),
            )),
            'redact_pii' => new BooleanField(array(
                'label' => __('Redact PII Before Sending'),
                'default' => false,
                'hint' => __(
                    'Mask email addresses, phone numbers, credit card numbers, and IPv4 addresses before sending '
                    . 'ticket context to external AI providers.'
                ),
            )),
            'quick_prompts' => new TextareaField(array(
                'label' => __('Quick Action Presets'),
                'required' => false,
                'hint' => __(
                    'Optional one-click prompt presets (up to 8, one per line as "Label | Instruction"). '
                    . 'Shown in the ticket toolbar and refine bar.'
                ),
                'configuration' => array(
                    'rows' => 4,
                    'html' => false,
                    'placeholder' => "Ask for Logs | Ask the customer for exact error logs, timestamps, and reproduction steps.\n"
                        . "Escalate Tier 2 | Draft a polite holding reply stating this has been escalated to Tier 2 engineering.",
                ),
            )),
            'enable_debug_log' => new BooleanField(array(
                'label' => __('Enable Diagnostic Logging'),
                'default' => false,
                'hint' => __('Log AI request metadata, latency, and token usage (no PII or API keys) to osTicket system logs.'),
            )),
            'department_ids' => new TextboxField(array(
                'label' => __('Allowed Department IDs'),
                'required' => false,
                'hint' => __(
                    'Optional comma-separated department IDs (e.g., 1,3) to restrict this instance. '
                    . 'Leave empty for all departments.'
                ),
                'configuration' => array('size' => 40, 'length' => 255),
            )),
        );
    }

    /**
     * Validate numeric/URL settings and preserve saved API key when left blank.
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $errors
     */
    public function pre_save(&$config, &$errors): bool
    {
        $apiUrl = trim((string) ($config['api_url'] ?? ''));
        if ($apiUrl !== '') {
            $scheme = strtolower((string) parse_url($apiUrl, PHP_URL_SCHEME));
            if (filter_var($apiUrl, FILTER_VALIDATE_URL) === false || !in_array($scheme, array('http', 'https'), true)) {
                $errors['api_url'] = __('Please enter a valid HTTP or HTTPS URL.');
            }
        }

        $tempRaw = trim((string) ($config['temperature'] ?? ''));
        if ($tempRaw !== '') {
            if (!is_numeric($tempRaw) || (float) $tempRaw < 0.0 || (float) $tempRaw > 2.0) {
                $errors['temperature'] = __('Temperature must be between 0.0 and 2.0.');
            }
        }

        $maxTokensRaw = trim((string) ($config['max_tokens'] ?? ''));
        if ($maxTokensRaw !== '') {
            if (!ctype_digit($maxTokensRaw) || (int) $maxTokensRaw < 64 || (int) $maxTokensRaw > 32768) {
                $errors['max_tokens'] = __('Max output tokens must be an integer between 64 and 32768.');
            }
        }

        $timeoutRaw = trim((string) ($config['timeout'] ?? ''));
        if ($timeoutRaw !== '') {
            if (!ctype_digit($timeoutRaw) || (int) $timeoutRaw < 5 || (int) $timeoutRaw > 300) {
                $errors['timeout'] = __('Timeout must be an integer between 5 and 300 seconds.');
            }
        }

        $entriesRaw = trim((string) ($config['max_thread_entries'] ?? ''));
        if ($entriesRaw !== '') {
            if (!ctype_digit($entriesRaw) || (int) $entriesRaw < 1 || (int) $entriesRaw > 100) {
                $errors['max_thread_entries'] = __('Max thread entries must be an integer between 1 and 100.');
            }
        }

        $deptRaw = trim((string) ($config['department_ids'] ?? ''));
        if ($deptRaw !== '') {
            $validFormat = preg_match('/^[\d\s,]+$/', $deptRaw) === 1;
            $deptIds = $validFormat
                ? array_filter(array_map('intval', preg_split('/[\s,]+/', $deptRaw) ?: array()), static fn (int $id): bool => $id > 0)
                : array();
            if (!$validFormat || $deptIds === array()) {
                $errors['department_ids'] = __('Allowed Department IDs must be comma-separated positive integers (e.g., 1,3).');
            }
        }

        if (!empty($config['clear_api_key'])) {
            $config['api_key'] = '';
            $config['clear_api_key'] = false;
        } else {
            $submittedKey = trim((string) ($config['api_key'] ?? ''));
            if ($submittedKey === '') {
                $existing = (string) $this->get('api_key');
                if ($existing !== '') {
                    $config['api_key'] = $existing;
                }
            } elseif (empty($errors) && class_exists('Crypto') && defined('SECRET_SALT')) {
                $encrypted = Crypto::encrypt($submittedKey, SECRET_SALT, self::CRYPTO_NAMESPACE);
                if (is_string($encrypted) && $encrypted !== '') {
                    $config['api_key'] = $encrypted;
                }
            }
        }

        if (!empty($errors)) {
            return false;
        }

        if (!empty($config['test_connection_on_save'])) {
            $config['test_connection_on_save'] = false;
            $model = trim((string) ($config['model'] ?? ''));
            if ($apiUrl === '' || $model === '') {
                $errors['api_url'] = __('API URL and Model Name are required to test the connection.');

                return false;
            }
            $plainKey = self::decryptApiKey((string) ($config['api_key'] ?? ''), $this->getNamespace());
            try {
                $client = new OpenAIClient($apiUrl, $plainKey, array(
                    'provider' => (string) ($config['provider'] ?? 'auto'),
                    'timeout' => (int) ($config['timeout'] ?: 45),
                    'reasoning_effort' => (string) ($config['reasoning_effort'] ?? ''),
                ));
                $res = $client->testConnection($model);
                global $ost;
                if (is_object($ost) && method_exists($ost, 'logDebug')) {
                    $ost->logDebug(
                        'AI Response Generator',
                        sprintf('Connection test succeeded (%s, %d ms)', $client->getProvider(), $res['latency_ms']),
                        true
                    );
                }
            } catch (Throwable $e) {
                $errors['api_url'] = __('Connection test failed: ') . $e->getMessage();

                return false;
            }
        }

        return parent::pre_save($config, $errors);
    }

    public static function decryptApiKey(string $stored, string $namespace = ''): string
    {
        if ($stored === '') {
            return '';
        }
        if (class_exists('Crypto') && defined('SECRET_SALT')) {
            foreach (array_unique(array_filter(array(self::CRYPTO_NAMESPACE, $namespace))) as $ctx) {
                $decrypted = Crypto::decrypt($stored, SECRET_SALT, $ctx);
                if (is_string($decrypted) && $decrypted !== '') {
                    return $decrypted;
                }
            }
        }

        return $stored;
    }
}
