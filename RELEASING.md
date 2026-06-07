# Releasing the ELAN AI Bridge plugin

The plugin is **proprietary**, so it is **not** distributed via wordpress.org.
Releases are GitHub Releases: a tag builds a clean installable `.zip`, and the
plugin's built-in updater (`includes/Updater/GitHubReleaseUpdater.php`) offers
that zip to installed sites the same way wordpress.org plugins update.

## Cut a release

1. Bump the version in **two places** in `elan-bridge.php` (they must match):
   - the `Version:` header
   - the `ELAN_BRIDGE_VERSION` constant
2. Commit, then tag and push:
   ```bash
   git commit -am "release: v0.2.0"
   git tag -a v0.2.0 -m "v0.2.0"
   git push origin main v0.2.0
   ```
3. The **Release** workflow (`.github/workflows/release.yml`) fires on the `v*`
   tag: it verifies the header version matches the tag, builds
   `dist/elan-bridge.zip`, and publishes it as a GitHub Release with
   auto-generated notes. The tag's `--expect` guard fails the build if you
   forgot to bump the version — so a mismatched zip can never ship.

Build the zip locally the same way CI does (no PHP/Composer required):

```bash
bin/build-plugin-zip.sh            # -> dist/elan-bridge.zip
```

Customers install it via **Plugins → Add New → Upload Plugin**, or unzip into
`wp-content/plugins/`.

## How auto-update works

The plugin carries an `Update URI` header (so wordpress.org never claims the
slug) and registers `GitHubReleaseUpdater`, which:

- polls `GET /repos/elanlanguages/elan-bridge-wordpress/releases/latest`
  (cached 6h),
- if the release version is newer than installed, shows the normal WordPress
  "update available" prompt,
- downloads the release's `elan-bridge.zip` asset through the GitHub asset API
  and installs it.

## Repo visibility vs. the update token

> **Decided: the repo is public.** Auto-update therefore works with **no token
> and no per-site config** — installed sites fetch release metadata and download
> the zip straight from the public GitHub API. This was verified end-to-end
> (tokenless metadata fetch + asset download) against `v0.1.0`. The options below
> are kept for reference in case the policy ever changes. The source is
> readable; the `License: Proprietary` header still governs reuse
> (source-available, not open source).

Auto-update needs the installed site to reach the release asset. There are two
supported paths:

| Option | What to do | Trade-off |
|---|---|---|
| **Public / source-available repo** *(simplest)* | Make `elan-bridge-wordpress` public | Updates work with zero config; the connector source becomes readable. The product's value is the bridge SaaS, not this thin connector, so this is usually fine. |
| **Private repo + token** | Keep the repo private; define `ELAN_BRIDGE_UPDATE_TOKEN` (a read-only GitHub token) in each site's `wp-config.php` | Source stays closed, but the token must be provisioned per site — only practical if you control the sites, since the token ships with the config. |

If neither fits (closed source **and** customer-controlled sites), front updates
with a **license-gated proxy**: an endpoint on the bridge that authenticates the
site's Application Password and returns the release metadata + a signed download
URL. The plugin already authenticates to the bridge, so this is the natural
long-term home — point `GitHubReleaseUpdater`'s endpoints at it when needed.

> Until one of these is in place, the **manual** path always works: download the
> zip from the GitHub Release (or build it locally) and upload it in wp-admin.
