# PostClosePortal

Illustrative prototype for conversational access to Post Close Portal documents.
It builds only the part that carries the risk: deciding who may see a document,
and filtering retrieval results so nothing else gets out.

## What is here

| Path | What it is |
|---|---|
| `app/Access/AccessResolver.php` | The permission chain P1 to P6 for one (user, portal, document), with the explicit administrator branch. |
| `app/Retrieval/PermittedSearch.php` | The filter between the search index and the client: first check, search, live second check, audit hand-off, fail closed. |
| `app/Access/*`, `app/Retrieval/*` | Small value types and the interfaces the two classes above depend on. |
| `tests/Fixtures/Universe.php` | The hand-written fixture world and `VISIBLE`, the hand-written judge of who may see what. |
| `tests/Unit/PermittedSearchTest.php` | Starts with `test_unauthorised_retrieval_is_refused`, the test that must never stop failing loudly. |

## Running the tests

```bash
composer install
```

```bash
vendor/bin/phpunit
```

Requires PHP 8.2 or later.

## Design choices worth knowing before reading the code

- **Plain PHP in Laravel's layout, no framework skeleton.** Classes live under
  `App\` and take their collaborators through the constructor, so they bind in
  a service provider unchanged. The skeleton was left out to keep the reading
  surface to the permission logic.
- **The index is treated as hostile.** The refusal test uses an index that
  ignores the portal and the allow-list and returns every matching document in
  the system. The second check must still refuse all but the permitted one.
- **The judge shares no code with the resolver.** `Universe::VISIBLE` is a
  hand-written list. The resolver is checked against it for every user,
  portal and document.
- **Denial reasons stay inside.** `Decision::$reason` exists for logs and
  audit. Clients get one answer for hidden, foreign and missing documents.
- **Every failure is no data.** A throw anywhere in the permission path, or a
  refused audit hand-off, returns nothing.

## Stubbed, faked or not built

- The MCP server, tool handlers and token validation. `PermittedSearch` is
  what a tool handler would call, with the user id taken from a validated token.
- A real search index. `FakeIndex` is a substring match.
- Redis and the 60-second expiry. `ArrayCache` never expires, which is the
  worst case for the revocation test.
- The audit queue. `RecordingAudit` is an in-memory list.
- Excerpt limits for Read-only users, response-time padding, and folder
  visibility.
- `AccessResolver` spells the chain out. In the application it would wrap the
  existing authorisation logic rather than restate it.
