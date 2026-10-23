# wp-harness

A throwaway WordPress + WooCommerce site for testing a plugin: one command makes a clean site with the plugin mounted and a demo catalog seeded, then the same script runs the tests, Plugin Check and a logged-in browser, and deletes the site when you're done. It doesn't depend on this plugin; copy the `harness` folder into any plugin repo (or point it at any plugin folder).

```sh
harness/wp-harness up          # clean site, plugin active, demo catalog, snapshot, server on :8080
harness/wp-harness test        # offline tests + integration tests (resets the snapshot first)
harness/wp-harness lint        # php -l every PHP file
harness/wp-harness check       # Plugin Check, leaving out what .distignore leaves out
harness/wp-harness shot 'edit.php?post_type=product' /shop/ --out shots
harness/wp-harness down        # stop the server and delete the site
```

Then browse **http://localhost:8080** (admin / admin). Use `localhost`, not `127.0.0.1`, or the login cookie won't stick.

## What it needs

It picks one of two ways to run:

| | Needs | Used when |
|---|---|---|
| **Direct** | PHP 8.1+ with `pdo_sqlite`, `curl`, `git`, `unzip` | PHP is installed (cloud containers) |
| **Docker** | Docker | there is no `php` but there is `docker` (a Mac with Docker Desktop), or `HARNESS_DOCKER=1` |

There is no MySQL: the site runs on SQLite through the [SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration) drop-in, so the whole site is one folder and resetting is a file copy. Everything is downloaded from GitHub, not wordpress.org, because cloud sandboxes often block wordpress.org: WordPress from the `WordPress/WordPress` mirror, WooCommerce and Plugin Check from their GitHub releases, WP-CLI from `wp-cli/builds`. Downloads are cached in `~/.cache/wp-harness/downloads`; a first `up` takes about 25 seconds, later ones about 15.

In Docker mode, `up` builds a small PHP image once (`harness/docker/Dockerfile`), runs a container named `wp-harness-<site>` with the plugin folder mounted, and every other command runs inside it. The site lives in a Docker volume of the same name. `shot` and `browse` run Playwright on your computer against the container's port.

Screenshots need Node and Playwright. Cloud containers have them in `/opt/node-tools`; elsewhere install them once with `npm i -g playwright && npx playwright install chromium`.

## Commands

| Command | Does |
|---|---|
| `up [DIR] [--port N] [--no-seed] [--fresh]` | Makes the site for the plugin in DIR (default: the nearest folder up from here with a main plugin file), activates WooCommerce and the plugin, seeds the demo catalog and saves a snapshot. Running it again keeps the existing site; `--fresh` remakes it. |
| `serve`, `stop` | Start or stop the web server |
| `seed` | Add the demo catalog again (skips products that are there) |
| `reset` | Put the database back to the snapshot |
| `snapshot` | Make the current database the snapshot |
| `wp ARGS` | WP-CLI on the site, e.g. `wp-harness wp plugin list` |
| `eval FILE` | `wp eval-file`, with paths relative to the plugin |
| `lint` | `php -l` on every PHP file except `vendor` and `node_modules` |
| `test [--unit\|--integration]` | Runs each `tests/test-*.php` with plain `php` and each `tests/integration-*.php` with `wp eval-file`, after a `reset`. A file fails if it exits non-zero (an uncaught exception does that). |
| `check [ARGS]` | Plugin Check with its runtime checks. Folders and files named in `.distignore` are excluded, so tests and this harness aren't checked. Extra args go to `wp plugin check`, e.g. `--format=json` or `--checks=i18n_usage`. |
| `shot PATH... [--out DIR] [--width N] [--full]` | Logs in and saves a screenshot of each path. A path without a leading `/` is under `wp-admin/`. |
| `browse SCRIPT.mjs [ARGS]` | Runs a Playwright script logged in; see below |
| `status`, `sites`, `down` | Show the site, list sites, delete the site |

Several sites can exist at once (one per plugin slug, or name one with `HARNESS_SITE`); give each its own `--port`.

## The site

- WordPress 7.1.3, WooCommerce 11.2.1, Plugin Check 2.1.0 (installed, inactive except during `check`). Override with `WP_VERSION`, `WC_VERSION` (`none` for a plugin that doesn't need WooCommerce), `PCP_VERSION`.
- `WP_DEBUG` on, logged to `wp-content/debug.log`, not displayed. Cron and automatic updates off. Pretty permalinks.
- WooCommerce set up as a US store in dollars with its pages made, and the setup wizard, coming-soon mode, tracking and marketplace suggestions turned off.
- The plugin is a symlink to your folder, so edits show up at once.
- Site files are in `~/.cache/wp-harness/sites/<site>/` (`wordpress/`, `db/wp.sqlite`, `snapshot.sqlite`, `server.log`).

## The demo catalog

`lib/seed-catalog.php` adds 32 products in Apparel, Drinkware, Coffee and Home. It is deliberately messy the way real catalogs are, so tools that clean up catalogs have something to find:

- Simple products that are really one product in variants: Classic Crew Tee in four colors, Organic V-Neck Tee in four sizes (XL costs more), Enamel Camp Mug in colors, House Blend Coffee in three grinds, Soy Candle in four scents, Single Origin Colombia in two sizes.
- Names written inconsistently: case, double spaces, a trailing space, brackets, dashes.
- SKUs in several styles (`TEE100-BLK`, `CDL-08-LAV`, lower-case `vn220-xl`), a duplicate SKU, two products with no SKU.
- A brand (Northwind) at the start of unrelated products.
- Sale prices, stock counts (one at 0), a draft, a hidden product, tags, and one real variable product (Canvas Apron, two colors).

Every product it makes has the meta `_harness_seed`. Use your own seed with `SEED=path/to/seed.php` in `.wp-harness`.

## Per-plugin settings

An optional `.wp-harness` file at the plugin root is read as bash:

```sh
HARNESS_UNIT='tests/unit/*.php'           # default tests/test-*.php
HARNESS_INTEGRATION='tests/wp/*.php'      # default tests/integration-*.php
WC_VERSION=none                           # plugin doesn't use WooCommerce
EXTRA_PLUGINS='plugin-check'              # more plugins to activate (must be in wp-content/plugins)
SEED="$PLUGIN_DIR/tests/seed.php"         # replace the demo catalog
```

## Browse scripts

A browse script is an ES module whose default export gets a logged-in page:

```js
export default async function ({ page, go, shot, problems, args, base }) {
	await go('edit.php?post_type=product');   // a wp-admin path, "/front/end/path" or a full URL
	await shot('products');                   // saves products.png in --out (default ./screenshots)
	await shot('first-row', page.locator('#the-list tr').first());
	if (problems.length) throw new Error(problems.join('\n'));
}
```

`problems` collects JavaScript errors, this site's failed requests and 4xx/5xx responses, and PHP notices printed on pages; they are also printed at the end. Dialogs are accepted. See `examples/walkthrough.mjs`.

## In cloud sessions

- Run node with `NO_PROXY='*'` (the script does) so requests to localhost skip the sandbox proxy.
- Each session starts with an empty cache, so the first `up` downloads everything again.
- WooCommerce prints a `_load_textdomain_just_in_time` notice when it is activated from WP-CLI. It is WooCommerce's, and harmless.
