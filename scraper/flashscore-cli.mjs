import { createRequire } from 'module';

const require = createRequire(import.meta.url);

// IMPORTANT:
// Using require() here (instead of ESM import) ensures Playwright resolves
// correctly when NODE_PATH is set by a wrapper script (common on shared hosts).
const { chromium } = require('playwright');

function parseFlashscoreDate(dateText) {
  const match = /^(\d{2})\.(\d{2})\.(\d{2})$/.exec(dateText);
  if (!match) return null;

  return {
    yyyy: 2000 + Number(match[3]),
    mm: Number(match[2]),
    dd: Number(match[1]),
  };
}

function isWithinOneDayET(parts, now = new Date()) {
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/New_York',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  });

  const [ny, nm, nd] = fmt.format(now).split('-').map(Number);

  const todayDays = Math.floor(Date.UTC(ny, nm - 1, nd) / 86400000);
  const gameDays  = Math.floor(Date.UTC(parts.yyyy, parts.mm - 1, parts.dd) / 86400000);

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

async function scrape(slug, id) {
  const url = `https://www.flashscore.com/player/${slug}/${id}/`;

  const browser = await chromium.launch({ headless: true });

  const context = await browser.newContext({
    userAgent:
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) ' +
      'AppleWebKit/537.36 (KHTML, like Gecko) ' +
      'Chrome/120.0.0.0 Safari/537.36',
  });

  const page = await context.newPage();

  // Speed up: block images/fonts
  await page.route('**/*', route => {
    const type = route.request().resourceType();
    if (type === 'image' || type === 'font') route.abort();
    else route.continue();
  });

  await page.goto(url, { waitUntil: 'domcontentloaded' });

  await page.waitForSelector('#last-matches .lmTable a:first-of-type', {
    timeout: 20000,
  });

  const raw = await page.evaluate(() => {
    const a = document.querySelector('#last-matches .lmTable a:first-of-type');
    if (!a) return null;

    return {
      href: a.href,
      dateText: a.querySelector('.lmTable__date')?.textContent?.trim() || '',
      icons: Array.from(a.querySelectorAll('.lmTable__icon')).map(el =>
        (el.textContent || '').trim()
      ).filter(Boolean),
    };
  });

  await browser.close();

  if (!raw) {
    return { ok: false, error: 'Could not locate last match row.' };
  }

  const dateParts = parseFlashscoreDate(raw.dateText);
  if (!dateParts) {
    return { ok: false, error: 'Invalid date format.', raw };
  }

  if (!isWithinOneDayET(dateParts)) {
    return {
      ok: true,
      ignored: true,
      reason: 'Game not within last day (ET).',
      game: { date_text: raw.dateText, url: raw.href },
    };
  }

  const stats = mapIcons(raw.icons);
  if (!stats) {
    return { ok: false, error: 'Stat columns missing.', raw };
  }

  return {
    ok: true,
    ignored: false,
    game: {
      date_text: raw.dateText,
      date_iso: `${dateParts.yyyy}-${String(dateParts.mm).padStart(2, '0')}-${String(dateParts.dd).padStart(2, '0')}`,
      url: raw.href,
    },
    stats,
  };
}

const [, , slug, id] = process.argv;

if (!slug || !id) {
  console.error(JSON.stringify({ ok: false, error: 'Missing slug or id.' }));
  process.exit(1);
}

scrape(slug, id)
  .then(result => console.log(JSON.stringify(result)))
  .catch(err => {
    console.error(JSON.stringify({ ok: false, error: String(err) }));
    process.exit(1);
  });
