<?php

use Phan\Issue;

/**
 * Phan configuration for PHP compatibility checking
 *
 * @see https://github.com/phan/phan/wiki/Phan-Config-Settings for all configurable options
 */
return [
    // The PHP version that the codebase will be checked for compatibility against.
    // Automatically inferred from composer.json requirement for "php" of ">=8.1"
    'target_php_version' => '8.1',

    // If enabled, missing properties will be created when
    // they are first seen.
    'allow_missing_properties' => false,

    // If enabled, null can be cast to any type and any
    // type can be cast to null.
    'null_casts_as_any_type' => false,

    // If enabled, allow null to be cast as any array-like type.
    'null_casts_as_array' => true,

    // If enabled, allow any array-like type to be cast to null.
    'array_casts_as_null' => true,

    // If enabled, scalars (int, float, bool, string, null)
    // are treated as if they can cast to each other.
    'scalar_implicit_cast' => false,

    // If enabled, any scalar array keys (int, string)
    // are treated as if they can cast to each other.
    'scalar_array_key_cast' => true,

    // If enabled, Phan will warn if **any** type in a method invocation's object
    // is definitely not an object.
    'strict_method_checking' => false,

    // If enabled, Phan will warn if **any** type of the object expression for a property access
    // does not contain that property.
    'strict_object_checking' => false,

    // If enabled, Phan will warn if **any** type in the argument's union type
    // cannot be cast to a type in the parameter's expected union type.
    'strict_param_checking' => false,

    // If enabled, Phan will warn if **any** type in a property assignment's union type
    // cannot be cast to a type in the property's declared union type.
    'strict_property_checking' => false,

    // If enabled, Phan will warn if **any** type in a returned value's union type
    // cannot be cast to the declared return type.
    'strict_return_checking' => false,

    // If true, seemingly undeclared variables in the global
    // scope will be ignored.
    'ignore_undeclared_variables_in_global_scope' => true,

    // Set this to false to emit `PhanUndeclaredFunction` issues for internal functions.
    'ignore_undeclared_functions_with_known_signatures' => true,

    // Backwards Compatibility Checking.
    'backward_compatibility_checks' => false,

    // If true, check to make sure the return type declared
    // in the doc-block matches the return type in the method signature.
    'check_docblock_signature_return_type_match' => false,

    // Set to true in order to attempt to detect dead (unreferenced) code.
    'dead_code_detection' => false,

    // Set to true in order to attempt to detect unused variables.
    'unused_variable_detection' => false,

    // Set to true in order to attempt to detect redundant and impossible conditions.
    'redundant_condition_detection' => false,

    // If enabled, Phan will act as though it's certain of real return types of internal functions.
    'assume_real_types_for_internal_functions' => false,

    // If true, this runs a quick version of checks.
    'quick_mode' => false,

    // The minimum severity level to report on.
    'minimum_severity' => Issue::SEVERITY_LOW,

    // Add any issue types to this list to inhibit them from being reported.
    //
    // Note: PhanAccessMethodInternal is suppressed because Symfony's Request::get()
    // method is marked as @internal but is a valid public API for accessing request
    // parameters. This is a Symfony design decision, not a compatibility issue.
    'suppress_issue_types' => [
        'PhanAccessMethodInternal',
        'PhanDeprecatedClass',
        'PhanDeprecatedFunction',
        'PhanTypeMismatchArgument',
        'PhanTypeMismatchArgumentReal',
        'PhanTypeArraySuspiciousNullable',
        'PhanTypePossiblyInvalidDimOffset',
        'PhanTypeMismatchArgumentNullableInternal',
        'PhanTypeMismatchArgumentInternalProbablyReal',
        'PhanTypeInvalidThrowsIsInterface',
        'PhanTypeMismatchArgumentSuperType',
        'PhanMissingRequireFile',
        // PHP 8.1 compatibility: suppress false positives for array operations
        'PhanTypeMismatchDimFetch',
        'PhanTypeMismatchDimAssignment',
        'PhanTypeInvalidDimOffset',
        // Intentional type casts and method_exists checks
        'PhanTypeInvalidLeftOperandOfAdd',
        'PhanUndeclaredMethod',
        // Minor optimization suggestions not affecting compatibility
        'PhanRedundantArrayValuesCall',
        'PhanTypeMismatchReturnNullable',
        // Unused imports (handled by code quality tools instead)
        'PhanUnreferencedUseNormal',
        // Trait property access - traits rely on using classes having the property
        'PhanUndeclaredProperty',
    ],

    // A regular expression to match files to be excluded from parsing and analysis.
    'exclude_file_regex' => '@^vendor/.*/(tests?|Tests?)/@',

    // A list of files that will be excluded from parsing and analysis.
    'exclude_file_list' => [],

    // Directories excluded from static analysis, but whose class and method info should be included.
    'exclude_analysis_directory_list' => [
        'vendor/',
        'tests/',
    ],

    // Enable this to enable checks of require/include statements referring to valid paths.
    'enable_include_path_checks' => true,

    // The number of processes to fork off during the analysis phase.
    'processes' => 1,

    // List of case-insensitive file extensions supported by Phan.
    'analyzed_file_extensions' => [
        'php',
    ],

    // A list of plugin files to execute.
    'plugins' => [
        'AlwaysReturnPlugin',
        'PregRegexCheckerPlugin',
        'UnreachableCodePlugin',
    ],

    // A list of directories that should be parsed for class and method information.
    'directory_list' => [
        'addons',
        'config',
        'db',
        'install',
        'lib',
        'tests',
        'tools',
        'vendor',
    ],

    // A list of individual files to include in analysis.
    'file_list' => [
        'index.php',
        'dynamic_update.php',
    ],
];
