<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
if (!defined('INCLUDE_DIR')) {
    require_once __DIR__ . '/phpstan-stubs.php';
}
require_once __DIR__ . '/../api/OpenAIClient.php';
require_once __DIR__ . '/../src/PromptBuilder.php';
require_once __DIR__ . '/../src/TicketContextBuilder.php';
require_once __DIR__ . '/../src/AIResponsePlugin.php';
require_once __DIR__ . '/../src/AIAjax.php';
