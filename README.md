# GLPI Nav

Gives the Bijstaan suite top-level sections in GLPI's central sidebar, instead
of leaving twenty-odd entries scattered through Tools, Setup, Administration and
Management wherever each plugin found room.

## Why this exists

GLPI hands a plugin exactly one lever over the sidebar. `menu_toadd` appends an
itemtype to one of core's seven fixed sectors — assets, assistance, management,
tools, plugins, administration, setup. A plugin cannot create a sector, and it
cannot ask for one.

That is fine for one plugin. With a suite it produces this:

| Where it ended up | What was actually there |
|---|---|
| **Administration** | LDAP, profiles, entities … *and* alert sources, on-call rotas, backup jobs, the triage queue, identity conflicts, configuration drift |
| **Setup** | mail receivers, OAuth clients, automatic actions … *and* change freezes, standard changes, known errors, major incidents |
| **Tools** | reminders, RSS, saved searches … *and* the vulnerability dashboard, the change calendar, releases, report definitions |

Nothing is wrong. Everything is one click away. Nothing is findable, because the
grouping describes which plugin supplied a page rather than what a technician is
doing.

Core has no near-miss to compare against here: there is no setting, no profile
option and no hook that reorders the sidebar. The only supported way in is
`Hooks::REDEFINE_MENUS`, which hands a callback the finished menu array on every
page render. That is what this plugin is.

It is a **coordinator**, not a feature. It owns no tables, adds no pages beyond
its own settings screen, and never creates a menu entry — it only moves ones
core already built.

## What it does

Ships four sections, inserted after Assistance:

* **Operations** — alerts, triage, backup board, alert sources, on-call rotas,
  backup jobs, maintenance windows, major incidents, maintenance announcements,
  the osquery live query console and agent fleet, scan targets, scanners and
  OID profiles
* **Security** — the vulnerability exposure dashboard and its advisories, EOL,
  review queue and exceptions; osquery's fleet compliance view
* **Service management** — change calendar and success rate, releases, risk
  questionnaires, change freezes, standard changes, known errors, SOPs,
  improvements, business services, asset provenance, identity conflicts,
  configuration drift
* **Reporting** — report definitions, generated reports

All of it is configuration. Sections can be renamed, re-iconed, reordered,
switched off, and moved to a different anchor in core's order; entries can be
reassigned or left where their plugin filed them; a single entry can be given a
different label in the menu. There is a Reset to defaults button, and saving
takes effect on the next page rather than on the next login.

## The part that is not obvious

Moving a menu entry is only half a change, and the other half is invisible if
you skip it.

`Html::header($title, $url, $sector, $item, $option)` is not decoration. GLPI
resolves three things by looking those arguments up in the menu array:

* the **breadcrumb**, from `menu[sector]['title']` and
  `menu[sector]['content'][item]`;
* the **Add button**, from
  `menu[sector]['content'][item]['options'][option]['links']['add']` — see
  `templates/layout/parts/context_links.html.twig`;
* the **sidebar highlight**, both levels, by comparing *titles*.

Move an entry to a new section while its pages still name the old sector and all
three disappear. Measured on thirteen pages: every one lost its Add button, its
breadcrumb entry and its highlight, while rendering perfectly and reporting
nothing. A list page that quietly cannot be added to is exactly the kind of
failure that survives a test suite.

So the entries this plugin moves are the ones whose pages *ask* where they live:

```php
$sector = class_exists(Nav::class) ? Nav::sector(KnownError::class, 'config') : 'config';
Html::header(KnownError::getTypeName(2), $_SERVER['PHP_SELF'], $sector, KnownError::class);
```

The `class_exists()` guard and the second argument are why that line is safe in
a plugin someone installs without this one: no glpinav, or glpinav switched off,
or that entry left in place, and the page gets its original sector back.

The same title-matching quirk has a second edge. Two entries with the same name
in one section both light up, on either page. glpi-signal's alert-suppression
windows and glpi-major's customer announcements were both called "Maintenance
windows" — harmless while they sat in different sectors, confusing the moment
they are neighbours. Hence the per-entry label override, and hence the settings
page warning when two names collide.

## Install

```
docker exec glpi-glpi-1 php bin/console glpi:plugin:install --username=glpi glpinav
docker exec glpi-glpi-1 php bin/console glpi:plugin:activate glpinav
```

Uninstall deletes five config rows and the right it registered. There is nothing
else: the stock layout comes back because the transform simply stops running,
and every patched page falls back to the sector it always named.

## Settings

Setup > Plugins > GLPI Nav. Needs `plugin_glpinav_config`, granted at install to
every profile already holding core `config` UPDATE.

| Setting | Default | What it does |
|---|---|---|
| Reorganise the central sidebar | on | Master switch. Off leaves GLPI's own layout completely untouched. |
| Put the suite sections after | Assistance | Which core sector the sections follow. Setup stays last regardless — core pins it. |
| Sections | four, as above | Order, visible/hidden, name, Tabler icon. Keys are fixed: 51 pages name them. |
| Entries | as above | Per entry: which section, or leave in place; and the name to show in the menu. |

## Limits

* **Central interface only.** The helpdesk portal — including glpi-major's
  Service status link — is untouched by design.
* **Not every entry can be moved.** glpi-ai's MCP servers and Assistant skills
  and glpi-identity's sources name their sector directly in their pages, so
  moving them would cost them their breadcrumb and their Add button. They are
  listed as **Fixed** on the settings page with that reason, rather than offered
  as a move that quietly breaks them — and in their case that is also the right
  answer: they are platform configuration, and Identity sources belongs beside
  core's own LDAP directories, which are the thing it is an alternative to. If a
  plugin adopts the one-line `Nav::sector()` call, adding its keys to
  `Registry::MOVABLE` is the whole change.
* **It cannot show anyone anything.** Entries are moved, never created, so a
  section is exactly as visible as its contents — and a section whose contents
  this profile cannot see is not rendered at all, rather than appearing empty.
  It is not a substitute for a profile.
* **Section membership is per instance, not per profile.** Giving different
  profiles different arrangements would be a visibility rule, and this plugin
  deliberately has none.
* `Html::getMenuSectorForItemtype()` still answers with the stock sector; it
  reads the `types` list, which the transform leaves alone. Core uses it only
  for item templates and manual links, neither of which applies to a suite
  itemtype.

## Screenshots

* `docs/screenshots/nav-01-sidebar.png` — the reorganised sidebar
* `docs/screenshots/nav-02-sidebar-dark.png` — the same on the dark palette
* `docs/screenshots/nav-03-settings.png` — the settings page

## Licence

GNU General Public License, version 3 or later — the same licence as GLPI.
This plugin is loaded into GLPI's process and extends its classes, so it is a
derivative work of GLPI and carries GLPI's licence. See [LICENSE](LICENSE).
