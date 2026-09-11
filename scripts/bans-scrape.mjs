import { readFile, writeFile, mkdir } from "node:fs/promises";
import { dirname } from "node:path";
// Playwright is imported lazily (only when there's something to scrape) so the
// empty-players path doesn't require the browser dependency to be installed.

// Pull model: read the player list WordPress committed to the repo, scrape
// Flashscore, and write results back to the repo. GitHub Actions then commits
// the results file, which WordPress pulls in for the nightly email. Nothing
// calls into WordPress, so the site's edge protection is never in the way.
const PLAYERS_PATH = process.env.BANS_PLAYERS_PATH || "data/players.json";
const RESULTS_PATH = process.env.BANS_RESULTS_PATH || "data/latest.json";

async function loadPlayers() {
  let text;
  try {
    text = await readFile(PLAYERS_PATH, "utf8");
  } catch (e) {
    console.error(`Could not read ${PLAYERS_PATH}: ${e.message}`);
    console.error(
      "WordPress writes this file when you save the Basketball Scores " +
        "settings (or click “Sync Players to GitHub Now”). Sync once, then re-run."
    );
    process.exit(1);
  }

  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    console.error(`Invalid JSON in ${PLAYERS_PATH}: ${e.message}`);
    process.exit(1);
  }

  const players = Array.isArray(data.players) ? data.players : [];
  return players
    .map((p) => ({
      label: p.label,
      slug: p.flashscore_slug,
      id: p.flashscore_id,
    }))
    .filter((p) => p.slug && p.id);
}

async function writeResults(rows) {
  const payload = {
    source: "github-actions",
    generated_at_utc: new Date().toISOString(),
    rows,
  };

  await mkdir(dirname(RESULTS_PATH), { recursive: true });
  await writeFile(RESULTS_PATH, JSON.stringify(payload, null, 2) + "\n", "utf8");
  console.log(`Wrote ${rows.length} row(s) to ${RESULTS_PATH}`);
}

function parseFlashscoreDate(dateText) {
  const m = /^(\d{2})\.(\d{2})\.(\d{2})$/.exec(dateText);
  if (!m) return null;
  return { yyyy: 2000 + Number(m[3]), mm: Number(m[2]), dd: Number(m[1]) };
}

function isWithinOneDayET(parts, now = new Date()) {
  const fmt = new Intl.DateTimeFormat("en-CA", {
    timeZone: "America/New_York",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  });

  const [ny, nm, nd] = fmt.format(now).split("-").map(Number);
  const todayDays = Math.floor(Date.UTC(ny, nm - 1, nd) / 86400000);
  const gameDays = Math.floor(Date.UTC(parts.yyyy, parts.mm - 1, parts.dd) / 86400000);

  const diff = todayDays - gameDays;
  return diff >= 0 && diff <= 1;
}

function mapIcons(icons) {
  if (!Array.isArray(icons) || icons.length < 6) return null;
  const [minutes, pts, reb, ast, stl, tov] = icons;
  return {
    minutes: String(minutes).trim(),
    points: Number(pts),
    rebounds: Number(reb),
    assists: Number(ast),
    steals: Number(stl),
    turnovers: Number(tov),
  };
}

async function scrapePlayer(page, player) {
  const url = `https://www.flashscore.com/player/${player.slug}/${player.id}/`;

  await page.goto(url, { waitUntil: "domcontentloaded" });
  await page.waitForSelector("#last-matches .lmTable a:first-of-type", {
    timeout: 30000,
  });

  const raw = await page.evaluate(() => {
    const a = document.querySelector("#last-matches .lmTable a:first-of-type");
    if (!a) return null;

    return {
      href: a.href,
      dateText: a.querySelector(".lmTable__date")?.textContent?.trim() || "",
      icons: Array.from(a.querySelectorAll(".lmTable__icon"))
        .map((el) => (el.textContent || "").trim())
        .filter(Boolean),
    };
  });

  if (!raw) return { ok: false, error: "No match row found" };

  const dateParts = parseFlashscoreDate(raw.dateText);
  if (!dateParts) return { ok: false, error: "Invalid date", raw };

  if (!isWithinOneDayET(dateParts)) {
    return { ok: true, ignored: true, reason: "Not within last day ET", raw };
  }

  const stats = mapIcons(raw.icons);
  if (!stats) return { ok: false, error: "Missing stats columns", raw };

  const game_date = `${dateParts.yyyy}-${String(dateParts.mm).padStart(2, "0")}-${String(
    dateParts.dd
  ).padStart(2, "0")}`;

  return {
    ok: true,
    ignored: false,
    game: {
      date_iso: game_date,
      url: raw.href,
    },
    stats,
  };
}

async function main() {
  const players = await loadPlayers();

  if (!players.length) {
    console.log("No scannable players in players.json. Writing empty results.");
    await writeResults([]);
    return;
  }

  const { chromium } = await import("playwright");
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    userAgent:
      "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
  });

  const page = await context.newPage();
  await page.route("**/*", (route) => {
    const t = route.request().resourceType();
    if (t === "image" || t === "font") route.abort();
    else route.continue();
  });

  const rows = [];

  for (const p of players) {
    try {
      const r = await scrapePlayer(page, p);
      if (r.ok && !r.ignored) {
        rows.push({
          player: p.label,
          game_date: r.game.date_iso, // WP formats to MM/DD/YYYY
          game_url: r.game.url, // WP strips querystring
          ...r.stats,
        });
      }
    } catch (e) {
      console.error(`Scrape error for ${p.label}:`, e);
    }
  }

  await browser.close();

  await writeResults(rows);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
