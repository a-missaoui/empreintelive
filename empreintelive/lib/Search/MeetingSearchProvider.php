<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Recherche unifiee de Nextcloud : les reunions de l'utilisateur, trouvees par
 * leur titre. Un resultat ouvre la reunion sur la page de l'app, en organisateur.
 *
 * Volontairement absent du selecteur de liens : il y insererait le lien de la
 * page de l'app, inutilisable par les participants. Le selecteur passe par son
 * element personnalise, qui insere le lien participant.
 */

namespace OCA\EmpreinteLive\Search;

use DateTimeImmutable;
use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\TokenService;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_slice;
use function is_string;
use function mb_stripos;
use function str_starts_with;
use function trim;
use function usort;

class MeetingSearchProvider implements IProvider {
	public function __construct(
		private EmpreinteApiService $api,
		private TokenService $tokens,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private IDateTimeFormatter $dateFormatter,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'empreintelive-meetings';
	}

	public function getName(): string {
		return $this->l10n->t('EMPREINTE Live meetings');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		// En tete sur la page de l'app, apres les resultats usuels ailleurs.
		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 60;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		$userId = $user->getUID();
		if ($term === '' || !$this->tokens->hasToken($userId)) {
			return SearchResult::complete($this->getName(), []);
		}

		try {
			$meetings = $this->api->getMeetings($userId);
		} catch (Throwable $e) {
			// Recherche globale : une panne EMPREINTE ne doit pas la casser.
			$this->logger->debug('Recherche de reunions indisponible', ['exception' => $e]);
			return SearchResult::complete($this->getName(), []);
		}

		$matches = [];
		foreach ($meetings as $meeting) {
			$title = (string)($meeting['title'] ?? '');
			if ($title !== '' && mb_stripos($title, $term) !== false) {
				$matches[] = $meeting;
			}
		}
		// Les plus recentes d'abord : c'est la reunion du jour qu'on cherche.
		usort($matches, fn (array $a, array $b): int => $this->startOf($b) <=> $this->startOf($a));

		$icon = $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
		$entries = [];
		foreach (array_slice($matches, 0, $query->getLimit()) as $meeting) {
			$id = (string)($meeting['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$entries[] = new SearchResultEntry(
				'',
				(string)$meeting['title'],
				$this->subline($meeting),
				$this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index', ['live' => $id]),
				$icon,
			);
		}
		return SearchResult::complete($this->getName(), $entries);
	}

	/**
	 * @param array<string,mixed> $meeting
	 */
	private function subline(array $meeting): string {
		$start = $this->startOf($meeting);
		// Fuseau et langue de l'utilisateur, pas ceux du serveur.
		return $start === 0
			? $this->l10n->t('Video meeting')
			: $this->dateFormatter->formatDateTime($start, 'medium', 'short');
	}

	/**
	 * @param array<string,mixed> $meeting
	 */
	private function startOf(array $meeting): int {
		$raw = $meeting['dateStartDiffusion'] ?? null;
		if (!is_string($raw) || $raw === '') {
			return 0;
		}
		try {
			return (new DateTimeImmutable($raw))->getTimestamp();
		} catch (Throwable) {
			return 0;
		}
	}
}
