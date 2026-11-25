<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConfigAndAjaxTest extends TestCase
{
    protected function tearDown(): void
    {
        Ticket::$stubTicket = null;
        Http::$lastResponse = null;
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        unset($GLOBALS['ost'], $GLOBALS['thisstaff']);
    }

    public function testParseQuickPromptsHandlesLabelsAndCapsCount(): void
    {
        $raw = implode("\n", [
            'Ask for Logs | Ask customer for browser console logs and timestamps.',
            'Standalone concise instruction without pipe',
            ' | Empty label uses instruction',
            'Empty instruction | ',
            'Line 4 | Four',
            'Line 5 | Five',
            'Line 6 | Six',
            'Line 7 | Seven',
            'Line 8 | Eight',
            'Line 9 | Nine',
        ]);

        $presets = AIResponseGeneratorPlugin::parseQuickPrompts($raw);

        $this->assertCount(8, $presets);
        $this->assertSame('Ask for Logs', $presets[0]['label']);
        $this->assertSame('Ask customer for browser console logs and timestamps.', $presets[0]['instruction']);
        $this->assertSame('Standalone concise instruction without…', $presets[1]['label']);
        $this->assertSame('Standalone concise instruction without pipe', $presets[1]['instruction']);
        $this->assertSame('Empty label uses instruction', $presets[2]['label']);
    }

    public function testConfigPreSaveValidatesUrlsNumbersDepartmentIdsAndPreservesKey(): void
    {
        $cfg = new AIResponseGeneratorPluginConfig();
        $cfg->data['api_key'] = 'saved-encrypted-key';

        $badConfig = [
            'api_url' => 'ftp://invalid.local',
            'temperature' => '3.5',
            'max_tokens' => '12',
            'timeout' => '999',
            'max_thread_entries' => '0',
            'department_ids' => 'Support, IT',
            'api_key' => '',
        ];
        $errors = [];

        $this->assertFalse($cfg->pre_save($badConfig, $errors));
        $this->assertArrayHasKey('api_url', $errors);
        $this->assertArrayHasKey('temperature', $errors);
        $this->assertArrayHasKey('max_tokens', $errors);
        $this->assertArrayHasKey('timeout', $errors);
        $this->assertArrayHasKey('max_thread_entries', $errors);
        $this->assertArrayHasKey('department_ids', $errors);

        $goodConfig = [
            'api_url' => 'https://api.openai.com/v1',
            'temperature' => '0.4',
            'max_tokens' => '1000',
            'timeout' => '30',
            'max_thread_entries' => '15',
            'department_ids' => '1, 3',
            'api_key' => '',
        ];
        $goodErrors = [];
        $this->assertTrue($cfg->pre_save($goodConfig, $goodErrors));
        $this->assertSame([], $goodErrors);
        $this->assertSame('saved-encrypted-key', $goodConfig['api_key']);

        $clearConfig = $goodConfig;
        $clearConfig['clear_api_key'] = true;
        $clearErrors = [];
        $this->assertTrue($cfg->pre_save($clearConfig, $clearErrors));
        $this->assertSame('', $clearConfig['api_key']);
        $this->assertFalse($clearConfig['clear_api_key']);
    }

    public function testIsInstanceAllowedForTicketRespectsDepartmentIds(): void
    {
        $cfg = new AIResponseGeneratorPluginConfig();
        $ticket = new Ticket();
        $ticket->stubDeptId = 3;

        $this->assertTrue(AIResponseGeneratorPlugin::isInstanceAllowedForTicket($cfg, $ticket));

        $cfg->data['department_ids'] = '1, 3, 5';
        $this->assertTrue(AIResponseGeneratorPlugin::isInstanceAllowedForTicket($cfg, $ticket));

        $cfg->data['department_ids'] = '1, 2';
        $this->assertFalse(AIResponseGeneratorPlugin::isInstanceAllowedForTicket($cfg, $ticket));
    }

    public function testAjaxControllerEnforcesCsrfTicketLookupAndPermissions(): void
    {
        $controller = new AIAjaxController();

        $GLOBALS['ost'] = new class {
            public function validateCSRFToken(string $token): bool
            {
                return $token === 'valid-token';
            }
        };
        $GLOBALS['thisstaff'] = new class {
            public function isAdmin(): bool
            {
                return false;
            }
        };

        $_POST = ['ticket_id' => '1', 'action' => 'reply'];
        $controller->generate();
        $this->assertNotNull(Http::$lastResponse);
        $this->assertSame(403, Http::$lastResponse['code']);
        $this->assertStringContainsString('Valid CSRF token required', Http::$lastResponse['content']);

        Http::$lastResponse = null;
        $_POST['__CSRFToken__'] = 'valid-token';
        $controller->generate();
        $this->assertNotNull(Http::$lastResponse);
        $this->assertSame(404, Http::$lastResponse['code']);

        $ticket = new Ticket();
        $role = new Role();
        $role->allowReply = false;
        $ticket->stubRole = $role;
        Ticket::$stubTicket = $ticket;

        Http::$lastResponse = null;
        $controller->generate();
        $this->assertNotNull(Http::$lastResponse);
        $this->assertSame(403, Http::$lastResponse['code']);
        $this->assertStringContainsString('missing permission to reply', Http::$lastResponse['content']);

        Http::$lastResponse = null;
        $controller->testConnection();
        $this->assertNotNull(Http::$lastResponse);
        $this->assertSame(403, Http::$lastResponse['code']);
        $this->assertStringContainsString('Administrator privileges', Http::$lastResponse['content']);
    }
}
