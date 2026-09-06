<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * Fixtures for nav-check.js. Idempotent; re-running rebuilds them in place.
 *
 *   docker exec -i glpi-glpi-1 php < glpi-nav/tests/fixtures.php
 *
 * Two users, both owned by this check and by nothing else:
 *
 *  - nav-probe / nav-probe-pw   a deliberately narrow profile: central
 *    interface, one suite right (glpi-kedb's known errors) and nothing else.
 *    Proves that a section with no entry this profile may see is not rendered,
 *    and that the ones it may see still are.
 *  - nav-dark / nav-dark-pw     Super-Admin on the auror_dark palette, for the
 *    dark-theme pass. A test user rather than the maintainer's own account,
 *    which is never touched.
 */

require '/var/www/glpi/vendor/autoload.php';

if (!defined('TU_USER')) {
    define('TU_USER', 'glpinav-fixtures');
}

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

if (!(new Auth())->login('glpi', 'glpi', true)) {
    fwrite(STDERR, "could not log in as glpi/glpi\n");
    exit(1);
}

(new Plugin())->init(true);

/** @var DBmysql $DB */
global $DB;

$out = [];

// --------------------------------------------------------------- the profile

$profile = new Profile();
$profiles_id = 0;
foreach ($DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['name' => 'Nav probe']]) as $row) {
    $profiles_id = (int) $row['id'];
}

if ($profiles_id === 0) {
    $profiles_id = (int) $profile->add([
        'name'      => 'Nav probe',
        'interface' => 'central',
        'comment'   => 'glpi-nav browser check: a profile that can see one suite entry and no others.',
    ]);
}

// Wipe every right on it, then grant exactly what the check depends on.
$DB->delete('glpi_profilerights', ['profiles_id' => $profiles_id]);
$grant = [
    // Enough to log in and land somewhere.
    'ticket'                => READ,
    // One movable suite entry, in one section.
    'plugin_glpikedb_ke'    => READ,
];
foreach ($grant as $name => $rights) {
    $DB->insert('glpi_profilerights', [
        'profiles_id' => $profiles_id,
        'name'        => $name,
        'rights'      => $rights,
    ]);
}

$out['profiles_id'] = $profiles_id;

// ----------------------------------------------------------------- the users

function ensure_user(string $login, string $password, int $profiles_id, ?string $palette): int
{
    /** @var DBmysql $DB */
    global $DB;

    $user = new User();
    $users_id = 0;
    foreach ($DB->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => $login]]) as $row) {
        $users_id = (int) $row['id'];
    }

    $fields = [
        'name'      => $login,
        'realname'  => 'glpi-nav check',
        'is_active' => 1,
        '_profiles_id' => $profiles_id,
        'profiles_id'  => $profiles_id,
        'entities_id'  => 0,
        '_entities_id' => 0,
        '_is_recursive' => 1,
    ];

    if ($users_id === 0) {
        // Only on creation: GLPI's password history rejects setting the same
        // password again, and a re-run would fill the log with a failure that
        // means nothing.
        $fields['password']  = $password;
        $fields['password2'] = $password;
        $users_id = (int) $user->add($fields);
    } else {
        $fields['id'] = $users_id;
        $user->update($fields);
    }

    // Profile_User rows: exactly one, the profile we want, recursive from root.
    $DB->delete('glpi_profiles_users', ['users_id' => $users_id]);
    $DB->insert('glpi_profiles_users', [
        'users_id'     => $users_id,
        'profiles_id'  => $profiles_id,
        'entities_id'  => 0,
        'is_recursive' => 1,
        'is_dynamic'   => 0,
    ]);

    $DB->update('glpi_users', ['palette' => $palette], ['id' => $users_id]);

    return $users_id;
}

$super = 0;
foreach ($DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['name' => 'Super-Admin']]) as $row) {
    $super = (int) $row['id'];
}

$out['nav_probe'] = ensure_user('nav-probe', 'nav-probe-pw', $profiles_id, null);
$out['nav_dark']  = ensure_user('nav-dark', 'nav-dark-pw', $super, 'auror_dark');

echo json_encode($out) . "\n";
