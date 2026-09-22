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

		// --- Proxy : conserve pour compat pendant la migration du front ---
		['name' => 'proxy#proxyRequest', 'url' => '/proxy', 'verb' => 'POST'],

		// --- OAuth cote serveur ---
		['name' => 'oAuth#status', 'url' => '/oauth/status', 'verb' => 'GET'],
		['name' => 'oAuth#register', 'url' => '/oauth/register', 'verb' => 'POST'],
		['name' => 'oAuth#login', 'url' => '/oauth/login', 'verb' => 'POST'],
		['name' => 'oAuth#authorize', 'url' => '/oauth/authorize', 'verb' => 'POST'],
		['name' => 'oAuth#approve', 'url' => '/oauth/approve', 'verb' => 'POST'],
		['name' => 'oAuth#connect', 'url' => '/oauth/connect', 'verb' => 'POST'],
		['name' => 'oAuth#logout', 'url' => '/oauth/logout', 'verb' => 'POST'],

		// --- Lives / Visio ---
		['name' => 'live#index', 'url' => '/lives', 'verb' => 'GET'],
		['name' => 'live#create', 'url' => '/lives', 'verb' => 'POST'],
		['name' => 'live#update', 'url' => '/lives/{id}', 'verb' => 'PUT'],
		['name' => 'live#destroy', 'url' => '/lives/{id}', 'verb' => 'DELETE'],

		// --- Autocompletion des participants ---
		['name' => 'attendee#search', 'url' => '/attendees/search', 'verb' => 'GET'],

		// Panneau documentaire d'une reunion.
		['name' => 'document#show', 'url' => '/lives/{liveId}/folder', 'verb' => 'GET'],
		['name' => 'document#suggest', 'url' => '/lives/{liveId}/folder/suggest', 'verb' => 'GET'],
		['name' => 'document#link', 'url' => '/lives/{liveId}/folder', 'verb' => 'POST'],
		['name' => 'document#unlink', 'url' => '/lives/{liveId}/folder', 'verb' => 'DELETE'],
		['name' => 'document#recordingFolder', 'url' => '/lives/{liveId}/recording-folder', 'verb' => 'POST'],

		// Liens de partage Nextcloud.
		['name' => 'share#index', 'url' => '/files/{fileId}/shares', 'verb' => 'GET'],
		['name' => 'share#create', 'url' => '/files/{fileId}/shares', 'verb' => 'POST'],
		['name' => 'share#destroy', 'url' => '/shares/{shareId}', 'verb' => 'DELETE'],

		// « Reunion sur ce document » : action du menu contextuel de l'app Files.
		['name' => 'documentMeeting#suggest', 'url' => '/files/{fileId}/meeting/suggest', 'verb' => 'GET'],
		['name' => 'documentMeeting#create', 'url' => '/files/{fileId}/meeting', 'verb' => 'POST'],

		// Medias de la reunion (documents presentes).
		['name' => 'document#medias', 'url' => '/lives/{liveId}/medias', 'verb' => 'GET'],
		['name' => 'document#sendMedia', 'url' => '/lives/{liveId}/medias', 'verb' => 'POST'],
		// docIndex contient des points et des tirets : requirements evite que le
		// routeur tronque le segment sur le premier point.
		[
			'name' => 'document#deleteMedia',
			'url' => '/lives/{liveId}/medias/{docIndex}',
			'verb' => 'DELETE',
			'requirements' => ['docIndex' => '.+'],
		],
	],
];
