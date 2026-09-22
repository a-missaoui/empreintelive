<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Domaines hebergeant le lecteur de reunion EMPREINTE. Une seule source pour la
 * CSP de l'iframe et pour les origines dont la page accepte les messages
 * (depot d'un enregistrement).
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\AppInfo\Application;
use OCP\IConfig;
use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function trim;

class MeetingDomainService {
	/**
	 * Surchargeables via la config
	 * (occ config:app:set empreintelive meeting_domains --value="https://a,https://b")
	 * pour les instances qui pointent vers un autre environnement EMPREINTE.
	 */
	public const DEFAULT_DOMAINS = [
		'https://join.empreinte.live',
		'https://meet.empreinte.live',
	];

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @return string[]
	 */
	public function domains(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'meeting_domains', '');
		if (trim($raw) === '') {
			return self::DEFAULT_DOMAINS;
		}

		$parsed = array_filter(array_map('trim', explode(',', $raw)));

		return $parsed === [] ? self::DEFAULT_DOMAINS : array_values($parsed);
	}
}
