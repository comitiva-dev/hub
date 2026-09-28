# Status

Work inside the hub. Phases, their exit criteria and the desktop side are tracked in comitiva's
`docs/STATUS.md`, which links here when a phase closes.

## Phase 8: sync hub (in progress)

### Done

- Laravel 13 with Sanctum (device tokens and web sessions), Reverb, Postgres and Pest. Docker
  Compose for development; a single production image (FrankenPHP) whose role is picked by the
  command.
- AGPL-3.0 `LICENSE`, a placeholder `CLA.md` pending legal review, and a CI workflow (Pint,
  Larastan, Pest on Postgres, `contract:check`, image to `ghcr.io/comitiva-dev/hub`). The workflow
  is written but has not run, because nothing is pushed yet.
- The contract copy: `contract:sync` and `contract:check` read `contract-v*` tags of comitiva
  (ADR 0016). Pinned at `contract-v0.3.0`.
- Extension points with community defaults, and domain events (`docs/extension-points.md`).
- Workspaces, roles, invitation links, shared agents, http-only workspace tool servers,
  conversations with unread counts per member, messages, the run ledger (run lock, event batches,
  approvals, heartbeats, finish, lease expiry), search, attachments and usage reports.

### Verification

| Check | Result |
|---|---|
| `php artisan test` (Postgres) | 46 passed, 1 skipped (the sibling-clone `contract:check` inside the container; run on the host instead). Every response checked is validated against its schema, and every broadcast against `HubEvent`. |
| `pint --test`, `phpstan` (level 6) | Clean. |
| `contract:check --from=../comitiva` | Matches `contract-v0.3.0`. |
| Production image | Built; migrated an empty database on start; `/api/v1/meta` and a registration answered. |

### Open

- CLA Assistant, the GitHub repository, and the first CI run and image: after you push.
- Legal review of AGPL-3.0 and the CLA text before the first outside contribution.
- Attachments are stored on the local disk. S3 works through `HUB_ATTACHMENTS_DISK` but has not
  been tried.
- No mail: invitations are links to copy.
