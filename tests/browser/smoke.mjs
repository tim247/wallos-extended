// Browser smoke test (Playwright, runs in CI): every page at phone width without PHP errors or
// horizontal overflow, the menu reachable to its last item, and the read-only login denied writes.
// Expects a running server (BASE_URL) on a fresh database; registers its own test account.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';

const base = process.env.BASE_URL || 'http://127.0.0.1:8721';
const user = 'browsertest';
const password = randomBytes(12).toString('hex'); // test value, only for this run
const failures = [];
const check = (ok, message) => { if (!ok) failures.push(message); console.log(`${ok ? 'ok  ' : 'FAIL'} ${message}`); };

const PAGES = ['index.php', 'subscriptions.php', 'contracts.php', 'insurance.php', 'financing.php', 'consumption.php',
  'calendar.php', 'yearly.php', 'stats.php', 'finances.php', 'report.php', 'wrapped.php', 'reminders.php', 'inventory.php',
  'emergency.php', 'tasks.php', 'person.php', 'annual_review.php', 'quick.php', 'bankimport.php', 'import.php',
  'history.php', 'settings.php', 'profile.php', 'admin.php', 'help.php', 'about.php'];

async function login(page, name, pass) {
  await page.goto(`${base}/login.php`);
  await page.fill('input[name="username"]', name);
  await page.fill('input[name="password"]', pass);
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"], input[type="submit"]')]);
}

// Elements that stick out to the right without a scrolling container around them.
const overflowScan = () => {
  const width = document.documentElement.clientWidth;
  const bad = [];
  document.querySelectorAll('main *, body > header *').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (!r.width || r.right <= width + 2 || getComputedStyle(el).position === 'fixed') return;
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
      const o = getComputedStyle(a).overflowX;
      if (o === 'auto' || o === 'scroll') return;
    }
    bad.push(`${el.tagName.toLowerCase()}.${String(el.className).split(' ')[0]}`);
  });
  return [...new Set(bad)].slice(0, 5);
};

const browser = await chromium.launch();
try {
  // 1. Register the first account (becomes the admin) and fill it with sample data.
  const context = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true });
  const page = await context.newPage();
  await page.goto(`${base}/registration.php`);
  await page.fill('input[name="username"]', user);
  await page.fill('input[name="firstname"]', 'Browser');
  await page.fill('input[name="lastname"]', 'Test');
  await page.fill('input[name="email"]', 'browser@example.test');
  await page.fill('input[name="password"]', password);
  await page.fill('input[name="confirm_password"]', password);
  await Promise.all([page.waitForNavigation(), page.click('input[type="submit"], button[type="submit"]')]);
  execFileSync('php', ['tests/browser/seed.php', user, password], { stdio: 'inherit' });
  await login(page, user, password);
  check(!page.url().includes('login.php'), 'login as the test account');

  // 2. Every page at phone width.
  for (const path of PAGES) {
    const response = await page.goto(`${base}/${path}`);
    await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
    const html = await page.content();
    const phpError = /<b>(Warning|Notice|Deprecated|Fatal error)<\/b>/.test(html);
    check(response.ok() && !phpError, `${path}: loads without PHP errors`);
    const bad = await page.evaluate(overflowScan);
    check(bad.length === 0, `${path}: nothing wider than the phone${bad.length ? ' (' + bad.join(', ') + ')' : ''}`);
  }

  // 3. The menu scrolls and its last item is reachable (on a small phone, too).
  await page.setViewportSize({ width: 375, height: 667 });
  await page.goto(`${base}/index.php`);
  const menu = await page.evaluate(() => {
    document.querySelector('.dropdown').classList.add('is-open');
    const content = document.querySelector('.dropdown-content');
    content.scrollTop = content.scrollHeight;
    const links = [...content.querySelectorAll('a')];
    const last = links[links.length - 1].getBoundingClientRect();
    return { right: Math.round(last.right), bottom: Math.round(last.bottom), width: innerWidth, height: innerHeight, count: links.length };
  });
  check(menu.right <= menu.width && menu.bottom <= menu.height, `menu: last of ${menu.count} items visible (${menu.right}x${menu.bottom})`);

  // 4. The read-only login sees pages but cannot write.
  await context.clearCookies();
  await login(page, `${user}_read`, password);
  for (const path of ['index.php', 'insurance.php', 'emergency.php', 'tasks.php', 'person.php']) {
    const response = await page.goto(`${base}/${path}`);
    check(response.ok() && page.url().endsWith(path), `read-only: ${path} opens`);
  }
  await page.goto(`${base}/settings.php`);
  check(!page.url().endsWith('settings.php'), 'read-only: settings are closed');
  const write = await page.evaluate(async () => {
    const r = await fetch('endpoints/life/emergency_entry.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
      body: JSON.stringify({ action: 'save', section: 'contacts', name: 'x' }),
    });
    return (await r.json()).success;
  });
  check(write === false, 'read-only: writing is denied');
} finally {
  await browser.close();
}

console.log(failures.length ? `\n${failures.length} check(s) failed` : '\nall browser checks passed');
process.exit(failures.length ? 1 : 0);
