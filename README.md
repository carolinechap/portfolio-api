# Portfolio API

Symfony 7.4 + API Platform backend for my Vue.js portfolio. It exposes two public, **unauthenticated** endpoints — a contact form and a RAG chat assistant — protected by anti-abuse controls (hCaptcha, rate limiting, origin checks) rather than by login.

## Tech stack

- PHP >= 8.3, Composer 2.x
- Symfony 7.4, API Platform 4.3
- Doctrine ORM 3.x, MySQL 8
- Google Gemini API (embeddings + generation) for the chat assistant
- PHPUnit (`symfony/test-pack`), PHPStan (level 10)

## Features

- **Contact form** (`POST /api/contacts`): a write-only API Platform resource that sends an email. Guarded by hCaptcha, per-IP and daily global rate limits, a honeypot, an `Origin` check and strict validation. Submissions are never readable over HTTP.
- **Chat assistant** (`POST /api/chat`): a stateless retrieval-augmented chatbot scoped to the portfolio (Gemini embeddings + generation, MySQL JSON vector store, in-PHP cosine retrieval, Server-Sent Events streaming). The front solves an hCaptcha once at `POST /api/chat/session` to obtain a short-lived session token, then reuses it on each message; rate-limited per IP.
- **No authentication layer, by design**: no users, no login, no JWT. Security rests on the anti-abuse controls above. HTTP errors follow RFC 7807 (`application/problem+json`).

## Requirements

- PHP 8.4+ with the usual Symfony extensions
- MySQL 8
- A Google Gemini API key (free tier is enough) for the chat assistant
- An hCaptcha site/secret key pair

## Installation

1. Clone and install dependencies:
   ```
   git clone https://github.com/carolinechap/portfolio-api.git
   cd portfolio-api
   composer install
   ```

2. Configure the environment. `.env` is committed with placeholder defaults; put real values in `.env.local` (gitignored). At minimum: `DATABASE_URL`, `MAILER_DSN` / `EMAIL_TO` / `EMAIL_FROM`, `HCAPTCHA_SECRET_KEY` / `HCAPTCHA_SITE_KEY`, `FRONT_URL`, `CORS_ALLOW_ORIGIN`, and the `GEMINI_*` keys. See `.env` for the full list and `CLAUDE.md` for what each one does.

3. Create the database and run migrations:
   ```
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate
   ```

4. Ingest the chat knowledge base (calls Gemini to embed `data/chat-knowledge.json`):
   ```
   php bin/console app:chat:ingest
   ```

## Running

```
symfony serve
```
(or `php -S 127.0.0.1:8000 -t public`)

## Useful commands

- `php bin/console app:chat:ingest` — (re)build the chat knowledge base from `data/chat-knowledge.json`
- `php bin/console app:chat:ask "question"` — run one question through the pipeline and print the answer with retrieval diagnostics (for evaluation)
- `php bin/console app:chat:purge-logs` — delete `chat_log` rows older than the retention window
- `php bin/console cache:clear` — clear the Symfony cache

## Testing

```
php bin/phpunit                 # test suite (SQLite in-memory)
vendor/bin/phpstan analyse      # static analysis, level 10
```

## Documentation

- `CLAUDE.md` — architecture, security model and conventions.
- `openapi.yaml` — request/response contract for both public endpoints.
