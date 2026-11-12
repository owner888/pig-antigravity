# pig-antigravity — notes for agents

The Antigravity provider for pig, as a pig package: a PHP port of
[pi-antigravity](https://github.com/Rahularya01/pi-antigravity). `README.md` says what it does;
this says how it is worked on. Keep this file under 100 lines.

## What follows what

- **Upstream is pi-antigravity, file by file.** `Grouping` is `models/grouping.ts`, `Discovery`
  is `models/discovery.ts` plus the catalog half of `client/client.ts`, `Routing` is
  `models/models.ts`, `AntigravityApi` is the request loop and error wording of
  `stream/stream.ts`, `Accounts` is `auth/accounts.ts`, `GoogleSearch` is `search/search.ts`,
  `ImageGenerator` is `image/image.ts`, `QuotaClient` is `usage/usage.ts`, `Credits` is
  `utils/credits.ts`, `Enterprise` is `client/enterprise.ts`, `Redaction` is
  `utils/security.ts`, `WebFetch/` is `web-fetch/` (`PublicUrl` is `utils/address.ts`, `Text` is
  `utils/text.ts`). A difference from upstream is a bug unless the code's own comment records it
  as the developer's choice.
- **Where upstream leans on a library, `WebFetch/` has its own class held to it by tests**: `Url`
  for the WHATWG URL, `Turndown` for turndown and its GFM tables, `Document` for the DOM (PHP's
  `dom` extension, the one thing the reader needs; without it the tool says how to install it).
  Lengths are counted in characters, not UTF-16 units. `tests/fixtures/web-fetch/` are
  upstream's saved pages and must read as upstream reads them.
- **When pi-antigravity releases**: read its `CHANGELOG.md` and the diff since the release this
  port is in step with (named at the top of `README.md`), port it, and move that name. The
  package's version follows upstream's.
- **The streaming and the Gemini request shapes are pig's** (`Pig\Ai\Providers\GoogleShared`,
  `AssistantMessageBuilder`); this package does not carry a second SSE loop.

## Rules

- **One User-Agent**: `AntigravityCli::userAgent()`. No request writes its own string — the
  deployment gates the catalogue on the version in it. A new CLI release is `VERSION` and
  `CHANGELIST` there, nothing else. `pig-gemini-video` asks the same class.
- **Error wording changes only in `AntigravityApi::friendlyAntigravityError()`**, word for word
  against upstream: pig's session decides whether to retry by reading it. A token in an error is
  redacted first (`redactSecrets()`).
- **Where this is narrower than upstream on purpose**: `publicErrorStatus()` hides the status
  for a 429 only, so a 502/503 keeps its number and is still retried.
- The catalog is the deployment's answer and nothing else; grouping lives only in `Grouping`,
  network, TTL and storage only in `Discovery`, and a routing target with no enum refuses the
  whole catalog (`Catalog`).
- No silent fallback: `catch` re-throws. No `@` suppression. `declare(strict_types=1)`, one
  class per file.
- The OAuth client id and secret are never in this repository.
- The developer's name does not appear in this repository; write "the developer".
- **Spending is opt-in**: `Credits` reads the person's own settings file, never the session's
  merged settings, so a project cannot switch AI credit overages on.
- **The reader asks Google's crawler and nothing else**: `read_url_content` never opens a
  connection to the page's own host, and `PublicUrl::check()` runs before any request.
- **Enterprise routing never falls back**: with `Enterprise::resolve()` answering a target, the
  consumer endpoints are not asked, and an incomplete configuration throws before any request.
- No composer dependencies. A git-installed package is not run through Composer, so
  `extensions/pig-antigravity.php` registers the autoloader itself. The entry keeps that file
  name: pig names an extension by its file, and settings written when it was bundled reach it.
- Git (commit, tag, push) is the developer's; report which files changed.

## Tests

`PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml` —
they use pig's test helpers (`Pig\Test\CannedServer`, `CapturesLog`) and, for the web panel, the
page's own `panels.js`. A test that sends the same turn twice is testing a retry: call
`AntigravityApi::forgetRequestNonces()` in `setUp`.

## Changelog

`CHANGELOG.md` in pig's format: `## Unreleased` while working, `## [x.y.z] - YYYY-MM-DD` at
release; the version lives in the git tag only.
