<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Invariants of the menu transform, asserted against the live menu.
 *
 * Read-only: it logs in, builds the menu core would build, runs the transform
 * over it and checks the properties that must hold. It writes no config and no
 * rows, so it is safe to run on an instance somebody is using — the thing it
 * would break is the thing it is checking.
 *
 * The invariants are the ones that, if they ever stop holding, produce a
 * failure nobody notices: an entry silently lost, an entry rendered twice, a
 * transform that is not reversible (which is what makes a settings save take
 * effect without a relog), or two entries in one section sharing a name (which
 * makes GLPI highlight both).
 *
 *   docker exec -i glpi-glpi-1 php < tests/transform.php
 */

use GlpiPlugin\Glpinav\Layout;
use GlpiPlugin\Glpinav\Nav;
use GlpiPlugin\Glpinav\Registry;
use GlpiPlugin\Glpinav\Settings;

require '/var/www/glpi/vendor/autoload.php';

if (!defined('TU_USER')) {
    define('TU_USER', 'glpinav-tests');
}

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

if (!(new Auth())->login('glpi', 'glpi', true)) {
    fwrite(STDERR, "could not log in as glpi/glpi\n");
    exit(1);
}

(new Plugin())->init(true);

if (!Plugin::isPluginActive('glpinav')) {
    fwrite(STDERR, "the glpinav plugin is not active on this instance\n");
    exit(1);
}

$failures = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? ' :: ' . $detail : '') . "\n";
    if (!$cond) {
        $failures++;
    }
}

/** @return array<string,string> page => "sector/key" */
function entries(array $menu): array
{
    $out = [];
    foreach ($menu as $sector => $data) {
        foreach (($data['content'] ?? []) as $key => $entry) {
            if (is_array($entry) && isset($entry['page'])) {
                $out[$sector . '|' . $key] = (string) $entry['page'];
            }
        }
    }
    return $out;
}

$stock    = Layout::stock();
$rendered = Layout::rendered();

// -------------------------------------------------------- nothing is lost
$before = array_count_values(array_values(entries($stock)));
$after  = array_count_values(array_values(entries($rendered)));
ksort($before);
ksort($after);

$lost = array_diff_key($before, $after);
ok('every page on the stock menu is still on the transformed menu',
    $lost === [], implode(', ', array_keys($lost)));

$gained = array_diff_key($after, $before);
ok('the transform invented nothing', $gained === [], implode(', ', array_keys($gained)));

$twice = array_keys(array_filter($after, static fn(int $n): bool => $n > 1));
ok('no page appears under two sectors', $twice === [], implode(', ', $twice));

// ------------------------------------------------------------- reversible
// The transform is handed its own output on the next request, because the
// result is cached in $_SESSION['glpimenu']. If it is not exactly reversible,
// entries drift a sector further from home on every page load.
$roundtrip = Layout::stock($rendered);
ok('unfolding the transformed menu reproduces the stock menu',
    entries($roundtrip) == entries($stock),
    count(array_diff_assoc(entries($roundtrip), entries($stock))) . ' differences');

// ------------------------------------------------------------- idempotent
$twiceFolded = Layout::rendered();
ok('folding twice changes nothing', entries($twiceFolded) == entries($rendered));

// -------------------------------------------------- no empty section is shown
$empty = [];
foreach (Settings::sections() as $section) {
    if (!isset($rendered[$section['key']])) {
        continue;
    }
    if (($rendered[$section['key']]['content'] ?? []) === []) {
        $empty[] = $section['key'];
    }
}
ok('no rendered section is empty', $empty === [], implode(', ', $empty));

// ---------------------------------------------------- no name collides in one
// GLPI marks the active entry by comparing titles, so two entries in a section
// sharing a name light both up on either page.
$clashes = [];
foreach (Settings::sections() as $section) {
    $seen = [];
    foreach (($rendered[$section['key']]['content'] ?? []) as $entry) {
        $title = (string) ($entry['title'] ?? '');
        if ($title === '') {
            continue;
        }
        if (isset($seen[$title])) {
            $clashes[] = $section['key'] . '/' . $title;
        }
        $seen[$title] = true;
    }
}
ok('no two entries in one section share a name', $clashes === [], implode(', ', $clashes));

// ------------------------------------- a section always has a reachable default
$bad = [];
foreach (Settings::sections() as $section) {
    if (!isset($rendered[$section['key']])) {
        continue;
    }
    $default = $rendered[$section['key']]['default'] ?? '';
    if ($default === '' || !in_array($default, entries($rendered), true)) {
        $bad[] = $section['key'];
    }
}
ok('every rendered section links somewhere real', $bad === [], implode(', ', $bad));

// ------------------------------------------- Nav::sector agrees with the fold
$disagreements = [];
foreach (Settings::assignments() as $item => $target) {
    $answer = Nav::sector($item, '__stock__');
    $present = isset($rendered[$target]['content'][$item]);
    if ($present && $answer !== $target) {
        $disagreements[] = "$item: rendered under $target, told to use $answer";
    }
    if (!$present && $answer !== '__stock__') {
        $disagreements[] = "$item: not rendered, but told to use $answer";
    }
}
ok('Nav::sector() answers with the section the entry is actually in',
    $disagreements === [], implode('; ', $disagreements));

// ------------------------------------- only declared-movable entries are moved
$unexpected = [];
foreach ($rendered as $sector => $data) {
    if (($data['_glpinav_section'] ?? false) !== true) {
        continue;
    }
    foreach (array_keys($data['content'] ?? []) as $key) {
        if (!Registry::isMovable((string) $key)) {
            $unexpected[] = (string) $key;
        }
    }
}
ok('no entry outside Registry::MOVABLE was moved', $unexpected === [], implode(', ', $unexpected));

echo "\n" . ($failures ? "FAILED: $failures\n" : "all invariants hold\n");
exit($failures ? 1 : 0);
