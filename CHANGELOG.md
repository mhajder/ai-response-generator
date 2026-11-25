# Changelog

## [1.0.0](https://github.com/mhajder/ai-response-generator/compare/v0.1.1...v1.0.0) (2026-11-25)


### ⚠ BREAKING CHANGES

* **backend:** Requires PHP 8.2+, replaces direct assets/.htaccess serving with scp/ajax.php/ai/assets/*, encrypts stored API keys, and restructures plugin configuration and AJAX endpoints.

### 🚀 Features

* **backend:** add multi-provider streaming, RAG context, PII redaction, and tests ([4a7d6ed](https://github.com/mhajder/ai-response-generator/commit/4a7d6ed686df57c37c15708a6da0a507893a2bc6))
* **ui:** stream rich-text previews, quick prompts, language picker, and draft safety ([53e7b5c](https://github.com/mhajder/ai-response-generator/commit/53e7b5c47bdc0e856884d62131f0fd8d405c0e14))


### 🐛 Bug Fixes

* **ui:** harden HTML tag stripping against nested tags ([49cfe02](https://github.com/mhajder/ai-response-generator/commit/49cfe029197c771928095bee7867348e724869e2))


### 📚 Documentation

* **readme:** update documentation and add plugin screenshots ([f48c756](https://github.com/mhajder/ai-response-generator/commit/f48c75655c0089affbb63f88f8649b397af75dad))


### 🧩 CI

* **github:** add CI workflows, Dependabot, templates, and community docs ([c566bb2](https://github.com/mhajder/ai-response-generator/commit/c566bb2a18698fe316695d76d99bb61fc0712b53))


### 📦 Build

* **deps:** pin Composer platform to PHP 8.2 and ignore PHPUnit majors ([b7eda53](https://github.com/mhajder/ai-response-generator/commit/b7eda536ebb05cd726915fba53b75b0e011904f2))
* **release:** add package builder script and release-please automation ([e83128f](https://github.com/mhajder/ai-response-generator/commit/e83128fa02f0322a32e26b0600599bf773f86ab3))
