# Contributing

Thanks for helping. Before you open a pull request:

1. Read [CLAUDE.md](CLAUDE.md): layout, rules and commands.
2. Run the checks: `docker compose exec app php artisan test`, `vendor/bin/pint --test` and
   `vendor/bin/phpstan analyse --memory-limit=1G`.
3. A change to a payload starts in the contract of
   [comitiva](https://github.com/comitiva-dev/comitiva) (`packages/contract`), not here.

## License

By contributing you agree that your contributions are licensed under the [Apache-2.0](LICENSE)
license. There is no CLA.
