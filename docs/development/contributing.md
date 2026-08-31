# Contributing

## Linting and code standards

Run coding standards checks:

```bash
docker compose --profile lint run --rm drupal-lint
```

Auto-fix coding standard violations:

```bash
docker compose --profile lint run --rm drupal-lint-auto-fix
```

Run Drupal compatibility checks:

```bash
docker compose --profile lint run --rm drupal-check
```

## Issue queue

Report bugs and feature requests on the
[drupal.org issue queue](https://www.drupal.org/project/issues/rl).

## Source code

- [git.drupalcode.org/project/rl](https://git.drupalcode.org/project/rl) (primary)
- [github.com/dxpr/rl](https://github.com/dxpr/rl) (mirror)
