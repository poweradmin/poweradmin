# Contributing to Poweradmin

Thank you for your interest in contributing to Poweradmin! We welcome contributions to improve the project.

## Where to Start

- **Bug reports and small UI fixes** are always welcome.
- **Good first issues**: filter the [issue tracker](https://github.com/poweradmin/poweradmin/issues) by the `good first issue` label.
- **Translations**: handled via Transifex - see [Translations](#translations). No PHP knowledge required.
- **Larger features**: open an issue first to discuss approach and target branch before writing code.

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

Poweradmin maintains multiple release branches (see [Version Support](README.md#version-support) in the README). Target your PR appropriately:

| Change type | Target branch |
|-------------|---------------|
| 3.x LTS bug fix or security update | `release/3.x` |
| Current stable bug fix | `release/4.2.x` |
| Pre-release stabilization | `release/4.3.x` |
| New feature (next release) | `master` |
| Experimental / breaking | `develop` |

Stable branches accept bug fixes and security updates only - no breaking changes and no new features. When in doubt, open an issue first.

### Code Quality

```bash
composer check:all       # Lint (PHPCS + PHPMD)
composer format:all      # Auto-fix style
composer analyse:all     # PHPStan + Psalm
composer compat:8.2      # PHP compatibility check (also :8.3, :8.4, :8.5)
```

### Testing

```bash
composer tests                  # Unit tests
composer tests:integration      # Integration tests (requires devcontainer)
composer tests:all              # All suites
```

Test files live in `tests/unit/` and `tests/integration/`. See existing tests for patterns - new functionality should include unit tests. End-to-end browser tests use Playwright in `playwright/tests/`.

### Translations

Translations flow through [Transifex](https://www.transifex.com/) and are merged into the repository via automated pull requests (branches named `tx_translations_*`). To contribute translations:

1. Request access to the Poweradmin project on Transifex
2. Translate strings through the Transifex web UI
3. The Transifex sync opens a PR with the updated `.po` files

Please do not submit hand-edited `.po` files via pull request - they will be overwritten by the next Transifex sync.

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

1. Target the correct branch (see [Branch Targeting](#branch-targeting))
2. Add tests for new functionality
3. Run code quality checks: `composer check:all && composer analyse:all`
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
