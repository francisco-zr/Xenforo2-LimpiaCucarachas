# Xenforo2-[ForoPL] Limpia Cucarachas

A XenForo 2.3 add-on that stops the classic overnight spammer: a fresh account posts a few harmless messages to get past XenForo's spam checks, then floods the forum with link spam until a moderator wakes up.

It uses an AI model to judge suspicious messages — with near-zero cost and zero impact on normal traffic.

## How it works

When a **recently registered account** posts a message **containing a link**, the message is sent to an AI model with a spam-classification prompt:

- **Spam verdict** — the message goes to the approval queue, with the AI's reasoning visible to moderators in the spam trigger log.
- **Clean verdict** — the message is posted normally. Legitimate new users who share a link are not punished.
- **API down or unreachable** — fail-closed: the suspicious message is held for moderation anyway, so an API outage never opens the door to spam.

### Flood protection

If a recent account already has several messages sitting in the moderation queue, further submissions are **blocked outright** — no AI call needed.

### Posting bursts

A determined spammer may post a burst of short, link-free messages to look like an active member before dropping their links. When a watched account exceeds a configurable number of messages within a rolling window (10 in 15 minutes by default), those link-free messages are also sent to the AI, with the posting rate included in the prompt.

### Media embeds

A spammer can hide a link inside an embedded social media post — for example `[MEDIA=twitter]2044…[/MEDIA]` — which carries no URL in the message text and would otherwise skip the check. On watched accounts, untrusted media embeds are **held for a moderator to review**, because the AI cannot see inside the embed. Harmless platforms (YouTube, Vimeo, Dailymotion, Twitch, SoundCloud, Spotify, Apple Music, Giphy, Imgur, Flickr) are trusted by default; you can also choose to let the AI analyse them instead, or turn media handling off.

### Closes the message-count loophole — and catches dormant accounts

XenForo only spam-checks users below the *"Maximum messages to check for spam"* threshold, which spammers bypass by posting a few innocent messages first. This add-on keeps all spam checks active (including your spam phrases and Akismet, if configured) for a configurable number of days **counted from the user's first message**, regardless of message count.

Counting from the first message — not from registration — means sleeper accounts don't slip through: an account registered months in advance still gets the full watch window the day it starts posting.

## AI provider

Works with **any OpenAI-compatible chat completions API**. Just set the endpoint URL, API key and model name in the options:

- **[OpenCode Zen](https://opencode.ai/)** — default configuration. The `big-pickle` test model is **free**, so you can try the add-on without spending anything. Requests identify the add-on with its own user agent and send the `x-opencode-session` header (a stable ID per watched user) as required by OpenCode Go.
- **DeepSeek** (`https://api.deepseek.com/chat/completions`, model `deepseek-chat`) — extremely cheap per-call pricing.
- OpenRouter, OpenAI, or any other compatible endpoint.

Since the AI is only called for recent accounts posting links (or posting in a burst), a typical forum makes a handful of calls per day — cost is effectively zero.

### Tamper-evident moderation

The text that triggered the flag is stored in the spam trigger log, so it stays visible in the approval queue even if the author rewrites the message while it is queued. Those rewrites are noted too, so an innocent-looking message in the queue can always be checked against what was actually submitted.

## Where decisions appear

The add-on keeps no log of its own; everything is visible through XenForo's native tools:

- **Spam trigger log / approval queue** — the AI's verdict and reasoning for held content, plus a warning when the author edits the message while it is queued.
- **Spam cleaner log** — the users you clean up as spammers.
- **Moderator log** — approvals, deletions and other moderator actions on the content.

## Options

All thresholds are configurable under **Admin CP → Options → [ForoPL] Limpia Cucarachas**:

- Days to watch after a user's first message
- Queued messages before blocking
- Only check messages containing links (on/off)
- Analyze link-free messages during posting bursts, and the message count / window
- Media embeds: hold for review / analyse with the AI / ignore, and the trusted media sites
- User groups exempt from the AI check
- API endpoint URL, key and model
- Request timeout, response token budget, message truncation
- Custom system prompt (advanced)

## Performance

Built for large forums:

- Zero added queries and zero HTTP calls for normal traffic — the add-on only wakes up for recent accounts.
- No template modifications, no JavaScript, no hooks on hot code paths.
- Failures are logged in the add-on's own log, not the server error log.

## Requirements

- XenForo 2.3+
- An API key for any OpenAI-compatible endpoint (OpenCode Zen's free tier works)
