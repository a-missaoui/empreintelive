<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// --- Page d'app plein écran (icône du menu d'apps / top-bar) ---
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// --- Proxy (Lot 1) : conserve pour compat pendant la migration du front ---
		['name' => 'proxy#proxyRequest', 'url' => '/proxy', 'verb' => 'POST'],

		// --- OAuth cote serveur (Lot 2) ---
		['name' => 'oAuth#status', 'url' => '/oauth/status', 'verb' => 'GET'],
		['name' => 'oAuth#register', 'url' => '/oauth/register', 'verb' => 'POST'],
		['name' => 'oAuth#login', 'url' => '/oauth/login', 'verb' => 'POST'],
		['name' => 'oAuth#authorize', 'url' => '/oauth/authorize', 'verb' => 'POST'],
		['name' => 'oAuth#approve', 'url' => '/oauth/approve', 'verb' => 'POST'],
		['name' => 'oAuth#connect', 'url' => '/oauth/connect', 'verb' => 'POST'],
		['name' => 'oAuth#logout', 'url' => '/oauth/logout', 'verb' => 'POST'],

		// --- Lives / Visio (Lot 3/4) ---
		['name' => 'live#index', 'url' => '/lives', 'verb' => 'GET'],
		['name' => 'live#create', 'url' => '/lives', 'verb' => 'POST'],
		['name' => 'live#update', 'url' => '/lives/{id}', 'verb' => 'PUT'],
		['name' => 'live#destroy', 'url' => '/lives/{id}', 'verb' => 'DELETE'],

		// --- Autocompletion des participants ---
		['name' => 'attendee#search', 'url' => '/attendees/search', 'verb' => 'GET'],
	],
];
