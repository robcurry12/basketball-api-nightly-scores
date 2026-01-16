import { chromium } from "playwright";

const PUSH_URL = process.env.BANS_PUSH_URL;
const SECRET = process.env.BANS_SECRET;

if (!PUSH_URL || !SECRET) {
  console.error("Missing BANS_PUSH_URL or BANS_SECRET env vars.");
  process.exit(1);
}

const PLAYERS_URL =
  process.env.BANS_PLAYERS_URL ||
  PUSH_URL.replace(/\/push\/?$/, "/players");

async function fetchPlayers() {
  const res = await fetch(PLAYERS_URL, {
    method: "GET",
    headers: { "X-BANS-SECRET": SECRET },
  });

  const text = await res.text();
  if (!res.ok) {
    console.error("Players fetch failed:", res.status, text);
    process.exit(1);
  }

  const data = JSON.parse(text);
  if (!data.ok || !Array.isArray(data.players)) {
    console.error("Players response invalid:", data);
    process.exit(1);
  }

  return data.players.map((p) => ({
    label: p.label,
    slug: p.flashscore_slug,
    id: p.flashscore_id,
  }));
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
  const players = await fetchPlayers();

  if (!players.length) {
    console.log("No players returned from WP admin. Nothing to scrape.");
    process.exit(0);
  }

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
          game_date: r.game.date_iso, // WP will format to MM/DD/YYYY
          game_url: r.game.url,       // WP will strip querystring
          ...r.stats,
        });
      }
    } catch (e) {
      console.error(`Scrape error for ${p.label}:`, e);
    }
  }

  await browser.close();

  const payload = {
    source: "github-actions",
    generated_at_utc: new Date().toISOString(),
    rows,
  };

  const res = await fetch(PUSH_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-BANS-SECRET": SECRET,
    },
    body: JSON.stringify(payload),
  });

  const text = await res.text();
  if (!res.ok) {
    console.error("Push failed:", res.status, text);
    process.exit(1);
  }

  console.log("Push OK:", text);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
