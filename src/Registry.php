<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpinav;

use Plugin;

/**
 * Which menu entries this plugin is willing to move, and what is actually on
 * the menu right now.
 *
 * The willingness list is hard-coded on purpose. Moving an entry is only safe
 * when the pages behind it resolve their own sector through Nav::sector()
 * instead of naming one literally — otherwise GLPI looks the page's declared
 * sector up in a menu that no longer holds the entry, and the breadcrumb, the
 * "Add" button and the sidebar highlight all silently disappear (measured).
 * That property belongs to the *page*, not to
 * the menu, so it cannot be discovered from the menu array — it has to be
 * asserted here, by a human who has looked at the calls.
 *
 * Everything not on this list stays where its own plugin filed it. That
 * includes all of core, and the pre-existing half of the suite whose source
 * this plugin does not get to touch.
 */
final class Registry
{
    /**
     * Movable menu key => the plugin that owns the pages behind it.
     *
     * Every one of these has had its `Html::header()` sector argument replaced
     * with a `Nav::sector(<this key>, '<stock sector>')` call, so it follows
     * whatever this plugin decides — including "nowhere", when glpinav is
     * inactive or its master switch is off.
     */
    public const MOVABLE = [
        'glpisignal_alert'                       => 'glpisignal',
        'glpisignal_triage'                      => 'glpisignal',
        'glpisignal_backup'                      => 'glpisignal',
        'glpisignal_source'                      => 'glpisignal',
        'glpisignal_rota'                        => 'glpisignal',
        'glpisignal_backupjob'                   => 'glpisignal',
        'glpisignal_maintenancewindow'           => 'glpisignal',

        'glpiplugin\glpimajor\incident'          => 'glpimajor',
        'glpiplugin\glpimajor\maintenance'       => 'glpimajor',

        'glpiplugin\glpivuln\menu'               => 'glpivuln',

        'glpichange_calendar'                    => 'glpichange',
        'glpichange_release'                     => 'glpichange',
        'glpiplugin\glpichange\riskassessment'   => 'glpichange',
        'glpiplugin\glpichange\freeze'           => 'glpichange',
        'glpiplugin\glpichange\standardchange'   => 'glpichange',

        'glpiplugin\glpikedb\knownerror'         => 'glpikedb',

        'glpiplugin\glpiimprove\improvement'     => 'glpiimprove',

        'glpiplugin\glpiservice\businessservice' => 'glpiservice',
        'glpiservice_census'                     => 'glpiservice',
        'glpiservice_conflicts'                  => 'glpiservice',
        'glpiservice_drift'                      => 'glpiservice',

        'glpiplugin\glpireport\reportdefinition' => 'glpireport',
        'glpiplugin\glpireport\report'           => 'glpireport',

        // Adopted 2026-08-22, when the pre-existing half of the suite was
        // allowed the same one-line change. Everything here has had its
        // Html::header() sector argument replaced too.
        //
        // Not adopted, and deliberately: glpi-ai's MCP servers and Assistant
        // skills, and glpi-identity's sources. They are platform configuration
        // and they read correctly where GLPI already files them; moving them
        // would be motion for its own sake. They stay Fixed on the settings
        // page, with the same reason as before — their pages name a sector
        // directly.
        'glpiplugin\glpiosquery\consolemenu'     => 'glpiosquery',
        'glpiplugin\glpiosquery\agent'           => 'glpiosquery',
        'glpiplugin\glpiosquery\compliancemenu'  => 'glpiosquery',

        'glpinetscan_target'                     => 'glpinetscan',
        'glpinetscan_scanner'                    => 'glpinetscan',
        'glpinetscan_oidprofile'                 => 'glpinetscan',

        'glpiplugin\glpisop\sop'                 => 'glpisop',
    ];

    public static function isMovable(string $item_key): bool
    {
        return isset(self::MOVABLE[mb_strtolower($item_key)]);
    }

    public static function owner(string $item_key): ?string
    {
        return self::MOVABLE[mb_strtolower($item_key)] ?? null;
    }

    /**
     * Every entry currently on this session's menu that came from a plugin,
     * with where it sits before this plugin touches anything.
     *
     * Driven off the live menu rather than off a list of our own, so the
     * settings page shows what the operator's own profile can actually see —
     * a suite plugin they have no rights for simply is not offered.
     *
     * @return list<array{key:string,title:string,page:string,icon:string,origin:string,origin_title:string,movable:bool,owner:?string}>
     */
    public static function discover(): array
    {
        $menu = Layout::stock();
        $out  = [];

        foreach ($menu as $sector => $data) {
            if (!is_array($data) || !isset($data['content']) || !is_array($data['content'])) {
                continue;
            }
            foreach ($data['content'] as $key => $entry) {
                if (!is_array($entry) || !isset($entry['page']) || !is_string($entry['page'])) {
                    continue;
                }
                if (!str_starts_with($entry['page'], '/plugins/')) {
                    continue;
                }
                $key = (string) $key;
                $out[] = [
                    'key'          => $key,
                    'title'        => (string) ($entry['title'] ?? $key),
                    'page'         => $entry['page'],
                    'icon'         => (string) ($entry['icon'] ?? 'ti ti-point'),
                    'origin'       => (string) $sector,
                    'origin_title' => (string) ($data['title'] ?? $sector),
                    'movable'      => self::isMovable($key),
                    'owner'        => self::owner($key),
                ];
            }
        }

        usort($out, static function (array $a, array $b): int {
            return [$b['movable'], $a['owner'] ?? '', $a['title']]
                <=> [$a['movable'], $b['owner'] ?? '', $b['title']];
        });

        return $out;
    }

    /** Human name for a plugin key, for the settings table's grouping column. */
    public static function pluginName(?string $key): string
    {
        if ($key === null) {
            return '';
        }
        $info = Plugin::getInfo($key);
        return (string) ($info['name'] ?? $key);
    }

    /**
     * Core's own sectors, as candidate anchors for where the suite sections go.
     *
     * @return array<string,string>
     */
    public static function coreSectors(): array
    {
        $out = [];
        foreach (Layout::stock() as $key => $data) {
            if (!is_array($data) || ($data['display'] ?? true) === false) {
                continue;
            }
            $out[(string) $key] = (string) ($data['title'] ?? $key);
        }
        return $out;
    }
}
