<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI Nav — suite-owned top-level sections in the central sidebar.
 *
 * GLPI gives a plugin exactly one lever over the sidebar: `menu_toadd`, which
 * appends an itemtype to one of core's seven fixed sectors. With a suite of
 * eighteen plugins that lever produces the mess this plugin exists to undo —
 * alerts and on-call rotas filed under Administration next to LDAP, change
 * freezes and major incidents under Setup next to the mail receivers, the
 * vulnerability dashboard under Tools next to the RSS reader. Nothing is
 * wrong; nothing is findable.
 *
 * This plugin is a *coordinator*, not a feature. It owns no data and adds no
 * pages beyond its own settings screen. It rewrites the menu array on the way
 * to the template (Hooks::REDEFINE_MENUS) so that entries the operator has
 * assigned to a suite section appear there instead of where their plugin
 * originally filed them.
 *
 * The half of the job that is not obvious: GLPI resolves the breadcrumb, the
 * "Add" button and the active highlight by looking the current page's
 * *declared* sector up in that same array (Html::header's 3rd argument, read
 * back by layout/parts/breadcrumbs.html.twig and context_links.html.twig). An
 * entry moved out from under its declared sector loses all three. So moving an
 * entry is only safe when its pages ask this plugin where they now live —
 * which is what Nav::sector() is for, and why only the suite plugins that call
 * it are movable.
 */

use GlpiPlugin\Glpinav\Layout;

define('PLUGIN_GLPINAV_VERSION', '0.1.0');
define('PLUGIN_GLPINAV_MIN_GLPI', '11.0');

// Settings live under this config context.
define('PLUGIN_GLPINAV_CONFIG_CONTEXT', 'plugin:glpinav');

function plugin_init_glpinav()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpinav'] = true;

    // The only functional hook in the plugin. Fires inside Html::header() and
    // Html::helpHeader() with the menu core just built; whatever we return is
    // what the sidebar, the breadcrumb and the context links are rendered from.
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::REDEFINE_MENUS]['glpinav'] = 'plugin_glpinav_redefine_menus';

    $PLUGIN_HOOKS['add_css']['glpinav'] = 'css/nav.css';

    $PLUGIN_HOOKS['config_page']['glpinav'] = 'front/config.php';
}

function plugin_version_glpinav()
{
    return [
        'name'         => 'GLPI Nav',
        'version'      => PLUGIN_GLPINAV_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-nav',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPINAV_MIN_GLPI]],
    ];
}

function plugin_glpinav_check_prerequisites()
{
    return true;
}

function plugin_glpinav_check_config($verbose = false)
{
    return true;
}

/**
 * A *function* hook: whatever this returns is the menu GLPI renders. A callback
 * that forgets to return, or that throws, empties the sidebar for every
 * technician on the instance — so Layout::apply() is written to hand back the
 * array it was given whenever anything at all is not as it expects.
 */
function plugin_glpinav_redefine_menus($menu)
{
    return Layout::apply(is_array($menu) ? $menu : []);
}
