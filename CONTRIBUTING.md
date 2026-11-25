# Contributing

Thanks for helping out. Bug reports and feature requests go through the [issue templates](https://github.com/mhajder/ai-response-generator/issues/new/choose). For security problems, follow [SECURITY.md](SECURITY.md) instead.

## Development setup

```sh
git clone https://github.com/mhajder/ai-response-generator.git
cd ai-response-generator
mise install
composer install
pnpm install
prek install
```

[prek](https://prek.j178.dev/) runs PHP syntax/style/static checks (`php -l`, PHPCS, PHPStan, PHPUnit), ESLint, Prettier, `node --test`, and `typos` on commit, and checks commit messages with Commitizen.

## Before opening a pull request

```sh
composer lint
composer cs-check
composer stan
composer test
pnpm test
pnpm run check
php -dphar.readonly=0 scripts/build-package.php
```

- Add or update PHPUnit tests in `tests/Unit/` or JS tests in `tests/js/` for the change.
- Update [README.md](README.md) when you add a setting, provider, or change behavior.
- To spin up a local osTicket 1.18 dev instance with the plugin mounted, run `docker compose -f compose.dev.yml up -d`.

## Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/), for example `feat(client): add gemini provider support` or `fix(ui): preserve draft formatting on insert`. Pull requests are squash-merged, and `release-please` builds the changelog and the next version from them.

## License

By contributing, you agree that your contributions are licensed under the project's MIT license. See [LICENSE](LICENSE).
