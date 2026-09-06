<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpinav\Settings;

/**
 * Install: no tables, one right.
 *
 * The plugin's entire state is four rows in glpi_configs, so "uninstall
 * restores the stock layout" is literally true — delete the rows, stop
 * answering the hook, and the next menu build is core's own. Nothing about the
 * arrangement is written into another plugin's data, and nothing about it
 * survives this plugin's removal.
 */
function plugin_glpinav_install()
{
    $right = 'plugin_glpinav_config';

    // The install hook also runs on upgrade, and addProfileRights() inserts
    // unconditionally — so only seed profiles that do not have the row yet.
    /** @var DBmysql $DB */
    global $DB;

    $existing = [];
    foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $right]]) as $row) {
        $existing[(int) $row['profiles_id']] = true;
    }

    if ($existing === []) {
        ProfileRight::addProfileRights([$right]);
    }

    // Rearranging everyone's navigation is an instance-wide act, so it is
    // granted to exactly the profiles that may already change instance-wide
    // configuration — not to anyone who happens to run a suite plugin.
    foreach (
        $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['name' => 'config', 'rights' => ['&', UPDATE]],
        ]) as $row
    ) {
        ProfileRight::updateProfileRights((int) $row['profiles_id'], [$right => ALLSTANDARDRIGHT]);
    }

    Settings::invalidateMenu();

    return true;
}

function plugin_glpinav_uninstall()
{
    Config::deleteConfigurationValues(
        PLUGIN_GLPINAV_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    ProfileRight::deleteProfileRights(['plugin_glpinav_config']);

    // The session still holds the rearranged copy; without this the operator
    // who ran the uninstall keeps seeing suite sections until they log out.
    Settings::invalidateMenu();

    return true;
}
