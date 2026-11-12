# Changelog

What changed, newest first, in pig's format: `## Unreleased` while working, `## [x.y.z] - YYYY-MM-DD`
at release. The version lives in the git tag only, and follows the pi-antigravity release the port
is in step with.

## Unreleased

### New Features

- **`read_url_content`** — the model can read a public web page: `read_url_content` (and `/antigravity.fetch [--raw] <url>`) asks Google's crawler for the page, first its cached copy and then a live fetch, and returns Markdown — the page's content without its navigation, tables and code kept — under a header that says where it came from, whether the copy is complete, and whether it looks like a login wall or an empty app shell. When the page itself cannot be read it tries one other form of it (the Markdown or JSON the page advertises, Wikipedia's API, GitHub's blob page, MDN's article data). One ten-second deadline and at most ten requests per read; private, local and credential-carrying addresses are refused before anything is sent; PDFs are refused; a long page is saved to a private temporary file and the answer says where. Unlike upstream, a table cell's Markdown escapes are written once, so a footnote mark or a link with brackets in a table stays whole. Needs PHP's `dom` extension, and says how to install it when it is missing. `ANTIGRAVITY_NO_FETCH_TOOL=1` leaves the tool out, `ANTIGRAVITY_WEB_FETCH_MAX_CHARS` sets how much comes back (pi-antigravity 0.13.0, #113).

### Added

- **Google One AI credit overages** — `/antigravity.credits` shows the account's AI credit balance, and `/antigravity.credits on` lets requests spend it when a quota pool is empty (`enabledCreditTypes: ["GOOGLE_ONE_AI"]`). Off unless you turn it on: `ANTIGRAVITY_USE_CREDITS`, `useG1Credits` in pig's own `settings.json` (a project's settings cannot switch it on), or the official CLI's settings. A quota wall now says whether overages are on, and an empty credit balance has its own message (pi-antigravity 0.13.0, #112).
- **Gemini Enterprise routing** — `ANTIGRAVITY_LOCATION=eu|us` (or `ANTIGRAVITY_ENTERPRISE=1`) with `ANTIGRAVITY_ENTERPRISE_PROJECT` and `ANTIGRAVITY_USER_TIER` sends chat only to that location's Business AI Code host, never on to the consumer endpoints; an incomplete configuration sends nothing. A license the consumer endpoint refuses (error #3501) is answered with these settings. Image generation is refused while it is on. `/antigravity.doctor` shows the routing in use (#106).
- `generate_image` takes `referenceImages`: up to four project-relative PNG, JPEG, WebP or GIF files, 10 MB each, which must still be inside the working directory once links are followed (#104).

### Changed

- In step with pi-antigravity 0.13.0.
- Requests identify as the Antigravity CLI 1.5.5, the current `agy` release.
- What is removed from an error or a diagnostic before it is shown is wider: Google token shapes, credentials in a URL and its parameters (nested and percent-encoded ones too), and `name=value` secrets, while an ordinary `state=` or `code=429` is left alone.
- Each Gemini and Claude model carries its own list price per million tokens instead of none, so a session's cost line shows what the same usage would list at; a subscription is still not billed by the token (#103).

### Fixed

- A model that had launched on one endpoint only — Gemini 4 Carbon on the daily one — was in the catalog's models and missing from `/model`: the picker's list of the last endpoint asked replaced the others'. The lists are joined now (pi-antigravity 0.13.0, #123).
- `before_provider_request` (`onPayload`) now sees and can replace an Antigravity chat request, as it does every other provider's (#126).
- A stored token or project id with anything but printable characters in it is refused instead of sent.
- "This model is unavailable right now (Resource has been exhausted …)" ended the turn although the model was only short of capacity on one endpoint or one tier — `gemini-4-argon` at medium with quota left, say. A 429 that names no limit is now asked of the other endpoint, and then of the model's other tiers (high first, then medium, low), before the turn fails; the failure is still not retried by the session, and a quota wall with a reset time is unchanged (pi-antigravity 0.13.0, #122, #125).

## [0.12.0] - 2026-11-10

### New Features

- **A package of its own** — Antigravity is no longer bundled with pig: `pig install git:github.com/owner888/pig-antigravity`. Same provider, sign-in, accounts, commands, tools and web panel; the sign-ins, the stored catalog and `disabledExtensions: ["pig-antigravity"]` carry over. `pig update` removes the old bundled copy.

### Changed

- In step with pi-antigravity 0.12.0. Requests identify as the Antigravity CLI 1.4.0 — the deployment hides its newest models, Gemini 4 Argon among them, from older versions — and every request sends the same User-Agent, held in `PigAntigravity\AntigravityCli` (`ANTIGRAVITY_USER_AGENT` overrides it).
- The model list is what the deployment answers and nothing else: the models Google's own picker lists (`agentModelSorts`), with the context window, output limit, image input and thinking the answer states. A model the answer no longer lists is not put back from the built-in table, a model of any family is listed, and picking a model the current catalog has dropped says to run `/antigravity.refresh`.
- A stored catalog is asked for again when it was filed for another account or project, or by an older version, instead of being reused for four hours; in print and RPC mode the refresh at session start is waited for.
- `generate_image` and `/antigravity.image` write only inside the working directory and never through a symbolic link; a path with no extension is a directory, and several images written to one named file are numbered.
- An assistant message records the model that actually answered (`responseModel`) and the gateway's `responseId`.

### Fixed

- `google_search` on the default model failed with HTTP 400: it sent a thinking budget of 0, which the Lite models refuse. A model that refuses a search's arguments is passed over for the next one, and a refused sign-in ends the search at once.
- A 429 that names no limit — how a model that is not being served answers — was reported as rate limiting and retried until the retries ran out; it is reported once as the model being unavailable.
- An endpoint that could not be reached ended the turn without the other endpoint being tried.
- A turn sent again after its stream died could hang behind the first attempt; every request after a turn's first goes out as a session of its own.
- A fallback from Gemini 3.8 Flash to an older Flash is only made to a model the current catalog still serves.
