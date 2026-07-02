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

## 3. Call the API

Send the key on every request, either as an `X-API-Key` header or as an
`Authorization: Bearer` header. The REST base is shown on the settings page and
is `/wp-json/translation/v1` on your site.

```bash
# Health check
curl -H "X-API-Key: <key>" https://your-site.example/wp-json/translation/v1/health

# List translatable pages
curl -H "X-API-Key: <key>" "https://your-site.example/wp-json/translation/v1/resources?type=page"

# Get one page's source strings and existing translations
curl -H "X-API-Key: <key>" https://your-site.example/wp-json/translation/v1/resources/42/translations

# Write a translation back for one locale
curl -X POST -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"locale":"de","values":{"title":"Hallo","content":"..."}}' \
  https://your-site.example/wp-json/translation/v1/resources/42/translations
```

### Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Plugin version, whether WPML is active, default language. |
| `GET` | `/locales` | The site's configured WPML locales. |
| `GET` | `/resources?type=page&locale=&cursor=&limit=` | Paginated list of source-language resources of a post type. |
| `GET` | `/resources/{id}/translations?locales=` | A resource's translatable keys plus every known translation. |
| `POST` | `/resources/{id}/translations` | Create/update the WPML translation for one `locale` from a `values` map. |

A valid API key makes the request act as the WordPress user who created it, so
the API can list drafts and private posts and attribute write-backs just as that
administrator could.

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
