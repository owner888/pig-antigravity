# pig-antigravity

给 [pig](https://github.com/owner888/pig) 编程智能体用的 Google Antigravity：用 Antigravity 订阅跑 Gemini、Claude 和 GPT-OSS，不走按量计费的 API key。它是 [pi-antigravity](https://github.com/Rahularya01/pi-antigravity) 的 PHP 移植，跟随其版本同步（当前 0.13.0）。

[English](README.md)

## 安装

```bash
pig install git:github.com/owner888/pig-antigravity
```

没有 Composer 依赖。Antigravity 原先随 pig 自带，现在是这个包。`pig update` 会删掉旧的自带副本并打印上面这行安装命令；已有的登录（`auth.json`、`antigravity-accounts.json`）、已保存的模型目录和 `disabledExtensions: ["pig-antigravity"]` 都继续有效。

## 使用前须知

这不是 Google 官方集成。请求按 Antigravity CLI 的方式发往 Google 的 Code Assist 后端，Google 可能因此限制或停用以这种方式使用订阅的账号。请用你承受得起失去访问权的账号。

OAuth 客户端不在本仓库里。登录前先在环境变量里设置 `ANTIGRAVITY_CLIENT_ID` 和 `ANTIGRAVITY_CLIENT_SECRET`，或在 pig 的 `settings.json` 里设置 `antigravity.clientId` 和 `antigravity.clientSecret`。

## 登录

```
/login antigravity
```

浏览器打开后回到 `localhost:51121/oauth-callback`。然后选模型：

```bash
pig --model antigravity/gemini-3.8-flash
```

用另一个 Google 账号再登录一次，前一个账号会保留。当前账号撞到硬性限额时，会在请求内切到下一个已关联的账号。

## 它带来什么

| | |
| --- | --- |
| **`antigravity` provider** | 后端对你的账号公布的模型，在会话开始时询问，最多每四小时一次，存在 `~/.pig/agent/models-store.json`。thinking 等级决定实际发出的运行时模型（`gemini-3.8-flash` 在 medium 下发的是 `gemini-3.8-flash-medium`） |
| **图片模型** | `gemini-3.1-flash-image` 及其回退模型，走 pig 的 `generateImages` |
| **`generate_image` 工具** | 生成图片，保存到 `.pig/generated-images/`，或工作目录内的指定路径。`referenceImages` 可随提示词附上最多四张项目内的 PNG、JPEG、WebP 或 GIF 参考图（每张不超过 10 MB） |
| **`google_search` 工具** | 通过已登录账号做 Google Search grounding：一份带来源的简短证据摘要 |
| **`read_url_content` 工具** | 通过 Google 的爬虫读取一个公开网页，交给模型的是 Markdown：去掉导航的正文，附带来源、这份副本是否完整、是否像登录墙或空的应用外壳。不执行 JavaScript，不读 PDF，拒绝内网和本机地址，长页面另存为私有临时文件。需要 PHP 的 `dom` 扩展（`apt install php-xml`、`dnf install php-xml`；Homebrew 和官方 Docker 镜像的 PHP 自带），缺少时会提示安装 |
| **Web 面板** | 在 `pig web` 里：已关联的账号、当前用哪个、各自 token 何时到期，以及限额进度条 |

### 命令

| 命令 | |
| --- | --- |
| `/antigravity.usage` | 各限额池的剩余量和重置时间 |
| `/antigravity.models [all]` | 当前目录里的模型 |
| `/antigravity.accounts` | 列出、切换、移除已关联账号 |
| `/antigravity.refresh` | 立即向后端询问模型目录 |
| `/antigravity.credits [on\|off]` | 账号的 Google One AI 额度余额，以及限额池用完后请求是否可以动用它。默认关闭，由你打开；保存在 pig 的 `settings.json` 的 `useG1Credits` |
| `/antigravity.search [--thinking] [--url <url>] <query>` | Google Search grounding |
| `/antigravity.fetch [--raw] <url>` | 像 `read_url_content` 那样读取一个网页；`--raw` 显示爬虫返回的原文，不做转换 |
| `/antigravity.image [--ratio <w:h>] [--model <id>] [--path <file>] <prompt>` | 生成图片 |
| `/antigravity.doctor` | 去掉敏感信息的诊断输出 |

### 环境变量

| 变量 | |
| --- | --- |
| `ANTIGRAVITY_CLIENT_ID`、`ANTIGRAVITY_CLIENT_SECRET` | OAuth 客户端（见上） |
| `ANTIGRAVITY_USER_AGENT` | 替换请求携带的 Antigravity CLI `User-Agent`。后端只对较新的 CLI 版本展示较新的模型 |
| `ANTIGRAVITY_BASE_URL` | 只向这一个 `https://*.googleapis.com` 端点询问目录，而不是默认的三个 |
| `ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS` | 已保存的目录复用多久（默认四小时；`0` 表示每次都问） |
| `ANTIGRAVITY_NO_EXTRA_TOOLS`、`ANTIGRAVITY_NO_SEARCH_TOOL`、`ANTIGRAVITY_NO_FETCH_TOOL`、`ANTIGRAVITY_NO_IMAGE_TOOL` | 设为 `1` 时不注册对应工具；provider 和斜杠命令保留 |
| `ANTIGRAVITY_WEB_FETCH_MAX_CHARS` | `read_url_content` 返回页面的多少个字符（默认 60000）；其余在另存的文件里 |
| `ANTIGRAVITY_USE_CREDITS` | `1`/`0` 打开或关闭 Google One AI 额度超量，优先于设置文件 |
| `ANTIGRAVITY_LOCATION`、`ANTIGRAVITY_ENTERPRISE_PROJECT`、`ANTIGRAVITY_USER_TIER` | Gemini Enterprise：`eu` 或 `us`（或用 `ANTIGRAVITY_ENTERPRISE=1` 表示 `global`）时，对话只发往该地区的 Business AI Code 主机，并带上许可证的 Cloud 项目 id 和等级。不会回退到普通端点，开启期间拒绝生成图片 |
| `PIG_OFFLINE` | 设置后不再通过网络询问目录 |

## 文件

在 `~/.pig/agent/` 下：`auth.json` 存当前登录，`antigravity-accounts.json` 存所有已关联账号，`models-store.json` 存模型目录。pig 自己没有目录时，会读 pi 写的那份（`~/.pi/agent/models-store.json`）。

## 开发

测试对着一份 pig 检出运行：

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

`PIG_DIR` 默认是本包旁边的 `pig` 目录。`scripts/fetch-antigravity-models.php` 根据线上目录打印内置的回退表（需要已登录的账号，或用 `--from <保存的响应>`）。

## 许可

MIT，见 [LICENSE](LICENSE) 和 [NOTICE](NOTICE)。
