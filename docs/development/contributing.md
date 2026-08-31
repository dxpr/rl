# Contributing

## Issue queue

Report bugs and feature requests on the
[drupal.org issue queue](https://www.drupal.org/project/issues/rl).

## Source code

- [git.drupalcode.org/project/rl](https://git.drupalcode.org/project/rl) (primary)
- [github.com/dxpr/rl](https://github.com/dxpr/rl) (mirror)

Pull requests are accepted on GitHub and mirrored to drupal.org.

## Branch conventions

- `1.x`: main development branch
- Feature branches: `feat/<description>` or `feature/<description>`
- Bug fixes: `fix/<description>`

## Coding standards

The project enforces Drupal coding standards and ESLint for JavaScript.
All checks run automatically on pull requests.

### PHP (Drupal coding standards)

```bash
docker compose --profile lint run --rm drupal-lint
```

Auto-fix coding standard violations:

```bash
docker compose --profile lint run --rm drupal-lint-auto-fix
```

### Drupal compatibility

```bash
docker compose --profile lint run --rm drupal-check
```

### JavaScript (ESLint)

```bash
npm ci
npx eslint .
```

## Running tests

The project uses end-to-end tests via Drush commands against a real Drupal
site. To run the full test suite locally:

```bash
docker compose --profile test run --rm e2e-test
```

<!-- TODO: screenshot of a passing test run output -->

## Architecture overview

The module is built around these core services:

- `rl.experiment_manager`: records turns (impressions) and rewards (conversions)
- `rl.ts_calculator`: Thompson Sampling algorithm implementation
- `rl.experiment_registry`: experiment registration and lookup
- `rl.cache_manager`: page cache lifetime management
- `rl.analyzer`: experiment analysis and recommendations

Consumer modules (like `rl_sorting`, `rl_page_title`, `rl_menu_link`) extend
the core by registering experiments and implementing variant selectors and
decorators.

<!-- TODO: architecture diagram showing core services and how consumer modules plug in -->
