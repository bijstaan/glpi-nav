<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpinav;

use Config;

/**
 * The whole of the plugin's state: five config rows, no tables.
 *
 * Three of them hold JSON — the section list, the item→section assignments and
 * the label overrides. They are stored as JSON rather than as one row per
 * section because the menu is rebuilt on every page render, and a second query
 * per section, per page, would be a visible cost for something that changes
 * twice a year.
 *
 * Everything read out of the config table is treated as hostile: a section
 * list that has been hand-edited into nonsense must degrade to "no sections",
 * never to a fatal inside Html::header() — which would take out every page in
 * the instance at once.
 */
final class Settings
{
    public const DEFAULTS = [
        // Master switch. Off leaves the stock GLPI layout completely untouched.
        'enabled'      => 1,
        // Which core sector the suite sections are inserted after. Core's own
        // order is assets, helpdesk, management, tools, plugins, admin, config;
        // day-to-day work belongs next to Assistance rather than after Setup.
        'insert_after' => 'helpdesk',
        'sections'     => '',
        'assignments'  => '',
        'labels'       => '',
    ];

    /**
     * The shipped information architecture.
     *
     * Keys are permanent and are what the suite plugins' pages name when they
     * ask Nav::sector() where they live; titles and icons are the operator's to
     * change. Prefixed `nav_` so they can never collide with a core sector key
     * (assets/helpdesk/management/tools/plugins/admin/config/preference) nor
     * with a future one.
     */
    public const DEFAULT_SECTIONS = [
        ['key' => 'nav_operations', 'title' => 'Operations',         'icon' => 'ti ti-radar-2',          'enabled' => 1],
        ['key' => 'nav_security',   'title' => 'Security',           'icon' => 'ti ti-shield-lock',      'enabled' => 1],
        ['key' => 'nav_service',    'title' => 'Service management', 'icon' => 'ti ti-arrows-exchange',  'enabled' => 1],
        ['key' => 'nav_reporting',  'title' => 'Reporting',          'icon' => 'ti ti-chart-histogram',  'enabled' => 1],
    ];

    /**
     * Where each movable suite entry goes by default.
     *
     * Keyed by the menu *content* key, which is what core stores the entry
     * under: `strtolower($itemtype)` for a plain itemtype, or the literal key
     * the class chose when it returned `is_multi_entries`.
     */
    public const DEFAULT_ASSIGNMENTS = [
        // glpi-signal: alerting, triage, on-call, backup, maintenance windows.
        'glpisignal_alert'                     => 'nav_operations',
        'glpisignal_triage'                    => 'nav_operations',
        'glpisignal_backup'                    => 'nav_operations',
        'glpisignal_source'                    => 'nav_operations',
        'glpisignal_rota'                      => 'nav_operations',
        'glpisignal_backupjob'                 => 'nav_operations',
        'glpisignal_maintenancewindow'         => 'nav_operations',
        // glpi-major: the incident itself and the announcements it publishes.
        'glpiplugin\glpimajor\incident'        => 'nav_operations',
        'glpiplugin\glpimajor\maintenance'     => 'nav_operations',
        // glpi-osquery: asking live machines a question, and the fleet of
        // agents that answers. Both are "what is happening on the estate right
        // now", which is the whole of this section.
        'glpiplugin\glpiosquery\consolemenu'   => 'nav_operations',
        'glpiplugin\glpiosquery\agent'         => 'nav_operations',
        // glpi-netscan: the three pages are one job — decide what to sweep,
        // see which collectors are enrolled, and describe how to interrogate a
        // device family. Splitting the third off into a configuration bucket
        // would separate it from the only two pages it is ever used with.
        'glpinetscan_target'                   => 'nav_operations',
        'glpinetscan_scanner'                  => 'nav_operations',
        'glpinetscan_oidprofile'               => 'nav_operations',

        // glpi-vuln: one entry, four options hanging off it.
        'glpiplugin\glpivuln\menu'             => 'nav_security',
        // glpi-osquery's compliance view is the same kind of thing as the
        // exposure dashboard: a standing posture picture of the estate (disk
        // encryption, firewall, screen lock), not something a technician acts
        // on today. It belongs beside it rather than beside the console.
        'glpiplugin\glpiosquery\compliancemenu' => 'nav_security',

        // glpi-change.
        'glpichange_calendar'                  => 'nav_service',
        'glpichange_release'                   => 'nav_service',
        'glpiplugin\glpichange\riskassessment' => 'nav_service',
        'glpiplugin\glpichange\freeze'         => 'nav_service',
        'glpiplugin\glpichange\standardchange' => 'nav_service',
        // glpi-kedb.
        'glpiplugin\glpikedb\knownerror'       => 'nav_service',
        // glpi-sop: a procedure is the written form of "how we do this one",
        // which is the same shelf as a known error and an improvement — the
        // practice, not the instance configuration Setup files it under today.
        'glpiplugin\glpisop\sop'               => 'nav_service',
        // glpi-improve.
        'glpiplugin\glpiimprove\improvement'   => 'nav_service',
        // glpi-service.
        'glpiplugin\glpiservice\businessservice' => 'nav_service',
        'glpiservice_census'                   => 'nav_service',
        'glpiservice_conflicts'                => 'nav_service',
        'glpiservice_drift'                    => 'nav_service',

        // glpi-report.
        'glpiplugin\glpireport\reportdefinition' => 'nav_reporting',
        'glpiplugin\glpireport\report'           => 'nav_reporting',
    ];

    /**
     * Menu labels this plugin overrides when it moves an entry.
     *
     * Only one is shipped, and it is not cosmetic. glpi-signal's suppression
     * windows and glpi-major's entity announcements both call themselves
     * "Maintenance windows"; they lived in different sectors, so nobody had to
     * care. Filing them side by side under Operations makes them
     * indistinguishable in the sidebar — and worse, GLPI decides which entry is
     * the active one by comparing *titles* (Html::header() sets $menu_active
     * from the entry's title, and layout/parts/menu.html.twig matches on it),
     * so two entries with one name both light up on either page. Measured.
     */
    public const DEFAULT_LABELS = [
        'glpiplugin\\glpimajor\\maintenance' => 'Maintenance announcements',
    ];

    /** @return array<string,string|int> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_GLPINAV_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            if ($value === null) {
                $out[$key] = $default;
                continue;
            }
            $out[$key] = is_int($default) ? (int) $value : (string) $value;
        }

        return $out;
    }

    public static function isEnabled(): bool
    {
        return (int) (self::all()['enabled']) === 1;
    }

    public static function insertAfter(): string
    {
        $after = trim((string) self::all()['insert_after']);
        return $after === '' ? 'helpdesk' : $after;
    }

    /**
     * The section list, in render order, normalised.
     *
     * An empty stored value means "never configured" and yields the shipped
     * defaults — which is also what makes uninstall a clean restore: delete the
     * rows and the next install starts from the same place.
     *
     * @return list<array{key:string,title:string,icon:string,enabled:int}>
     */
    public static function sections(): array
    {
        $raw = (string) self::all()['sections'];
        $decoded = $raw === '' ? self::DEFAULT_SECTIONS : json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = self::DEFAULT_SECTIONS;
        }

        $out  = [];
        $seen = [];
        foreach ($decoded as $section) {
            if (!is_array($section)) {
                continue;
            }
            $key = self::normaliseKey((string) ($section['key'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'key'     => $key,
                'title'   => trim((string) ($section['title'] ?? '')) ?: $key,
                'icon'    => self::normaliseIcon((string) ($section['icon'] ?? '')),
                'enabled' => ((int) ($section['enabled'] ?? 1)) === 1 ? 1 : 0,
            ];
        }

        return $out;
    }

    /**
     * item key => section key, filtered to sections that exist and are enabled
     * and to items this plugin is willing to move.
     *
     * @return array<string,string>
     */
    public static function assignments(): array
    {
        $raw = (string) self::all()['assignments'];
        $decoded = $raw === '' ? self::DEFAULT_ASSIGNMENTS : json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = self::DEFAULT_ASSIGNMENTS;
        }

        $live = [];
        foreach (self::sections() as $section) {
            if ($section['enabled'] === 1) {
                $live[$section['key']] = true;
            }
        }

        $out = [];
        foreach ($decoded as $item => $section) {
            if (!is_string($item) || !is_string($section)) {
                continue;
            }
            $item = mb_strtolower(trim($item));
            if ($item === '' || !isset($live[$section])) {
                continue;
            }
            if (!Registry::isMovable($item)) {
                continue;
            }
            $out[$item] = $section;
        }

        return $out;
    }

    /**
     * item key => the label to show instead of the entry's own title.
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        $raw = (string) self::all()['labels'];
        $decoded = $raw === '' ? self::DEFAULT_LABELS : json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = self::DEFAULT_LABELS;
        }

        $out = [];
        foreach ($decoded as $item => $label) {
            if (!is_string($item) || !is_string($label)) {
                continue;
            }
            $item  = mb_strtolower(trim($item));
            $label = trim($label);
            if ($item === '' || $label === '' || !Registry::isMovable($item)) {
                continue;
            }
            $out[$item] = $label;
        }

        return $out;
    }

    /**
     * @param list<array{key:string,title:string,icon:string,enabled:int}> $sections
     * @param array<string,string>                                        $assignments
     * @param array<string,string>                                        $labels
     */
    public static function save(int $enabled, string $insert_after, array $sections, array $assignments, array $labels): void
    {
        Config::setConfigurationValues(PLUGIN_GLPINAV_CONFIG_CONTEXT, [
            'enabled'      => $enabled === 1 ? 1 : 0,
            'insert_after' => $insert_after,
            'sections'     => json_encode(array_values($sections)),
            'assignments'  => json_encode($assignments),
            'labels'       => json_encode($labels),
        ]);
        self::invalidateMenu();
    }

    public static function reset(): void
    {
        Config::setConfigurationValues(PLUGIN_GLPINAV_CONFIG_CONTEXT, [
            'enabled'      => self::DEFAULTS['enabled'],
            'insert_after' => self::DEFAULTS['insert_after'],
            'sections'     => '',
            'assignments'  => '',
            'labels'       => '',
        ]);
        self::invalidateMenu();
    }

    /**
     * Drop the per-session menu cache so the next page render rebuilds it.
     *
     * Html::generateMenuSession() only rebuilds when $_SESSION['glpimenu'] is
     * missing; without this a technician who saves here would keep seeing the
     * old arrangement until they logged out and back in.
     */
    public static function invalidateMenu(): void
    {
        unset($_SESSION['glpimenu']);
    }

    /** Section keys are used as PHP array keys and as URL-visible sector names. */
    private static function normaliseKey(string $key): string
    {
        $key = mb_strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? '';
        return $key;
    }

    /** Only Tabler icon classes; anything else would be markup in an attribute. */
    private static function normaliseIcon(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '' || !preg_match('/^ti ti-[a-z0-9-]+$/', $icon)) {
            return 'ti ti-folder';
        }
        return $icon;
    }
}
