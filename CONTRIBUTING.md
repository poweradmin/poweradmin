# Contributing to Poweradmin

Thank you for your interest in contributing to Poweradmin! We welcome contributions to improve the project.

## Where to Start

- **Bug reports and small UI fixes** are always welcome.
- **Translations**: edit the `.po` file for your language and open a pull request - see [Translations](#translations). No PHP knowledge required.
- **Larger features**: open an issue first to discuss the approach before writing code.

## Project Architecture

Poweradmin follows Domain-Driven Design with three layers under `lib/`:

- `lib/Domain/` - business logic, entities, value objects
- `lib/Application/` - controllers, services
- `lib/Infrastructure/` - database access, PowerDNS API client, LDAP, etc.

Entry points: `index.php`, `dynamic_update.php`, `install/index.php`. Templates live in `templates/` (Twig). Configuration: copy `config/settings.defaults.php` to `config/settings.php`.

User-facing documentation lives at [docs.poweradmin.org](https://docs.poweradmin.org/) (separate repository). The `docs/` folder in this repo contains internal research notes, not user docs.

## Getting Started

### Prerequisites

- PHP 8.2 or higher
- Composer for dependency management
- Database server (MySQL/MariaDB, PostgreSQL, or SQLite)
- Access to a PowerDNS server for testing

### Option 1: Devcontainer (Recommended)

The repository ships with a devcontainer that provides MariaDB, PostgreSQL, SQLite, and Adminer out of the box. Open the repo in VS Code with the **Dev Containers** extension and reopen in container.

Default credentials and URLs:

- MariaDB / PostgreSQL: user `pdns`, password `poweradmin`
- Adminer: http://localhost:8090
- App: http://localhost:8080 (MySQL), :8081 (PostgreSQL), :8082 (SQLite)

Load test users (password `Poweradmin123`):

```bash
.devcontainer/scripts/import-test-data.sh
```

This creates `admin`, `manager`, `client`, `viewer`, `noperm`, and `inactive` accounts for testing permission scenarios.

### Option 2: Manual Setup

1. **Fork and clone**
   ```bash
   git clone https://github.com/YOUR_USERNAME/poweradmin.git
   cd poweradmin
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure**
   ```bash
   cp config/settings.defaults.php config/settings.php
   # Edit config/settings.php with your database and PowerDNS settings
   ```

## Development Workflow

### Branch Targeting

Open every pull request against `master` (the default branch), whether it is a bug fix, a feature or a translation.

A temporary `develop` branch holds 4.6.0 work and will be removed after 4.5.0 is released. You don't need to target it - the maintainer moves changes between the two.

Bug fixes are backported to the supported release branches (see [Version Support](README.md#version-support)) by the maintainer, so there is no need to open a separate PR per branch.

### Code Quality

```bash
composer check:all       # Lint (PHPCS)
composer format:all      # Auto-fix style
composer analyse:all     # PHPCS + PHPStan + project lint rules (lint:*)
composer compat:8.2      # PHP compatibility check (also :8.3, :8.4, :8.5)
```

### Adding Classes or Dependencies

`vendor/` is committed as a no-dev tree, and its optimized classmap also covers `lib/`. After adding or removing a class under `lib/` or `install/helpers/`, or changing dependencies, regenerate it or the **Vendor Integrity** check fails. For a dependency change, run `composer require` or `composer update <package>` first so `composer.lock` is refreshed, then:

```bash
composer install --no-dev --prefer-dist
composer dump-autoload --optimize --no-dev
```

Commit the resulting `vendor/` changes (`vendor/composer/installed.php` can be skipped), then run `composer install` again to get the dev tools back. Do not commit the `vendor/` changes that this second install makes.

### Testing

```bash
composer tests                  # Unit tests
composer tests:integration      # Integration tests (requires devcontainer)
composer tests:all              # All suites
```

Test files live in `tests/unit/` and `tests/integration/`. See existing tests for patterns - new functionality should include unit tests. End-to-end browser tests use Playwright in `playwright/tests/`.

### Translations

Translations are gettext catalogues in `locale/<locale>/LC_MESSAGES/messages.po`. Many of them were produced with automated tools and contain mistakes that only a native speaker can spot, so corrections are welcome. To contribute:

1. Open the `.po` file for your language in a PO editor such as [Poedit](https://poedit.net/), or any text editor
2. Fix or add translations, keeping `%s`/`%d` placeholders and HTML tags intact
3. Rebuild the compiled catalogue: `msgfmt locale/<locale>/LC_MESSAGES/messages.po -o locale/<locale>/LC_MESSAGES/messages.mo`
4. Open a pull request against `master` with both the `.po` and `.mo` files

See the [translations guide](https://docs.poweradmin.org/contributing/translations/) for details on plural forms and format strings.

## Contribution Guidelines

1. **Code Quality**: PSR-12 with a 250-character line limit. Add type hints and return types for all methods.
2. **Testing**: Add tests for new functionality and ensure existing tests pass.
3. **Documentation**: If a change is user-visible, open a documentation PR against the [poweradmin-docs](https://github.com/poweradmin/poweradmin-docs) repository.

### Commit Guidelines

Poweradmin uses [Conventional Commits](https://www.conventionalcommits.org/):

```
type(scope): short description
```

- **Types**: `fix`, `feat`, `chore`, `docs`, `refactor`, `test`, `style`
- **Common scopes**: `templates`, `api`, `auth`, `zones`, `records`, `deps`, `ddns`, `install`
- **Title only**: keep the subject self-explanatory; avoid extended description bodies unless really needed.
- **Reference issues inline**: `fix(templates): batch PTR form 404 error (closes #123)`

Recent examples:

```
fix(install): disambiguate database vs admin credentials on step 4
feat(ddns): add POST /api/v2/dynamic-dns endpoint
docs(readme): link php.net supported-versions for PHP support policy
```

### Pull Request Process

1. Target `master` (see [Branch Targeting](#branch-targeting))
2. Add tests for new functionality
3. Run code quality checks: `composer analyse:all && composer compat:8.2`
4. Ensure all tests pass: `composer tests`
5. Submit pull request with a clear description and reference related issues

## Attribution Policy

All meaningful contributions are credited in release notes. Please note:

- Sometimes similar ideas come from multiple contributors; implementation quality determines which is merged
- Contributions may be partially accepted or rewritten to maintain project consistency
- Even if your exact code isn't used, your ideas will still be credited if they influence the final implementation

If you notice your contribution hasn't been acknowledged, please reach out - I'm always open to corrections and want to ensure everyone receives proper recognition.

## Getting Help

- **Issues**: [GitHub Issues](https://github.com/poweradmin/poweradmin/issues)
- **Documentation**: [Official Documentation](https://docs.poweradmin.org/)

## License

By contributing, you agree that your contributions will be licensed under the GNU General Public License v3.0.

Thank you for your contributions!
