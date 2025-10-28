# Contributing to Poweradmin

Thank you for your interest in contributing to Poweradmin! We welcome contributions to improve the project.

## Getting Started

### Prerequisites

- PHP 8.1 or higher
- Composer for dependency management
- Database server (MySQL, PostgreSQL, or SQLite)
- Access to a PowerDNS server for testing

### Development Setup

1. **Fork and Clone**
   ```bash
   git clone https://github.com/YOUR_USERNAME/poweradmin.git
   cd poweradmin
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Set Up Configuration**
   ```bash
   cp config/settings.defaults.php config/settings.php
   # Edit config/settings.php with your database and PowerDNS settings
   ```

## Development Workflow

### Code Quality

```bash
# Lint code
composer check:all

# Fix code style
composer format:all

# Static analysis
composer analyse:all
```

### Testing

```bash
# Run unit tests
composer tests

# Run all test suites
composer tests:all
```

## Contribution Guidelines

1. **Code Quality**: Follow PSR-12 coding standards with 250-character line limit
2. **Testing**: Add tests for new functionality and ensure all tests pass
3. **Documentation**: Update relevant documentation for new features

### Commit Guidelines

- Write clear, descriptive commit messages
- Use present tense ("Add feature" not "Added feature")
- Reference issues in commit messages (e.g., "Fixes #123")

### Pull Request Process

1. Update documentation if adding features
2. Add tests for new functionality
3. Run code quality checks: `composer check:all && composer analyse:all`
4. Ensure all tests pass: `composer tests:all`
5. Submit pull request with clear description and reference related issues

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
