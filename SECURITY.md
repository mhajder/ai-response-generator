# Security Policy

## Supported versions

Only the latest release gets security fixes. If you can, check that the issue still happens on it before reporting.

## Reporting a vulnerability

Please do not open a public issue. Report it privately through [GitHub private vulnerability reporting](https://github.com/mhajder/ai-response-generator/security/advisories/new).

Include the affected plugin version, osTicket version, PHP version, how to reproduce it, and what an attacker could do with it. Do not send real API keys, tokens, or customer ticket data; redact them.

I aim to acknowledge a report within 7 days and will keep you updated until it is fixed and released.

## Scope

In scope is this plugin (`ai-response-generator`), for example:

- API keys or credentials leaking into responses, logs, or UI markup
- missing or bypassable staff authentication, department checks, or CSRF validation on `scp/ajax.php/ai/*`
- XSS or HTML injection via ticket content, templates, or Redactor editor insertion
- SSRF or unsafe request handling in the AI client or AJAX controller

Vulnerabilities in osTicket core itself belong with the [osTicket project](https://github.com/osTicket/osTicket/security).
