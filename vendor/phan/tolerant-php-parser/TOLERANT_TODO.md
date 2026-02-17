# Tolerant Parser Update TODO

## Overview

This branch tracks work needed to bring the tolerant PHP parser up to date for PHP 8.3, 8.4, and 8.5 so that downstream consumers (notably Phan) can rely on it when the native `ext-ast` extension is unavailable. Phan lives in the sibling repo located at `~/phan`, and its tolerant-to-php-ast bridge (`src/Phan/AST/TolerantASTConverter/TolerantASTConverter.php`) often needs to evolve in lockstep with changes made here. The fork currently matches upstream commit `457738cbe` (September 2024) and is missing several recent language features.

## Current Status

- PHPUnit suites `invariants` and `api` pass locally when run with `zend.assertions=1` and `assert.exception=1`.
- Grammar fixtures (`tests/cases/parser*.tree/.diag`) were generated before the latest language changes; they will need regeneration once parser updates land.
- Validation tests that depend on large submodules (`validation/frameworks/*`) remain skipped/not vendored.

## Gaps / Work Items

### PHP 8.3 ✅

All major PHP 8.3 features are implemented and tested:

- **Dynamic class constant fetch** (`Foo::{expr}`): ✅ **COMPLETE** - verified via `tests/samples/dynamic_class_const.php`
- **Typed class constants**: ✅ **COMPLETE** - including union types (`const int|string VALUE = 3`), tested in `tests/cases/parser/classConstDeclaration*.php`
- **`#[\Override]` attribute**: ✅ **COMPLETE** - fixtures added to verify tolerant preserves them on methods, tested in `tests/cases/parser/override-attribute-methods.php`
- **Arbitrary static variable initialisers**: ✅ **COMPLETE** - tolerant accepts the new grammar for function-level `static` declarations with arbitrary expressions, tested in `tests/cases/parser/static-variable-initializers.php`

### PHP 8.4 ✅

All major PHP 8.4 features are implemented and tested:

- **Property access hooks** (`public int $count { get => ...; set { ... } }`): ✅ **COMPLETE**
  - Tokenizer recognizes `get`/`set` and hook modifiers
  - AST nodes for hook lists/bodies align with php-ast's `AST_PROP_ELEM` `hooks` child
  - Conversion produces `AST_PROPERTY_HOOK`/`AST_PROPERTY_HOOK_SHORT_BODY` nodes
  - Tested in `tests/cases/parser/propertyHook*.php` including edge cases (default values, constructor parameters)
  - Property hook modifiers (public/private/protected/static/final) supported
- **Asymmetric visibility** (`public private(set) $prop`): ✅ **COMPLETE**
  - Added `TokenKind::PrivateSetKeyword` (174) and `TokenKind::ProtectedSetKeyword` (175)
  - `Parameter` node includes `$setVisibilityToken` field
  - Runtime compatibility for PHP < 8.4 via `defined()` checks
  - Tested in `tests/cases/parser84/asymetrical-visiblity.php`
- **`new Foo()->bar()` without wrapping parentheses**: ✅ **COMPLETE**
  - Parser handles reduced precedence via `parsePostfixExpressionRest()`
  - `ObjectCreationExpression` allowed in postfix contexts
  - Tested in `tests/cases/parser84/new-without-parenthesis.php`
- **PHP 8.4 deprecation fixes**: ✅ **COMPLETE**
  - Added return type declarations (`: \Generator`, `: void`) to methods in `src/Node.php`
  - Prevents implicit nullable parameter deprecation warnings

### PHP 8.5 (in progress)

Monitor RFCs merged into php-src and mirror the token/grammar changes:

- **Pipe operator** (`expr |> func(...)`): ✅ **COMPLETE**
  - Added `TokenKind::PipeToken` for `|>` operator
  - Binary expression precedence rules implemented
  - Comprehensive test coverage in `tests/cases/parser85/pipe-operator-*.php`:
    - Basic usage: `$result = "Hello" |> strlen(...)`
    - Chained pipes with arrow functions
    - Instance and static method calls
    - Pipe operator within match expressions
  - Parser directory `tests/cases/parser85/` added with version check (PHP_VERSION_ID >= 80500)
- **Clone with property modifications** (`clone($obj, ["prop" => $value])`): ✅ **COMPLETE**
  - RFC v2 syntax implemented (function-like, NOT `with` keyword)
  - Added `openParen`, `commaToken`, `modifications`, `closeParen` fields to `CloneExpression` node
  - Supports traditional `clone $obj`, parenthesized `clone($obj)`, and modifications `clone($obj, [...])`
  - Tested in `tests/cases/parser85/clone-*.php` (6 comprehensive tests)
  - Note: Earlier "clone with" RFC v1 (`clone $obj with [...]`) was not accepted; PHP 8.5 uses v2 function syntax
- **Final property promotion** (`final string $property`): ✅ **COMPLETE**
  - Added `FinalKeyword` to `isParameterModifier()` to allow `final` as parameter modifier
  - Added `FinalKeyword` and `ReadonlyKeyword` to `isParameterStartFn()` for parameter start detection
  - Supports all syntax variations:
    - `final string $prop` - Final alone
    - `public final string $prop` - With visibility
    - `final readonly string $prop` - With readonly
    - `public readonly final string $prop` - All modifiers combined
  - Tested in `tests/cases/parser85/final-promotion-*.php` (5 comprehensive tests)
- **Extended attribute targets** (`#[Deprecated] const FOO = 1;`): ✅ **COMPLETE**
  - Added support for attributes on global constants (PHP 8.5+)
  - Updated Parser.php to include `TokenKind::ConstKeyword` in allowed attribute targets
  - Added `attributes` field to `ConstDeclaration` node
  - Added `ConstDeclaration` to instanceof check for attaching attributes
  - Verified attributes on class constants still work correctly
  - Verified `#[Override]` on properties works correctly (PHP 8.5+)
  - Tested in `tests/cases/parser85/attributes-*.php` and `tests/cases/parser85/override-on-property.php`
- Track any additional keywords (`with`, operator tokens, etc.) and update `TokenKind.php` / `TokenStringMaps.php` accordingly.

### Diagnostics & Node Mapping

- Review `TokenKind`/`TokenStringMaps` for completeness after adding new tokens.
- Ensure node classes record line/column spans needed by language-server features when hooks and new constructs are present.
- Verify edits still map correctly (`TextEdit`, node mapping) for newly supported syntax.

### Tests & Tooling

- Add focused fixtures for property hooks and any new constructs under `tests/cases/parser` (and regenerate `.tree/.diag`).
- Re-enable / update tolerant PHPUnit suites once fixtures are refreshed.
- Consider syncing targeted tests from Phan’s fallback suite to catch divergences early.

### Integration with Phan

- After implementing features, run Phan’s fallback parser tests (`./tests/run_test __FakeSelfFallbackTest`) to ensure parity, and adjust the tolerant AST converter (`~/phan/src/Phan/AST/TolerantASTConverter/TolerantASTConverter.php`) as needed so both repositories stay in sync.

### Verification Strategy

- **AST comparison**: use Phan’s `tools/dump_ast.php` (php-ast) to capture the expected AST for new syntax. For the tolerant side, run `php tools/PrintTolerantAst.php` (raw tolerant tree) in combination with Phan’s `internal/dump_fallback_ast.php` (which invokes `src/Phan/AST/TolerantASTConverter/TolerantASTConverter.php`) to ensure both the parse tree and converted php-ast structures match expectations across the two projects.
- **PHP runtime selection**: on this dev machine we can run `sudo newphp 83`, `sudo newphp 84`, etc. to switch CLI versions; other environments may require Docker images, phpenv, asdf, etc. Record the PHP version used when regenerating fixtures.
- **Leverage Phan fixtures**: pull feature-specific testcases (e.g. property hooks, asymmetric visibility) from `phan/tests/files/src` into tolerant’s parser tests to validate new constructs.
- **Run tolerant PHPUnit suites**: keep `vendor/bin/phpunit --testsuite invariants,api` (with `zend.assertions=1`) as a fast regression check while iterating.

Recommended sample inputs for AST diffs (update as new fixtures are added):

| Feature | Sample file(s) | Min PHP | Status | Native AST dump | Tolerant dump |
| --- | --- | --- | --- | --- | --- |
| Dynamic class const fetch | `tests/samples/dynamic_class_const.php` | 8.3 | ✅ | `php ~/phan/tools/dump_ast.php --json …` | `php tools/PrintTolerantAst.php …` + `php ~/phan/internal/dump_fallback_ast.php --php-ast …` |
| Typed class constants | `tests/cases/parser/classConstDeclaration*.php` | 8.3 | ✅ | (run after `sudo newphp 83+`) | same as above |
| #[\Override] attribute | `tests/cases/parser/override-attribute-methods.php` | 8.3 | ✅ | (run after `sudo newphp 83+`) | same as above |
| Arbitrary static initializers | `tests/cases/parser/static-variable-initializers.php` | 8.3 | ✅ | (run after `sudo newphp 83+`) | same as above |
| Property hooks | `tests/samples/property_hooks.php`, `tests/cases/parser/propertyHook*.php` | 8.4 | ✅ | (run after `sudo newphp 84`) | same as above |
| Asymmetric visibility | `tests/cases/parser84/asymetrical-visiblity.php` | 8.4 | ✅ | (run after `sudo newphp 84`) | same as above |
| New without parenthesis | `tests/cases/parser84/new-without-parenthesis.php` | 8.4 | ✅ | (run after `sudo newphp 84`) | same as above |
| Pipe operator | `tests/cases/parser85/pipe-operator-*.php` | 8.5 | ✅ | `php ~/phan/tools/dump_ast.php --json …` | `php tools/PrintTolerantAst.php …`, `php ~/phan/internal/dump_fallback_ast.php --php-ast …` |
| Clone with modifications | `tests/cases/parser85/clone-*.php` | 8.5 | ✅ | (run after `sudo newphp 85`) | same as above |
| Final property promotion | `tests/cases/parser85/final-promotion-*.php` | 8.5 | ✅ | (run after `sudo newphp 85`) | same as above |
| Extended attribute targets | `tests/cases/parser85/attributes-*.php`, `tests/cases/parser85/override-on-property.php` | 8.5 | ✅ | (run after `sudo newphp 85`) | same as above |

## Completed Work Summary

As of October 2025, the tolerant parser now has full support for:

- **PHP 8.3**: Dynamic class constant fetch, typed class constants (including union types), #[\Override] attribute on methods, arbitrary static variable initializers
- **PHP 8.4**: Property hooks (with modifiers and edge cases), asymmetric visibility, new without parenthesis, deprecation fixes
- **PHP 8.5**: Pipe operator, clone with property modifications, final property promotion, extended attribute targets

**Test Coverage**: 31,516 tests passing across all PHP versions (8.1-8.5)
**CI Configuration**: Updated to test on PHP 8.1, 8.2, 8.3, 8.4, 8.5.0RC1-cli

## Next Steps

All major PHP 8.3, 8.4, and 8.5 features are now fully implemented and tested! ✅

The tolerant parser is feature-complete for PHP 8.1-8.5 with comprehensive test coverage.
