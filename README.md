# Comitiva hub

The hub lets a team share agents and conversations in [Comitiva](https://comitiva.dev) and see
each other's replies stream in real time. This is the **community edition**: open source
(AGPL-3.0) and self-hostable. The official hosted hub runs at `app.comitiva.dev`.

In this version the hub is a **sync hub**. It stores shared workspaces and fans out events. It
does not call LLMs itself: a member's desktop runs each turn with that member's own connection
and publishes the reply to the hub as it streams.

- **Workspaces**, with owners, admins and members, and invitation links.
- **Shared agents.** An agent names a provider and a model. Each member links it to one of their
  own connections, so nobody's API key is shared.
- **Conversations** everyone in the workspace sees live, with unread counts per member.
- **Workspace tool servers** (MCP over http). Secret headers stay on each member's desktop.
- **Search**, **attachments** and a **usage** report of what members' desktops ran.

What stays on each desktop: connections and keys, folders, local (stdio) tool servers, harness
sessions and "always allow" decisions.

## Run it

You need Docker. The image serves the API on port 80 and has three roles, chosen by the command:

| Role | Command |
|---|---|
| web (default) | FrankenPHP serving the API |
| realtime | `php artisan reverb:start --host=0.0.0.0 --port=8080` |
| scheduler | `php artisan schedule:work` (ends runs whose desktop went away) |

All three need the same environment (see `.env.example`), a Postgres 15+ database with the
`unaccent` extension available, and `APP_KEY`. Set `HUB_MIGRATE=1` on one role to run migrations
at start. Desktops reach the WebSocket at `HUB_REALTIME_HOST:HUB_REALTIME_PORT`. Put TLS in front
of both ports in production.

```bash
docker run -d --name hub-web -p 80:80 --env-file hub.env -e HUB_MIGRATE=1 ghcr.io/comitiva-dev/hub
docker run -d --name hub-realtime -p 8080:8080 --env-file hub.env ghcr.io/comitiva-dev/hub \
  php artisan reverb:start --host=0.0.0.0 --port=8080
docker run -d --name hub-scheduler --env-file hub.env ghcr.io/comitiva-dev/hub php artisan schedule:work
```

Then, in Comitiva: **Settings → Hub**, enter the hub's address, and create an account. The first
account can always be created. After that, `HUB_REGISTRATION=invite-only` limits sign-ups to
invitation links.

## Develop

```bash
docker compose up -d                           # Postgres, the app (:8810), Reverb (:8811), the scheduler
docker compose exec app composer install       # first run
docker compose exec app php artisan migrate
docker compose exec app php artisan test       # Pest on Postgres (database hub_test)
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
```

More: [CLAUDE.md](CLAUDE.md) (conventions), [docs/api.md](docs/api.md) (REST and WebSocket),
[docs/extension-points.md](docs/extension-points.md), [docs/STATUS.md](docs/STATUS.md).

## The contract

Every payload follows `@comitiva/contract` in the
[comitiva](https://github.com/comitiva-dev/comitiva) repository. The hub keeps a copy of its JSON
Schemas, taken from a pinned `contract-v*` tag, in `resources/contract`
(`php artisan contract:sync <tag>`), and CI fails when the copy drifts
(`php artisan contract:check`).

## License

AGPL-3.0-only (see [LICENSE](LICENSE)). If you run a modified hub as a service, you must offer its
source to its users. Contributions are accepted under a CLA ([CLA.md](CLA.md)).
