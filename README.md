# ELAN AI Bridge for WordPress

Connect your multilingual WordPress site to the **ELAN AI Bridge** and translate
your pages and posts automatically. Once connected, ELAN reads the content you
choose, translates it into your site's languages, and writes the translations
back into WPML — so your existing multilingual setup simply fills in.

There are no API keys to copy and no code to touch: install the plugin, click
**Connect**, sign in to ELAN, and choose what to translate.

## Before you start

You'll need:

- **WordPress 6.4 or newer** on **PHP 8.1 or newer** — most modern hosts already
  qualify (*Tools → Site Health* will tell you, or ask your host).
- **WPML** installed and active, with your languages already set up. ELAN
  translates the content WPML manages, so your site needs to be multilingual
  with WPML first.
- An **ELAN AI Bridge account** — <https://app.elanlanguages.ai>.
- An **administrator** login on your WordPress site (you need access to
  *Settings*).
- Your site must be **reachable over HTTPS from the internet**, so ELAN can read
  your content. (A site that only runs on your laptop or behind a firewall can't
  be reached.)

## 1. Install the plugin

1. Download the latest **`elan-bridge.zip`** from the
   [Releases page](https://github.com/elanlanguages/elan-bridge-wordpress/releases).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose `elan-bridge.zip`, click **Install Now**, then **Activate**.

## 2. Connect to ELAN

1. Go to **Settings → ELAN AI Bridge**.
2. Under **Content to translate**, tick the types you want ELAN to handle — for
   example *Pages* and *Posts*.
3. Click **Connect with ELAN**.
4. You'll be sent to ELAN to **sign in and choose the organization** this site
   belongs to. Approve the connection.
5. You're returned to WordPress and the page shows **Connected**, with your
   organization name. That's it.

The plugin sets up the secure connection for you in the background — nothing to
copy or paste.

## What happens next

ELAN pulls the content you selected, translates it into your site's WPML
languages, and saves each translation as the matching WPML translation of the
original page or post. You keep reviewing and publishing in WordPress and WPML
exactly as you do today. Translation choices — which languages, glossaries, tone
of voice — live in your ELAN account.

## Keeping it up to date

Updates are **automatic**. When a new version is released, the usual **update
notice** appears on your *Plugins* page — update it like any other plugin.
There's nothing to re-download.

## Disconnecting

Go to **Settings → ELAN AI Bridge** and click **Disconnect**. That removes the
connection and the secure password the plugin created for it. You can reconnect
at any time.

## Troubleshooting

- **The connect screen says WPML is required, or nothing happens.** Make sure
  **WPML Multilingual CMS** is installed, active, and your languages are set up
  under *WPML → Languages*.
- **The connection failed.** Click **Connect with ELAN** again. If it keeps
  failing, check that your site is reachable over **HTTPS from the internet**
  and that you signed in to the correct ELAN organization.
- **I don't see the *ELAN AI Bridge* menu.** Only administrators can connect —
  sign in with an administrator account, and confirm the plugin is activated
  under *Plugins*.

## Support

Stuck, or have a question? Email
[support@elanlanguages.com](mailto:support@elanlanguages.com), or reach out to
your ELAN account manager.
