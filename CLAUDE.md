# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Symfony 7.4 + API Platform 4.3 backend for a Vue.js portfolio site. PHP >= 8.3 (8.4 in use), MySQL 8, Doctrine ORM 3.x, JWT auth via LexikJWTAuthenticationBundle.

## Common commands

```bash
composer install                                 # install deps (runs cache:clear + assets:install)
php bin/console cache:clear                      # clear Symfony cache
php bin/console doctrine:database:create         # create DB defined in DATABASE_URL
php bin/console doctrine:migrations:migrate      # apply migrations from migrations/
php bin/console make:migration                   # generate a migration from entity diff
php bin/console doctrine:fixtures:load           # load UserFixtures (admin user)
php bin/console lexik:jwt:generate-keypair       # generate config/jwt/{private,public}.pem
php bin/console app:chat:ingest                  # ingest data/chat-knowledge.json into chat_chunk (calls Gemini embeddings)
php bin/console app:chat:purge-logs              # delete chat_log rows older than CHAT_LOG_RETENTION_DAYS
symfony serve                                    # local dev server (or `php -S 127.0.0.1:8000 -t public`)
```

## Testing

PHPUnit 13 via `symfony/test-pack`. Tests live under `tests/`, using SQLite in-memory for kernel tests (`DATABASE_URL="sqlite:///:memory:"` in `.env.test`).

```bash
php bin/phpunit                                  # run the whole suite
php bin/phpunit tests/Chat/                      # only chat tests
vendor/bin/phpstan analyse                       # static analysis level 10 (configured in phpstan.dist.neon)
```

## Environment

Required env vars (see `.env` for the full list, set real values in `.env.local`):

- `DATABASE_URL` — MySQL 8 DSN (e.g. `mysql://user:pass@127.0.0.1:3306/db?serverVersion=8.0`).
- `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` / `JWT_PASSPHRASE` — generated via `lexik:jwt:generate-keypair`.
- `USER_ADMIN_EMAIL` / `USER_ADMIN_PASSWORD` — consumed by `UserFixtures` at fixture load time.
- `EMAIL_TO` / `EMAIL_FROM` — used by `EmailService` for the contact-form notification.
- `HCAPTCHA_VERIFY_URL` / `HCAPTCHA_SECRET_KEY` — hCaptcha siteverify endpoint and secret. Free plan returns `success` only (no `score`), so the verifier only checks `success`.
- `CORS_ALLOW_ORIGIN` — regex, defaults to localhost in `.env`.
- `FRONT_URL` — target of the `/` redirect in non-dev environments (see `IndexController`); also checked by `OriginCheckListener` for `/api/chat`.
- `GEMINI_API_KEY` / `GEMINI_API_BASE_URL` / `GEMINI_EMBEDDING_MODEL` / `GEMINI_GENERATION_MODEL` / `GEMINI_DAILY_LIMIT` — Google Gemini API credentials and tuning (free tier).
- `CHAT_KNOWLEDGE_FILE` — absolute path to the knowledge JSON (uses `%kernel.project_dir%`, resolved via `env(resolve:...)`).
- `CHAT_SYSTEM_PROMPT_FILE` — absolute path to the chat system prompt (read via `env(file:resolve:...)`).
- `CHAT_RETRIEVAL_K` / `CHAT_RETRIEVAL_THRESHOLD` / `CHAT_HISTORY_MAX_PAIRS` / `CHAT_LOG_RETENTION_DAYS` — retrieval and conversation tuning.

## Architecture

Three public surfaces: **auth, contact form, and chat assistant**.

- **Auth**: `POST /api/login_check` (route `auth` in `config/routes.yaml`). Stateless JWT firewall (`config/packages/security.yaml`) — the JSON login handler is wired to Lexik's success/failure handlers. Subsequent requests authenticate by `Authorization: Bearer <jwt>`.
- **Contact resource** (`src/Entity/Contact.php`): exposed by API Platform with a single `POST` operation, secured by `is_granted('ROLE_ADMIN')` even though it is the contact-form endpoint — the front sends the admin JWT plus a reCAPTCHA token. The route is `/api/contacts`.
- **Chat assistant**: `POST /api/chat` — stateless RAG pipeline (Gemini embeddings + generation, MySQL JSON vector store, in-PHP cosine retrieval, SSE streaming). See dedicated section below.
- **Index** (`src/Controller/IndexController.php`): `/` redirects to `FRONT_URL` in non-dev, to `/_profiler` in dev. There is no UI in this app.

### Contact write pipeline

`POST /api/contacts` goes through this chain — when modifying the contact flow, all three layers usually need to stay in sync:

1. **Denormalization** into `Contact` using the `contact:write` serialization group. The `token` field exists only in this group (no DB column) and carries the reCAPTCHA response.
2. **Validation**: standard constraints (`NotBlank`, `Email`, `AssertPhoneNumber`) plus `HCaptchaConstraint` on `token`. `HCaptchaConstraintValidator` POSTs to `HCAPTCHA_VERIFY_URL` with the secret + token and fails the constraint if `success` is false. Empty/null tokens short-circuit and pass — front-end is expected to always send one.
3. **State processor** (`src/State/ContactProcessor.php`): wraps the Doctrine persist processor (`api_platform.doctrine.orm.state.persist_processor`), invoking `EmailService::sendMail()` *before* persisting. Mail failures are silently swallowed — persistence still proceeds.

`EmailService` renders `templates/email/contact.html.twig` via `TemplatedEmail` and sends through the Symfony Mailer (`MAILER_DSN`).

### Security model

- `User` entity (`src/Entity/User.php`) is the only identity provider, looked up by email. `ROLE_ADMIN` is a class constant (`User::ROLE_ADMIN`) — reuse it instead of the string literal when checking grants.
- `UserFixtures` creates exactly one admin user from `USER_ADMIN_EMAIL` / `USER_ADMIN_PASSWORD`. There is no registration endpoint.
- `ContactVoter` exists but the `Contact` resource currently checks `ROLE_ADMIN` inline in the `#[ApiResource]` attribute, not via the voter — the voter is not actively wired into the resource's security expression.
- Access control in `security.yaml`: `/api/docs` is public, `POST /api/login` is public, `POST /api/contacts` requires `IS_AUTHENTICATED_FULLY`. Everything else inherits the firewall default.

### Phone numbers

`Contact::$phone` uses the `phone_number` Doctrine type from `odolbeau/phone-number-bundle` and stores a `libphonenumber\PhoneNumber`. The setter accepts a string and parses it via `PhoneNumberUtil`, swallowing `NumberParseException` to null — keep this behaviour when touching the setter. The OpenAPI schema is overridden to `string` via `#[ApiProperty(openapiContext: ['type' => 'string'])]`.

## Chat assistant

Standalone RAG pipeline on `POST /api/chat`, scoped to Caroline's portfolio. All sources live under `src/Chat/*` (controller, services, entities, repos, command, listener, DTO, exception). Tests live under `tests/Chat/*`.

### Request pipeline (in order)

1. `OriginCheckListener` (priority 16, after `RouterListener` priority 32) — 403 if `Origin !== FRONT_URL`.
2. `HCaptchaVerifier::verify($request->headers->get('X-HCaptcha-Token'))` — 403 if invalid. Shared with the contact form validator.
3. `RateLimiterFactory $chatPerIpLimiter` (sliding window, 60/h per IP, declared in `config/packages/rate_limiter.yaml`) — 429 on overflow.
4. JSON → `ChatRequest` DTO via Serializer + Validator constraints (`NotBlank`, `Length 1-500`, Unicode `Regex`, `Count max 6` on history) — 400 on failure.
5. `QuotaGuard` — checks the daily Gemini counter in `cache.app` (`gemini_quota:YYYY-MM-DD`, TTL 26h). If exhausted → SSE `event: error reason: quota_exceeded`, no HTTP call.
6. `EmbeddingService::embed($question)` — Gemini `batchEmbedContents` with `outputDimensionality: 768`. 429 → `QuotaExceededException` + guard exhausted.
7. `ChatChunkRepository::findTopK($embedding, K)` — loads all chunks from `chat_chunk`, computes cosine in PHP (`dot / (||a|| * ||b||)`), sorts, slices top-K.
8. If `topScore < CHAT_RETRIEVAL_THRESHOLD` → SSE off-scope message + `event: done outcome: off_scope`. **No generation call** (saves quota).
9. `PromptBuilder::build($chunks, $history, $question)` — system prompt (from `config/prompts/chat_system.txt`) + chunks + history truncated to `CHAT_HISTORY_MAX_PAIRS * 2` messages + assistant answers truncated to 200 chars.
10. `GeminiClient::streamGenerate($prompt)` — yields tokens from Gemini `streamGenerateContent?alt=sse`.
11. Each token → SSE `event: chunk data: {"token":"..."}`. Final → `event: done outcome: answered`.
12. `ChatLogger::log(...)` persists a row in `chat_log` (question, answer, top_score, chunks_used keys, outcome enum).

### Data layer

- **`chat_chunk`** — knowledge base. Columns: `source_key` (unique), `content`, `content_hash` (sha256 for diff), `embedding` (JSON array of 768 floats), `metadata` (JSON), timestamps.
- **`chat_log`** — RGPD-minimal trace. Columns: `question`, `answer`, `top_score`, `chunks_used` (JSON of source_keys), `outcome` (enum `answered|off_scope|quota_exceeded|validation_error|internal_error`), `created_at`. **No IP, no session, no user identifier.**

The Doctrine mapping for `App\Chat\Entity` is registered in `config/packages/doctrine.yaml`.

### Knowledge ingestion

`data/chat-knowledge.json` (committed sample) feeds `app:chat:ingest`. Format:

```json
{ "entries": [ { "key": "unique.id", "type": "skill|experience|project|contact", "content": "text fed to Gemini", "tags": ["..."] } ] }
```

The command hashes each `content` with sha256, then **inserts** new entries, **updates** changed ones, **skips** unchanged, **deletes** missing. `--force` re-embeds everything. `--file=path` overrides the default. Embeddings are batched (up to 100 per Gemini call).

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
| `CHAT_RETRIEVAL_THRESHOLD` | 0.65 | Lower if too many legitimate questions hit off-scope; raise if off-topic queries slip through |
| `CHAT_HISTORY_MAX_PAIRS` | 3 | Lower to save tokens, raise for richer multi-turn |
| `GEMINI_DAILY_LIMIT` | 1200 | Raise toward 1500 if traffic justifies and free tier is comfortable |

Inspect realized scores to recalibrate:

```bash
php bin/console doctrine:query:sql "SELECT outcome, MIN(top_score), AVG(top_score), MAX(top_score) FROM chat_log GROUP BY outcome"
```
