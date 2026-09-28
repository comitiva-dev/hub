# API

REST under `/api/v1`. Desktops send `Authorization: Bearer <token>` (a personal access token per
device). The web UI (Phase 9) uses a session cookie. Bodies are JSON and are validated against the
contract's JSON Schemas (`resources/contract/schema`, named in brackets below). Every error is:

```json
{ "error": { "code": "conversation_busy", "message": "…", "retryable": false } }
```

Codes come from the contract's `ErrorCode`. The usual ones: `invalid_request` (422),
`hub_auth_required` (401), `forbidden` (403), `not_found` (404, also for workspaces you are not in),
`conversation_busy` (409), `run_expired` (409), `rate_limited` (429).

## Meta and auth

| Method | Path | Body → Response |
|---|---|---|
| GET | `/meta` (public) | → `HubMeta`: `apiVersion`, `edition`, `contractVersion`, `capabilities`, `realtime` (the WebSocket's key, host, port, scheme) |
| POST | `/auth/register` | `HubRegisterInput` → `HubAuthResult` (201). `email_taken`; `forbidden` when invite-only; `invitation_invalid` |
| POST | `/auth/login` | `HubLoginInput` → `HubAuthResult`. `invalid_credentials` |
| POST | `/auth/logout` | → 204, revokes this token |
| GET | `/me` | → `HubUser` |
| POST | `/login`, `/logout` (web routes) | session sign-in for the web UI |

## Workspaces, members, invitations

| Method | Path | Who | Body → Response |
|---|---|---|---|
| GET | `/workspaces` | you | → `Workspace[]` (with your `role`) |
| POST | `/workspaces` | anyone | `WorkspaceDraft` → `Workspace` (you are its owner) |
| GET, PATCH, DELETE | `/workspaces/{ws}` | member; admin (rename); owner (delete) | `WorkspaceDraft` → `Workspace` |
| GET | `/workspaces/{ws}/members` | member | → `Member[]` |
| PATCH | `/workspaces/{ws}/members/{user}` | owner | `MemberPatch` → `Member`. A workspace always keeps an owner |
| DELETE | `/workspaces/{ws}/members/{user}` | admin (members only), owner | → 204 |
| POST | `/workspaces/{ws}/leave` | member, except the last owner | → 204 |
| GET, POST | `/workspaces/{ws}/invitations` | admin | `InvitationDraft` → `InvitationCreated` (the link, shown once) / `Invitation[]` |
| DELETE | `/invitations/{id}` | admin | → 204 |
| GET | `/invitations/{token}` (public) | anyone with the link | → `InvitationPreview` |
| POST | `/invitations/{token}/accept` | the invited email | → `Workspace` |

## Agents and tool servers

| Method | Path | Who | Body → Response |
|---|---|---|---|
| GET, POST | `/workspaces/{ws}/agents` | member | `SharedAgentDraft` → `SharedAgent` |
| GET, PATCH, DELETE | `/agents/{id}` | member; its creator or an admin to change or delete | `SharedAgentPatch` → `SharedAgent` |
| GET | `/workspaces/{ws}/tool-servers` | member | → `WorkspaceToolServer[]` |
| POST | `/workspaces/{ws}/tool-servers` | admin | `WorkspaceToolServerDraft` → `WorkspaceToolServer` (http only) |
| PATCH, DELETE | `/tool-servers/{id}` | admin | `WorkspaceToolServerPatch` → `WorkspaceToolServer` |

## Conversations and messages

| Method | Path | Body → Response |
|---|---|---|
| GET | `/workspaces/{ws}/conversations?agentId=&archived=` | → `HubConversationSummary[]`, newest activity first, `unread` per caller |
| POST | `/workspaces/{ws}/conversations` | `HubConversationDraft` → `Conversation` |
| PATCH | `/conversations/{id}` | `HubConversationPatch` → `Conversation`. `titleSource: auto` replaces only the placeholder title |
| POST | `/conversations/{id}/read` | → 204 |
| GET | `/conversations/{id}/messages?beforeSeq=&limit=` | → `MessagePage` (oldest first, at the conversation's `rev`) |
| POST | `/conversations/{id}/cancel` | → 204. Asks the running desktop to stop (its member or an admin) |
| GET | `/conversations/{id}/usage` | → `UsageTotals` |

## Runs (the executing desktop)

A turn is run by the desktop of the member who sends it (ADR 0017 in comitiva):

1. `POST /conversations/{id}/runs` with `HubRunStartInput` (`runId` chosen by the desktop, and
   `content`, or no `content` to retry a failed reply) → `HubRunStartResult` (201): the user
   message, the empty streaming reply, and the `history` for the runner. `conversation_busy` when
   another run holds the conversation.
2. `POST /runs/{runId}/events` with `HubRunEventsInput` (`batch` 1, 2, …; `events` are
   `run.text_delta`, `run.block`, `run.tool_call`, `run.tool_result`) → `{ rev }`. A repeated
   batch number is acknowledged and ignored.
3. `POST /runs/{runId}/approvals` with `HubRunApprovalInput` → 204, once the member has answered
   the pending tool call on their desktop.
4. `POST /runs/{runId}/heartbeat` → 204, every 10 s while nothing else is sent. The lease lasts
   30 s (`HUB_RUN_LEASE_SECONDS`).
5. `POST /runs/{runId}/finish` with `HubRunFinishInput` (final `content`, `status`, `error`,
   `stopReason`, `usage`) → `HubRunFinishResult`.

Only the token that started a run can post to it (`forbidden` otherwise). If a lease lapses, the
reply ends as `error { code: interrupted }` and later posts get `run_expired`.

## Search, attachments, usage

| Method | Path | Body → Response |
|---|---|---|
| GET | `/workspaces/{ws}/search?query=&limit=` | → `SearchResult` (titles by substring, messages by full text, accents ignored, last word as a prefix) |
| POST | `/workspaces/{ws}/attachments` | `AttachmentInput` → `AttachmentBlock` (the block's `source.path` is the attachment id) |
| GET | `/attachments/{id}` | → the file |
| GET | `/workspaces/{ws}/usage/summary?from=&to=&tzOffsetMinutes=` | → `UsageSummary` (`byConnection` holds members) |
| GET | `/workspaces/{ws}/usage/timeseries?…` | → `UsageBucket[]`, one per day on the viewer's calendar |
| GET | `/workspaces/{ws}/usage/records?…` | → `HubUsageRecord[]` |

## WebSocket

Reverb speaks the Pusher protocol. `/meta` says where it is. Private and presence channels are
authorized at `POST /broadcasting/auth` with the same token. The Pusher event name is the payload's
`type`, and every payload matches the contract's `HubEvent`.

| Channel | Who | Events |
|---|---|---|
| `private-user.{userId}` | that user | `workspace.joined`, `workspace.updated`, `workspace.left` |
| `presence-workspace.{workspaceId}` | members (presence: `{ id, name }`) | `member.added`, `member.updated`, `member.removed`, `agent.created`, `agent.updated`, `agent.deleted`, `tool_server.created`, `tool_server.updated`, `tool_server.deleted`, `conversation.created`, `conversation.updated` |
| `private-conversation.{conversationId}` | members | `message.created`, `message.updated`, `run.started`, `run.text_delta`, `run.block`, `run.tool_call`, `run.tool_result`, `run.done`, `run.error`, `run.cancel_requested` |

`message.*`, `run.text_delta`, `run.block` and `run.tool_result` carry the conversation's `rev`,
one per event. A client drops events at or below the `rev` of the page it fetched, and refetches
when it sees a gap (ADR 0008 in comitiva). The other events carry no `rev`.
