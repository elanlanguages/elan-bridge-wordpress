# ELAN AI Bridge — WordPress plugin

Exposes WPML-managed pages and their translations to the **ELAN AI Bridge**
so the bridge can pull source segments, translate them, and (later) write
translations back.

## Architecture (topology A — pull)

```
WordPress site                                  ELAN AI Bridge (bridge-api-connectors)
┌───────────────────────────┐                  ┌──────────────────────────────────┐
│  WPML  ── icl_* tables     │                  │  WordPressConnector              │
│   ▲                        │   HTTP Basic     │   (adapters/connectors/wordpress)│
│   │ wpml_* filters         │   App Password   │   provider="wordpress"           │
│  elan-bridge plugin        │ ◀─────pull────── │   capabilities={READ, TRANSLATE} │
│   /wp-json/elan/v1/...     │                  │                                  │
│   (canonical CMS shape)    │ ──────JSON─────▶ │   → _common/cms.py pydantic      │
└───────────────────────────┘                  └──────────────────────────────────┘
```

- **This plugin is the server.** It never calls out to the bridge.
- The plugin does the WPML-specific work and emits the **canonical CMS
  vocabulary** (`TranslatableResource` / `TranslationKey` /
  `ResourceWithTranslations`) that every bridge CMS connector speaks.
- The bridge authenticates with a **WordPress Application Password** (the
  `app_password` credential type already defined in the bridge) and pulls
  from `/wp-json/elan/v1/...`. The connector is a thin pass-through.

"Segments" here are **translatable fields** (`title`, `content`, `excerpt`,
plus anything added via the `elan_bridge_extra_translation_keys` filter).
Sentence-level segmentation is the bridge's job (its `context_window` modes).

## Prerequisites

- **Docker Desktop** (running) and **Node 18+** — for `wp-env`.
- A **WPML subscription**. WPML is commercial and not on wordpress.org. The
  easiest path is the **OTGS Installer** plugin (`otgs-installer-plugin.<ver>.zip`,
  from your wpml.org account) — it registers the site with your account and
  downloads the WPML components from inside wp-admin. This plugin only needs
  **WPML Multilingual CMS** (the core); String Translation is optional (for
  interface strings) and the rest are irrelevant here. Drop OTGS in `.wpml/` (gitignored)
  so `.wp-env.json` installs it on start. (Alternatively, if you have the
  component zips directly, list them in `.wp-env.json` instead.)
- A wpml.org account you can use to **register `http://localhost:8888`** as a
  development site (WPML allows dev-site registration).

## 1. Bring up the demo (wp-env)

```bash
# Put the OTGS Installer here (gitignored). Filename must match .wp-env.json.
mkdir -p .wpml
cp ~/Downloads/otgs-installer-plugin.*.zip .wpml/

# Start WordPress + MariaDB + this plugin + the OTGS Installer, all in Docker.
npx wp-env start
```

WordPress is now at **http://localhost:8888** (admin: `admin` / `password`).
A second test instance runs on `:8889`.

> Want to boot WordPress *without* WPML first (e.g. before the zips arrive)?
> Create `.wp-env.override.json` with a `"plugins": ["."]` array; it overrides
> the committed config and is gitignored.

Run WP-CLI / Composer / PHPUnit inside the containers with `npx wp-env run cli <cmd>`:

```bash
npx wp-env run cli wp plugin list
```

## 2. Install + configure WPML (one-time, via the admin UI)

1. http://localhost:8888/wp-admin (admin / password) → **Plugins**. The OTGS
   Installer adds a registration prompt / a **Commercial** tab under
   *Plugins → Add New*. Register with your wpml.org account (add
   `http://localhost:8888` as a development site to get a key).
2. From the OTGS installer, **download + activate WPML Multilingual CMS** —
   that's all this plugin needs (its reader uses only core `wpml_*` filters).
   String Translation is optional; "Translation Management" is not a separate
   plugin in current WPML (it's part of core).
3. **WPML → Setup**: pick a content language (e.g. English), add target
   languages (e.g. German, French), finish the wizard.
4. Create a Page, then use the **language switcher in the post editor** to add
   a German/French translation. Now you have a `trid` group with siblings —
   exactly what the plugin surfaces.

## 3. Create an Application Password (for the bridge)

1. **Users → Profile → Application Passwords** (or create a dedicated service
   user with an editorial role first).
2. Name it `elan-bridge`, generate, and copy the password (shown once).

## 4. Smoke-test the REST surface

```bash
SITE=http://localhost:8888
USER=admin
APP_PW='xxxx xxxx xxxx xxxx xxxx xxxx'   # the generated app password

curl -s -u "$USER:$APP_PW" "$SITE/wp-json/elan/v1/health" | jq
curl -s -u "$USER:$APP_PW" "$SITE/wp-json/elan/v1/locales" | jq
curl -s -u "$USER:$APP_PW" "$SITE/wp-json/elan/v1/resources?type=page&limit=10" | jq
curl -s -u "$USER:$APP_PW" "$SITE/wp-json/elan/v1/resources/<ID>/translations" | jq
```

`Settings → ELAN AI Bridge` in wp-admin shows the exact `site_url` and pull
URL to paste into the bridge.

## 5. Connect from the bridge

In `bridge-api-connectors`, add an `app_password` connection with
`{site_url: http://host.docker.internal:8888, username, app_password}` and the
`WordPressConnector` (provider `"wordpress"`) will pull. Use
`host.docker.internal` (not `localhost`) if the bridge runs in its own container.

## REST reference (`/wp-json/elan/v1`)

| Method | Route | Returns |
|---|---|---|
| GET | `/health` | `{ok, plugin_version, wpml_active, default_language}` |
| GET | `/locales` | `{locales: [{code, name, is_default, locale}]}` |
| GET | `/resources?type=&locale=&cursor=&limit=` | `{resources: [{id, type, title, metadata}], next_cursor}` |
| GET | `/resources/{id}/translations?locales=` | `{resource, source_locale, keys[], translations{key:{locale:value}}, metadata}` |

All routes require an authenticated user with `manage_options` (or the
`elan_bridge_pull` capability). `set_resource_translations` (write-back) is
deferred until the bridge's approval queue lands.

## Plugin development

```bash
composer install                 # PSR-4 autoload + dev tools
composer lint                    # PHPCS against WordPress Coding Standards
composer lint:fix                # PHPCBF autofix
npx wp-env run tests-cli "cd /var/www/html/wp-content/plugins/elan-bridge && composer test"
```

Structure:

```
elan-bridge.php                  # plugin header + bootstrap
includes/
  Plugin.php                     # wiring (singleton, hook registration)
  Wpml/WpmlReader.php            # WPML access via wpml_* filters (never raw SQL)
  Rest/CmsController.php         # /wp-json/elan/v1/... routes
  Admin/SettingsPage.php         # Settings → ELAN AI Bridge
uninstall.php
.wp-env.json                     # WordPress + WPML demo environment
phpcs.xml.dist                   # coding standards
```

## Extending what gets translated

Custom fields, ACF, SEO meta, or page-builder content aren't core post fields.
Add them as translation keys without touching the plugin:

```php
add_filter( 'elan_bridge_extra_translation_keys', function ( array $keys, WP_Post $post, string $locale ) {
    $subtitle = get_post_meta( $post->ID, 'subtitle', true );
    if ( $subtitle ) {
        $keys[] = array(
            'key'           => 'meta.subtitle',
            'source_value'  => $subtitle,
            'source_locale' => $locale,
            'source_digest' => hash( 'sha256', $subtitle ),
        );
    }
    return $keys;
}, 10, 3 );
```
