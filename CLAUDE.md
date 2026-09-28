# Comitiva hub

The Laravel hub of Comitiva, community edition (AGPL-3.0). Teams share agents and conversations
through it in real time. In Phase 8 it is a **sync hub**: desktops execute runs and publish them;
the hub stores, orders and broadcasts. Why and how: comitiva's `docs/adr/0015`, `0016`, `0017`.

## Read first

- `docs/STATUS.md`: what is done and what is next, inside the hub. Phases themselves are tracked in
  comitiva's `docs/STATUS.md`.
- `docs/api.md`: the REST API and the WebSocket channels and events.
- `docs/extension-points.md`: the interfaces and events other packages build on (public API, semver).
- `../comitiva/packages/contract/src/hub/`: the zod source of every payload. The hub never edits
  `resources/contract/`: that is a copy.

## Layout

```
app/Contract/Contract.php       JSON Schema validation against resources/contract (opis/json-schema, draft 2020-12)
app/Http/Controllers/Api/V1/    one controller per resource; bodies validated with $this->body($request, 'Schema')
app/Runs/RunLedger.php          run lock, event batches, approvals, finish, lease expiry (the heart of the sync)
app/Realtime/                   HubBroadcast (one event, ShouldBroadcastNow) + Realtime (after-commit sending)
app/Extension/                  BillingGateway, UsageMeter, RunGate, PlanLimits, IdentityProvider, AuditSink
app/Extension/Community/        their defaults, bound in app/Providers/HubServiceProvider.php
app/Events/                     domain events: WorkspaceCreated, MemberAdded, MemberRemoved, RunCompleted
app/Support/                    Present (models → contract shapes), Blocks, Invitations, UsageReports, Iso
resources/contract/             schema/*.json + VERSION, copied by `contract:sync` (never edited by hand)
```

## Non-negotiable rules

- Every request body is validated against a contract schema. Every response and broadcast matches
  one: the tests check responses with `expectContract`, and every broadcast is checked against
  `HubEvent`. A shape change starts in comitiva's contract: tag it there, then run
  `php artisan contract:sync <tag>` here.
- JSON stays JSON: use the `App\Casts\Json` cast and stdClass, never Laravel's `array` cast. It
  turns `{}` into `[]`, which changes a tool call's input.
- Errors are `{ error: { code, message, retryable } }`, with codes from the contract's `ErrorCode`.
  Throw `App\Exceptions\HubException`.
- Workspace ids never leak: someone outside a workspace gets a 404, not a 403.
- Message events carry the conversation's `rev`, assigned under the conversation's row lock; pages
  are read under a share lock (ADR 0008 in comitiva). Broadcasts go out after commit.
- Secrets never reach the hub: no API keys, no secret header values (`{ secretRef: 'member' }`),
  no local paths beyond what a message's content says.
- Nothing from `hub-enterprise` comes here; enterprise code plugs in only through
  `app/Extension` and `app/Events`.

## Conventions

- Laravel 13, PHP ≥ 8.3 (image on 8.4), Postgres only (jsonb, row locks, full-text search). Pest on
  Postgres, Pint (laravel preset), Larastan level 6.
- Models use `HasUlids`, `#[Fillable]`, `$dateFormat = 'Y-m-d H:i:s.vP'` and `timestampsTz(3)`.
  Dates leave as ISO 8601 UTC with milliseconds (`App\Support\Iso`).
- Conventional commits: `feat: …`, `fix(runs): …`, `docs: …`. Small commits, with the suite run
  first.
- Code, comments, commits and docs in English.

## Commands

```bash
docker compose up -d                          # postgres, app :8810, reverb :8811, scheduler
docker compose exec app php artisan test      # Pest (database hub_test)
vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G
php artisan contract:sync contract-vX.Y.Z --from=../comitiva   # copy the schemas from a comitiva tag
php artisan contract:check --from=../comitiva                  # fail on drift
php artisan hub:expire-runs                   # end runs whose desktop stopped renewing (scheduled)
docker build --target production -t comitiva-hub:local .
```
