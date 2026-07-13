<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Bootstrap des tests unitaires. On charge uniquement les autoloaders de
 * Nextcloud (OCP\*, Sabre\*) + un autoloader PSR-4 pour l'app, SANS booter le
 * serveur complet : les tests unitaires n'utilisent que des mocks.
 */

$core = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';

require_once $core . '/lib/composer/autoload.php';
require_once $core . '/3rdparty/autoload.php';

// L'app DAV (coeur) fournit OCA\DAV\CalDAV\CalDavBackend, mocke dans les tests
// (creation du calendrier dedie). Son autoloader n'est pas charge par le coeur.
$davAutoload = $core . '/apps/dav/composer/autoload.php';
if (is_file($davAutoload)) {
	require_once $davAutoload;
}

spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\EmpreinteLive\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$rel = str_replace('\\', '/', substr($class, strlen($prefix)));
	$file = __DIR__ . '/../lib/' . $rel . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
