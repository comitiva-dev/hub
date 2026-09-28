# Contributing

Thanks for helping. Before you open a pull request:

1. Read [CLAUDE.md](CLAUDE.md): layout, rules and commands.
2. Run the checks: `docker compose exec app php artisan test`, `vendor/bin/pint --test` and
   `vendor/bin/phpstan analyse --memory-limit=1G`.
3. A change to a payload starts in the contract of
   [comitiva](https://github.com/comitiva-dev/comitiva) (`packages/contract`), not here.

## License and CLA

The hub is licensed under AGPL-3.0-only. Contributions are accepted under the
[Contributor License Agreement](CLA.md), which CLA Assistant asks you to sign on your first pull
request. The CLA lets the project combine the hub with its hosted edition.
