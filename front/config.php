<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpinav\Layout;
use GlpiPlugin\Glpinav\Registry;
use GlpiPlugin\Glpinav\Settings;

Session::checkRight('plugin_glpinav_config', READ);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/*
 * Two forms on this page — the arrangement, and "restore the defaults" — so
 * each POST says which one it is. Rebuilding the whole configuration from a
 * POST that only carried the reset button would wipe the section titles and
 * every assignment, silently, and look like it had worked.
 */
$posted = null;
if (!empty($_POST['glpinav_form'])) {
    $posted = (string) $_POST['glpinav_form'];
}

if ($posted !== null) {
    // No explicit Session::checkCSRF: GLPI 11's CheckCsrfListener validated and
    // consumed the token before this script ran, so a second check always fails.
    Session::checkRight('plugin_glpinav_config', UPDATE);

    if ($posted === 'reset') {
        Settings::reset();
        Session::addMessageAfterRedirect(__s('Navigation restored to the shipped defaults.', 'glpinav'));
        Html::back();
    }

    if ($posted === 'layout') {
        $sections = [];
        foreach ((array) ($_POST['section_key'] ?? []) as $idx => $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $sections[] = [
                'key'     => $key,
                'title'   => (string) ($_POST['section_title'][$idx] ?? $key),
                'icon'    => (string) ($_POST['section_icon'][$idx] ?? ''),
                'enabled' => !empty($_POST['section_enabled'][$idx]) ? 1 : 0,
                'order'   => (int) ($_POST['section_order'][$idx] ?? 0),
            ];
        }

        // Order is a number the operator types rather than a drag handle: it
        // survives a page that lists a dozen rows without any JavaScript, and
        // two rows sharing a number keep the order they were listed in.
        usort($sections, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        foreach ($sections as &$section) {
            unset($section['order']);
        }
        unset($section);

        /*
         * Item keys travel in a parallel hidden field rather than as the select's
         * own name. They contain backslashes (`glpiplugin\glpimajor\incident` —
         * core stores an entry under `strtolower($itemtype)`), and a name that
         * has to survive PHP's POST-key mangling intact is a bug waiting to be
         * found by whichever itemtype is unlucky.
         */
        $assignments = [];
        $labels      = [];
        foreach ((array) ($_POST['item_key'] ?? []) as $idx => $item) {
            $item = mb_strtolower(trim((string) $item));
            if ($item === '') {
                continue;
            }
            $label = trim((string) ($_POST['item_label'][$idx] ?? ''));
            if ($label !== '') {
                $labels[$item] = $label;
            }
            $target = (string) ($_POST['item_section'][$idx] ?? '');
            if ($target === '') {
                continue;
            }
            $assignments[$item] = $target;
        }

        Settings::save(
            !empty($_POST['enabled']) ? 1 : 0,
            (string) ($_POST['insert_after'] ?? 'helpdesk'),
            $sections,
            $assignments,
            $labels
        );

        Session::addMessageAfterRedirect(__s('Navigation saved.', 'glpinav'));
        Html::back();
    }

    Session::addMessageAfterRedirect(__s('Nothing recognised in that submission.', 'glpinav'), false, ERROR);
    Html::back();
}

Html::header(__('Navigation', 'glpinav'), $_SERVER['PHP_SELF'], 'config', 'plugins');

$can_edit    = Session::haveRight('plugin_glpinav_config', UPDATE);
$enabled     = Settings::isEnabled();
$sections    = Settings::sections();
$assignments = Settings::assignments();
$labels      = Settings::labels();
$items       = Registry::discover();
$anchors     = Registry::coreSectors();
$rendered    = Layout::rendered();

echo "<div class='container-fluid glpinav-config' style='max-width:960px'>";

if (!$can_edit) {
    echo "<div class='alert alert-info'>" . __s('Read only: you may look at this arrangement but not change it.', 'glpinav') . "</div>";
}

// ------------------------------------------------------------------- status

$live = [];
foreach ($sections as $section) {
    if (isset($rendered[$section['key']])) {
        $live[$section['key']] = count($rendered[$section['key']]['content'] ?? []);
    }
}
$movable_count = 0;
foreach ($items as $item) {
    if ($item['movable']) {
        $movable_count++;
    }
}

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Status', 'glpinav') . "</h3></div><div class='card-body'>";

if (!$enabled) {
    echo "<p class='mb-2'><span class='badge bg-secondary-lt'>" . __s('Off', 'glpinav') . "</span> "
       . __s('The sidebar is exactly as GLPI builds it. Nothing below has any effect until this is switched on.', 'glpinav')
       . "</p>";
} elseif ($live === []) {
    echo "<p class='mb-2'><span class='badge bg-warning-lt'>" . __s('Nothing to show', 'glpinav') . "</span> "
       . __s('No section has an entry your profile can see, so none is rendered. A section is never shown empty.', 'glpinav')
       . "</p>";
} else {
    echo "<p class='mb-2'><span class='badge bg-success-lt'>" . __s('Active', 'glpinav') . "</span> "
       . sprintf(
           __s('%1$d sections in your sidebar, holding %2$d entries.', 'glpinav'),
           count($live),
           array_sum($live)
       ) . "</p>";
    echo "<ul class='mb-2'>";
    foreach ($sections as $section) {
        if (!isset($live[$section['key']])) {
            continue;
        }
        echo "<li><i class='" . $e($section['icon']) . " me-1'></i>" . $e($section['title'])
           . " <span class='text-muted'>" . sprintf(__s('%d entries', 'glpinav'), $live[$section['key']]) . "</span></li>";
    }
    echo "</ul>";
}

/*
 * Two entries with one name in a section is not a cosmetic problem. GLPI marks
 * the active sidebar entry by comparing the current page's menu title against
 * each entry's title, so a duplicate lights both up on either page. It only
 * ever happens because two plugins independently chose the same word and were
 * filed apart — which is precisely what this plugin undoes.
 */
$clashes = [];
foreach ($sections as $section) {
    $seen = [];
    foreach (($rendered[$section['key']]['content'] ?? []) as $entry) {
        $title = (string) ($entry['title'] ?? '');
        if ($title === '') {
            continue;
        }
        if (isset($seen[$title])) {
            $clashes[] = $section['title'] . ' · ' . $title;
        }
        $seen[$title] = true;
    }
}
if ($clashes !== []) {
    echo "<div class='alert alert-warning mb-2'>"
       . __s('Two entries in the same section share a name. GLPI highlights the active entry by matching its title, so both will light up on either page — give one of them a different name below.', 'glpinav')
       . "<ul class='mb-0 mt-1'>";
    foreach (array_unique($clashes) as $clash) {
        echo "<li>" . $e($clash) . "</li>";
    }
    echo "</ul></div>";
}

echo "<p class='form-text mb-0'>"
   . sprintf(
       __s('%1$d of the %2$d plugin entries on your menu can be moved. The rest name their sector in their own pages, so moving them would cost them their breadcrumb and their Add button — they stay where their plugin filed them.', 'glpinav'),
       $movable_count,
       count($items)
   )
   . "</p>";

echo "</div></div>";

echo "<form method='post'>";
echo Html::hidden('glpinav_form', ['value' => 'layout']);
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// ------------------------------------------------------------------ general

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('General', 'glpinav') . "</h3></div><div class='card-body'>";

echo "<label class='form-check form-switch mb-3'>";
echo "<input type='checkbox' class='form-check-input' name='enabled' value='1' "
   . ($enabled ? "checked" : "") . ($can_edit ? "" : " disabled") . ">";
echo "<span class='form-check-label'>" . __s('Reorganise the central sidebar', 'glpinav') . "</span>";
echo "</label>";

echo "<div class='mb-1'><label class='form-label' for='glpinav-anchor'>"
   . __s('Put the suite sections after', 'glpinav') . "</label>";
echo "<select class='form-select' id='glpinav-anchor' name='insert_after'" . ($can_edit ? "" : " disabled") . ">";
foreach ($anchors as $key => $title) {
    echo "<option value='" . $e($key) . "'" . ($key === Settings::insertAfter() ? " selected" : "") . ">"
       . $e($title) . "</option>";
}
echo "</select>";
echo "<div class='form-text'>"
   . __s('Setup always stays last: GLPI pins it there itself, whatever this says.', 'glpinav')
   . "</div></div>";

echo "</div></div>";

// ----------------------------------------------------------------- sections

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Sections', 'glpinav') . "</h3></div><div class='card-body'>";

echo "<div class='glpinav-scroll'><table class='table table-sm align-middle'>";
echo "<thead><tr>"
   . "<th style='width:5rem'>" . __s('Order', 'glpinav') . "</th>"
   . "<th style='width:5rem'>" . __s('Shown', 'glpinav') . "</th>"
   . "<th>" . __s('Name', 'glpinav') . "</th>"
   . "<th style='width:14rem'>" . __s('Icon', 'glpinav') . "</th>"
   . "</tr></thead><tbody>";

foreach ($sections as $idx => $section) {
    $off = $section['enabled'] !== 1;
    echo "<tr" . ($off ? " class='glpinav-off'" : "") . ">";
    echo "<td>" . Html::hidden("section_key[$idx]", ['value' => $section['key']])
       . "<input type='number' class='form-control form-control-sm' name='section_order[$idx]' value='"
       . (($idx + 1) * 10) . "' step='10'" . ($can_edit ? "" : " disabled") . "></td>";
    echo "<td><input type='checkbox' class='form-check-input' name='section_enabled[$idx]' value='1'"
       . ($off ? "" : " checked") . ($can_edit ? "" : " disabled") . "></td>";
    echo "<td><input type='text' class='form-control form-control-sm' name='section_title[$idx]' value='"
       . $e($section['title']) . "'" . ($can_edit ? "" : " disabled") . ">"
       . "<div class='glpinav-key text-muted'>" . $e($section['key']) . "</div></td>";
    echo "<td class='d-flex align-items-center gap-2'>"
       . "<span class='glpinav-icon-preview'><i class='" . $e($section['icon']) . "'></i></span>"
       . "<input type='text' class='form-control form-control-sm' name='section_icon[$idx]' value='"
       . $e($section['icon']) . "'" . ($can_edit ? "" : " disabled") . "></td>";
    echo "</tr>";
}

echo "</tbody></table></div>";
echo "<div class='form-text'>"
   . __s('Icons are Tabler classes, as GLPI uses everywhere else — "ti ti-radar-2". Anything else falls back to a folder. Section keys are fixed: the suite\'s pages name them when they ask where they live.', 'glpinav')
   . "</div>";
echo "</div></div>";

// -------------------------------------------------------------------- items

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Entries', 'glpinav') . "</h3></div><div class='card-body'>";

echo "<p class='form-text mt-0'>"
   . __s('Everything your profile can currently see on the menu that came from a plugin. Leaving an entry in place keeps it exactly where its own plugin filed it.', 'glpinav')
   . "</p>";

echo "<div class='glpinav-scroll'><table class='table table-sm align-middle'>";
echo "<thead><tr>"
   . "<th>" . __s('Entry', 'glpinav') . "</th>"
   . "<th class='glpinav-item-origin'>" . __s('Filed under', 'glpinav') . "</th>"
   . "<th style='width:14rem'>" . __s('Section', 'glpinav') . "</th>"
   . "<th style='width:13rem'>" . __s('Name in the menu', 'glpinav') . "</th>"
   . "</tr></thead><tbody>";

foreach ($items as $idx => $item) {
    echo "<tr>";
    echo "<td><i class='" . $e($item['icon']) . " me-1'></i>" . $e($item['title']);
    if ($item['owner'] !== null) {
        echo " <span class='text-muted'>· " . $e(Registry::pluginName($item['owner'])) . "</span>";
    }
    echo "<div class='glpinav-key text-muted'>" . $e($item['key']) . "</div></td>";
    echo "<td class='glpinav-item-origin text-muted'>" . $e($item['origin_title']) . "</td>";
    echo "<td>";

    if (!$item['movable']) {
        echo "<span class='text-muted'>" . __s('Fixed', 'glpinav') . "</span>";
        echo "<div class='form-text'>" . __s('Its pages name a sector directly.', 'glpinav') . "</div>";
        echo "</td><td class='text-muted'>&mdash;</td></tr>";
        continue;
    }

    echo Html::hidden("item_key[$idx]", ['value' => $item['key']]);
    echo "<select class='form-select form-select-sm' name='item_section[$idx]'" . ($can_edit ? "" : " disabled") . ">";
    echo "<option value=''>" . __s('Leave in place', 'glpinav') . "</option>";
    foreach ($sections as $section) {
        $selected = ($assignments[$item['key']] ?? null) === $section['key'];
        echo "<option value='" . $e($section['key']) . "'" . ($selected ? " selected" : "") . ">"
           . $e($section['title']) . ($section['enabled'] === 1 ? "" : " " . __s('(hidden)', 'glpinav')) . "</option>";
    }
    echo "</select>";

    // Only applied to an entry that is actually moved: renaming something in
    // the sector its own plugin owns would be this plugin editing another
    // plugin's page from the outside.
    echo "</td><td><input type='text' class='form-control form-control-sm' name='item_label[$idx]' value='"
       . $e($labels[$item['key']] ?? '') . "' placeholder='" . $e($item['title']) . "'"
       . ($can_edit ? "" : " disabled") . "></td></tr>";
}

echo "</tbody></table></div>";
echo "</div></div>";

if ($can_edit) {
    echo "<div class='d-flex gap-2 mb-4'>";
    echo "<button type='submit' class='btn btn-primary'>" . __s('Save') . "</button>";
    echo "</div>";
}

echo "</form>";

if ($can_edit) {
    echo "<form method='post' class='mb-4'>";
    echo Html::hidden('glpinav_form', ['value' => 'reset']);
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo "<button type='submit' class='btn btn-outline-secondary'>"
       . __s('Reset to defaults', 'glpinav') . "</button>";
    echo "<div class='form-text'>"
       . __s('Restores the shipped sections and assignments. It does not uninstall anything.', 'glpinav')
       . "</div>";
    echo "</form>";
}

echo "</div>";

Html::footer();
