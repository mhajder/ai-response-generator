import js from '@eslint/js';

const sharedRules = {
  'prefer-const': 'error',
  'no-var': 'error',
  eqeqeq: ['error', 'always'],
  curly: ['error', 'multi-line'],
  'no-eval': 'error',
  'no-implied-eval': 'error',
  'no-shadow': 'error',
  'no-unused-vars': ['error', { varsIgnorePattern: '^_', caughtErrors: 'none' }],
};

export default [
  js.configs.recommended,
  {
    files: ['assets/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: 'script',
      globals: {
        window: 'readonly',
        document: 'readonly',
        navigator: 'readonly',
      },
    },
    rules: sharedRules,
  },
  {
    files: ['tests/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
    },
    rules: sharedRules,
  },
];
