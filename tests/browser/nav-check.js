// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The navigation, across every plugin at once.
//
// GLPI gives a plugin two ways to be reachable: a `config_page` hook, which puts
// a Configure link on the row in Setup > Plugins, and a `menu_toadd` hook, which
// puts an entry in the sidebar. Registering both for the same page is the
// natural thing to do and is what made the Setup menu unusable — with eight
// plugins installed, most of that menu was second links to pages already listed
// one click away.
//
// So this asserts the rule rather than the current list: no sidebar entry may
// point at a page the Plugins list already links. Entries that reach something
// *else* — an item list, an operational page — are exactly what the sidebar is
// for and are checked to still be there.
//
// It navigates by clicking rather than by URL. A menu entry that GLPI never
// renders is invisible to a check that visits URLs directly, which is how a
// fully-working page can be completely unreachable and still pass its tests.
//
// The second half covers glpi-nav, which moves suite entries out of core's
// sectors into sections of its own. That move is only half a change: GLPI
// resolves a page's breadcrumb, its "Add" button and its sidebar highlight by
// looking the sector the page *declares* up in the menu array, so an entry that
// moves while its pages still name the old sector loses all three, silently.
// Everything below the "glpi-nav" banner exists because that failure is
// invisible on a page that otherwise renders perfectly.
//
// Fixtures: pipe glpi-nav/tests/fixtures.php into the container first. It makes
// nav-probe (one suite right, to prove empty sections are not rendered) and
// nav-dark (auror_dark palette, for the dark pass).
const { chromium } = require('playwright');

const BASE = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + detail : ''}`);
  if (!cond) fail.push(name);
}

/**
 * Did the page actually render, or is this GLPI's error card?
 *
 * Not a length heuristic: a list page with nothing in it is legitimately a few
 * hundred characters, and judging it by size marks working pages as broken.
 * What distinguishes a failure is the error card itself, and an empty <main>.
 */
async function rendered(page) {
  const body = await page.evaluate(() => document.body.innerText);
  const main = await page.evaluate(() => (document.querySelector('main')?.innerText || '').trim());

  return !/An unexpected error occurred|Access denied/i.test(body) && main.length > 10;
}

/** Every sidebar entry, grouped by the sector it hangs under. */
async function sidebar(page) {
  return page.evaluate(() => {
    const out = {};
    for (const item of document.querySelectorAll('li.nav-item.dropdown')) {
      // The sector name is on the toggle button's label, not on a link — the
      // sector itself is not navigable, only the entries under it are.
      const sector = (item.getAttribute('aria-label')
        || item.querySelector('.menu-label')?.innerText
        || '').trim();
      if (!sector) continue;
      out[sector] = out[sector] || [];
      for (const a of item.querySelectorAll('a.dropdown-item')) {
        out[sector].push({ text: a.innerText.trim(), href: a.getAttribute('href') || '' });
      }
    }
    return out;
  });
}

/** The plugin rows and the page each one's Configure link points at. */
async function pluginRows(page) {
  await page.goto(`${BASE}/front/plugin.php`, { waitUntil: 'networkidle' });
  return page.evaluate(() =>
    [...document.querySelectorAll('table.search-results tr')]
      .map((tr) => {
        // Cell 0 is the massive-action checkbox; the name is cell 1, and the
        // Configure link is repeated on both the name and the actions cell.
        const name = (tr.cells?.[1]?.innerText || '').trim();
        const cfg = tr.querySelector('a[href*="/plugins/"][href$=".php"]');
        return name && cfg ? { name, href: cfg.getAttribute('href') } : null;
      })
      .filter(Boolean));
}

/** Log in, from whatever page we are on. */
async function login(page, user, password) {
  await page.goto(`${BASE}/front/logout.php`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', user);
  await page.fill('input[type=password]', password);
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
}

/** Breadcrumb text, Add button, and what the sidebar considers active. */
async function context(page) {
  return page.evaluate(() => ({
    crumbs: [...document.querySelectorAll('.breadcrumb .breadcrumb-item')].map((li) => li.innerText.trim()),
    add: !!document.querySelector('.navbar-nav a.btn-primary .ti-plus'),
    activeSector: [...document.querySelectorAll('li.nav-item.dropdown.active')].map((li) =>
      (li.getAttribute('aria-label') || li.querySelector('.menu-label')?.innerText || '').trim()),
    activeItem: [...document.querySelectorAll('a.dropdown-item.active')].map((a) => a.innerText.trim()),
  }));
}

/**
 * Colour maths, injected into the page rather than eval'd there: GLPI serves a
 * content-security policy, and a check that quietly fails to run is worse than
 * one that fails loudly.
 *
 * Two things a naive version gets wrong, both of which turn a correct stylesheet
 * into a failing assertion:
 *
 *  - `color-mix()` resolves to `color(srgb 0.61 0.65 0.75 / 0.72)`, not to
 *    `rgb(...)`. Read as 0-255 that is almost black, and every themed page
 *    "fails" contrast.
 *  - a translucent colour has to be composited over what is behind it before it
 *    can be measured at all.
 *
 * getComputedStyle also reports `rgba(0,0,0,0)` for an unpainted background, so
 * finding what text sits on means walking up to the first opaque ancestor.
 */
function colourProbe() {
  // -> [r, g, b, a] with r/g/b in 0-255.
  const parse = (s) => {
    const n = (s.match(/-?[\d.]+%?/g) || []).map((x) =>
      x.endsWith('%') ? parseFloat(x) / 100 : parseFloat(x));
    if (n.length < 3) return [0, 0, 0, 1];
    const scale = s.startsWith('color(') ? 255 : 1;
    return [n[0] * scale, n[1] * scale, n[2] * scale, n.length > 3 ? n[3] : 1];
  };
  const lum = (c) => {
    const v = c.slice(0, 3).map((x) => {
      x /= 255;
      return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  };
  const over = (fg, bg) => [0, 1, 2].map((i) => fg[i] * fg[3] + bg[i] * (1 - fg[3]));
  const behind = (el) => {
    for (let n = el; n; n = n.parentElement) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (c[3] > 0.99) return c;
    }
    return parse(getComputedStyle(document.body).backgroundColor);
  };
  const contrast = (el) => {
    const bg = behind(el);
    const a = lum(over(parse(getComputedStyle(el).color), bg));
    const b = lum(bg);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  };
  return { parse, lum, behind, contrast };
}

/** Every sub-text element in a container, with its measured contrast. */
async function subTextContrast(page, selector) {
  return page.evaluate(
    ({ sel, src }) => {
      const { contrast } = new Function('return ' + src)()();
      const root = document.querySelector(sel);
      if (!root) return null;
      return [...root.querySelectorAll('.form-text, .text-muted')]
        .filter((el) => el.innerText.trim().length > 2 && el.offsetParent !== null)
        .map((el) => ({ text: el.innerText.trim().slice(0, 40), ratio: +contrast(el).toFixed(2) }));
    },
    { sel: selector, src: colourProbe.toString() }
  );
}

/**
 * Panels painting near-white on a dark page — the --tblr-bg-surface-secondary
 * trap, which core's dark palettes leave at #e6e6e6.
 *
 * Form controls are excluded: a checkbox, a text input and a primary button are
 * *meant* to be lighter than the page, they are core's styling rather than a
 * plugin's, and counting them makes the assertion permanently red. What matters
 * is a container that paints a light slab and then writes body-coloured text on
 * it, so the filter is "light background, and text of its own".
 */
async function washedOut(page, selector) {
  return page.evaluate(
    ({ sel, src }) => {
      const { parse, lum } = new Function('return ' + src)()();
      const root = document.querySelector(sel);
      if (!root) return null;
      if (lum(parse(getComputedStyle(document.body).backgroundColor)) > 0.2) return [];
      const skip = ['INPUT', 'BUTTON', 'SELECT', 'TEXTAREA', 'OPTION', 'PROGRESS'];
      return [...root.querySelectorAll('*')]
        .filter((el) => {
          if (skip.includes(el.tagName) || el.closest('button')) return false;
          const bg = parse(getComputedStyle(el).backgroundColor);
          if (bg[3] < 0.99 || lum(bg) < 0.6) return false;
          const own = [...el.childNodes]
            .filter((n) => n.nodeType === 3)
            .map((n) => n.textContent.trim())
            .join('');
          return own.length > 1;
        })
        .map((el) => `${el.tagName}.${String(el.className).slice(0, 40)}`);
    },
    { sel: selector, src: colourProbe.toString() }
  );
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });

  const problems = [];
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
  page.on('response', (r) => {
    if (r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.url()}`);
  });

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', 'glpi');
  await page.fill('input[type=password]', 'glpi');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  const rows = await pluginRows(page);
  const configured = new Set(rows.map((r) => r.href));

  console.log('\nPlugins page');
  check('every installed plugin offers a Configure link', rows.length >= 7, `${rows.length} rows`);
  for (const row of rows) {
    console.log(`      ${row.name} → ${row.href}`);
  }

  const menu = await sidebar(page);

  console.log('\nNo sidebar entry duplicates a Configure link');
  const duplicates = [];
  for (const [sector, entries] of Object.entries(menu)) {
    for (const entry of entries) {
      if (configured.has(entry.href)) {
        duplicates.push(`${sector} → ${entry.text} (${entry.href})`);
      }
    }
  }
  check('the sidebar has no second link to a plugin settings page',
    duplicates.length === 0, duplicates.join('; '));

  console.log('\nSetup stays short');
  const setup = menu['Setup'] || [];
  const pluginEntries = setup.filter((e) => e.href.startsWith('/plugins/'));
  console.log('      ' + (pluginEntries.map((e) => e.text).join(', ') || '(none)'));
  check('Setup carries only plugin entries that are not settings pages',
    pluginEntries.every((e) => !configured.has(e.href)));

  console.log('\nNothing operational was lost');
  // Deliberately not "…is still under Administration". Which section an entry
  // sits in is glpi-nav's business and is asserted section by section further
  // down; what this half is for is the older rule — that pruning the sidebar of
  // second links to settings pages never took an operational page with it.
  // Naming a sector here would also make this block fail whenever glpi-nav is
  // switched off, which is a supported state.
  const whereIs = (fragment) =>
    Object.entries(menu).find(([, entries]) => entries.some((e) => e.href.includes(fragment)))?.[0];

  for (const [label, fragment] of [
    ['the SOPs', 'glpisop/front/sop.php'],
    ['the osquery agents', 'glpiosquery/front/agent.php'],
    ['osquery compliance', 'glpiosquery/front/compliance.php'],
    ['the osquery live query', 'glpiosquery/front/console.php'],
    ['the scan targets', 'glpinetscan/front/target.php'],
    ['the scanners', 'glpinetscan/front/scanner.php'],
    ['the OID profiles', 'glpinetscan/front/oidprofile.php'],
    ['the identity sources', 'glpiidentity/front/source.php'],
    ['the MCP servers', 'glpiai/front/mcp/server.php'],
  ]) {
    const where = whereIs(fragment);
    check(`the sidebar still reaches ${label}`, !!where, where || 'nowhere');
  }

  console.log('\nEvery settings page still opens');
  for (const row of rows) {
    await page.goto(BASE + row.href, { waitUntil: 'networkidle' });
    check(`${row.name} renders`, await rendered(page), row.href);
  }

  // Every plugin page still in the sidebar, opened rather than merely counted.
  // Repointing a breadcrumb at a menu entry that no longer exists is the
  // failure this change could plausibly cause, and it does not show up until
  // the page is actually rendered.
  console.log('\nEvery plugin page left in the sidebar still opens');
  const sidebarPages = new Map();
  for (const entries of Object.values(menu)) {
    for (const entry of entries) {
      if (entry.href.startsWith('/plugins/')) sidebarPages.set(entry.href, entry.text);
    }
  }
  for (const [href, text] of sidebarPages) {
    await page.goto(BASE + href, { waitUntil: 'networkidle' });
    check(`${text} renders`, await rendered(page), href);
  }

  console.log('\nPages reached from a settings page, not from the menu');
  for (const [from, link, label] of [
    ['/plugins/glpiai/front/config.php', 'mcp/server.php', 'the AI settings page links the MCP servers'],
    ['/plugins/glpisop/front/config.php', 'overview.php', 'the SOP settings page links the adoption report'],
  ]) {
    await page.goto(BASE + from, { waitUntil: 'networkidle' });
    const target = page.locator(`a[href*="${link}"]`).first();
    check(label, (await target.count()) > 0);
    if (await target.count()) {
      await target.click();
      await page.waitForLoadState('networkidle');
      check('  …and it opens', await rendered(page), page.url());
    }
  }

  // ======================================================================
  //  glpi-nav — the suite's own sections
  // ======================================================================

  const SECTIONS = ['Operations', 'Security', 'Service management', 'Reporting'];
  const EXPECTED = {
    // The last five Operations entries and the last Security one come from the
    // pre-existing plugins, which adopted Nav::sector() on 2026-08-22.
    Operations: ['Alerts', 'Triage queue', 'Backup board', 'Alert sources', 'On-call rotas',
      'Backup jobs', 'Maintenance windows', 'Major incidents', 'Maintenance announcements',
      'osquery live query', 'osquery agents', 'Scan targets', 'Scanners', 'OID profiles'],
    Security: ['Vulnerability exposure', 'osquery compliance'],
    'Service management': ['Change calendar', 'Releases', 'Risk questionnaires', 'Change freezes',
      'Standard changes', 'Known errors', 'SOPs', 'Improvements', 'Business services',
      'Asset provenance', 'Identity conflicts', 'Configuration drift'],
    Reporting: ['Report definitions', 'Generated reports'],
  };

  // What deliberately did *not* move, and where it stayed. glpi-ai's MCP
  // servers and Assistant skills are platform configuration; glpi-identity's
  // sources belong beside core's own LDAP directories, which are the thing they
  // are an alternative to. Asserted rather than assumed, so a later default
  // that quietly drags them into a suite section is caught.
  const STAYED = {
    Setup: ['MCP servers', 'Assistant skills'],
    Administration: ['Identity sources'],
  };

  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  const nav = await sidebar(page);

  console.log('\n=== glpi-nav ===');
  console.log('\nThe suite sections render, with their entries');
  for (const name of SECTIONS) {
    const entries = (nav[name] || []).map((entry) => entry.text);
    check(`${name} is a top-level section`, entries.length > 0, `${entries.length} entries`);
    for (const wanted of EXPECTED[name]) {
      check(`  ${name} lists ${wanted}`, entries.includes(wanted));
    }
  }

  console.log('\nWhat was left where its own plugin filed it');
  for (const [sector, entries] of Object.entries(STAYED)) {
    const there = (nav[sector] || []).map((entry) => entry.text);
    for (const wanted of entries) {
      check(`  ${sector} still lists ${wanted}`, there.includes(wanted));
    }
  }

  // The sections are meant to sit with the day-to-day work, not after Setup.
  const order = Object.keys(nav);
  check('the sections follow Assistance',
    order.indexOf('Operations') === order.indexOf('Assistance') + 1,
    order.join(' > '));

  console.log('\nMoved entries left their old sectors');
  // The failure this guards is a *duplicate*, not an absence: a transform that
  // copies instead of moving looks completely right on the page it was tested
  // on and puts every suite entry in the sidebar twice.
  const seenHref = new Map();
  const duped = [];
  for (const [sector, entries] of Object.entries(nav)) {
    for (const entry of entries) {
      if (seenHref.has(entry.href)) duped.push(`${entry.text}: ${seenHref.get(entry.href)} + ${sector}`);
      seenHref.set(entry.href, sector);
    }
  }
  check('no page is listed under two sectors', duped.length === 0, duped.join('; '));

  for (const [sector, gone] of [
    ['Administration', ['Alerts', 'Alert sources', 'On-call rotas', 'Asset provenance',
      'osquery agents', 'osquery compliance', 'Scan targets', 'Scanners', 'OID profiles']],
    ['Tools', ['Improvements', 'Releases', 'Report definitions', 'Vulnerability exposure',
      'osquery live query']],
    ['Setup', ['Known errors', 'Change freezes', 'Major incidents', 'SOPs']],
    ['Management', ['Business services']],
  ]) {
    const left = (nav[sector] || []).map((entry) => entry.text);
    check(`${sector} no longer carries ${gone.join(', ')}`,
      gone.every((title) => !left.includes(title)),
      left.join(', '));
  }

  console.log('\nNo two entries in one section share a name');
  // GLPI marks the active entry by comparing titles, so a duplicate name inside
  // a section lights both entries up on either page. glpi-signal's suppression
  // windows and glpi-major's entity announcements were both "Maintenance
  // windows" until glpi-nav relabelled one of them.
  for (const name of SECTIONS) {
    const titles = (nav[name] || []).map((entry) => entry.text);
    check(`${name} has no duplicate entry name`,
      new Set(titles).size === titles.length, titles.join(', '));
  }

  console.log('\nBreadcrumb, Add button and highlight followed the move');
  // These four are the memory-documented failure mode: a list page whose entry
  // moved keeps rendering, and quietly cannot be added to.
  for (const [label, href, section, entry, wantAdd] of [
    ['signal sources', '/plugins/glpisignal/front/source.php', 'Operations', 'Alert sources', true],
    ['kedb known errors', '/plugins/glpikedb/front/knownerror.php', 'Service management', 'Known errors', true],
    ['improve register', '/plugins/glpiimprove/front/improvement.php', 'Service management', 'Improvements', true],
    ['change releases', '/plugins/glpichange/front/release.php', 'Service management', 'Releases', true],
    ['change freezes', '/plugins/glpichange/front/freeze.php', 'Service management', 'Change freezes', true],
    ['major incidents', '/plugins/glpimajor/front/incident.php', 'Operations', 'Major incidents', true],
    ['vuln exceptions', '/plugins/glpivuln/front/exemption.php', 'Security', 'Vulnerability exposure', true],
    ['report definitions', '/plugins/glpireport/front/reportdefinition.php', 'Reporting', 'Report definitions', true],
    ['service business services', '/plugins/glpiservice/front/businessservice.php', 'Service management', 'Business services', true],
    ['signal alerts', '/plugins/glpisignal/front/alert.php', 'Operations', 'Alerts', false],
    ['service census', '/plugins/glpiservice/front/census.php', 'Service management', 'Asset provenance', false],
    ['change success rate', '/plugins/glpichange/front/stats.php', 'Service management', 'Change calendar', false],

    // The pre-existing plugins, adopted 2026-08-22. The saved-query list is the
    // one that has actually broken before: it is registered as the `savedquery`
    // *option* under the console entry, not as an entry of its own, so its Add
    // button is only found when the page passes sector, item AND option — and
    // the sector it passes now has to be the one the console was moved into.
    ['osquery console', '/plugins/glpiosquery/front/console.php', 'Operations', 'osquery live query', false],
    ['osquery saved queries', '/plugins/glpiosquery/front/savedquery.php', 'Operations', 'osquery live query', true],
    ['osquery agents', '/plugins/glpiosquery/front/agent.php', 'Operations', 'osquery agents', false],
    ['osquery compliance', '/plugins/glpiosquery/front/compliance.php', 'Security', 'osquery compliance', false],
    ['netscan targets', '/plugins/glpinetscan/front/target.php', 'Operations', 'Scan targets', true],
    ['netscan scanners', '/plugins/glpinetscan/front/scanner.php', 'Operations', 'Scanners', false],
    ['netscan OID profiles', '/plugins/glpinetscan/front/oidprofile.php', 'Operations', 'OID profiles', true],
    ['sop library', '/plugins/glpisop/front/sop.php', 'Service management', 'SOPs', true],

    // Left alone on purpose — same three signals, still resolving against the
    // core sector their pages name.
    ['ai MCP servers', '/plugins/glpiai/front/mcp/server.php', 'Setup', 'MCP servers', true],
    ['ai assistant skills', '/plugins/glpiai/front/assistant/skill.php', 'Setup', 'Assistant skills', true],
    ['identity sources', '/plugins/glpiidentity/front/source.php', 'Administration', 'Identity sources', true],
  ]) {
    await page.goto(BASE + href, { waitUntil: 'networkidle' });
    const ctx = await context(page);
    check(`${label}: breadcrumb names ${section}`, ctx.crumbs[1] === section, ctx.crumbs.join(' > '));
    check(`${label}: breadcrumb keeps the entry`, ctx.crumbs.length >= 3, ctx.crumbs.join(' > '));
    check(`${label}: Add button ${wantAdd ? 'present' : 'correctly absent'}`, ctx.add === wantAdd);
    check(`${label}: ${section} is the highlighted section`,
      ctx.activeSector.join(',') === section, ctx.activeSector.join(',') || '(none)');
    check(`${label}: ${entry} is the highlighted entry`,
      ctx.activeItem.join(',') === entry, ctx.activeItem.join(',') || '(none)');
  }

  console.log('\nForm pages follow their list into the new section');
  // A form page has no Add button and, where its item is not itself a menu
  // entry, no entry crumb either — but it still names a sector, and leaving
  // that literal behind is how a breadcrumb ends up claiming a section the
  // page's list no longer lives in.
  for (const [label, href, section] of [
    ['new saved query', '/plugins/glpiosquery/front/savedquery.form.php', 'Operations'],
    ['new scan target', '/plugins/glpinetscan/front/target.form.php', 'Operations'],
    ['new OID profile', '/plugins/glpinetscan/front/oidprofile.form.php', 'Operations'],
    ['new SOP', '/plugins/glpisop/front/sop.form.php', 'Service management'],
  ]) {
    await page.goto(BASE + href, { waitUntil: 'networkidle' });
    const ctx = await context(page);
    check(`${label}: breadcrumb names ${section}`, ctx.crumbs[1] === section, ctx.crumbs.join(' > '));
  }

  console.log("\nThe menu finder and the palette see the new arrangement");
  // Html::getMenuFuzzySearchList() reads $_SESSION['glpimenu'] and never passes
  // through the redefine_menus hook, so this only works because glpi-nav writes
  // its result back into the session copy.
  const fuzzy = await page.evaluate(async (base) => {
    const r = await fetch(`${base}/ajax/fuzzysearch.php`, { credentials: 'same-origin' });
    return r.json();
  }, BASE);
  const fuzzyTitles = fuzzy.map((entry) => entry.title);
  for (const wanted of ['Operations > Alerts', 'Security > Vulnerability exposure',
    'Service management > Known errors', 'Reporting > Report definitions',
    'Operations > Scan targets', 'Operations > osquery agents',
    'Security > osquery compliance', 'Service management > SOPs']) {
    check(`Ctrl+Alt+G finds "${wanted}"`, fuzzyTitles.includes(wanted));
  }
  check('no moved entry is still advertised under its old sector',
    !fuzzyTitles.some((t) => ['Administration > Alerts', 'Setup > Known errors',
      'Administration > Scan targets', 'Administration > osquery agents',
      'Setup > SOPs', 'Tools > osquery live query'].includes(t)),
    fuzzyTitles.filter((t) => /Alerts|Known errors|Scan targets|osquery|SOPs/.test(t)).join(' | '));
  check('and the entries that stayed are still advertised where they are',
    ['Setup > MCP servers', 'Administration > Identity sources'].every((t) => fuzzyTitles.includes(t)),
    fuzzyTitles.filter((t) => /MCP servers|Identity sources/.test(t)).join(' | '));

  await page.keyboard.press('Control+k');
  await page.waitForTimeout(900);
  if (await page.locator('.glpipalette-input').count()) {
    for (const [typed, wanted] of [
      ['Known errors', 'Service management > Known errors'],
      ['Scan targets', 'Operations > Scan targets'],
    ]) {
      await page.fill('.glpipalette-input', '');
      await page.type('.glpipalette-input', typed, { delay: 15 });
      await page.waitForTimeout(1400);
      const rows = await page.evaluate(() =>
        [...document.querySelectorAll('.glpipalette-title')].map((el) => el.textContent.trim()));
      check(`the command palette offers "${wanted}"`,
        rows.some((r) => r === wanted),
        rows.slice(0, 6).join(' | '));
    }
    await page.keyboard.press('Escape');
  } else {
    console.log('      (glpi-palette not active — skipped)');
  }

  // -------------------------------------------------------------- screenshots
  await page.goto(`${BASE}/plugins/glpisignal/front/alert.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOTS}/nav-01-sidebar.png`, clip: { x: 0, y: 0, width: 470, height: 1080 } });

  await page.goto(`${BASE}/plugins/glpinav/front/config.php`, { waitUntil: 'networkidle' });
  check('the glpi-nav settings page opens', await rendered(page));
  await page.screenshot({ path: `${SHOTS}/nav-03-settings.png`, fullPage: true });

  console.log('\nSaving reorganises the sidebar without a relog');
  // Html::generateMenuSession() only rebuilds when $_SESSION['glpimenu'] is
  // missing, so a settings page that forgets to drop it appears to do nothing
  // until the technician logs out and back in.
  const renameTo = 'Reporting and metrics';
  await page.fill(".glpinav-config input[name='section_title[3]']", renameTo);
  await page.click(".glpinav-config form button[type=submit].btn-primary");
  await page.waitForLoadState('networkidle');
  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  const renamed = await sidebar(page);
  check('the renamed section appears in the same session', !!renamed[renameTo], Object.keys(renamed).join(' > '));
  check('the old name is gone', !renamed['Reporting']);
  check('its entries came with it',
    (renamed[renameTo] || []).some((entry) => entry.text === 'Report definitions'));

  await page.goto(`${BASE}/plugins/glpinav/front/config.php`, { waitUntil: 'networkidle' });
  await page.click(".glpinav-config form button[type=submit].btn-outline-secondary");
  await page.waitForLoadState('networkidle');
  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  const restored = await sidebar(page);
  check('Reset to defaults puts the shipped arrangement back',
    !!restored['Reporting'] && !restored[renameTo], Object.keys(restored).join(' > '));

  console.log('\nSwitched off, every adopted page goes back to stock');
  // The pre-existing plugins have to keep working on an instance that never
  // installed glpi-nav. The literal in each patched Html::header() is the
  // sector the page named before, and the master switch is the same code path a
  // missing plugin takes — Nav::sector() returns its second argument before it
  // looks at anything else. So this measures the "no glpi-nav" state rather
  // than assuming the fallback compiles.
  await page.goto(`${BASE}/plugins/glpinav/front/config.php`, { waitUntil: 'networkidle' });
  await page.uncheck(".glpinav-config input[name='enabled']");
  await page.click(".glpinav-config form button[type=submit].btn-primary");
  await page.waitForLoadState('networkidle');

  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  const off = await sidebar(page);
  check('no suite section is rendered', SECTIONS.every((s) => !off[s]), Object.keys(off).join(' > '));

  for (const [label, href, sector, entry, wantAdd] of [
    ['osquery console', '/plugins/glpiosquery/front/console.php', 'Tools', 'osquery live query', false],
    ['osquery saved queries', '/plugins/glpiosquery/front/savedquery.php', 'Tools', 'osquery live query', true],
    ['osquery agents', '/plugins/glpiosquery/front/agent.php', 'Administration', 'osquery agents', false],
    ['osquery compliance', '/plugins/glpiosquery/front/compliance.php', 'Administration', 'osquery compliance', false],
    ['netscan targets', '/plugins/glpinetscan/front/target.php', 'Administration', 'Scan targets', true],
    ['netscan scanners', '/plugins/glpinetscan/front/scanner.php', 'Administration', 'Scanners', false],
    ['netscan OID profiles', '/plugins/glpinetscan/front/oidprofile.php', 'Administration', 'OID profiles', true],
    ['sop library', '/plugins/glpisop/front/sop.php', 'Setup', 'SOPs', true],
  ]) {
    await page.goto(BASE + href, { waitUntil: 'networkidle' });
    const ctx = await context(page);
    check(`off · ${label}: back under ${sector}`, ctx.crumbs[1] === sector, ctx.crumbs.join(' > '));
    check(`off · ${label}: Add button ${wantAdd ? 'present' : 'correctly absent'}`, ctx.add === wantAdd);
    check(`off · ${label}: ${entry} is the highlighted entry`,
      ctx.activeItem.join(',') === entry, ctx.activeItem.join(',') || '(none)');
  }

  await page.goto(`${BASE}/plugins/glpinav/front/config.php`, { waitUntil: 'networkidle' });
  await page.click(".glpinav-config form button[type=submit].btn-outline-secondary");
  await page.waitForLoadState('networkidle');
  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  const backOn = await sidebar(page);
  check('switching it back on restores the sections',
    SECTIONS.every((s) => !!backOn[s]), Object.keys(backOn).join(' > '));

  console.log('\nA section nobody may see is not rendered');
  // The rule is that this plugin only ever moves entries core already put on
  // the menu, so a section is exactly as visible as its contents. nav-probe has
  // one suite right and nothing else.
  await login(page, 'nav-probe', 'nav-probe-pw');
  const probe = await sidebar(page);
  check('the one section the probe can see is rendered', !!probe['Service management'],
    Object.keys(probe).join(' > '));
  check('and holds only what the probe may see',
    (probe['Service management'] || []).map((entry) => entry.text).join(',') === 'Known errors',
    (probe['Service management'] || []).map((entry) => entry.text).join(','));
  for (const empty of ['Operations', 'Security', 'Reporting']) {
    check(`${empty} is not rendered at all`, !probe[empty]);
  }

  console.log('\nDark theme');
  await login(page, 'nav-dark', 'nav-dark-pw');
  await page.goto(`${BASE}/plugins/glpisignal/front/alert.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  const darkBody = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  check('the dark user is actually on a dark palette', !/255, ?255, ?255/.test(darkBody), darkBody);
  await page.screenshot({ path: `${SHOTS}/nav-02-sidebar-dark.png`, clip: { x: 0, y: 0, width: 470, height: 1080 } });

  await page.goto(`${BASE}/plugins/glpinav/front/config.php`, { waitUntil: 'networkidle' });
  const darkMuted = await subTextContrast(page, '.glpinav-config');
  const worst = (darkMuted || []).reduce((a, b) => (a && a.ratio < b.ratio ? a : b), null);
  check('every sub-text on the settings page clears 4.5:1 in dark',
    (darkMuted || []).every((m) => m.ratio >= 4.5),
    worst ? `worst ${worst.ratio}:1 — "${worst.text}"` : 'nothing measured');
  const pale = await washedOut(page, '.glpinav-config');
  check('nothing paints a near-white panel on the dark page',
    (pale || []).length === 0, (pale || []).join(' | '));

  await login(page, 'glpi', 'glpi');
  await page.goto(`${BASE}/plugins/glpinav/front/config.php`, { waitUntil: 'networkidle' });
  const lightMuted = await subTextContrast(page, '.glpinav-config');
  const lightWorst = (lightMuted || []).reduce((a, b) => (a && a.ratio < b.ratio ? a : b), null);
  check('and still clears it in light',
    (lightMuted || []).every((m) => m.ratio >= 4.5),
    lightWorst ? `worst ${lightWorst.ratio}:1 — "${lightWorst.text}"` : 'nothing measured');

  // glpi-change's release list throws inside core's search engine
  // (SQLProvider::giveItem, "Class name must be a valid object or a string").
  // It is separated out rather than folded into the line below because it has
  // nothing to do with navigation — it reproduces with glpinav deactivated —
  // and burying it there would make every future run's failure ambiguous.
  const changeRelease = problems.filter((p) => p.includes('glpichange/front/release.php'));
  const other = problems.filter((p) => !p.includes('glpichange/front/release.php'));

  check("glpi-change's release list renders without a server error", changeRelease.length === 0,
    'pre-existing, in Search::show() — reproduces with glpinav deactivated');
  check('no other server errors or JavaScript errors anywhere', other.length === 0, other.join(' | '));

  await browser.close();

  console.log(`\n${fail.length ? `FAILED: ${fail.join(', ')}` : 'all checks passed'}`);
  process.exit(fail.length ? 1 : 0);
})().catch((e) => {
  console.error('ERROR', e);
  process.exit(1);
});
