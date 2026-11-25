<?php

return array(
    'id' =>           'ai-response-generator:osticket',
    'version' =>      '1.0.0', // x-release-please-version
    'name' =>         'AI Response Generator',
    'description' =>  'Adds AI-powered reply generation, ticket summarization, and draft rewriting to the osTicket agent panel.',
    'author' =>       'Mateusz Hajder',
    'ost_version' =>  '1.17',
    'plugin' =>       'src/AIResponsePlugin.php:AIResponseGeneratorPlugin',
    'include_path' => '',
    'url' =>          'https://github.com/mhajder/ai-response-generator',
);
