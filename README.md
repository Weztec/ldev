# Linux Dev

A Laravel Valet/Herd-style local development environment for **Fedora 44 KDE Plasma**.

Linux Dev provisions a full local dev stack (nginx, PHP-FPM across multiple PHP versions, MariaDB,
PostgreSQL, Valkey, Memcached, Mailpit, RustFS (S3 storage), Meilisearch, Supervisor, mkcert, dnsmasq, cloudflared) and layers a dashboard
on top for managing sites, services, and per-project settings from a browser — no terminal needed
for day-to-day work, once it's installed.

**New to Linux Dev?** This file covers installing it. Once it's running, the dashboard has a built-in
**Help** section (bottom of the sidebar) with a page-by-page walkthrough of every feature and how
to set up different kinds of projects — that's the faster reference once you're up and running.

**About the name.** This project is **FLDev**: the "F" means it was built for Fedora. Other Linux
distributions will get their own versions with a different prefix, for example **ULDev** for
Ubuntu. Inside this project the distro-neutral name `ldev` is used for paths, services and commands
(`~/.ldev`, `~/.config/ldev`, `ldev-dashboard`, and so on).

## What's included

- Wildcard `*.test` domains over HTTPS via mkcert + dnsmasq, including subdomains
- Multiple PHP versions (7.4 through 8.5) selectable per site, switchable at any time
- Multiple Node.js versions (via nvm) selectable per site
- MariaDB, PostgreSQL, and SQLite — pick a driver per project, and either create a new database or
  point at one that already exists
- Mailpit for catching every project's outbound mail, RustFS for local S3-compatible storage,
  Meilisearch for fast, typo-tolerant search (one switch connects a project, including Laravel Scout)
- A per-project `dump()`/`dd()` viewer: send a project's dumps to its dashboard page instead of the
  browser, including from JSON, Livewire, queued jobs and artisan commands
- Supervisor-managed background processes per project: queue workers, Laravel Reverb (WebSockets),
  the Laravel scheduler, plus your own custom jobs (one-off commands or cron-scheduled tasks,
  saveable as reusable templates)
- A dashboard for creating new projects (fresh Laravel scaffold, or cloned from an existing
  GitHub/Bitbucket repository or a plain static/WordPress project), managing services, browsing
  databases (via Adminer), reading logs, and backing up and restoring both project and dashboard data
- A committed `ldev.json` per project, so everyone who clones it gets the same PHP/Node versions,
  database, workers and jobs, with checks that the installed services meet the project's requirements
- A temporary public demo link per project through a free Cloudflare quick tunnel (no signup)
- Background housekeeping timers: every 5 minutes it looks for new projects in `~/Sites` and checks
  health (failed services, expiring certificates, low disk space), and once a day it renews
  certificates, backs up databases, and checks for newer versions
- Update awareness: new Linux Dev releases, newer PHP/Node/framework releases, newer npm for each
  installed Node version (with a one-click upgrade), and pending Fedora
  updates for the stack's own packages show as a dashboard banner, with a copyable
  `sudo dnf upgrade` command
- Per-project dependency health: Composer and npm security advisories (`composer audit`,
  `npm audit`) plus outdated packages. Pick packages (or a whole list) and the update is first
  tried in a sandbox copy of the project, which checks that the app boots, routes load, views
  compile, the front end builds and the tests pass. Major-version upgrades can be tried the same
  way (the version constraint is raised in the copy only). The notes say whether a problem is new
  or was already there, nothing changes in the project until you apply the tested versions, and a
  desktop notification tells you when the test has finished
- A per-project test runner for Pest, PHPUnit, Vitest and Jest that picks the right runner for
  each file: run everything, a suite, only last run's failures, or picked files and single tests;
  see passed/failed/skipped results with failure messages and skip reasons; optionally measure code
  coverage; and keep a short run history that flags possibly flaky tests. Each developer can
  override test settings (database password, host, API keys) for their own machine without editing
  the project's `phpunit.xml`
- Health badges on the Sites list for vulnerable packages, failing tests and tested updates that
  are ready to apply
- Composer credentials for private package repositories (such as Flux Pro), set once globally and
  overridable per project when one project needs a different account or license. Per-project ones
  go in that project's git-ignored `auth.json`
- A project file editor for `.env`, `.env.example`, `.env.testing`, `phpunit.xml` and
  `.gitignore`, with a rolling `.env` backup and an XML check before saving phpunit files
- A live system overview (OS, uptime, load, memory, disk, database sizes, certificate expiry) and
  optional KDE desktop notifications when something needs attention

## Requirements

- Fedora 44 (or compatible), KDE Plasma
- `sudo` access, using the account you actually intend to develop as (not a raw root login)
- An internet connection during install (packages, Composer, npm, mkcert's CA, etc. are downloaded)

## Installing

```
sudo ./install.sh
```

This runs `setup-environment.sh` (installs and configures every OS-level service listed above —
one-time setup, safe to re-run later if you want to pick up newer package versions) and then
`deploy-app.sh` (builds and starts the dashboard itself). A first-time install takes several
minutes — it's downloading and installing real packages, not just copying files.

Installing adds a **Linux Dev** launcher to your applications menu (KDE Plasma's app launcher/
kickoff) — use that to open the dashboard the first time; it starts the dashboard service if it
isn't already running and opens it with the access token attached. Your browser remembers that
token afterward (as a cookie), so `http://127.0.0.1:8090` on its own is enough from then on. If
you ever get a 403, reopen it from the launcher; see Troubleshooting below for the terminal
commands, which work without dashboard access.

### Re-running things later

| Command | When to use it |
|---|---|
| `sudo ./deploy-app.sh` | You've changed `control-app/` (the dashboard's own code) and want to redeploy it. Safe to re-run any time — it doesn't touch your projects. |
| `sudo ./setup-environment.sh` | You want to pick up newer versions of the underlying OS packages, or re-apply configuration after something changed. Idempotent — safe to run again. |
| `sudo ./uninstall.sh` | Removes everything Linux Dev installed. Leaves `~/Sites` (your actual projects) and your S3 bucket data alone — see [Layout](#layout) below for exactly what's kept and what isn't. |

### Updating Linux Dev

The installed version is shown at the bottom of the dashboard's sidebar. Once a day it checks
GitHub for a newer release; when there is one, the sidebar says *Update available*, and
**Settings → Updates → Update** shows the exact command for your checkout with a **Copy** button.
To update, from your checkout:

```
git pull
sudo ./install.sh
```

`install.sh` re-runs both setup scripts, which are safe to run again. Your sites, settings and
tokens are kept, and `deploy-app.sh` backs up the dashboard's database before it touches anything.

## First steps

1. Open the dashboard and look at the **Sites** page — empty on a fresh install.
2. Click **New Project** to scaffold a fresh Laravel app, or clone an existing repository. The
   in-app Help page's "Creating a project" and "Project types" sections walk through every option
   (database driver, PHP/Node version, starter kits, S3/Reverb, WordPress, static sites, and so on).
3. Once created, your project is live at `https://<name>.test` — a real HTTPS address with a
   trusted local certificate, no extra setup.
4. From there, each project has its own **Overview** page (status, git, logs, quick links to
   Mailpit/Adminer) and **Project settings** page (PHP/Node/database, background jobs, backups,
   `.env` editing) — both explained in-app under Help.

The sidebar can be collapsed to icons only (the toggle is at the bottom, next to Help) if you want
more room.

## Layout

```
install.sh            → runs setup-environment.sh then deploy-app.sh (first-time install)
setup-environment.sh  → OS packages/services/DNS/TLS/DB-auth. Idempotent. Run once per machine.
deploy-app.sh          → scaffolds the dashboard app and (re)deploys it. Re-run whenever
                         control-app/ changes.
uninstall.sh           → reverses both. Leaves ~/Sites and S3 bucket data alone.
control-app/           → the dashboard's source (overlaid onto a Laravel skeleton at deploy time)
```

On a running machine, Linux Dev also uses:

| Path | What's there | Survives an uninstall? |
|---|---|---|
| `~/Sites` | Every project you create or link — one directory per project. | Yes |
| `~/.ldev/app` | The deployed dashboard itself. Fully rebuilt by `deploy-app.sh`. | No (regenerable) |
| `~/.ldev/storage` | S3 bucket data (RustFS) and Meilisearch's indexes. | Yes |
| `~/.ldev/storage/dependency-sandbox`, `~/.ldev/storage/test-runs`, `~/.ldev/storage/dumps` | Throwaway project copies for testing dependency updates, test-run logs, and collected `dump()` output. Safe to delete. | No |
| `~/.config/ldev` | nginx vhosts, TLS certs, per-project logs, Supervisor job configs, the dashboard's access token, project and dashboard backups. | No (regenerable config; **back up `~/.config/ldev/backups` yourself if it matters** — see the Help section's "Backups & recovery" page) |

## Sharing a project's setup (ldev.json)

On a project's **Project settings** page, **Save current settings to ldev.json** writes a file to
the project root with its PHP and Node versions, database driver and name, flags (Xdebug, queue
worker, Reverb, scheduler, Meilisearch, daily backups), queue settings and custom background jobs. Commit it and
anyone who adds or clones the project gets the same setup automatically. It never contains
passwords or `.env` values. New projects created from a starter kit get one automatically, in their
first commit. Any other project without one shows a **Create ldev.json** notice on its Overview
page, and the file can be viewed and edited under **Project settings → Environment / project files**.
Changing a project's PHP or Node version doesn't touch the file, so you can try a version first: a
note offers **Keep … for everyone** (updates `ldev.json`) or **Switch back**.

```json
{
  "php": "8.4",
  "node": "24",
  "database": { "driver": "mysql", "name": "acme_shop" },
  "queue": { "enabled": true, "queues": "default,emails", "workers": 2 },
  "scheduler": true,
  "requires": { "mariadb": ">=11.4", "postgresql": "^17 || ^18" }
}
```

Every key is optional. `requires` states the service versions a project needs (`mariadb`,
`postgresql`, `valkey`, `memcached`, `nginx`, using Composer-style constraints). Services are
installed once and shared by every project, so Linux Dev checks these and shows a *requirements not
met* badge rather than running a different version per project. PHP and Node versions are
per project.

## Connecting GitHub/Bitbucket repositories (dashboard's Repositories page)

The dashboard's own Repositories page (list your repos, clone one into a new project, push a
newly created project to a brand-new remote repo) talks to GitHub's/Bitbucket's REST API
directly, which needs its own token — an SSH key covers git's clone/push/pull protocol, but not
API calls like "list my repositories." If you don't need any of that, a plain SSH clone URL in the
New Project wizard needs no token at all — see the in-app Help page's "Repositories" section.

### Bitbucket

Bitbucket retired its old "App Password" mechanism in June 2026 — API tokens are the only option
now.

1. Go to https://id.atlassian.com/manage-profile/security/api-tokens
2. **Create API token with scopes**
3. Give it a name (e.g. "ldev") and an expiry (Atlassian caps this at 365 days)
4. App: **Bitbucket**
5. Scopes: `read:repository:bitbucket` (covers listing/cloning) — add
   `write:repository:bitbucket` too if you also want to use the "create a new repository" feature
6. Create it and copy the token immediately — it's shown only once

On the Repositories page: **Provider** → Bitbucket, **Username** → your Bitbucket username,
**Personal access token** → paste it, **Expires on** → whatever date you picked in step 3 (the
dashboard tracks this and warns you before it lapses — see below).

### GitHub

1. Go to https://github.com/settings/tokens?type=beta (fine-grained tokens)
2. **Generate new token**
3. Name it, set an expiration (up to 1 year, or "No expiration")
4. **Repository access**: "All repositories," or select specific ones
5. **Permissions → Repository permissions** → **Contents: Read and write** (Metadata is included
   automatically and can't be unchecked — that's expected, it's just baseline repo-info access),
   plus **Administration: Read and write** if you want the dashboard to create new repositories
   for you (*Push to new remote*). Without it, creating a repository fails with a 403.
6. **Generate token** and copy it

On the Repositories page: **Provider** → GitHub, **Username** → your GitHub username, **Personal
access token** → paste it.

### Renewing a token

Neither provider lets a token renew itself — there's no automated way around generating a fresh
one by hand when it expires. The Repositories page tracks each token's expiry date, shows a
warning banner once one is within 30 days of expiring (or already expired), and has a
"Renew"/"Edit" action per token: paste the new value there (leaving it blank keeps the current
one, if you're just updating the expiry date) rather than deleting and re-adding it.

## Troubleshooting

- **Dashboard shows a 403** — your browser's saved token doesn't match `~/.config/ldev/token`.
  You don't need the dashboard to fix it. Either open **Linux Dev** from the applications menu (it
  passes the current token and your browser saves it again), or run it from a terminal:
  ```bash
  ~/.ldev/scripts/launch-dashboard.sh
  ```
  or open the link by hand:
  ```bash
  xdg-open "http://127.0.0.1:8090/?token=$(cat ~/.config/ldev/token)"
  ```
  If `~/.config/ldev/token` is missing, or you want a new token (for example if it leaked), make a
  new one first, then use the launcher again:
  ```bash
  rm -f ~/.config/ldev/token
  cd ~/.ldev/app && php artisan ldev:generate-token
  ```
- **A site returns 502** — that project's PHP-FPM pool isn't running. Check the PHP Versions page.
- **A `.test` domain says "Server not found"** — check that `dnsmasq` is running on the Services
  page.
- **A project's tests fail with "Access denied" or "Unknown database"** — its `phpunit.xml` expects
  a different database setup. On the project's page open Tests → Test environment, click
  *Use ldev database settings*, then *Create database* if it's offered.

The in-app **Help** page has a fuller Troubleshooting section, and covers every other page and
setting in the dashboard in more depth than this file does.

## License

MIT — see [LICENSE](LICENSE).
