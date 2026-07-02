# Translation API for WordPress

Expose your multilingual WordPress content over a small, self-contained REST
API so any translation system can **read your source strings** and **write
translations back** into WPML. Access is guarded by an **API key you create in
the plugin's settings** — there's no external service to sign up for and nothing
to connect to.

This is the generic, standalone version: the plugin is purely a REST server on
your own site. You (or your translation vendor) call it with an API key.

## Before you start

You'll need:

- **WordPress 6.4 or newer** on **PHP 8.1 or newer** (*Tools → Site Health* will
  tell you, or ask your host).
- **WPML** installed and active, with your languages already set up. The API
  reads and writes the content WPML manages, so your site needs to be
  multilingual with WPML first.
- An **administrator** login on your WordPress site (you need access to
  *Settings* to create API keys).
- Your site should be **reachable over HTTPS** from wherever the translation
  system runs, so it can call the API.

## 1. Install the plugin

1. Download the latest **`translation-api.zip`** from the
   [Releases page](https://github.com/elanlanguages/translation-api-wordpress/releases)
   (or build it with `bin/build-plugin-zip.sh`).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose `translation-api.zip`, click **Install Now**, then **Activate**.

## 2. Create an API key

1. Go to **Settings → Translation API**.
2. Under **Create an API key**, give it a label (e.g. the name of the system
   that will use it) and click **Create key**.
3. **Copy the key immediately** — it's shown only once. Only a hashed form is
   stored, so it can never be shown again. If you lose it, revoke it and create
   a new one.

You can create as many keys as you like (one per integration is a good idea)
and revoke any of them at any time from the same screen.

## API reference

All routes live under the base:

```
https://your-site.example/wp-json/translation/v1
```

(The exact base for your site is shown on the *Settings → Translation API*
screen.) Responses are JSON. `POST` bodies are JSON — send
`Content-Type: application/json`.

### Authentication

Every request must carry a valid API key, sent **either** way:

```
X-API-Key: <your-key>
```

```
Authorization: Bearer <your-key>
```

If both are present, `X-API-Key` wins. A valid key makes the request act as the
WordPress user who created it, so the API can read drafts and private posts and
attribute write-backs just as that administrator could. Requests without a valid
key get `401 Unauthorized`:

```json
{
  "code": "translation_api_forbidden",
  "message": "A valid API key is required. Send it as the X-API-Key header.",
  "data": { "status": 401 }
}
```

### Common concepts

- **Locale** — a WPML language code such as `en`, `de`, or `pt-pt` (close to,
  but not always identical to, BCP-47). See `GET /locales`.
- **Resource** — one translatable piece of content (a page, post, or other post
  type), identified by its numeric WordPress id.
- **Key** — a translatable field of a resource, addressed by a canonical name.
  Out of the box these are `title`, `content`, and `excerpt`; a site can add
  more (see [Extending what gets translated](#extending-what-gets-translated)).

---

### `GET /health`

Liveness and environment check.

**Response `200`**

```json
{
  "ok": true,
  "plugin_version": "0.1.0",
  "wpml_active": true,
  "default_language": "en"
}
```

```bash
curl -H "X-API-Key: <key>" \
  https://your-site.example/wp-json/translation/v1/health
```

---

### `GET /locales`

The site's configured WPML locales.

**Response `200`**

```json
{
  "locales": [
    { "code": "en", "name": "English", "is_default": true,  "locale": "en_US" },
    { "code": "de", "name": "German",  "is_default": false, "locale": "de_DE" }
  ]
}
```

| Field | Meaning |
| --- | --- |
| `code` | WPML language code — use this value everywhere else in the API. |
| `name` | Human-readable language name. |
| `is_default` | `true` for the site's source language. |
| `locale` | WordPress locale (e.g. `de_DE`), or `null` if WPML didn't provide one. |

**Errors:** `409` if WPML is not active (see [WPML errors](#wpml-errors)).

---

### `GET /resources`

A paginated list of source-language resources of one post type. Only
source-language rows are listed, so each piece of content appears once; its
translations are reachable via `GET /resources/{id}/translations`.

**Query parameters**

| Name | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | string | `page` | Any registered post type, e.g. `page`, `post`. |
| `locale` | string | site default | Source locale to list. |
| `limit` | integer | `50` | Page size, `1`–`200`. |
| `cursor` | string | — | Pass the `next_cursor` from the previous response to get the next page. |

**Response `200`**

```json
{
  "resources": [
    {
      "id": "42",
      "type": "page",
      "title": "About us",
      "metadata": {
        "modified_gmt": "2026-06-30 11:04:12",
        "status": "publish",
        "slug": "about-us",
        "link": "https://your-site.example/about-us/"
      }
    }
  ],
  "next_cursor": "50"
}
```

`next_cursor` is a string to pass back as `cursor` for the next page, or `null`
on the last page. Statuses listed include `publish`, `draft`, `pending`,
`future`, and `private` (so content can be translated before it goes live).

**Errors:** `400` if `type` is not a registered post type; `409` if WPML is not
active.

```bash
curl -H "X-API-Key: <key>" \
  "https://your-site.example/wp-json/translation/v1/resources?type=page&limit=100"
```

---

### `GET /resources/{id}/translations`

A single resource's translatable keys (the source strings) plus every known
translation.

**Path parameter:** `id` — the resource's numeric id.

**Query parameters**

| Name | Type | Default | Notes |
| --- | --- | --- | --- |
| `locales` | string | all | Comma-separated locale codes to restrict the `translations` map, e.g. `de,fr`. |

**Response `200`**

```json
{
  "resource": {
    "id": "42",
    "type": "page",
    "title": "About us",
    "metadata": {
      "modified_gmt": "2026-06-30 11:04:12",
      "status": "publish",
      "slug": "about-us",
      "link": "https://your-site.example/about-us/"
    }
  },
  "source_locale": "en",
  "keys": [
    {
      "key": "title",
      "source_value": "About us",
      "source_locale": "en",
      "source_digest": "9f86d0818880..."
    },
    {
      "key": "content",
      "source_value": "<!-- wp:paragraph -->...",
      "source_locale": "en",
      "source_digest": "2c26b46b68ff..."
    }
  ],
  "translations": {
    "title":   { "de": "Über uns" },
    "content": { "de": "<!-- wp:paragraph -->..." }
  }
}
```

- `keys` are the source strings to translate. `source_digest` is a SHA-256 hex
  of `source_value` — store it to detect when the source later changes.
- `translations` maps each key to `{ locale: translated_value }` for every
  translation that already exists. It's `{}` when there are none.

**Errors:** `404` if the resource doesn't exist; `409` if WPML is not active.

```bash
curl -H "X-API-Key: <key>" \
  "https://your-site.example/wp-json/translation/v1/resources/42/translations?locales=de,fr"
```

---

### `POST /resources/{id}/translations`

Create or update the WPML translation of one resource **for a single locale**.
The plugin writes the values onto the target-language post (creating it if it
doesn't exist yet) and links it to the source as its WPML translation.

> ⚠️ This changes site content. Gate it behind your own review/approval before
> calling.

**Path parameter:** `id` — the **source** resource's numeric id.

**Request body**

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `locale` | string | yes | Target WPML locale code, e.g. `de`. |
| `values` | object | yes | Map of `key` → translated string. Keys not recognised as core fields are passed to the write-back filter; unknown keys are reported as skipped. |

```json
{
  "locale": "de",
  "values": {
    "title": "Über uns",
    "content": "<!-- wp:paragraph -->Wir sind ...<!-- /wp:paragraph -->"
  }
}
```

**Response `200`** (all keys written)

```json
{
  "resource_id": "42",
  "locale": "de",
  "keys_written": 2,
  "keys_skipped": 0,
  "errors": []
}
```

**Response `422`** — same shape, but returned when `errors` is non-empty (some
keys failed to write). **`400`** if `values` is missing or empty; **`404`** if
the source resource doesn't exist; **`409`** if WPML is not active.

```bash
curl -X POST \
  -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"locale":"de","values":{"title":"Über uns"}}' \
  https://your-site.example/wp-json/translation/v1/resources/42/translations
```

---

### WPML errors

Every content route needs WPML. When it's inactive they return `409`:

```json
{
  "code": "translation_api_wpml_inactive",
  "message": "WPML is not active on this site.",
  "data": { "status": 409 }
}
```

## Extending what gets translated

By default the API exposes the core post fields (`title`, `content`, `excerpt`).
To add custom fields, ACF, or SEO meta, use these filters from your own code:

- `translation_api_extra_translation_keys` — append extra source keys when
  reading a post.
- `translation_api_set_extra_translation_keys` — write those extra keys back on
  the translation post.

## Removing a key or the plugin

- Revoke a key under **Settings → Translation API** — any client using it stops
  working immediately.
- Deleting the plugin removes all stored keys.

## Troubleshooting

- **Requests return "A valid API key is required".** Check the key is sent as
  `X-API-Key` (some hosts strip the `Authorization` header), and that it hasn't
  been revoked.
- **Endpoints say WPML is not active.** Make sure **WPML Multilingual CMS** is
  installed, active, and your languages are set up under *WPML → Languages*.
- **I don't see the *Translation API* menu.** Only administrators can manage
  keys — sign in as an administrator and confirm the plugin is activated.
