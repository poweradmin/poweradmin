// Lints the Playwright suite, the only JavaScript this repository owns.
// templates/ ships vendored assets and is deliberately not covered.
const browserGlobals = {
    document: 'readonly',
    window: 'readonly',
    navigator: 'readonly',
    localStorage: 'readonly',
    sessionStorage: 'readonly',
    getComputedStyle: 'readonly',
};

export default [
    {
        files: ['playwright/**/*.js'],
        languageOptions: {
            // Import attributes (`with { type: 'json' }`) need the newest grammar
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                console: 'readonly',
                process: 'readonly',
                URL: 'readonly',
                URLSearchParams: 'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                Buffer: 'readonly',
                fetch: 'readonly',
                ...browserGlobals,
            },
        },
        rules: {
            // A typo'd global is always a bug, so it fails the run.
            'no-undef': 'error',
            // Warns for now: most current hits are conditions a test computed
            // and never asserted, which need strengthening rather than deleting.
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
            'no-empty': ['error', { allowEmptyCatch: true }],
        },
    },
    {
        // The tools are CommonJS scripts run directly by node
        files: ['playwright/tools/**/*.js'],
        languageOptions: {
            sourceType: 'commonjs',
            globals: {
                require: 'readonly',
                module: 'writable',
                __dirname: 'readonly',
                ...browserGlobals,
            },
        },
    },
];
