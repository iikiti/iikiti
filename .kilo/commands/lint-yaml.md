# Lint YAML Configuration

Lint the project's YAML configuration files with `--parse-tags` to handle
Symfony DI-specific tags like `!tagged_iterator`.

```bash
php bin/console lint:yaml config/ --parse-tags
```

Files using Symfony DI tags in `config/services.yaml` are valid — the
`--parse-tags` flag allows the linter to accept them.
