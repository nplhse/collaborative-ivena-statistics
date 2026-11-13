import js from '@eslint/js';
import eslintConfigPrettier from 'eslint-config-prettier';
import globals from 'globals';

export default [
    {
        ignores: ['assets/vendor/**', 'public/**', 'var/**', 'vendor/**'],
    },
    js.configs.recommended,
    {
        files: ['assets/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'no-unused-vars': [
                'warn',
                {
                    argsIgnorePattern: '^_',
                    varsIgnorePattern: '^_',
                    caughtErrorsIgnorePattern: '^_',
                },
            ],
            'no-console': ['warn', { allow: ['error', 'warn'] }],
        },
    },
    {
        // Offline data preparation tool (#1051): plain scripts inlined into one HTML file.
        files: ['src/Encounter/UI/DataPrep/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'script',
            globals: {
                ...globals.browser,
            },
        },
    },
    {
        files: ['tests/Encounter/DataPrep/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.node,
            },
        },
    },
    eslintConfigPrettier,
];
