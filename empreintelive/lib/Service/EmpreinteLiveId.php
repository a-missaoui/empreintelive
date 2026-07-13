<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Extraction du liveId EMPREINTE depuis un evenement calendrier. Aucune donnee
 * n'est stockee cote app : le liveId est toujours relu dans l'ICS (proprietes
 * X-EMPREINTE-*-URL, LOCATION ou DESCRIPTION). Mutualise entre le listener de
 * synchronisation (suppression/modification) et le back-fill du calendrier.
 */

namespace OCA\EmpreinteLive\Service;

use Sabre\VObject\Reader;
use Throwable;

final class EmpreinteLiveId {
	/** Proprietes ICS ou l'URL de la visio (donc le liveId) peut se trouver. */
	private const URL_PROPERTIES = ['X-EMPREINTE-ADMIN-URL', 'X-EMPREINTE-PARTICIPANT-URL', 'LOCATION', 'DESCRIPTION'];

	/** Cherche un liveId dans les proprietes EMPREINTE, sinon dans LOCATION/DESCRIPTION. */
	public static function fromIcs(string $ics): ?string {
		if ($ics === '') {
			return null;
		}
		try {
			$vobject = Reader::read($ics);
		} catch (Throwable $e) {
			return null; // ICS illisible : on ignore.
		}

		foreach ($vobject->getComponents() as $component) {
			if ($component->name !== 'VEVENT') {
				continue;
			}
			foreach (self::URL_PROPERTIES as $prop) {
				$value = $component->{$prop};
				if ($value === null) {
					continue;
				}
				$liveId = self::fromUrl((string)$value);
				if ($liveId !== null) {
					return $liveId;
				}
			}
		}
		return null;
	}

	/**
	 * Extrait l'identifiant numerique d'un Live depuis une URL EMPREINTE.
	 * Porte de src/utils/empreinteMeeting.js (extractLiveIdFromUrl).
	 */
	public static function fromUrl(string $url): ?string {
		if ($url === '') {
			return null;
		}
		$patterns = [
			'/[?&]room=(\d+)(?:-|&|$)/i',
			'#meet\.empreinte\.live/(\d+)#',
			'#empreinte\.live/live/(\d+)#',
			'#empreinte\.live/(\d+)#',
			'#join\.empreinte\.live/[^?\s]*[?&]room=(\d+)#i',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $url, $matches) === 1 && isset($matches[1])) {
				return $matches[1];
			}
		}
		return null;
	}
}
