# basketball-api-nightly-scores

WordPress plugin that emails a nightly basketball stat summary (plus a CSV
attachment). Scraping runs externally (GitHub Actions + Playwright) and is
pushed into WordPress over REST.

## How it works

1. **Per-player scanning fields.** Each Player (`rba_player`) post has a
   **Nightly Scores (Flashscore)** meta box with a Flashscore **slug** and
   **ID** (stored as post meta `bans_flashscore_slug` / `bans_flashscore_id`).
2. **Choosing the crawl.** *Settings → Basketball Scores* lists every published
   player with an **Include** checkbox (stored as meta `bans_include_in_crawl`).
   A player is scanned only when it is ticked **and** has both Flashscore
   fields. The same page holds the daily recipients, test email, and the
   GitHub push secret.
3. **Nightly scrape (external).** GitHub Actions calls
   `GET /wp-json/bans/v1/push`'s sibling `GET /wp-json/bans/v1/players`
   (authenticated with `X-BANS-SECRET`) to get the included players, scrapes
   Flashscore, and `POST`s the rows to `/wp-json/bans/v1/push`, where they are
   stored in the `bans_last_push` option.
4. **Nightly email.** A WP-Cron event (`bans_nightly_event`, daily ~2am) emails
   the last-pushed rows with a CSV attachment. "Send Test Email" reuses the
   most recent push.

## GitHub Actions secrets

- `BANS_PUSH_URL` — e.g. `https://example.com/wp-json/bans/v1/push`
- `BANS_SECRET` — the push secret shown on the admin page

## Notes

- The `rba_player` custom post type is registered by the companion
  **rba-blocks** plugin/theme; this plugin only attaches fields to it. Change
  the `BANS_PLAYER_POST_TYPE` constant if that key ever changes.
- On upgrade, any legacy players stored in the old `bans_settings` option are
  migrated once onto matching player posts (matched by title).
