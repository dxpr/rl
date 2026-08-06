import antfu from '@antfu/eslint-config';

export default antfu(
  {
    type: 'lib',
    javascript: {
      overrides: {
        'no-console': ['error', { allow: ['warn', 'error'] }],
      },
    },
    typescript: false,
    vue: false,
    react: false,
    jsonc: false,
    yaml: false,
    toml: false,
    markdown: false,
    stylistic: {
      semi: true,
      quotes: 'single',
    },
  },
  {
    files: ['js/**/*.js', 'modules/**/js/**/*.js'],
    languageOptions: {
      globals: {
        Drupal: 'readonly',
        drupalSettings: 'readonly',
        once: 'readonly',
        jQuery: 'readonly',
        Plotly: 'readonly',
        navigator: 'readonly',
        window: 'readonly',
        document: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        fetch: 'readonly',
        Promise: 'readonly',
        Blob: 'readonly',
        Array: 'readonly',
        IntersectionObserver: 'readonly',
        console: 'readonly',
        JSON: 'readonly',
        Object: 'readonly',
        Math: 'readonly',
        Infinity: 'readonly',
      },
    },
    rules: {
      'no-prototype-builtins': 'off',
      'prefer-spread': 'off',
    },
  },
  {
    ignores: [
      'js/vendor/**',
      'node_modules/**',
    ],
  },
);
