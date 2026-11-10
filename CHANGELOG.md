# Changelog

What changed, newest first, in pig's format: `## Unreleased` while working, `## [x.y.z] - YYYY-MM-DD`
at release. The version lives in the git tag only, and follows the pi-antigravity release the port
is in step with.

## Unreleased

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
