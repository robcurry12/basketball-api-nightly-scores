# basketball-api-nightly-scores

WordPress plugin + GitHub Actions job that emails a nightly basketball stat
summary (with a CSV attachment). Scraping runs on GitHub (Playwright); data
moves **through the repo**, so nothing ever calls into the WordPress site.

## Why the repo is the transport

The WordPress site sits behind managed-host edge protection (SiteGround
Anti-Bot AI / WAF) that blocks inbound API requests from datacenter IPs like
GitHub Actions runners. Outbound requests from the site are fine. So:

- **WordPress → GitHub (outbound):** on save, WP writes the crawl list to
  `data/players.json` via the GitHub Contents API.
- **GitHub Actions:** reads `data/players.json`, scrapes Flashscore, writes
  `data/latest.json`, and commits it back to the repo.
- **WordPress ← GitHub (outbound):** the nightly WP-Cron pulls
  `data/latest.json` from the repo's public raw URL and emails it.

## How it works

1. **Per-player scanning fields.** Each Player (`rba_player`) post has a
   **Nightly Scores (Flashscore)** meta box with a Flashscore **slug** and
   **ID** (post meta `bans_flashscore_slug` / `bans_flashscore_id`). These are
   also editable inline on the admin page.
2. **Choosing the crawl.** *Settings → Basketball Scores* lists every published
   player with an **Include** checkbox (meta `bans_include_in_crawl`). A player
   is scanned only when ticked **and** it has both Flashscore fields. Saving the
   page pushes the list to `data/players.json` in the repo.
3. **Nightly scrape (GitHub Actions).** The scheduled workflow runs
   `scripts/bans-scrape.mjs`, which scrapes each player and writes
   `data/latest.json`, then commits it.
4. **Nightly email (WP-Cron).** `bans_nightly_event` (daily ~2am) pulls
   `data/latest.json` from GitHub, formats the rows, and emails them with a CSV.
   "Send Test Email" does the same on demand.

## Admin configuration (Settings → Basketball Scores)

- **Daily Recipients / Test Email** — where the email goes.
- **GitHub Repository** — owner, repo, branch, and the players/results file
  paths (defaults: `data/players.json`, `data/latest.json`).
- **Access Token** — a **fine-grained personal access token** scoped to this
  one repository with **Contents: Read and write**. Used only for the outbound
  write of `players.json`. Leave blank to keep the saved token.
- **Sync Players to GitHub Now** / **Send Test Email** buttons for manual runs.

## GitHub Actions

`.github/workflows/bans-scrape.yml` runs on a cron (7am UTC ≈ 2–3am ET) and on
manual dispatch. It needs `permissions: contents: write` (already set) so it can
commit `data/latest.json` using the built-in `GITHUB_TOKEN` — **no repository
secrets are required** anymore (the old `BANS_PUSH_URL` / `BANS_SECRET` secrets
can be deleted).

## Notes

- The `rba_player` custom post type is registered by the companion
  **rba-blocks** plugin/theme; this plugin only attaches fields to it. Change
  the `BANS_PLAYER_POST_TYPE` constant if that key ever changes.
- Legacy players stored in the old `bans_settings` option are migrated once onto
  matching player posts (matched by title).
- Scheduling: make sure the WP-Cron email time is comfortably *after* the
  Actions run so the freshest `latest.json` is in the repo when WordPress pulls.
