# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Symfony 7.4 + API Platform 4.3 backend for a Vue.js portfolio site. PHP >= 8.3 (8.4 in use), MySQL 8, Doctrine ORM 3.x. **No authentication layer**: there are no users, no login and no JWT (all removed, along with Symfony's SecurityBundle). The two public endpoints (contact form, chat assistant) are anonymous and protected by anti-abuse controls — hCaptcha, rate limiting, origin checks — not by login. See Security model.

## Common commands

```bash
composer install                                 # install deps (runs cache:clear + assets:install)
php bin/console cache:clear                      # clear Symfony cache
php bin/console doctrine:database:create         # create DB defined in DATABASE_URL
php bin/console doctrine:migrations:migrate      # apply migrations from migrations/
php bin/console make:migration                   # generate a migration from entity diff
php bin/console app:chat:ingest                  # ingest data/chat-knowledge.json into chat_chunk (calls Gemini embeddings)
php bin/console app:chat:ask "question"          # run one question through the pipeline (real Gemini) and print answer + retrieval diagnostics
php bin/console app:chat:purge-logs              # delete chat_log rows older than CHAT_LOG_RETENTION_DAYS
symfony serve                                    # local dev server (or `php -S 127.0.0.1:8000 -t public`)
```

## Testing

PHPUnit 13 via `symfony/test-pack`. Tests live under `tests/`, using SQLite in-memory for kernel tests (`DATABASE_URL="sqlite:///:memory:"` in `.env.test`).

```bash
php bin/phpunit                                  # run the whole suite
php bin/phpunit tests/Chat/                      # only chat tests
vendor/bin/phpstan analyse                       # static analysis level 10 (configured in phpstan.dist.neon)
# phpstan reads var/cache/dev/App_KernelDevDebugContainer.xml — run `cache:warmup` first if the dev cache is cold.
vendor/bin/phpstan analyse src/Entity/Contact.php  # scope analysis to specific files by passing paths
```
## Environment

Required env vars (see `.env` for the full list, set real values in `.env.local`):

- `DATABASE_URL` — MySQL 8 DSN (e.g. `mysql://user:pass@127.0.0.1:3306/db?serverVersion=8.0`).
- `EMAIL_TO` / `EMAIL_FROM` — used by `EmailService` for the contact-form notification.
- `HCAPTCHA_VERIFY_URL` / `HCAPTCHA_SECRET_KEY` — hCaptcha siteverify endpoint and secret. Free plan returns `success` only (no `score`), so the verifier only checks `success`.
- `CORS_ALLOW_ORIGIN` — regex, defaults to localhost in `.env`.
- `FRONT_URL` — target of the `/` redirect in non-dev environments (see `IndexController`); also the allowed `Origin` for `/api/chat` (`OriginCheckListener`) and `/api/contacts` (`ContactGuardListener`).
- `SYMFONY_TRUSTED_PROXIES` — comma-separated reverse-proxy IP(s)/CIDRs (e.g. `127.0.0.1,REMOTE_ADDR`). **Must be set in production** or the per-IP rate limiters collapse to one shared bucket (see Security model). Empty/unset locally = no proxy trusted.
- `GEMINI_API_KEY` / `GEMINI_API_BASE_URL` / `GEMINI_EMBEDDING_MODEL` / `GEMINI_GENERATION_MODEL` / `GEMINI_DAILY_LIMIT` — Google Gemini API credentials and tuning (free tier).
- `CHAT_KNOWLEDGE_FILE` — absolute path to the knowledge JSON (uses `%kernel.project_dir%`, resolved via `env(resolve:...)`).
- `CHAT_SYSTEM_PROMPT_FILE` — absolute path to the chat system prompt (read via `env(file:resolve:...)`).
- `CHAT_RETRIEVAL_K` / `CHAT_RETRIEVAL_THRESHOLD` / `CHAT_HISTORY_MAX_PAIRS` / `CHAT_LOG_RETENTION_DAYS` — retrieval and conversation tuning.
- `CHAT_SESSION_TTL` — chat session token lifetime in seconds (default 1800, set as a `parameters: env(CHAT_SESSION_TTL)` fallback in `config/services.yaml`; override in `.env.local`).

## Architecture

Two public surfaces: **contact form and chat assistant**. Both are anonymous; there is no auth layer.

- **Contact resource** (`src/Entity/Contact.php`): exposed by API Platform with a single `POST` operation at `/api/contacts`, with **no security expression** (anonymous). It is guarded by `ContactGuardListener` (origin + per-IP rate limit), a single-use hCaptcha token, a honeypot field, a daily global cap, and strict validation. API Platform auto-adds a `NotExposed` GET item (returns 404) for IRI generation only — submissions are never readable over HTTP.
- **Chat assistant**: `POST /api/chat` — stateless RAG pipeline (Gemini embeddings + generation, MySQL JSON vector store, in-PHP cosine retrieval, SSE streaming). See dedicated section below.
- **Index** (`src/Controller/IndexController.php`): `/` redirects to `FRONT_URL` in non-dev, to `/_profiler` in dev. There is no UI in this app.

### Contact write pipeline

`POST /api/contacts` goes through this chain — when modifying the contact flow, all layers usually need to stay in sync:

0. **`ContactGuardListener`** (`src/EventListener/ContactGuardListener.php`, `kernel.request` priority 16, before the controller) — for `POST /api/contacts` only: 403 if `Origin !== FRONT_URL`, then a per-IP sliding-window rate limit (`contact_per_ip`, 10/h, in `config/packages/rate_limiter.yaml`) → 429 on overflow. Mirrors the chat endpoint's inline checks; it is the structural anti-flood guard since every accepted POST sends an email.
1. **Denormalization** into `Contact` using the `contact:write` serialization group. The `token` field exists only in this group (no DB column) and carries the hCaptcha response.
2. **Validation**: standard constraints (`NotBlank` with `normalizer: 'trim'`, `Length` matching each DB column — 50 for names, 100 for email/company/opportunity, 5000 for message — `Email`, `AssertPhoneNumber`) plus `NotBlank` + `HCaptchaConstraint` on `token`. `HCaptchaConstraintValidator` POSTs to `HCAPTCHA_VERIFY_URL` with the secret + token and fails the constraint if `success` is false. The validator still short-circuits on empty/null, but `NotBlank` (key `error.captcha.missing`) now rejects a missing/empty token first, so a captcha-less submission can no longer pass — see the security note below. A honeypot field `website` (write-group only, no column, `Blank` constraint, key `error.field.invalid`) must stay empty; the real front never renders it, so a bot that fills it gets a 422.
3. **State processor** (`src/State/ContactProcessor.php`): wraps the Doctrine persist processor (`api_platform.doctrine.orm.state.persist_processor`). First consumes the `contact_global` daily limiter (100/day, all IPs) — 429 if exhausted, before any mail or persist — then invokes `EmailService::sendMail()` *before* persisting. Mail failures are silently swallowed — persistence still proceeds. The global cap is consumed here (post-validation) so captcha-failing requests can't burn it.

`EmailService` renders `templates/email/contact.html.twig` via `TemplatedEmail` and sends through the Symfony Mailer (`MAILER_DSN`).

### Security model

- **No authentication, by design.** There is no `User`, no firewall, no login, no JWT; Symfony's SecurityBundle is removed and `config/packages/security.yaml` no longer exists. Both public endpoints are anonymous. Security rests entirely on the anti-abuse controls below, not on identity — a public SPA cannot hold a secret, so authenticating it was theatre. If a real authenticated area is ever needed (e.g. an admin to read submissions), reintroduce SecurityBundle + a `User` and protect *those* operations explicitly; never gate the public contact form behind a front-embedded credential again.
- **hCaptcha is the only bot filter — `NotBlank` on the token is load-bearing.** `HCaptchaConstraintValidator::validate()` fail-opens: it returns *without a violation* on a `null`/empty token. So `NotBlank(message: 'error.captcha.missing')` on `Contact::$token` is what rejects a missing/empty token (422) before that fail-open branch. With no auth behind the form, hCaptcha is the sole identity-free wall, which makes this `NotBlank` critical — **do not remove it** without first moving the validator to fail closed. (The chat path below already fails closed.)
- **Chatbot captcha path.** The chat no longer verifies hCaptcha per message. The single solve happens at `POST /api/chat/session` (`HCaptchaVerifier::verify(X-HCaptcha-Token)`, *fails closed*, single-use anti-replay — shared with the contact form), which mints a short-lived session token via `ChatSessionTokenManager`. `POST /api/chat` then only validates that token (`X-Chat-Session` → **401** `session_invalid`/`session_expired` on missing/invalid/expired). The bot wall sits at session creation (capped by `chat_session_per_ip`, 20/h), message volume by `chat_per_ip` (60/h). A captcha-less or session-less chat request can never reach generation. Missing/invalid captcha at the mint endpoint → **422** violation on `token`, same shape as the contact form.
- **hCaptcha tokens are single-use (anti-replay).** `HCaptchaVerifier::verify()` records each accepted token (sha256, in `cache.app` under `hcaptcha.<hash>`, TTL 600s > hCaptcha's ~2 min token lifetime) and rejects any second presentation. Without this, siteverify would accept the same solved token repeatedly, letting one captcha solve be scripted into many submissions. Failed tokens are **not** recorded, so a transient siteverify failure doesn't burn a legitimate token. Shared by both the contact form and the chat endpoint.
- **Trusted proxies.** `framework.yaml` sets `trusted_proxies: '%env(default::SYMFONY_TRUSTED_PROXIES)%'`. **In production this env var MUST be set** to the reverse-proxy IP(s); otherwise `getClientIp()` returns the proxy IP and every client shares one rate-limit bucket. Unset locally it resolves to empty (no proxy trusted, `X-Forwarded-*` ignored) so a spoofed `X-Forwarded-For` cannot dodge the limiter.
- **Contact is never readable via the API.** The `Contact` resource declares only `POST`; API Platform auto-adds a `NotExposed` `GET` item operation (route `_api_/contacts/{id}{._format}_get`, controller `api_platform.action.not_exposed`) **purely for IRI generation — it returns 404**. There is no way to read stored submissions over HTTP. Keep it that way: there is no auth layer to protect a read operation, so do not add a real `Get`/`GetCollection` (a future admin reader would need SecurityBundle reintroduced first).
- **Anti-spam, defense in depth (contact form).** No single control is the wall; they stack: single-use hCaptcha (real bot filter) + `contact_per_ip` (10/h) + `contact_global` (100/day) + honeypot + `Origin` check + strict validation. A public form cannot be made literally un-callable outside the front (any browser request is replayable via curl); the goal is to make automated/at-scale abuse infeasible and cap the blast radius. The strongest *additional* layer is infrastructural (edge WAF / bot management such as Cloudflare Turnstile) and lives outside this app.
- **Recon surface reduced in prod.** `api_platform.yaml` disables `enable_docs` / `enable_swagger` / `enable_swagger_ui` / `enable_re_doc` / `enable_entrypoint` under `when@prod` (kept in dev). CORS (`nelmio_cors.yaml`) is narrowed to `allow_methods: [POST, OPTIONS]` and `allow_headers: [Content-Type, Authorization, X-HCaptcha-Token, X-Chat-Session, Cache-Control]` instead of the previous all-verbs + `*` wildcard; `expose_headers` includes `Retry-After` so the front can read it on a 429. `Authorization` is kept only so a front still sending a legacy bearer token doesn't trip CORS preflight; it is ignored server-side and can be dropped once the front stops sending it.

### Phone numbers
The `phone` column uses the `phone_number` Doctrine type from `odolbeau/phone-number-bundle` (registered in `config/packages/doctrine.yaml`). `Contact::setPhone()` accepts a `PhoneNumber` or a string; the international `+` prefix is **mandatory** (default region `Contact::PHONE_DEFAULT_REGION = 'FR'`). An empty string becomes `null`; a string without `+` or that fails to parse throws `NotNormalizableValueException`. Because `api_platform.yaml` sets `collect_denormalization_errors: true`, that error is collected as a **422** validation violation on `phone` (generic type message, e.g. `This value should be of type string.`), **not** a 400 — verified in `tests/Api/ContactResourceTest`. It does **not** silently null invalid input. The OpenAPI schema is overridden to advertise `phone` as a plain `string` (`#[ApiProperty(openapiContext: ['type' => 'string'])]`).

## Chat assistant

Standalone RAG pipeline on `POST /api/chat`, with session minting on `POST /api/chat/session`, scoped to Caroline's portfolio. All sources live under `src/Chat/*` (controllers, services, entities, repos, command, listener, DTO, exception, `Http/ProblemResponseFactory`). Tests live under `tests/Chat/*`.

### Request pipeline (in order)

The chat is **two-step**: the front mints a short-lived **session token** once at `POST /api/chat/session` (one hCaptcha solve), then sends many messages to `POST /api/chat` carrying that token (header `X-Chat-Session`) until it expires — instead of a captcha per message. All pre-stream errors are `application/problem+json` (RFC 7807) via `ProblemResponseFactory`.

**`POST /api/chat/session`** (`ChatSessionController`): `OriginCheckListener` (403) → `chat_session_per_ip` limiter (20/h → 429) → `HCaptchaVerifier::verify(X-HCaptcha-Token)` (fail-closed, single-use; missing/invalid → **422** violation on `token`) → returns `{session, expiresIn}`. The token is a stateless HMAC-SHA256 blob (payload `{iat, exp, nonce}`, signed with `%kernel.secret%`, TTL `CHAT_SESSION_TTL`, default 1800s) issued/validated by `ChatSessionTokenManager` — **no DB, no JWT, no SecurityBundle**.

**`POST /api/chat`.** `ChatController` is thin: it runs the HTTP guards (steps 1-4) then delegates to `ChatPipeline::run()` which executes the RAG pipeline (steps 5-12) and **yields typed events** (`App\Chat\Stream\ChunkEvent` / `DoneEvent` / `ErrorEvent`); `SseStreamFactory` turns that event stream into the SSE `StreamedResponse`. No business logic lives in the controller. Steps:

1. `OriginCheckListener` (priority 16, after `RouterListener` priority 32) — sets a 403 problem+json response if `Origin !== FRONT_URL` (covers both the `chat` and `chat_session` routes).
2. `ChatSessionTokenManager::check($request->headers->get('X-Chat-Session'))` — missing/invalid → **401** problem+json `reason: session_invalid`; expired → **401** `reason: session_expired`. hCaptcha is **not** checked here (it was consumed at `/api/chat/session`).
3. `RateLimiterFactory $chatPerIpLimiter` (sliding window, 60/h per IP, declared in `config/packages/rate_limiter.yaml`) — 429 problem+json (with `Retry-After`) on overflow.
4. JSON → `ChatRequest` DTO via Serializer + Validator constraints (`NotBlank`, `Length 1-500`, Unicode `Regex`, `Count max 6` + `Valid` cascade into `ChatMessage` on history) — **422** problem+json (`ConstraintViolation` + `violations[]`) on validation failure; malformed JSON → **400** problem+json.
5. `QuotaGuard` — checks the daily Gemini counter in `cache.app` (`gemini_quota.YYYY-MM-DD`, TTL 26h). If exhausted → SSE `event: error reason: quota_exceeded`, no HTTP call.
6. `EmbeddingService::embed($question, EmbeddingTaskType::RetrievalQuery)` — Gemini `batchEmbedContents` with `outputDimensionality: 768` and `taskType: RETRIEVAL_QUERY`. The asymmetry matters: chunks are embedded with `RETRIEVAL_DOCUMENT` at ingest, queries with `RETRIEVAL_QUERY` here — without it the cosine scores of relevant and off-topic content overlap and no threshold separates them. 429 → `QuotaExceededException` + guard exhausted.
7. `ChatChunkRepository::findTopK($embedding, K)` — loads all chunks from `chat_chunk`, computes cosine in PHP (`dot / (||a|| * ||b||)`), sorts, slices top-K.
8. If `topScore < CHAT_RETRIEVAL_THRESHOLD` → SSE off-scope message + `event: done outcome: off_scope`. **No generation call** (saves quota).
9. `PromptBuilder::build($chunks, $history, $question)` — system prompt (from `config/prompts/chat_system.txt`) + chunks + history truncated to `CHAT_HISTORY_MAX_PAIRS * 2` messages + assistant answers truncated to 200 chars.
10. `GeminiClient::streamGenerate($prompt)` — yields tokens from Gemini `streamGenerateContent?alt=sse`. `generationConfig` sets `maxOutputTokens: 200`, `temperature: 0.2`, and **`thinkingConfig.thinkingBudget: 0`** (thinking disabled). The latter two are deliberate: on a small thinking-capable model (e.g. `gemini-3.1-flash-lite`), default thinking + high temperature produced unstable/garbled answers for this simple grounded-RAG task. Don't re-enable thinking here.
11. Each token → SSE `event: chunk data: {"token":"..."}`. Final → `event: done outcome: answered`.
12. `ChatLogger::log(...)` persists a row in `chat_log` (question, answer, top_score, chunks_used keys, outcome enum).

**Caching to cut Gemini calls (`ChatQueryCache`, in `cache.app`).** For a stateless turn (empty `history`), `answered`/`off_scope` responses are cached by normalized question (`chat.answer.<knowledgeVersion>.<hash>`, 7-day TTL) → a repeated question costs **0** Gemini calls (the cached answer is replayed as SSE). Query embeddings are cached separately (`chat.qembed.<model>.<hash>`, 30-day TTL, keyed by `GEMINI_EMBEDDING_MODEL`) → a repeated question with history costs 1 call (generation only). Error/quota/leak outcomes are never cached. The answer cache is invalidated on every ingest via a bumped `chat.knowledge_version` stamp; query embeddings are not (they don't depend on the knowledge base). `app:chat:ask` deliberately bypasses the cache.

### Data layer

- **`chat_chunk`** — knowledge base. Columns: `source_key` (unique), `content`, `content_hash` (sha256 for diff), `embedding` (JSON array of 768 floats), `metadata` (JSON), timestamps.
- **`chat_log`** — RGPD-minimal trace. Columns: `question`, `answer`, `top_score`, `chunks_used` (JSON of source_keys), `outcome` (enum `answered|off_scope|quota_exceeded|validation_error|internal_error`), `created_at`. **No IP, no session, no user identifier.**

The Doctrine mapping for `App\Chat\Entity` is registered in `config/packages/doctrine.yaml`.

### Knowledge ingestion

`data/chat-knowledge.json` (committed sample) feeds `app:chat:ingest`. Format:

```json
{ "entries": [ { "key": "unique.id", "type": "skill|experience|project|contact", "content": "text fed to Gemini", "tags": ["..."] } ] }
```

The command hashes each `content` with sha256, then **inserts** new entries, **updates** changed ones, **skips** unchanged, **deletes** missing. `--force` re-embeds everything. `--file=path` overrides the default. Embeddings are batched (up to 100 per Gemini call) with `taskType: RETRIEVAL_DOCUMENT`. **After changing the embedding model or task type, re-run with `--force` and recalibrate `CHAT_RETRIEVAL_THRESHOLD`** — the score distribution shifts. Each ingest invalidates both the chunk cache and the cached chat answers (`ChatQueryCache::invalidateAnswers()`).

### Anti-abuse: zero-budget guarantee

`QuotaGuard` enforces `GEMINI_DAILY_LIMIT` (default 1200, below the 1500 RPD Gemini free tier). On Gemini 429, the guard is force-exhausted so subsequent same-day calls short-circuit without HTTP. Result: **no overage is ever billable** as long as the Gemini API key has no billing enabled.

### Cron — log retention

Add this line to crontab on the production VPS for daily RGPD purge at 03:00:

```cron
0 3 * * *  /usr/bin/php /var/www/portfolio-api/bin/console app:chat:purge-logs --no-interaction
```

The command deletes `chat_log` rows older than `CHAT_LOG_RETENTION_DAYS` (default 30) via `ChatLogRepository::deleteOlderThan()`.

### Tunable parameters (in `.env.local`)

| Var | Default | When to change |
|---|---|---|
| `CHAT_RETRIEVAL_K` | 5 | Lower to save input tokens, raise if answers feel under-informed |
| `CHAT_RETRIEVAL_THRESHOLD` | 0.65 | Lower if too many legitimate questions hit off-scope; raise if off-topic queries slip through. Calibrate against the realized score gap measured with `app:chat:ask "..."` on in-scope vs off-topic questions |
| `CHAT_HISTORY_MAX_PAIRS` | 3 | Lower to save tokens, raise for richer multi-turn |
| `GEMINI_DAILY_LIMIT` | 1200 | Raise toward 1500 if traffic justifies and free tier is comfortable |

Inspect realized scores to recalibrate:

```bash
php bin/console doctrine:query:sql "SELECT outcome, MIN(top_score), AVG(top_score), MAX(top_score) FROM chat_log GROUP BY outcome"
```
- **`.env` is committed** with placeholder defaults; real secrets belong in `.env.local` (gitignored) or Symfony's secrets vault. `HCAPTCHA_SITE_KEY` / `HCAPTCHA_SECRET_KEY` are blank in `.env` by design.
- **Email errors are intentionally swallowed** in `ContactProcessor::process()`. If you need to surface mailer failures, change that behavior deliberately — don't assume the empty `catch` is a bug.
- The codebase mixes indentation styles (2-space in newer files like `Contact.php` / `IndexController.php`, 4-space in older Symfony-generated files like `User.php`). Match the file you're editing rather than reformatting.
- **Migrations are MySQL-dialect** (`AUTO_INCREMENT` / `LONGTEXT` / `utf8mb4`) and run under MAMP. `doctrine:migrations:diff` pulls any *unapplied* pending migrations into the generated file as noise — trim the generated migration down to just your intended change.
