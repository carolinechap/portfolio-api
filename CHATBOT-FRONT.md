# Chatbot — Documentation d'intégration front

Cible : équipe front (Vue.js) qui consomme `POST /api/chat`.

Tout le pipeline est décrit côté back dans `CLAUDE.md` (section _Chat assistant_) — ce document se concentre sur ce que le front doit envoyer, ce qu'il reçoit, et comment dérouler le flux SSE proprement.

---

## 1. Endpoint

| | |
|---|---|
| URL | `POST {API_BASE}/api/chat` |
| Auth | **Aucune** (pas de JWT) — la protection repose sur l'`Origin`, hCaptcha, et le rate-limit IP |
| Content-Type | `application/json` |
| Réponse succès | `text/event-stream` (SSE) |
| Réponse erreur | `application/json` (validation / captcha / rate-limit) |

> ⚠️ L'endpoint n'est ouvert qu'aux requêtes dont le header `Origin` correspond à la variable `FRONT_URL` côté back. Toute autre origine → `403`. En dev, demander au back de pointer `FRONT_URL` vers l'URL du dev server Vue (ex. `http://localhost:5173`).

---

## 2. Headers requis

| Header | Obligatoire | Valeur |
|---|---|---|
| `Content-Type` | oui | `application/json` |
| `Origin` | oui | Envoyé automatiquement par le navigateur ; doit être `=== FRONT_URL` côté back |
| `X-HCaptcha-Token` | oui | Token hCaptcha frais (voir §5) |
| `Accept` | recommandé | `text/event-stream` |

Le back **n'utilise pas de cookie** ni de session — pas besoin de `credentials: 'include'`.

---

## 3. Payload (request body)

```json
{
  "question": "string (1-500 chars)",
  "history": [
    { "role": "user",      "content": "..." },
    { "role": "assistant", "content": "..." }
  ],
  "website": ""
}
```

### Règles de validation (cf. `ChatRequest`, `ChatMessage`)

| Champ | Contrainte |
|---|---|
| `question` | non vide, 1–500 caractères, Unicode autorisé (lettres, chiffres, ponctuation, espaces, symboles). Rejeté si match les patterns d'injection (`ignore previous instructions`, `reveal your system prompt`, etc.) |
| `history` | tableau, max **6 entrées** (3 paires user/assistant) |
| `history[].role` | `"user"` ou `"assistant"` |
| `history[].content` | non vide, max **1000 caractères** |
| `website` | **doit rester vide** — honeypot anti-bot. Ne jamais l'afficher ni le pré-remplir. |

Échec de validation → `400 { "error": "validation failed" }` (ou `"invalid payload"` si JSON malformé).

### Gestion de l'historique côté front

- Le back tronque déjà l'historique côté prompt (`CHAT_HISTORY_MAX_PAIRS` paires, réponses assistant coupées à 200 caractères) — pas besoin d'optimiser côté front, mais respecter la limite de **6 entrées** dans le payload sinon `400`.
- Stocker l'historique en mémoire (Pinia / état local). Pas de persistance attendue côté back : le chat est stateless.
- À la fin d'un échange réussi, pousser dans `history` les deux dernières entrées (`{role:'user', content: question}` puis `{role:'assistant', content: réponse complète}`).

---

## 4. Réponse — flux SSE

Quand la validation passe, le back retourne un `StreamedResponse` de type `text/event-stream` avec ces events :

### `event: chunk`

Un token de la réponse de l'assistant.

```
event: chunk
data: {"token":"Bonjour"}

event: chunk
data: {"token":", je"}
```

→ concaténer `data.token` au fur et à mesure pour afficher la réponse en streaming.

### `event: done`

Fin du stream. Toujours envoyé en dernier en cas de succès, off-scope, ou leak détecté (le back termine proprement).

```
event: done
data: {"outcome":"answered"}
```

`outcome` ∈ :

| valeur | sens côté front |
|---|---|
| `answered` | réponse complète délivrée |
| `off_scope` | la question est hors sujet ; un chunk avec un message court a déjà été émis avant le `done` |

### `event: error`

Erreur côté back **pendant** le stream (Gemini, quota). Le stream se termine ici, **pas de `done` ensuite**.

```
event: error
data: {"reason":"quota_exceeded","message":"L'assistant est très sollicité..."}
```

`reason` ∈ :

| valeur | message FR (déjà fourni par le back, à afficher tel quel) |
|---|---|
| `quota_exceeded` | "L'assistant est très sollicité aujourd'hui et n'est plus disponible. Réessaie demain ou utilise le formulaire de contact." |
| `internal_error` | "Une erreur est survenue, réessaie dans un instant." |

---

## 5. hCaptcha

- Site key fournie par le back (à mettre en variable d'env front).
- Charger le script officiel hCaptcha (`https://js.hcaptcha.com/1/api.js`).
- Mode recommandé : **invisible** (le formulaire de contact utilise la même mécanique côté back).
- Générer un token via `hcaptcha.execute()` **juste avant** chaque appel `/api/chat` — les tokens hCaptcha sont single-use et expirent vite. Ne pas réutiliser un token pour deux questions.
- Passer le token dans le header `X-HCaptcha-Token`.

---

## 6. Rate limiting

- 60 requêtes / heure / IP, sliding window.
- Dépassement → `429 Too Many Requests` avec header `Retry-After` (secondes).
- Recommandation UX : désactiver l'input + afficher un compte à rebours basé sur `Retry-After`.

---

## 7. Codes HTTP

| Code | Quand | Body |
|---|---|---|
| `200` | requête acceptée, stream démarre | SSE |
| `400` | JSON invalide ou validation échouée | `{"error": "invalid payload"\|"validation failed"}` |
| `403` | `Origin` invalide **ou** hCaptcha invalide | message texte Symfony |
| `429` | rate-limit IP dépassé | message texte + header `Retry-After` |
| `5xx` | erreur infra | — |

Note : les erreurs Gemini / quota arrivent **après** le `200` (déjà dans le stream) sous forme d'`event: error`. Le front doit donc gérer les deux niveaux : HTTP avant le stream, SSE pendant.

---

## 8. Exemple d'intégration (Vue 3 / Fetch + ReadableStream)

`fetch` natif est préférable à `EventSource` ici parce qu'`EventSource` ne supporte que `GET` et pas les headers custom (donc impossible d'envoyer `X-HCaptcha-Token`).

```ts
// src/services/chat.ts
export type ChatMessage = { role: 'user' | 'assistant'; content: string }
export type ChatStreamEvent =
  | { type: 'chunk'; token: string }
  | { type: 'done'; outcome: 'answered' | 'off_scope' }
  | { type: 'error'; reason: 'quota_exceeded' | 'internal_error'; message: string }

export async function* streamChat(
  apiBase: string,
  question: string,
  history: ChatMessage[],
  hcaptchaToken: string,
  signal?: AbortSignal,
): AsyncGenerator<ChatStreamEvent> {
  const res = await fetch(`${apiBase}/api/chat`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'text/event-stream',
      'X-HCaptcha-Token': hcaptchaToken,
    },
    body: JSON.stringify({ question, history, website: '' }),
    signal,
  })

  if (!res.ok) {
    // 400, 403, 429... → pas de stream
    let body: unknown = null
    try { body = await res.json() } catch { /* ignore */ }
    throw Object.assign(new Error(`HTTP ${res.status}`), { status: res.status, body, retryAfter: res.headers.get('Retry-After') })
  }

  const reader = res.body!.getReader()
  const decoder = new TextDecoder()
  let buffer = ''

  while (true) {
    const { value, done } = await reader.read()
    if (done) break
    buffer += decoder.decode(value, { stream: true })

    // SSE = events séparés par "\n\n", lignes "event:" / "data:" dans chaque event
    let sep: number
    while ((sep = buffer.indexOf('\n\n')) !== -1) {
      const raw = buffer.slice(0, sep)
      buffer = buffer.slice(sep + 2)

      let eventName = 'message'
      let dataLine = ''
      for (const line of raw.split('\n')) {
        if (line.startsWith('event:')) eventName = line.slice(6).trim()
        else if (line.startsWith('data:')) dataLine += line.slice(5).trim()
      }
      if (!dataLine) continue

      const payload = JSON.parse(dataLine)
      if (eventName === 'chunk')      yield { type: 'chunk', token: payload.token }
      else if (eventName === 'done')  yield { type: 'done', outcome: payload.outcome }
      else if (eventName === 'error') yield { type: 'error', reason: payload.reason, message: payload.message }
    }
  }
}
```

Usage minimaliste dans un composant :

```ts
const answer = ref('')
const status = ref<'idle' | 'streaming' | 'done' | 'error'>('idle')

async function ask(question: string) {
  status.value = 'streaming'
  answer.value = ''
  const token = await hcaptcha.execute() // invisible mode
  try {
    for await (const ev of streamChat(API_BASE, question, history.value, token)) {
      if (ev.type === 'chunk')      answer.value += ev.token
      else if (ev.type === 'done')  status.value = 'done'
      else if (ev.type === 'error') { status.value = 'error'; errorMsg.value = ev.message }
    }
    // Push final message dans l'historique si answered
    if (status.value === 'done') {
      history.value.push({ role: 'user', content: question })
      history.value.push({ role: 'assistant', content: answer.value })
      // Garder max 6 entrées pour respecter la limite back
      while (history.value.length > 6) history.value.shift()
    }
  } catch (e: any) {
    status.value = 'error'
    if (e.status === 429) errorMsg.value = `Trop de questions, réessaie dans ${e.retryAfter}s.`
    else if (e.status === 403) errorMsg.value = 'Captcha invalide ou origine refusée.'
    else errorMsg.value = 'Erreur inattendue.'
  }
}
```

---

## 9. Recommandations UX

- **Streaming visuel** : afficher la réponse caractère par caractère au fur et à mesure des `event: chunk` (cf. usage de `answer.value += token`). Ajouter un caret clignotant tant que `status === 'streaming'`.
- **Annulation** : passer un `AbortSignal` à `streamChat` pour qu'un bouton "Stop" coupe le stream proprement (le back ne facture rien de plus, Gemini continue mais le front ignore).
- **Off-scope** : pas besoin de UI spéciale, le back émet déjà un chunk avec le message FR avant le `done` — il s'affiche comme une réponse normale.
- **Quota épuisé / erreur** : afficher `message` tel quel (déjà localisé FR) et désactiver l'input pour ~1h (quota Gemini se reset à minuit UTC).
- **Honeypot** : ne **jamais** rendre le champ `website` dans le DOM. L'envoyer à `""` dans tous les payloads.

---

## 10. Spec OpenAPI

Voir `docs/chatbot/openapi.yaml` (le `text/event-stream` n'est pas un type modélisé richement par OpenAPI 3.1 — le schema décrit la requête + les codes d'erreur HTTP ; la grammaire SSE est documentée en `description`).
