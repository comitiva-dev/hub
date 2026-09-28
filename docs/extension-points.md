# Extension points

The hub's public API for other packages: the interfaces in `app/Extension` and the events in
`app/Events`. They follow semver. Breaking one is a major version of the hub, because it breaks
every package built on it (ADR 0015 in comitiva).

A package plugs in from its own service provider, registered after
`App\Providers\HubServiceProvider`. It rebinds the interfaces it needs and listens to the events.
The hub never references such a package.

```php
public function register(): void
{
    $this->app->bind(\App\Extension\RunGate::class, MyUsagePolicy::class);
}

public function boot(): void
{
    Event::listen(\App\Events\RunCompleted::class, RecordForInvoice::class);
}
```

## Interfaces

| Interface | Community default | Called |
|---|---|---|
| `BillingGateway` | `NoBilling`: disabled, no portal | reserved for billing screens (Phase 9+) |
| `UsageMeter::record(UsageRecord)` | `StoredUsageMeter`: nothing to add, the record is stored | after a run's usage is committed |
| `RunGate::refuse(User, Conversation): ?string` | `AllowAllRuns` | before a run starts; a reason refuses it with `forbidden` |
| `PlanLimits::maxMembers(Workspace)`, `maxWorkspaces(User)` | `UnlimitedPlan`: null | accepting an invitation; creating a workspace |
| `IdentityProvider::attempt(email, password)`, `register(...)` | `PasswordIdentity` | sign-in (token and session) and registration |
| `AuditSink::record(action, actor, workspace, data)` | `NoAudit` | role changes, removals, workspace and agent deletion |

## Events

| Event | When | Payload |
|---|---|---|
| `WorkspaceCreated` | a workspace is created | `workspace`, `owner` |
| `MemberAdded` | an invitation is accepted | `membership` |
| `MemberRemoved` | a member is removed or leaves | `workspace`, `userId` |
| `RunCompleted` | a run finishes, fails, is cancelled or expires | `run`, `status` (`complete`, `cancelled`, `error`, `expired`), `usage` (`UsageRecord` or null) |

Events are dispatched after the transaction that caused them commits.

Usage that desktops report is shown, not billed: a client can alter it (ADR 0015). Billing by
usage covers only what the hub itself runs, which starts in Phase 9.
