# pig-antigravity

Google Antigravity for the [pig](https://github.com/owner888/pig) coding agent: Gemini, Claude and GPT-OSS on an Antigravity subscription instead of a metered API key. A PHP port of [pi-antigravity](https://github.com/Rahularya01/pi-antigravity), kept in step with its releases (currently 0.13.0).

[中文](README.zh-CN.md)

## Installation

```bash
pig install git:github.com/owner888/pig-antigravity
```

No Composer dependencies. Antigravity was bundled with pig until it became this package. `pig update` removes the old bundled copy and prints the install line above; your sign-ins (`auth.json`, `antigravity-accounts.json`), the stored model catalog and `disabledExtensions: ["pig-antigravity"]` all carry over.

## Before you use it

This is not an official Google integration. Requests go to Google's Code Assist backend the way the Antigravity CLI sends them, and Google may limit or suspend an account that uses a subscription this way. Use an account you can afford to lose access with.

The OAuth client is not in this repository. Set `ANTIGRAVITY_CLIENT_ID` and `ANTIGRAVITY_CLIENT_SECRET` in the environment, or `antigravity.clientId` and `antigravity.clientSecret` in pig's `settings.json`, before signing in.

## Signing in

```
/login antigravity
```

A browser opens and comes back to `localhost:51121/oauth-callback`. Then pick a model:

```bash
pig --model antigravity/gemini-3.8-flash
```

Signing in again with another Google account keeps the first one. A hard quota wall on the active account switches to the next linked account inside the request.

## What it adds

| | |
| --- | --- |
| **Provider `antigravity`** | The models the deployment advertises for your account, asked for at session start and at most every four hours, filed in `~/.pig/agent/models-store.json`. A thinking level picks the runtime model (`gemini-3.8-flash` at medium is sent as `gemini-3.8-flash-medium`) |
| **Image models** | `gemini-3.1-flash-image` and its fallbacks, through pig's `generateImages` |
| **`generate_image` tool** | Generates an image and saves it under `.pig/generated-images/`, or a path inside the working directory. `referenceImages` sends up to four project-relative PNG, JPEG, WebP or GIF files (10 MB each) beside the prompt |
| **`google_search` tool** | Google Search grounding through the signed-in account: a short evidence brief with sources |
| **`read_url_content` tool** | Reads one public web page through Google's crawler and hands the model Markdown: the page's content without its navigation, with where it came from, how complete the copy is, and whether it looks like a login wall or an empty app shell. No JavaScript is run, PDFs are not read, private and local addresses are refused, and a long page is saved to a private temporary file. Needs PHP's `dom` extension (`apt install php-xml`, `dnf install php-xml`; Homebrew's and the official Docker image's PHP have it) and says so when it is missing |
| **Web panel** | In `pig web`: the linked accounts, which one is active, when each token runs out, and the quota meters |

### Commands

| Command | |
| --- | --- |
| `/antigravity.usage` | Quota left in each pool, and when it resets |
| `/antigravity.models [all]` | The models in the current catalog |
| `/antigravity.accounts` | List, switch and remove linked accounts |
| `/antigravity.refresh` | Ask the deployment for its catalog now |
| `/antigravity.credits [on\|off]` | The account's Google One AI credit balance, and whether requests may spend it once a quota pool is empty. Off until you turn it on; kept as `useG1Credits` in pig's `settings.json` |
| `/antigravity.search [--thinking] [--url <url>] <query>` | Google Search grounding |
| `/antigravity.fetch [--raw] <url>` | Read a web page the way `read_url_content` does; `--raw` shows what the crawler returned, unconverted |
| `/antigravity.image [--ratio <w:h>] [--model <id>] [--path <file>] <prompt>` | Generate an image |
| `/antigravity.doctor` | Diagnostics with secrets removed |

### Environment

| Variable | |
| --- | --- |
| `ANTIGRAVITY_CLIENT_ID`, `ANTIGRAVITY_CLIENT_SECRET` | The OAuth client (see above) |
| `ANTIGRAVITY_USER_AGENT` | Replaces the Antigravity CLI `User-Agent` requests carry. The backend shows newer models only to newer CLI versions |
| `ANTIGRAVITY_BASE_URL` | One `https://*.googleapis.com` endpoint for the catalog instead of the usual three |
| `ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS` | How long a stored catalog is reused (default four hours; `0` asks every time) |
| `ANTIGRAVITY_NO_EXTRA_TOOLS`, `ANTIGRAVITY_NO_SEARCH_TOOL`, `ANTIGRAVITY_NO_FETCH_TOOL`, `ANTIGRAVITY_NO_IMAGE_TOOL` | `1` keeps the tools from being registered; the provider and the slash commands stay |
| `ANTIGRAVITY_WEB_FETCH_MAX_CHARS` | How many characters of a page `read_url_content` returns (default 60000); the rest is in the saved file |
| `ANTIGRAVITY_USE_CREDITS` | `1`/`0` turns Google One AI credit overages on or off, over what the settings say |
| `ANTIGRAVITY_LOCATION`, `ANTIGRAVITY_ENTERPRISE_PROJECT`, `ANTIGRAVITY_USER_TIER` | Gemini Enterprise: `eu` or `us` (or `ANTIGRAVITY_ENTERPRISE=1` for `global`) sends chat only to that location's Business AI Code host, with the license's Cloud project id and tier. Nothing falls back to the consumer endpoint, and image generation is refused while it is on |
| `PIG_OFFLINE` | Set: the catalog is never asked for over the network |

## Files

Under `~/.pig/agent/`: `auth.json` holds the active sign-in, `antigravity-accounts.json` every linked account, `models-store.json` the catalog. A catalog pi wrote (`~/.pi/agent/models-store.json`) is read when pig has none of its own.

## Development

The tests run against a pig checkout:

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

`PIG_DIR` defaults to a `pig` directory next to this package. `scripts/fetch-antigravity-models.php` prints the built-in fallback tables from the live catalog (it needs a signed-in account, or `--from <saved reply>`).

## License

MIT — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
