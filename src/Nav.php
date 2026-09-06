<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpinav;

use Plugin;

/**
 * What a suite page calls to find out which sector it is in this week.
 *
 * `Html::header($title, $url, $sector, $item)` is not decoration: GLPI looks
 * `$menu[$sector]['content'][$item]` up to build the breadcrumb, and
 * `…['links']['add']` under it to decide whether the page gets its "Add"
 * button (templates/layout/parts/context_links.html.twig). A page that names a
 * sector its entry has been moved out of loses both, plus the sidebar
 * highlight, with no error anywhere.
 *
 * So the suite's movable pages ask instead of asserting:
 *
 *     $sector = class_exists(Nav::class) ? Nav::sector(Foo::class, 'admin') : 'admin';
 *     Html::header(Foo::getTypeName(2), $_SERVER['PHP_SELF'], $sector, Foo::class);
 *
 * The `class_exists()` guard and the second argument are the whole reason this
 * is safe to add to a plugin that must also run on an instance where glpinav
 * was never installed: with no glpinav, or with glpinav switched off, or with
 * this particular entry left where it was, the page gets its original sector
 * back and behaves exactly as it did before.
 */
final class Nav
{
    /**
     * @param string $item_key      the menu content key for this page's entry —
     *                              in practice the same string the page passes
     *                              as Html::header()'s $item argument
     * @param string $stock_sector  where the entry lives when nothing has moved
     */
    public static function sector(string $item_key, string $stock_sector): string
    {
        try {
            if (!Plugin::isPluginActive('glpinav') || !Settings::isEnabled()) {
                return $stock_sector;
            }

            $item = mb_strtolower($item_key);
            $target = Settings::assignments()[$item] ?? null;
            if ($target === null) {
                return $stock_sector;
            }

            // Assigned is not the same as rendered. A section is dropped when
            // this session can see none of its entries, and pointing a
            // breadcrumb at a section that was never emitted is precisely the
            // failure this method exists to prevent — so confirm rather than
            // assume.
            $menu = Layout::rendered();
            if (!isset($menu[$target]['content'][$item])) {
                return $stock_sector;
            }

            return $target;
        } catch (\Throwable $e) {
            return $stock_sector;
        }
    }
}
