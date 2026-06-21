# CLAUDE.md

## Project

Symfony 7.4 + API Platform 4.3 backend for a Vue.js portfolio (PHP 8.4, MySQL 8, Doctrine ORM 3). **No auth layer, by design**: no users, no login, no JWT, SecurityBundle removed. The two public endpoints (contact form, chat assistant) are anonymous, protected by anti-abuse controls (hCaptcha, rate limiting, origin checks), not by login. See Security model.

## Commands

```bash
composer install                            # deps (runs cache:clear + assets:install)
php bin/console doctrine:migrations:migrate # apply migrations/
php bin/console make:migration              # generate a migration from entity diff
php bin/console app:chat:ingest             # embed data/chat-knowledge.json into chat_chunk (calls Gemini)
php bin/console app:chat:ask "question"     # run one question through the pipeline, print answer + retrieval diagnostics
php bin/console app:chat:purge-logs         # delete chat_log rows older than CHAT_LOG_RETENTION_DAYS
symfony serve                               # dev server (or php -S 127.0.0.1:8000 -t public)
```

## Testing

```bash
php bin/phpunit                  # full suite (SQLite in-memory, .env.test)
php bin/phpunit tests/Chat/      # chat only
vendor/bin/phpstan analyse       # level 10; reads var/cache/dev container, run cache:warmup if cold
```

## Environment

Real values go in `.env.local` (`.env` is committed with placeholders); full list in `.env`. The non-obvious ones:

- `SYMFONY_TRUSTED_PROXIES`: **MUST be set in production** to the reverse-proxy IP(s)/CIDRs, otherwise `getClientIp()` returns the proxy IP and every client shares one rate-limit bucket. Unset locally means no proxy trusted (a spoofed `X-Forwarded-For` is ignored).
- `FRONT_URL`: allowed `Origin` for `/api/chat` and `/api/contacts`, and target of the `/` redirect in non-dev.
- `CHAT_KNOWLEDGE_FILE` / `CHAT_SYSTEM_PROMPT_FILE`: resolved via `env(resolve:...)` and `env(file:resolve:...)`.
- `CHAT_SESSION_TTL`: chat session-token lifetime in seconds (default 1800, fallback in `config/services.yaml`).
- `DATABASE_URL`, `MAILER_DSN` / `EMAIL_TO` / `EMAIL_FROM`, `CORS_ALLOW_ORIGIN`, `HCAPTCHA_*`, `GEMINI_*`, `CHAT_RETRIEVAL_*` / `CHAT_HISTORY_MAX_PAIRS` / `CHAT_LOG_RETENTION_DAYS`: self-explanatory; see `.env` and Tunable parameters.

## Architecture

Two public surfaces, both anonymous:

- **Contact** (`src/Entity/Contact.php`): single `POST /api/contacts`, no security expression. Guarded by `ContactGuardListener` (origin + per-IP limit), single-use hCaptcha, honeypot, daily global cap, strict validation. API Platform auto-adds a `NotExposed` GET (404) for IRI generation only; submissions are never readable over HTTP.
- **Chat** (`POST /api/chat`, session mint at `POST /api/chat/session`): stateless RAG (Gemini embeddings + generation, MySQL JSON vector store, in-PHP cosine, SSE). See Chat assistant.
- **Index** (`src/Controller/IndexController.php`): `/` redirects to `FRONT_URL` (non-dev) or `/_profiler` (dev). No UI.

### Contact write pipeline

`POST /api/contacts`, layers that must stay in sync:

0. `ContactGuardListener` (`kernel.request` priority 16): 403 if `Origin !== FRONT_URL`, then `contact_per_ip` limit (10/h) → 429. Structural anti-flood, since every accepted POST sends an email.
1. Denormalize via the `contact:write` group. `token` (hCaptcha response) and honeypot `website` live only in this group, with no DB column.
2. Validation, all failures → **422**: `Length` matching each column (50 names, 100 email/company/opportunity, 5000 message), `Email`, `AssertPhoneNumber`, plus `NotBlank` + `HCaptchaConstraint` on `token` and `Blank` on `website` (real front never renders it).
3. `ContactProcessor`: consumes `contact_global` daily cap (100/day) → 429 if exhausted, then `EmailService::sendMail()` **before** persist. Mail failures are swallowed (persist still proceeds). The global cap is consumed post-validation so captcha-failing requests can't burn it.

`EmailService` renders `templates/email/contact.html.twig` and sends via Symfony Mailer (`MAILER_DSN`).

### Security model

Security rests on anti-abuse, not identity (a public SPA can't hold a secret). Non-obvious points:

- **No auth, by design.** No `User`/firewall/login/JWT; SecurityBundle removed, no `security.yaml`. If an authenticated area is ever needed (e.g. an admin to read submissions), reintroduce SecurityBundle + a `User` and protect *those* operations; never gate the public form behind a front-embedded credential.
- **`NotBlank` on `Contact::$token` is load-bearing.** `HCaptchaConstraintValidator` fail-opens (returns without a violation on a null/empty token), so `NotBlank(error.captcha.missing)` is what rejects a missing token (422). **Do not remove it** without first making the validator fail closed.
- **Chat captcha is fail-closed and one-shot per session.** No captcha per message: one solve at `POST /api/chat/session` (`HCaptchaVerifier::verify`, fails closed, missing/invalid → 422 on `token`) mints a session token; `POST /api/chat` only checks that token (`X-Chat-Session` → 401 on missing/invalid/expired). Bot wall = session creation (`chat_session_per_ip`, 20/h); message volume = `chat_per_ip` (60/h).
- **hCaptcha tokens are single-use (anti-replay).** `HCaptchaVerifier` records each accepted token (sha256 in `cache.app`, TTL 600s) and rejects any second presentation. Failed tokens are not recorded, so a transient siteverify failure doesn't burn a legit token. Shared by contact form and chat-session mint.
- **Contact is never readable.** Only `POST` is declared; the auto `NotExposed` GET returns 404. Do not add a real `Get`/`GetCollection` (there's no auth layer to protect it).
- **Defense in depth (contact).** No single wall: single-use hCaptcha + `contact_per_ip` (10/h) + `contact_global` (100/day) + honeypot + origin check + validation. The strongest *additional* layer is infrastructural (edge WAF / Cloudflare Turnstile) and lives outside this app.
- **Reduced prod recon surface.** `api_platform.yaml` disables docs/swagger/redoc/entrypoint `when@prod`. CORS (`nelmio_cors.yaml`): `allow_methods: [POST, OPTIONS]`, `allow_headers: [Content-Type, Authorization, X-HCaptcha-Token, X-Chat-Session, Cache-Control]`, `expose_headers` includes `Retry-After`. `Authorization` is kept only so a legacy bearer token doesn't trip CORS preflight; it is ignored server-side.

### Phone numbers

`phone` uses the `phone_number` Doctrine type (`odolbeau/phone-number-bundle`). The international `+` prefix is **mandatory** (default region `Contact::PHONE_DEFAULT_REGION = 'FR'`). Empty string becomes `null`; a string without `+` or that fails to parse throws `NotNormalizableValueException`, which (because `collect_denormalization_errors: true`) is collected as a **422** violation on `phone`, not a 400. Invalid input is never silently nulled. OpenAPI advertises `phone` as a plain `string` via `#[ApiProperty(openapiContext: ['type' => 'string'])]`.

## Chat assistant

RAG pipeline scoped to Caroline's portfolio. Sources under `src/Chat/*` (controllers, services, entities, repos, commands, listener, DTO, exception, `Http/`, `Stream/`); tests under `tests/Chat/*`. All pre-stream errors are `application/problem+json` (RFC 7807) via `ProblemResponseFactory`.

**Two-step flow.** The front mints a session token once at `POST /api/chat/session` (one hCaptcha solve), then posts many messages to `POST /api/chat` carrying header `X-Chat-Session` until it expires. The token is a stateless HMAC-SHA256 blob (`{iat, exp, nonce}` signed with `%kernel.secret%`, TTL `CHAT_SESSION_TTL`) issued/validated by `ChatSessionTokenManager`: no DB, no JWT, no SecurityBundle.

**`POST /api/chat` pipeline.** `ChatController` is thin (HTTP guards only), then delegates to `ChatPipeline::run()`, which yields typed events (`ChunkEvent`/`DoneEvent`/`ErrorEvent`) that `SseStreamFactory` turns into the SSE response. No business logic in the controller.

1. `OriginCheckListener` (priority 16): 403 if `Origin !== FRONT_URL` (covers both chat routes).
2. `ChatSessionTokenManager::check(X-Chat-Session)`: 401 `session_invalid` / `session_expired`. Captcha is not re-checked here (consumed at mint).
3. `chatPerIpLimiter` (60/h): 429 with `Retry-After`.
4. JSON → `ChatRequest` DTO + Validator (`NotBlank`, `Length 1-500`, Unicode `Regex`, `Count max 6` + `Valid` cascade on history) → 422; malformed JSON → 400.
5. `QuotaGuard`: daily Gemini counter (`gemini_quota.YYYY-MM-DD`). Exhausted → SSE `error quota_exceeded`, no HTTP call.
6. `EmbeddingService::embed($q, RetrievalQuery)`: `batchEmbedContents`, `outputDimensionality: 768`. **taskType asymmetry matters**: chunks are embedded `RETRIEVAL_DOCUMENT` at ingest, queries `RETRIEVAL_QUERY` here; without it the cosine scores of relevant and off-topic content overlap and no threshold separates them.
7. `ChatChunkRepository::findTopK`: loads all chunks, cosine in PHP, slices top-K.
8. `topScore < CHAT_RETRIEVAL_THRESHOLD` → SSE off-scope + `done off_scope`. **No generation call** (saves quota).
9. `PromptBuilder::build($chunks, $history, $question, $greeting)`: system prompt (`config/prompts/chat_system.txt`) + chunks + history truncated to `CHAT_HISTORY_MAX_PAIRS * 2` (assistant answers to 200 chars).
10. `GeminiClient::streamGenerate`: `streamGenerateContent?alt=sse`, `generationConfig`: `maxOutputTokens: 200`, `temperature: 0.2`, `thinkingConfig.thinkingBudget: 0`. **Don't re-enable thinking**: on the small thinking-capable model, default thinking + high temperature produced garbled answers for this grounded-RAG task.
11. Tokens → SSE `chunk`; final → `done answered`. If the streamed answer contains the system-prompt leak marker, the stream is cut and logged `internal_error`.
12. `ChatLogger::log(...)` writes a `chat_log` row.

**Greeting short-circuit (before retrieval).** A message that is only a greeting (`GREETING_WORDS`, optionally with `GREETING_FILLERS` small talk) is answered warmly with **no Gemini call** (a bare greeting falls below the retrieval threshold and would otherwise hit off-scope). The greeting word is time-aware via an injected `ClockInterface` (`Bon matin` before 13h Europe/Paris, else `Bonjour`). "Bonjour, tu fais du Symfony ?" is *not* a greeting and flows through normal retrieval.

**Caching (`ChatQueryCache`, in `cache.app`).** For stateless turns (empty `history`), `answered`/`off_scope` are cached by normalized question (`chat.answer.<knowledgeVersion>.<hash>`, 7d) so a repeat costs 0 Gemini calls. Query embeddings are cached separately (`chat.qembed.<model>.<hash>`, 30d) so a repeat with history costs 1 call (generation only). Error/quota/leak outcomes are never cached. The answer cache is invalidated on every ingest via a bumped `chat.knowledge_version`. `app:chat:ask` bypasses the cache.

### Data layer

- **`chat_chunk`**, the knowledge base: `source_key` (unique), `content`, `content_hash` (sha256), `embedding` (JSON, 768 floats), `metadata`, timestamps.
- **`chat_log`**, RGPD-minimal: `question`, `answer`, `top_score`, `chunks_used`, `outcome` (`answered|off_scope|quota_exceeded|validation_error|internal_error`), `created_at`. **No IP, session or user id.** Before storage `ChatLogger` sanitizes both `question` and `answer`: personal data (email/phone/16-digit card) → `[PERSONAL_DATA]`, API-key tokens in the answer → `[REDACTED]`, then `strip_tags`, control-char stripping (anti log-injection), truncation (question 500, answer 5000).

Doctrine mapping for `App\Chat\Entity` is registered in `config/packages/doctrine.yaml`.

### Knowledge ingestion

`data/chat-knowledge.json` feeds `app:chat:ingest`:

```json
{ "entries": [ { "key": "unique.id", "type": "skill|experience|project|contact", "content": "text fed to Gemini", "tags": ["..."] } ] }
```

Each `content` is screened against `KnowledgeIngester::FORBIDDEN_PATTERNS` (instruction injection, `$ENV_VAR` shapes, API-key tokens) before embedding; any match aborts the whole ingestion (`InvalidArgumentException`), so a poisoned file never reaches `chat_chunk`. The command hashes each `content` (sha256), then inserts new / updates changed / skips unchanged / deletes missing. `--force` re-embeds all, `--file=path` overrides. Batched (100/call) with `RETRIEVAL_DOCUMENT`. **After changing the embedding model or task type, re-run `--force` and recalibrate `CHAT_RETRIEVAL_THRESHOLD`** (the score distribution shifts). Each ingest invalidates the chunk cache and the cached answers.

### Zero-budget guarantee

`QuotaGuard` enforces `GEMINI_DAILY_LIMIT` (default 1200, below the 1500 RPD free tier). On a Gemini 429 the guard force-exhausts, so same-day calls short-circuit without HTTP. No overage is billable as long as the key has no billing enabled.

### Log retention (cron)

Daily RGPD purge on the prod VPS:

```cron
0 3 * * *  /usr/bin/php /var/www/portfolio-api/bin/console app:chat:purge-logs --no-interaction
```

Deletes `chat_log` rows older than `CHAT_LOG_RETENTION_DAYS` (default 30) via `ChatLogRepository::deleteOlderThan()`.

### Tunable parameters (`.env.local`)

| Var | Default | When to change |
|---|---|---|
| `CHAT_RETRIEVAL_K` | 5 | Lower to save input tokens, raise if answers feel under-informed |
| `CHAT_RETRIEVAL_THRESHOLD` | 0.65 | Lower if legit questions hit off-scope, raise if off-topic slips through. Calibrate with `app:chat:ask` on in-scope vs off-topic questions |
| `CHAT_HISTORY_MAX_PAIRS` | 3 | Lower to save tokens, raise for richer multi-turn |
| `GEMINI_DAILY_LIMIT` | 1200 | Raise toward 1500 if traffic justifies |

Inspect realized scores: `php bin/console doctrine:query:sql "SELECT outcome, MIN(top_score), AVG(top_score), MAX(top_score) FROM chat_log GROUP BY outcome"`

## Gotchas

- **`.env` is committed** with placeholders; secrets belong in `.env.local` or the secrets vault. `HCAPTCHA_SITE_KEY` / `HCAPTCHA_SECRET_KEY` are blank by design.
- **Email errors are intentionally swallowed** in `ContactProcessor::process()`. Don't assume the empty `catch` is a bug.
- **Mixed indentation**: 2-space in newer files (`Contact.php`, `IndexController.php`), 4-space in older Symfony-generated ones. Match the file you edit, don't reformat.
- **Migrations are MySQL-dialect** (`AUTO_INCREMENT` / `LONGTEXT` / `utf8mb4`), run under MAMP. `doctrine:migrations:diff` pulls unapplied pending migrations in as noise; trim the generated file to just your intended change.
