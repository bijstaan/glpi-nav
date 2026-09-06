<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpinav;

use Html;
use Session;

/**
 * The menu transform: take the array core just built, hand back one where the
 * suite's entries sit under suite-owned top-level sections.
 *
 * Three properties this has to hold, in order of how badly they break things:
 *
 * 1. **It can never throw.** This runs inside Html::header(), so an exception
 *    here is a blank page on every screen in the instance. Every step degrades
 *    to "return what I was given".
 * 2. **It is idempotent.** The transformed menu is written back into
 *    $_SESSION['glpimenu'] (so the command palette and core's Ctrl+Alt+G menu
 *    finder, which read that array directly and never see this hook, show the
 *    new arrangement too). That means the *next* request hands us our own
 *    output. Every moved entry carries an `_glpinav_origin` breadcrumb so the
 *    transform can be undone exactly before being redone, which is also what
 *    makes a settings change take effect without a relog.
 * 3. **It never widens visibility.** Entries are moved, never created. Core
 *    already filtered the menu by the session's rights before we saw it, so a
 *    section is only as visible as its contents — and a section whose contents
 *    are all invisible is not emitted at all, rather than rendering as an empty
 *    dropdown.
 */
final class Layout
{
    /** Set on a moved entry: the sector key it came from. */
    private const ORIGIN = '_glpinav_origin';

    /** Set on a sector this plugin created, so unfold() knows what to discard. */
    private const OURS = '_glpinav_section';

    /**
     * Hook entry point. Returns the menu to render.
     */
    public static function apply(array $menu): array
    {
        if ($menu === []) {
            return $menu;
        }

        // The helpdesk portal has its own menu, built by generateHelpMenu() and
        // shaped nothing like this one. It is also the one place a requester
        // sees, so it is deliberately out of scope.
        if (Session::getCurrentInterface() !== 'central') {
            return $menu;
        }

        try {
            $stock = self::unfold($menu);

            if (!Settings::isEnabled()) {
                self::cacheInSession($stock);
                return $stock;
            }

            $folded = self::fold($stock);
            self::cacheInSession($folded);

            return $folded;
        } catch (\Throwable $e) {
            // A broken navigation plugin must not be able to take the sidebar
            // with it. Left visible in the PHP log; the operator still has a
            // working, stock menu.
            trigger_error('glpinav: menu transform skipped: ' . $e->getMessage(), E_USER_WARNING);
            return $menu;
        }
    }

    /**
     * The menu as core built it, with any previous transform undone.
     *
     * This is what the settings page enumerates and what Nav::sector() reasons
     * about, so both see the same ground truth as the hook.
     */
    public static function stock(?array $menu = null): array
    {
        $menu ??= Html::generateMenuSession();
        return is_array($menu) ? self::unfold($menu) : [];
    }

    /**
     * The menu as it will render, for a caller that needs to know where an
     * entry ended up (Nav::sector()).
     */
    public static function rendered(): array
    {
        $stock = self::stock();
        if (!Settings::isEnabled()) {
            return $stock;
        }
        return self::fold($stock);
    }

    /**
     * Put every moved entry back where it came from and drop our sections.
     */
    private static function unfold(array $menu): array
    {
        $returning = [];

        foreach ($menu as $sector => $data) {
            if (!is_array($data)) {
                continue;
            }
            foreach (($data['content'] ?? []) as $key => $entry) {
                if (!is_array($entry) || !isset($entry[self::ORIGIN])) {
                    continue;
                }
                $origin = (string) $entry[self::ORIGIN];
                unset($entry[self::ORIGIN], $menu[$sector]['content'][$key]);
                $returning[$origin][$key] = $entry;
            }

            if (($data[self::OURS] ?? false) === true) {
                unset($menu[$sector]);
            }
        }

        foreach ($returning as $origin => $entries) {
            if (!isset($menu[$origin]) || !is_array($menu[$origin])) {
                // The sector the entry came from is gone (a plugin was
                // deactivated between the two renders). Dropping the entry here
                // would hide it; core will rebuild it on the next full
                // regeneration, so park it under Plugins rather than lose it.
                $origin = isset($menu['plugins']) ? 'plugins' : (string) array_key_first($menu);
            }
            foreach ($entries as $key => $entry) {
                $menu[$origin]['content'][$key] = $entry;
            }
        }

        return $menu;
    }

    /**
     * Move the assigned entries into their sections and splice the sections in.
     */
    private static function fold(array $stock): array
    {
        $assignments = Settings::assignments();
        if ($assignments === []) {
            return $stock;
        }
        $labels = Settings::labels();

        $buckets = [];
        $emptied = [];

        foreach ($assignments as $item => $section) {
            foreach ($stock as $sector => $data) {
                if (!is_array($data) || !isset($data['content'][$item]) || !is_array($data['content'][$item])) {
                    continue;
                }
                $entry = $data['content'][$item];
                // Only entries that are actually navigable move; an entry with
                // no page renders nothing and would make a section look
                // populated when it is not.
                if (!isset($entry['page']) || !is_string($entry['page']) || $entry['page'] === '') {
                    continue 2;
                }
                $entry[self::ORIGIN] = (string) $sector;
                if (isset($labels[$item])) {
                    // Renaming here rather than only in the sidebar keeps the
                    // breadcrumb, the active-entry match (which compares
                    // titles) and the Ctrl+Alt+G finder all saying the same
                    // thing.
                    $entry['title'] = $labels[$item];
                }
                $buckets[$section][$item] = $entry;

                unset($stock[$sector]['content'][$item]);
                $emptied[(string) $sector][] = (string) $entry['page'];
                continue 2;
            }
        }

        if ($buckets === []) {
            return $stock;
        }

        // A sector's `default` is the page the breadcrumb's sector crumb links
        // to. Core sets it from the first entry with a page, which for every
        // core sector is a core entry — but a hand-built config could still
        // move whatever it points at, so repair it rather than assume.
        foreach ($emptied as $sector => $pages) {
            $default = $stock[$sector]['default'] ?? null;
            if ($default === null || !in_array($default, $pages, true)) {
                continue;
            }
            $stock[$sector]['default'] = self::firstPage($stock[$sector]['content'] ?? []);
            if ($stock[$sector]['default'] === null) {
                unset($stock[$sector]['default']);
            }
        }

        $sections = [];
        foreach (Settings::sections() as $section) {
            if ($section['enabled'] !== 1) {
                continue;
            }
            $content = $buckets[$section['key']] ?? [];
            $default = self::firstPage($content);
            if ($default === null) {
                // Nothing this session may see. Emitting the section anyway
                // would put an empty, unclickable heading in everyone's sidebar.
                continue;
            }
            $sections[$section['key']] = [
                'title'     => $section['title'],
                'icon'      => $section['icon'],
                'default'   => $default,
                'content'   => $content,
                self::OURS  => true,
            ];
        }

        if ($sections === []) {
            return $stock;
        }

        return self::splice($stock, $sections, Settings::insertAfter());
    }

    /**
     * Insert the sections into the sector order, after the chosen anchor.
     *
     * Rebuilding the array rather than array_splice()ing it keeps the keys,
     * which are what every lookup in the templates is done by.
     */
    private static function splice(array $menu, array $sections, string $after): array
    {
        if (!isset($menu[$after])) {
            // Anchor gone (a core sector can vanish for a profile with no
            // rights in it). Fall back to just before Setup, which core pins
            // last, so the sections still land above it rather than after it.
            $after = null;
            foreach (array_keys($menu) as $key) {
                if ($key === 'config') {
                    break;
                }
                $after = (string) $key;
            }
        }

        $out = [];
        $placed = false;
        foreach ($menu as $key => $value) {
            $out[$key] = $value;
            if ($key === $after) {
                foreach ($sections as $skey => $svalue) {
                    $out[$skey] = $svalue;
                }
                $placed = true;
            }
        }

        if (!$placed) {
            foreach ($sections as $skey => $svalue) {
                $out[$skey] = $svalue;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $content */
    private static function firstPage(array $content): ?string
    {
        foreach ($content as $entry) {
            if (is_array($entry) && isset($entry['page']) && is_string($entry['page']) && $entry['page'] !== '') {
                return $entry['page'];
            }
        }
        return null;
    }

    /**
     * Keep the session copy in step with what we just rendered.
     *
     * Html::getMenuFuzzySearchList() — core's Ctrl+Alt+G finder, and the source
     * glpi-palette flattens for its command list — reads $_SESSION['glpimenu']
     * without ever going through Html::header(), so this hook is invisible to
     * it unless the result is written back.
     */
    private static function cacheInSession(array $menu): void
    {
        if (!isset($_SESSION['glpimenu']) || !is_array($_SESSION['glpimenu'])) {
            return;
        }
        $_SESSION['glpimenu'] = $menu;
    }
}
