# Status

Work inside the hub. Phases, their exit criteria and the desktop side are tracked in comitiva's
`docs/STATUS.md`, which links here when a phase closes.

## Phase 8: sync hub (done)

### Done

- Laravel 13 with Sanctum (device tokens and web sessions), Reverb, Postgres and Pest. Docker
  Compose for development; a single production image (FrankenPHP) whose role is picked by the
  command.
- AGPL-3.0 `LICENSE`, a placeholder `CLA.md` pending legal review, and a CI workflow (Pint,
  Larastan, Pest on Postgres, `contract:check`, image to `ghcr.io/comitiva-dev/hub`). The
  repository is public at `github.com/comitiva-dev/hub`.
- The contract copy: `contract:sync` and `contract:check` read `contract-v*` tags of comitiva
  (ADR 0016). Pinned at `contract-v0.3.0`.
- Extension points with community defaults, and domain events (`docs/extension-points.md`).
- Workspaces, roles, invitation links, shared agents, http-only workspace tool servers,
  conversations with unread counts per member, messages, the run ledger (run lock, event batches,
  approvals, heartbeats, finish, lease expiry), search, attachments and usage reports.

### Verification

| Check | Result |
|---|---|
| `php artisan test` (Postgres) | 47 passed, 1 skipped (the sibling-clone `contract:check` inside the container; run on the host instead). Every response checked is validated against its schema, and every broadcast against `HubEvent`. |
| `pint --test`, `phpstan` (level 6) | Clean. |
| `contract:check --from=../comitiva` | Matches `contract-v0.3.0`. |
| Production image | Built; migrated an empty database on start; `/api/v1/meta` and a registration answered. |
| CI on GitHub | Green on `main` (run 36454645560): checks, then the image pushed to `ghcr.io/comitiva-dev/hub` (`main` and `sha-…` tags). The first run failed `contract:check` because the `contract-v*` tags were not pushed yet. |
| comitiva's `test:e2e:hub` | Two Comitiva desktops against this image (Postgres, Reverb, scheduler): sign-up, a workspace, an invitation link, presence, a shared agent linked to each member's connection, and each member's reply streaming live to the other. Passed. |

### Open

- The `ghcr.io/comitiva-dev/hub` package is private (GitHub's default for a new package), so the
  README's `docker run` needs a login. Make it public in the package settings.
- CLA Assistant is not installed, and AGPL-3.0 and the CLA text need legal review, both before the
  first outside contribution.
- Attachments are stored on the local disk. S3 works through `HUB_ATTACHMENTS_DISK` but has not
  been tried.
- No mail: invitations are links to copy.
