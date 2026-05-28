# Chatbot du portfolio

Assistant conversationnel **strictement restreint** au parcours, aux projets, aux compétences et au contact de Caroline Chapeau (développeuse back Symfony/Drupal). Toute question hors-périmètre est rejetée par un message standard, y compris en cas de tentative de contournement.

---

## Sommaire

1. [Principes](#principes)
2. [Architecture](#architecture)
3. [Cycle de vie d'une question](#cycle-de-vie-dune-question)
4. [Base de connaissances et ingestion](#base-de-connaissances-et-ingestion)
5. [Variables d'environnement](#variables-denvironnement)
6. [Anti-abus et garantie budget zéro](#anti-abus-et-garantie-budget-zéro)
7. [Économie de tokens](#économie-de-tokens)
8. [Exploitation](#exploitation)
9. [Contrat côté front (SSE)](#contrat-côté-front-sse)
10. [Tests](#tests)
11. [Réglage et monitoring](#réglage-et-monitoring)
12. [Choix de conception](#choix-de-conception)

---

## Principes

**RAG (Retrieval-Augmented Generation)** : on n'entraîne pas un modèle, on fournit à Gemini les bons extraits de connaissance **au moment de la question**, et il rédige la réponse à partir de ces extraits.

### Pourquoi pas simplement appeler Gemini ?

Un LLM seul ne connaît pas mon parcours. Sans contexte, il inventerait ou avouerait son ignorance. Avec le RAG, on lui sert sur un plateau les bouts de mon CV pertinents pour la question posée.

### Pipeline en 3 mots

> **Embedder, Récupérer, Générer.**

- **Embedder** = transformer un texte en vecteur de 768 nombres (Gemini `gemini-embedding-001`)
- **Récupérer** = trouver les chunks de mon portfolio dont le vecteur est le plus proche de celui de la question (similarité cosinus)
- **Générer** = envoyer à Gemini le prompt système verrouillé + les chunks pertinents + la question, et streamer la réponse

### Contraintes structurantes

| Contrainte | Conséquence |
|---|---|
| Budget zéro centime | `QuotaGuard` arrête les appels Gemini à 1200/jour (sous le free tier 1500), aucune bascule payante |
| RGPD-friendly | Logs minimaux (Q + R + score + outcome), pas d'IP, pas de session, purge auto 30 jours |
| Pas de clé API exposée | Toute la logique est côté backend Symfony, le front n'a aucun secret |
| Maintien dans le temps | Pointer sur le modèle Gemini **GA actuel**, surveiller les release notes |

---

## Architecture

### Vue d'ensemble

```
Front Vue 3                            Backend Symfony 7.4
─────────────                          ──────────────────────────────────
                                       
[Page chat] ─POST─► /api/chat ──► OriginCheckListener (priority 16)
                                       │
                                       ▼
                                  HCaptchaVerifier ◄── (réutilisé par /contact)
                                       │
                                       ▼
                                  RateLimiter (60/h par IP)
                                       │
                                       ▼
                                  ChatRequest DTO + Validator
                                       │
                                       ▼
                                  QuotaGuard ──[exhausted]──► SSE error
                                       │
                                       ▼
                                  EmbeddingService ─►─┐
                                       │             │
                                       │      Gemini API
                                       │   (text-embedding)
                                       │             │
                                       ▼◄────────────┘
                                  ChatChunkRepository::findTopK
                                       │  (cosinus PHP)
                                       │
                                       ▼
                                  [score < seuil] ──► SSE off-scope
                                       │
                                       ▼
                                  PromptBuilder (système + chunks + historique)
                                       │
                                       ▼
                                  GeminiClient::streamGenerate ─►─┐
                                       │                         │
                                       │                   Gemini API
                                       │                  (generation SSE)
                                       │                         │
                                       ▼◄────────────────────────┘
                                  StreamedResponse SSE
                                       │
                       ◄────data────────┘
                                       │
                                       ▼ (en post-traitement)
                                  ChatLogger ─► chat_log
```

### Modules et fichiers

```
src/Chat/
  Controller/
    ChatController.php          ← orchestre tout le pipeline, retourne SSE
  Dto/
    ChatRequest.php             ← validation (question 1-500 chars, history max 6)
    ChatMessage.php             ← role + content (user|assistant)
  Entity/
    ChatChunk.php               ← un fragment de connaissance + son embedding
    ChatLog.php                 ← trace anonymisée d'une conversation
    ChatOutcome.php             ← enum: answered, off_scope, quota_exceeded, ...
  Repository/
    ChatChunkRepository.php     ← findTopK (cosinus en PHP) + CRUD basique
    ChatLogRepository.php       ← deleteOlderThan pour la purge
    ScoredChunk.php             ← value object (chunk + score)
  Service/
    HCaptchaVerifier.php        ← partagé avec le validator du formulaire contact
    QuotaGuard.php              ← compteur quotidien Gemini, garde-fou budget zéro
    EmbeddingService.php        ← wrapper Gemini batchEmbedContents (768 dims)
    GeminiClient.php            ← wrapper Gemini streamGenerateContent (SSE)
    PromptBuilder.php           ← assemble système + chunks + historique + question
    ChatLogger.php              ← persiste les logs RGPD-minimal
    KnowledgeIngester.php       ← logique d'upsert/skip/delete depuis le JSON
    IngestionReport.php         ← résumé des opérations (added, updated, ...)
  Command/
    IngestChatKnowledgeCommand.php  ← `app:chat:ingest`
    PurgeChatLogsCommand.php        ← `app:chat:purge-logs`
  EventListener/
    OriginCheckListener.php     ← 403 si Origin != FRONT_URL, sur /api/chat seulement
  Exception/
    QuotaExceededException.php
    GeminiException.php

config/prompts/
  chat_system.txt               ← prompt système verrouillé (modifiable hors code)

config/packages/
  nelmio_security.yaml          ← headers HTTP sécurité (clickjacking, nosniff, HSTS, ...)
  rate_limiter.yaml             ← config du rate-limiter chat_per_ip
  doctrine.yaml                 ← mapping App\Chat\Entity

data/
  chat-knowledge.json           ← base de connaissances éditée à la main
```

---

## Cycle de vie d'une question

Le détail de ce qui se passe quand un visiteur tape une question dans le chat.

| Étape | Composant | Action | En cas d'échec |
|---|---|---|---|
| 1 | `OriginCheckListener` | Vérifie que `Origin` matche `FRONT_URL` | `403`, pas de log |
| 2 | `HCaptchaVerifier` | Vérifie le token via API hCaptcha | `403`, log `validation_error` |
| 3 | `RateLimiterFactory chat_per_ip` | Compte 1 hit par IP (60/h max) | `429`, header `Retry-After` |
| 4a | Serializer + Validator `ChatRequest` | Désérialise et valide (longueur, charset Unicode, regex anti-injection, honeypot) | `400`, log `validation_error` |
| 4b | Honeypot (`website` doit être vide) | Filtre les bots qui remplissent tous les champs | `400`, log `validation_error` |
| 5 | `QuotaGuard::canCall()` | Vérifie le compteur quotidien Gemini | SSE `error`, log `quota_exceeded` |
| 6 | `EmbeddingService::embed()` | Embedding de la question (Gemini) | SSE `error`, log `quota_exceeded` |
| 7 | `ChunkRepository::findTopK()` | Top-K chunks par cosinus (PHP, **chunks en cache APCu** TTL 5 min) | — |
| 8 | Seuil `CHAT_RETRIEVAL_THRESHOLD` | Si `topScore < 0.55` (ou ce que tu as réglé) | SSE off-scope, log `off_scope`, pas d'appel Gemini de génération |
| 9 | `PromptBuilder::build()` | Système + contexte + historique + question | — |
| 10 | `GeminiClient::streamGenerate()` | Génération streamée avec `safetySettings` (4 catégories `BLOCK_LOW_AND_ABOVE`) | SSE `error`, log `quota_exceeded` |
| 11 | `StreamedResponse` SSE | Flush de chaque token au navigateur | — |
| 11b | Détection fuite system prompt | Si la réponse contient un marqueur du prompt système → log `internal_error` | — |
| 12 | `ChatLogger::log()` | Persistance RGPD-minimal (`strip_tags` + cap 5000 chars sur answer) | — |

### Multi-tour

Le front peut envoyer un `history` de paires `{role, content}` (max 6 messages = 3 paires). `PromptBuilder` tronque silencieusement au-delà de `CHAT_HISTORY_MAX_PAIRS`. Les réponses de l'assistant sont également tronquées à 200 caractères dans l'historique transmis (économie de tokens).

L'embedding est calculé **uniquement sur la question courante**, pas sur l'historique — moins d'API calls, plus rapide.

---

## Base de connaissances et ingestion

### Le fichier `data/chat-knowledge.json`

C'est **toi** qui maintiens ce fichier à la main. Chaque entrée est un chunk autonome qui sera envoyé à Gemini comme contexte.

```json
{
  "entries": [
    {
      "key": "experience.arneo-2024",
      "type": "experience",
      "content": "Depuis 2024, je travaille chez Arneo, agence digitale lyonnaise, en tant que développeuse back sur des projets Symfony et Drupal.",
      "tags": ["symfony", "drupal", "arneo"]
    },
    {
      "key": "skill.symfony",
      "type": "skill",
      "content": "Symfony 5, 6, 7 — API Platform, Doctrine ORM, Messenger, Security, Mailer.",
      "tags": ["symfony", "php"]
    }
  ]
}
```

- `key` : identifiant unique, sert au diff (insert/update/delete)
- `type` : libre, classification interne
- `content` : **le texte effectivement envoyé à Gemini**. Soigne sa formulation, c'est ça qui détermine la qualité des réponses.
- `tags` : libre, indexable plus tard si besoin

### Comment ingérer

```bash
php bin/console app:chat:ingest
```

L'algorithme :

1. Calcule un hash sha256 du `content` de chaque entrée
2. Compare avec la table `chat_chunk` :
   - **Nouveau** → insert + appel Gemini pour l'embedding
   - **Hash identique** → skip (économie de quota)
   - **Hash différent** → update + appel Gemini pour ré-embedder
3. Les entrées présentes en BDD mais absentes du JSON sont supprimées

Options :
- `--force` : ré-embed toutes les entrées (utile si on change `outputDimensionality`)
- `--dry-run` : non implémenté en v1
- `--file=path` : override du chemin

Affichage :

```
[OK] Ingestion complete

 * Added       : 2
 * Updated     : 1
 * Skipped     : 7
 * Deleted     : 0
 * Gemini calls: 1
```

### Le batching

`text:batchEmbedContents` accepte jusqu'à 100 entrées par requête. La commande regroupe les entrées à embedder pour un seul appel API.

---

## Variables d'environnement

À mettre dans `.env.local` (jamais `.env` qui est commité). Toutes sont obligatoires sauf si valeur par défaut sensée existe.

### Gemini

| Var | Exemple | Description |
|---|---|---|
| `GEMINI_API_KEY` | `AIza...` | Clé obtenue sur [aistudio.google.com](https://aistudio.google.com/apikey). **Jamais commit.** |
| `GEMINI_API_BASE_URL` | `https://generativelanguage.googleapis.com/v1beta` | Base de l'API. Stable. |
| `GEMINI_EMBEDDING_MODEL` | `gemini-embedding-001` | Modèle d'embeddings actuel (mai 2026). À surveiller en cas de deprecation. |
| `GEMINI_GENERATION_MODEL` | `gemini-2.5-flash-lite` | Modèle de génération, le plus économique du free tier. |
| `GEMINI_DAILY_LIMIT` | `1200` | Plafond quotidien d'appels Gemini (sous le free tier 1500/jour). |

### Chat

| Var | Défaut | Description |
|---|---|---|
| `CHAT_KNOWLEDGE_FILE` | `%kernel.project_dir%/data/chat-knowledge.json` | Chemin du JSON source (résolu via `env(resolve:...)`). |
| `CHAT_SYSTEM_PROMPT_FILE` | `%kernel.project_dir%/config/prompts/chat_system.txt` | Chemin du prompt système (lu via `env(file:resolve:...)`). |
| `CHAT_RETRIEVAL_K` | `5` | Nombre de chunks top-K envoyés au LLM. |
| `CHAT_RETRIEVAL_THRESHOLD` | `0.55` | Seuil cosinus minimum pour considérer la question dans le périmètre. |
| `CHAT_HISTORY_MAX_PAIRS` | `3` | Nombre de paires Q/R conservées dans l'historique transmis. |
| `CHAT_LOG_RETENTION_DAYS` | `30` | Rétention des logs avant purge auto. |

### Front

| Var | Description |
|---|---|
| `FRONT_URL` | URL exacte du site Vue. Doit matcher l'header `Origin` envoyé par le navigateur. |

### hCaptcha

| Var | Description |
|---|---|
| `HCAPTCHA_SECRET_KEY` | Secret hCaptcha (en dev : `0x0000000000000000000000000000000000000000` accepte tous les tokens). |
| `HCAPTCHA_VERIFY_URL` | `https://api.hcaptcha.com/siteverify` |

---

## Anti-abus et garantie budget zéro

### Couches de défense (12)

1. **Origin check** — bloque les appels cross-domain depuis Postman, curl extérieur, sites tiers. Si tu testes en local depuis curl, il faut envoyer le bon `Origin`.
2. **hCaptcha invisible** — le front résout un token au premier message de la session, à renvoyer en header `X-HCaptcha-Token`. En dev avec les clés de test, n'importe quel token passe.
3. **Rate-limit IP** — sliding window 60/h via `symfony/rate-limiter`. Au-delà, `429` avec `Retry-After`.
4. **Validation DTO** — bloque les questions vides, trop longues (>500 chars), avec caractères de contrôle exotiques.
5. **Regex anti-injection** — sur `ChatRequest::$question`, rejette les patterns connus de prompt injection (`ignore previous instructions`, `disregard your system prompt`, `reveal your instructions`, `you are now/actually`). Limité mais filtre le bruit basique avant même l'appel LLM.
6. **Honeypot field `website`** — champ vide attendu, jamais affiché au front. Les bots scrappers qui remplissent tous les champs JSON sont filtrés en `400`.
7. **Seuil de similarité + prompt verrouillé** — pas de classification LLM séparée pour décider si la question est dans le périmètre. Le seuil cosinus + le prompt système suffisent.
8. **`safetySettings` Gemini** — l'appel de génération force les 4 catégories `HARM_CATEGORY_*` à `BLOCK_LOW_AND_ABOVE` (haine, harcèlement, contenu sexuel explicite, contenu dangereux). Le modèle refuse de produire le contenu sensible avant même qu'on le voie.
9. **Détection de fuite du system prompt** — après accumulation des tokens, si la réponse contient un marqueur distinctif du prompt système, le log est marqué `internal_error` pour alerter (mais le user reçoit quand même les tokens, qui sont déjà streamés).
10. **Sanitization au stockage** — `strip_tags()` + cap 5000 chars sur `chat_log.question` et `chat_log.answer` avant insert. Défense en profondeur pour un futur viewer admin.
11. **Headers HTTP sécurité (Nelmio Security Bundle)** — `clickjacking: DENY`, `nosniff`, `referrer-policy: strict-origin-when-cross-origin`, `HSTS` activé en prod (1 an + subdomains).
12. **Audit log d'ingestion** — `app:chat:ingest` log via PSR Logger qui ingère quoi (fichier, compteurs). Traçable dans les logs Symfony.

### Le `QuotaGuard`

C'est la **garantie absolue de budget zéro**.

- Compteur quotidien stocké dans `cache.app` (clé `gemini_quota:YYYY-MM-DD`, TTL 26h)
- Incrémenté **avant** chaque appel Gemini (embedding et génération comptent chacun pour 1)
- Si `count >= GEMINI_DAILY_LIMIT` → on bloque, message générique
- Si Gemini renvoie 429 (concurrence ou erreur de comptage de notre côté) → on catch + on force le compteur au max pour bloquer le reste de la journée

Tant que tu n'as pas activé le billing sur ta clé Gemini, **aucun dépassement n'est facturable**. Et si tu actives le billing, ce garde-fou continue de fonctionner.

### À propos du filtrage anti-injection

Le filtre regex sur la question (couche 5) attrape les patterns connus (`ignore previous instructions`, `disregard your system prompt`, etc.) mais c'est une défense **basique** par design — les attaques évoluent et ne se filtrent pas exhaustivement par regex. Les vraies couches de défense restent :

- Le **seuil de similarité** (couche 7) qui empêche les questions vraiment hors-sujet d'atteindre le LLM
- Le **prompt système verrouillé** qui interdit au modèle de suivre les consignes injectées
- Les **`safetySettings`** Gemini (couche 8) qui filtrent le contenu sensible côté modèle
- La **détection post-stream** (couche 9) qui alerte si une fuite passe quand même

Le pire qui arrive si une attaque sophistiquée "passe" toutes les couches : le LLM consomme un peu de quota gratuit et génère une réponse hors-sujet — pas de fuite de secret, pas de coût.

---

## Économie de tokens

Pour rester sous le free tier sans effort, on optimise trois leviers :

### 1. Le prompt système

Court et dense. Le fichier `config/prompts/chat_system.txt` fait ~530 caractères (~135 tokens). C'est ce qui est envoyé **à chaque appel de génération**, donc chaque caractère économisé compte.

### 2. Embeddings réduits

`outputDimensionality: 768` dans la requête Gemini → vecteurs 4× plus petits que par défaut (3072), stockage et calcul cosinus 4× plus rapides. Aucune perte de qualité perceptible jusqu'à 768 dims (technique Matryoshka du modèle).

### 3. Historique compressé

- Sliding window de 3 paires (6 messages max)
- Réponses passées tronquées à 200 caractères avec ellipse
- Pas de re-embedding de l'historique (seule la question courante est embedded)

### Budget approximatif par question

| Composant | Tokens |
|---|---|
| Système | ~135 |
| Chunks top-K (5 × ~150) | ~750 |
| Historique (6 × ~50) | ~300 |
| Question | ~20 |
| **Input total** | **~1200** |
| Output | ~80-150 |
| **Total / question** | **~1300-1400** |

Free tier Gemini 2.5 Flash Lite : 1M tokens/minute, 1500 RPD. Largement de la marge pour un portfolio.

---

## Exploitation

### Déploiement

1. Cloner le repo sur le VPS
2. `composer install --no-dev --optimize-autoloader`
3. Créer la DB MySQL, configurer `DATABASE_URL` dans `.env.local`
4. `php bin/console doctrine:migrations:migrate --no-interaction`
5. Créer la clé hCaptcha de prod sur [dashboard.hcaptcha.com](https://dashboard.hcaptcha.com)
6. Créer la clé Gemini sur [aistudio.google.com](https://aistudio.google.com/apikey)
7. Remplir `.env.local` (les `HCAPTCHA_*` réels, `GEMINI_API_KEY`, `FRONT_URL`, `CORS_ALLOW_ORIGIN`)
8. Peupler `data/chat-knowledge.json` avec ton vrai contenu
9. `php bin/console app:chat:ingest` (1 appel Gemini, ingère tous les chunks)
10. Configurer ton serveur web pour pointer sur `public/`
11. Vérifier qu'Apache/Nginx **ne bufferise pas** les réponses (pour le streaming SSE)

### Cron de purge

Ajouter dans la crontab du serveur :

```cron
0 3 * * *  /usr/bin/php /var/www/portfolio-api/bin/console app:chat:purge-logs --no-interaction
```

Adapte le chemin. Tourne tous les jours à 3h du matin, supprime les `chat_log` plus vieux que `CHAT_LOG_RETENTION_DAYS`.

### Quand mettre à jour la connaissance

À chaque évolution du parcours :
1. Éditer `data/chat-knowledge.json`
2. `php bin/console app:chat:ingest`
3. Vérifier dans `chat_log` que les nouvelles questions matchent bien

Tu peux faire ça en prod directement (la commande est idempotente, ne réembed que ce qui a changé). L'ingester **invalide automatiquement le cache APCu des chunks** (`chat.chunks`), donc les nouvelles entrées sont prises en compte au prochain appel `/api/chat` sans cache:clear.

Chaque exécution écrit aussi un **audit log** dans le canal Symfony par défaut :

```
[2026-05-20 10:42:18] app.INFO: Chat knowledge ingested {"file":"...","added":2,"updated":1,"skipped":7,"deleted":0,"apiCalls":1}
```

Tu peux suivre ces entrées via `tail -f var/log/prod.log` ou un agrégateur (Sentry, etc.).

### Si Gemini déprécie un modèle (comme `text-embedding-004` en jan 2026)

1. Trouve le nom du nouveau modèle dans les release notes Google
2. Mets à jour `GEMINI_EMBEDDING_MODEL` ou `GEMINI_GENERATION_MODEL` dans `.env.local`
3. `php bin/console cache:clear`
4. Pour les embeddings : `php bin/console app:chat:ingest --force` (ré-embed avec le nouveau modèle)
5. Lance un curl de test sur `/api/chat`

---

## Contrat côté front (SSE)

Quand tu coderas l'app Vue 3, voici ce que le back attend et envoie.

### Requête

```http
POST /api/chat HTTP/1.1
Host: api.caroline-chapeau.com
Origin: https://caroline-chapeau.com
Content-Type: application/json
Accept: text/event-stream
X-HCaptcha-Token: <token résolu au premier message de la session>

{
  "question": "Tu fais du Symfony ?",
  "history": [
    { "role": "user", "content": "C'est quoi ton parcours ?" },
    { "role": "assistant", "content": "Je travaille chez Arneo depuis 2024..." }
  ],
  "website": ""
}
```

> ⚠️ **Champ honeypot `website`** : ne **jamais** afficher ce champ dans l'UI, et toujours envoyer `""` (ou omettre — le défaut backend est `""`). Si un bot scrape l'API et remplit ce champ → 400. Pense à désactiver l'autofill du browser sur ce champ s'il existe en DOM (`autocomplete="off"`, `tabindex="-1"`, `aria-hidden="true"`, ou plus simple : ne le rends pas).

### Réponse — happy path

```
event: chunk
data: {"token":"Oui"}

event: chunk
data: {"token":", "}

event: chunk
data: {"token":"je"}

event: chunk
data: {"token":" travaille"}

...

event: done
data: {"outcome":"answered"}
```

### Réponse — hors-sujet

```
event: chunk
data: {"token":"Désolée, je ne sais pas répondre à ça. Je suis l'assistant de Caroline Chapeau."}

event: done
data: {"outcome":"off_scope"}
```

### Réponse — quota dépassé

```
event: error
data: {"reason":"quota_exceeded","message":"L'assistant est très sollicité aujourd'hui et n'est plus disponible. Réessaie demain ou utilise le formulaire de contact."}
```

### Codes d'erreur HTTP avant SSE

- `403` — Origin invalide ou hCaptcha refusé
- `400` — DTO invalide (question vide, trop longue, caractères non autorisés)
- `429` — Rate limit dépassé (header `Retry-After`)

### Côté Vue 3 — pattern de consommation

`EventSource` natif ne supporte que GET, donc tu consommes le SSE via `fetch()` + `ReadableStream` :

```javascript
const response = await fetch('/api/chat', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'text/event-stream',
    'X-HCaptcha-Token': captchaToken,
  },
  body: JSON.stringify({ question, history }),
});

const reader = response.body.getReader();
const decoder = new TextDecoder();
let buffer = '';

while (true) {
  const { value, done } = await reader.read();
  if (done) break;
  buffer += decoder.decode(value, { stream: true });
  // parser ligne par ligne, extraire les `event:` et `data:`
}
```

---

## Tests

PHPUnit 13, 31 tests, 63 assertions.

```bash
# Suite complète
php bin/phpunit

# Cibler le chatbot
php bin/phpunit tests/Chat/

# Static analysis (level 10, max)
vendor/bin/phpstan analyse
```

### Découpage

| Couche | Tests |
|---|---|
| Repository (cosinus, retrieval) | `tests/Chat/Repository/` |
| Services (hCaptcha, quota, embeddings, génération, prompt, logger, ingester) | `tests/Chat/Service/` |
| EventListener (Origin check) | `tests/Chat/EventListener/` |
| Controller (pipeline complet) | `tests/Chat/Controller/` |
| Command (ingest, purge) | `tests/Chat/Command/` |

### Stratégie

- **Aucun test n'appelle réellement Gemini.** `HttpClientInterface` est toujours mocké via `MockHttpClient`.
- **DB de test** : SQLite in-memory (`DATABASE_URL="sqlite:///:memory:"` dans `.env.test`). Doctrine `SchemaTool` crée les tables à chaque `setUp()`.
- **Stubs de services externes** : `EmbeddingService`, `GeminiClient`, `HCaptchaVerifier` sont non-final non-readonly, donc extensibles par anonymous class dans les tests.

---

## Réglage et monitoring

### Observer la distribution des scores

```bash
php bin/console doctrine:query:sql "
  SELECT outcome,
         MIN(top_score) as min_score,
         AVG(top_score) as avg_score,
         MAX(top_score) as max_score,
         COUNT(*) as nb
  FROM chat_log
  GROUP BY outcome
"
```

### Symptômes et réglages

| Symptôme | Cause probable | Action |
|---|---|---|
| Beaucoup de `off_scope` sur des questions légitimes | `CHAT_RETRIEVAL_THRESHOLD` trop strict | Baisse à 0.50-0.55 |
| Réponses inventées / hallucinations | Chunks trop pauvres ou top-K trop petit | Enrichir `data/chat-knowledge.json` OU monter `CHAT_RETRIEVAL_K` à 7 |
| Quota Gemini saturé en pleine journée | Pic de trafic ou attaque | Resserrer le rate-limit dans `config/packages/rate_limiter.yaml` |
| Réponses tronquées (output trop court) | `gemini-2.5-flash-lite` est concis par design | Switch sur `gemini-2.5-flash` (plus généreux) si le free tier le permet |
| Réponses trop verbeuses (output trop long) | Le LLM n'a pas suivi la consigne "1-3 phrases" | Resserrer le prompt système, ajouter "réponse de 80 tokens max" |

---

## Choix de conception

### Pourquoi MySQL et pas PostgreSQL/pgvector ?

Parce que le projet utilisait déjà MySQL. Plutôt que migrer, on stocke les embeddings en JSON et on calcule le cosinus en PHP. Pour ~50 chunks ça prend quelques millisecondes — totalement OK.

Cette décision plafonne à ~1000 chunks max avant que le calcul PHP devienne lent. On en est loin pour un portfolio.

### Pourquoi pas Mistral ou Groq ?

- **Mistral** : free tier "experimental" sans SLA, instable
- **Groq** : pas d'embeddings (il faudrait 2 providers, donc 2 clés, 2 dashboards, 2 fragilités)
- **Gemini** : un seul provider qui couvre embeddings + génération avec un free tier généreux

### Pourquoi pas un classifier LLM en amont (in-scope/off-scope) ?

Doubler les appels LLM doublerait la consommation de quota et la latence. Le seuil de similarité sur les embeddings est suffisant : si la question matche bien un chunk → in-scope, sinon → off-scope. Le prompt système verrouillé renforce la décision côté génération.

### Pourquoi un controller custom et pas une ressource API Platform ?

API Platform n'est pas conçu pour du streaming SSE. Le chat n'a pas de structure REST (pas de read/list/update/delete), donc un controller dédié est plus simple et plus lisible.

### Pourquoi le système prompt dans un fichier externe ?

Pour pouvoir le modifier sans toucher au code PHP, le tester en isolation, et le versionner indépendamment. Chargé via `env(file:resolve:CHAT_SYSTEM_PROMPT_FILE)` au boot du container.

### Pourquoi `final readonly` partout sauf 3 services ?

`final readonly` est le défaut PHP 8.4 idéal pour les services stateless. Mais `HCaptchaVerifier`, `EmbeddingService`, `GeminiClient` sont **étendus par des anonymous classes dans les tests** pour faire des stubs sans réseau. Et un `readonly class` ne peut PAS être étendu. Donc ils sont juste `class` (mais avec propriétés `readonly` individuellement — même sécurité d'immutabilité).
