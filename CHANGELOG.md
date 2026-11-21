# Changelog

What changed, newest first, in pig's format: `## Unreleased` while working, `## [x.y.z] - YYYY-MM-DD`
at release. The version lives in the git tag only, and follows the pi-antigravity release the port
is in step with.

## Unreleased

In step with pi-antigravity 0.15.0.

### Added

- A system prompt over 126 characters is moved into the first user message and `systemInstruction` is left out, as pi-antigravity 0.15 does: some consumer endpoints (`cloudcode-pa` among them) answer a longer `systemInstruction` with a generic 429 `RESOURCE_EXHAUSTED` — which reads as "This model is unavailable right now" — whatever it says, and take the same text inside the conversation at any length. pig's own prompt is far longer than that. `ANTIGRAVITY_INLINE_SYSTEM=1|true|on` always moves it, `0|false|off` (or `ANTIGRAVITY_PROMPT_GUARD=off`) never does.
- Gemini 4 Neon is offered although Google's picker does not list it yet, as Hydrogen and Oxygen are; Neon is given 262,144 output tokens, Argon 65,536, and Claude Opus 5.5 and Sonnet 5.5 128,000 (they were 64,000), where the catalog states no limit.
- A warning in both READMEs on Section 6 of the Google Antigravity Additional Terms of Service, under which Google has suspended accounts used through third-party software.

### Changed

- **A daily limit on the 429s each account may receive**, as the developer asked, so a run of refusals cannot get an account suspended: every 429 counts, per account and per local day; at the limit nothing more is sent on that account until the next day, and the turn ends with a message for the person that pig does not send to the model or retry. A quota wall's failover passes such an account over. 5 by default; `antigravity.daily429Limit` in the person's own `~/.pig/agent/settings.json`. Every request goes through `DailyLimitedTransport`, on pig's new `HttpTransport`, so this needs a pig with that interface.
- **A 429 is handled as pi-antigravity handles it again**, as the developer asked, undoing 0.14.2: a refused chat request goes on to the other endpoint, then a sibling tier of the same model and the next candidate model; at a quota wall the next stored account not yet tried is renewed and used; and a capacity refusal (a 429 naming no limit, or a 503 "No capacity available") is sent again after pi-antigravity 0.15's ladder of 3, 8, 15 and 25 seconds (`ANTIGRAVITY_CAPACITY_BACKOFF_MS`, `ANTIGRAVITY_CAPACITY_ATTEMPTS`), with the route it ended on remembered for ten minutes so the next turn goes there first. A throttle says "retrying automatically" again, which the session retries. The usage read, Google Search, the catalog refresh, the sign-in's project lookup and `read_url_content` go on to the next endpoint at a 429, and image generation to the next endpoint and model, as upstream's do (its image request has no account failover).
- The catalog, the usage read and the sign-in ask two endpoints, `daily-cloudcode-pa` and then `cloudcode-pa`; the `daily-cloudcode-pa.sandbox` host no longer answers and is not asked.
- The system prompt is neutralized of more of pi's and Pi-Bolt's wording before it is sent, as pi-antigravity 0.15 does: their whole opening sentences become "You are a coding agent helping the user with their project.", and pi 1.5.2's "codemode scripts and non-LLM models" line is reworded. pig's own opener is now pi's word for word with pig's name in it (pig's `SystemPrompt::ROLE`), so it becomes the neutral one too.

### Fixed

- Claude and GPT-OSS no longer fail with `tool_use ids were found without tool_result blocks` after a turn whose tool call was interrupted before its result was recorded, or whose results came with images: every call's response goes first in the turn after it, and a call with none is answered "Tool execution was interrupted before a result was recorded.", as pi-antigravity 0.15 does.

## [0.14.3] - 2026-11-19

### Changed

- Signing in asks for pi-antigravity's six scopes (`aicode` was missing) and looks for the Cloud project as the Antigravity CLI does — its `User-Agent` and `ideType: ANTIGRAVITY` — where it used to send Gemini CLI's headers; the email lookup and the token requests send what pi-antigravity's do and nothing else. An account already signed in keeps its five scopes until it signs in again.
- The Cloud project is looked for as pi-antigravity looks: on its three endpoints in its order (there were two, in another), then `listCloudAICompanionProjects` when the answer names none, and under any of the names Google gives a project. An account with none gets an id of its own, made from its email (or `ANTIGRAVITY_PROJECT_ID`), where every such account used to be given the same project, `rising-fact-p41fc`. An account already signed in keeps the project it has until it signs in again.
- `/antigravity.usage`, `/antigravity.models` and the accounts panel send pi-antigravity's requests: its endpoints in its order, the model list asked of every endpoint under the project the account carries, and the project shown read under any of the names Google gives it. A usage read now fails when no endpoint returns a model list, as upstream's does, where it used to show the rest without the models. Image generation and Google Search use the same endpoint order too, and all of them honour `ANTIGRAVITY_BASE_URL`.
- Switching account (`/antigravity.accounts switch` and `rotate`, the panel's buttons, and removing the active account) renews the token of the account being switched to there and then when it has under five minutes left, as pi-antigravity does, where the renewal used to wait for the next request. A renewal that fails leaves the switch or the removal unmade, and says why.

## [0.14.2] - 2026-11-19

### Changed

- **A 429 from Google stops the request, whatever kind it is.** Google has been suspending accounts, and a run of 429s tends to come first; one refused turn used to be sent up to eight times, and a quota wall was sent once for every signed-in account. Now it is that one request and nothing else: not the other endpoint, not another tier of the same model (`-xhigh` no longer steps down to `-high`), not the next account, and the session does not retry it — a throttle no longer says "retrying automatically". The same in image generation, Google Search, `read_url_content`, `/antigravity.usage`, the catalog refresh and the sign-in. You decide whether to send again, wait, or change model or account (`/antigravity.accounts rotate`). No setting turns this off. Not pi-antigravity's behaviour.

### Removed

- Switching to the next account by itself at a quota wall, and stepping to a sibling tier at a model out of capacity (see above).

## [0.14.1] - 2026-11-19

### Changed

- `/login antigravity` works with nothing set up: it signs in under Google's public Antigravity desktop OAuth client, as pi-antigravity does, instead of refusing until `ANTIGRAVITY_CLIENT_ID` and `ANTIGRAVITY_CLIENT_SECRET` (or `antigravity.clientId` and `antigravity.clientSecret`) were set. Those still name your own client, and must now be set together: one without the other is refused rather than mixed with the built-in client.
- An account Google has suspended ("This service has been disabled in this account for violation of Terms of Service") is told so, and to appeal: the error no longer says "re-login or try another model", neither of which lifts a suspension. Not pi-antigravity's wording.
- `/antigravity.usage` shows each quota group's description under its name — the deployment's own line naming the models that share the pool — and, in the terminal, colours each bar by how much is left: half or more, a fifth or more, less than that. Not pi-antigravity's; after Google's own CLI.

## [0.14.0] - 2026-11-16

### Added

- **Gemini 4 Hydrogen and Oxygen** — both are selectable although Google's own picker lists do not name them yet; an `-xhigh` runtime variant and a display name ending `(XHigh)` or `(X-High)` are the `xhigh` thinking level; a model that has only an `xhigh` variant routes every level to it. Hydrogen carries its list price ($1.50 / $7.50 / $0.075 per million tokens) and its own output limit of 262,144 tokens where the catalog states none, instead of its family's 65,535.

### Changed

- In step with pi-antigravity 0.14.0.
- A capacity 429 on an `-xhigh` tier steps down to `-high`, then `-medium`; `-xhigh` itself is tried after those two.

### Fixed

- A model the deployment has switched off (`disabled: true`, or an id ending `-disabled`) was offered in `/model` when the picker's lists still named it.
- "This model is unavailable right now (Resource has been exhausted …)" in a session whose prompt quoted pi's: Google's proxy answers certain phrases with a false 429 — "running inside pi, a coding agent harness", "coding assistant running inside pi", "coding agent inside pi", "practical, and tool-aware." — and they are now reworded in the system instruction on the way out. pig's own prompt has none of them; a custom prompt, an appended one or a context file can.

## [0.13.0] - 2026-11-12

### New Features

- **`read_url_content`** — the model can read a public web page: `read_url_content` (and `/antigravity.fetch [--raw] [--path <file>] <url>`, where `--path` writes the whole page to a file inside the working directory) asks Google's crawler for the page, first its cached copy and then a live fetch, and returns Markdown — the page's content without its navigation, tables and code kept — under a header that says where it came from, whether the copy is complete, and whether it looks like a login wall or an empty app shell. When the page itself cannot be read it tries one other form of it (the Markdown or JSON the page advertises, Wikipedia's API, GitHub's blob page, MDN's article data). One ten-second deadline and at most ten requests per read; private, local and credential-carrying addresses are refused before anything is sent; PDFs are refused; a long page is saved to a private temporary file and the answer says where. Unlike upstream, a table cell's Markdown escapes are written once, so a footnote mark or a link with brackets in a table stays whole, and a superscript or subscript is marked (`2^30`, `H_2O`) instead of run into the text, where it read as "230". Needs PHP's `dom` extension, and says how to install it when it is missing. `ANTIGRAVITY_NO_FETCH_TOOL=1` leaves the tool out, `ANTIGRAVITY_WEB_FETCH_MAX_CHARS` sets how much comes back (pi-antigravity 0.13.0, #113).

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
