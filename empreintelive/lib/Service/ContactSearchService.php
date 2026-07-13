<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Autocompletion des participants : recherche d'utilisateurs / contacts Nextcloud
 * par nom ou email via l'API publique OCP\Contacts (c'est ce que le Calendar
 * officiel utilise sous le capot pour son champ "Attendees"). L'app Calendar
 * n'est jamais sollicitee directement -> architecture inchangee.
 */

namespace OCA\EmpreinteLive\Service;

use OCP\Contacts\IManager;
use function is_array;

class ContactSearchService {
	/** Nombre maximum de suggestions renvoyees. */
	private const LIMIT = 20;

	public function __construct(
		private IManager $contacts,
	) {
	}

	/**
	 * Recherche des personnes (nom + email) correspondant a la requete.
	 *
	 * @return list<array{name:string, email:string}> Suggestions dedupliquees par email
	 */
	public function search(string $query): array {
		$query = trim($query);
		if ($query === '') {
			return [];
		}

		$results = $this->contacts->search($query, ['FN', 'EMAIL'], ['limit' => self::LIMIT]);

		$out = [];
		$seen = [];
		foreach ($results as $card) {
			$name = (string)($card['FN'] ?? '');
			foreach ($this->emailsOf($card) as $email) {
				$key = strtolower($email);
				if ($email === '' || isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$out[] = ['name' => $name !== '' ? $name : $email, 'email' => $email];
			}
		}
		return $out;
	}

	/**
	 * Un contact peut porter un email (string) ou plusieurs (array).
	 *
	 * @param array<string,mixed> $card
	 * @return list<string>
	 */
	private function emailsOf(array $card): array {
		$email = $card['EMAIL'] ?? null;
		if (is_array($email)) {
			return array_values(array_map('strval', $email));
		}
		if (is_string($email) && $email !== '') {
			return [$email];
		}
		return [];
	}
}
