<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Liens de partage Nextcloud sur les fichiers
 * du dossier lie a une reunion.
 *
 * Lecture seule, mot de passe et expiration sont NATIFS dans Share\IManager :
 * aucune logique de droits n'est ecrite ici. On respecte aussi les reglages
 * d'instance (partage par lien autorise, mot de passe impose, expiration par
 * defaut) plutot que de les contourner.
 */

namespace OCA\EmpreinteLive\Service;

use DateTime;
use OCA\EmpreinteLive\Exception\ShareNotAllowedException;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\HintException;
use OCP\IURLGenerator;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use function trim;

class ShareService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IManager $shareManager,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * Cree un lien de partage sur un fichier ou dossier.
	 *
	 * @param array{editable?:bool, password?:?string, expiration?:?string} $options
	 * @return array<string,mixed>
	 * @throws ShareNotAllowedException
	 */
	public function createLink(string $userId, int $fileId, array $options = []): array {
		if (!$this->shareManager->shareApiAllowLinks()) {
			throw new ShareNotAllowedException('links_disabled');
		}

		$node = $this->resolveNode($userId, $fileId);
		if ($node === null) {
			throw new ShareNotAllowedException('not_found');
		}

		// Le droit de partage vient de Nextcloud, pas de nous.
		if (($node->getPermissions() & Constants::PERMISSION_SHARE) === 0) {
			throw new ShareNotAllowedException('not_shareable');
		}

		$password = trim((string)($options['password'] ?? ''));
		if ($password === '' && $this->shareManager->shareApiLinkEnforcePassword()) {
			throw new ShareNotAllowedException('password_required');
		}

		$share = $this->shareManager->newShare();
		$share->setNode($node);
		$share->setShareType(IShare::TYPE_LINK);
		$share->setSharedBy($userId);
		$share->setPermissions($this->permissionsFor($node, (bool)($options['editable'] ?? false)));

		if ($password !== '') {
			$share->setPassword($password);
		}

		$expiration = $this->expirationDate($options['expiration'] ?? null);
		if ($expiration !== null) {
			$share->setExpirationDate($expiration);
		}

		try {
			$created = $this->shareManager->createShare($share);
		} catch (HintException $e) {
			// Politique de mot de passe, quota de partage... : ce sont des erreurs
			// utilisateur, pas des pannes. Nextcloud fournit deja un message clair.
			throw new ShareNotAllowedException('rejected', $e->getHint());
		}

		return $this->describe($created);
	}

	/**
	 * Liens de partage existants poses par cet utilisateur sur ce fichier.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function listLinks(string $userId, int $fileId): array {
		$node = $this->resolveNode($userId, $fileId);
		if ($node === null) {
			return [];
		}

		$out = [];
		foreach ($this->shareManager->getSharesBy($userId, IShare::TYPE_LINK, $node, false, 50) as $share) {
			$out[] = $this->describe($share);
		}

		return $out;
	}

	/**
	 * Supprime un lien. Refuse si le partage n'a pas ete cree par cet utilisateur.
	 *
	 * @throws ShareNotAllowedException
	 */
	public function deleteLink(string $userId, string $shareId): void {
		try {
			$share = $this->shareManager->getShareById($shareId);
		} catch (ShareNotFound) {
			throw new ShareNotAllowedException('not_found');
		}

		if ($share->getSharedBy() !== $userId) {
			throw new ShareNotAllowedException('not_owner');
		}

		$this->shareManager->deleteShare($share);
	}

	private function resolveNode(string $userId, int $fileId): ?Node {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			return null;
		}

		// getById ne rend que ce que cet utilisateur peut voir.
		$nodes = $userFolder->getById($fileId);

		return $nodes[0] ?? null;
	}

	/**
	 * Lecture seule par defaut. En mode modifiable, un dossier recoit aussi le
	 * droit de creer et supprimer, un fichier seulement celui de mettre a jour.
	 */
	private function permissionsFor(Node $node, bool $editable): int {
		if (!$editable) {
			return Constants::PERMISSION_READ;
		}

		if ($node instanceof Folder) {
			return Constants::PERMISSION_READ
				| Constants::PERMISSION_CREATE
				| Constants::PERMISSION_UPDATE
				| Constants::PERMISSION_DELETE;
		}

		return Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE;
	}

	/** Date fournie, sinon expiration par defaut de l'instance, sinon aucune. */
	private function expirationDate(?string $raw): ?DateTime {
		$raw = trim((string)$raw);
		if ($raw !== '') {
			$date = DateTime::createFromFormat('Y-m-d', $raw);
			if ($date === false) {
				return null;
			}
			$date->setTime(0, 0, 0);

			return $date;
		}

		if ($this->shareManager->shareApiLinkDefaultExpireDate()) {
			$days = $this->shareManager->shareApiLinkDefaultExpireDays();
			$date = new DateTime();
			$date->setTime(0, 0, 0);
			$date->modify('+' . $days . ' days');

			return $date;
		}

		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function describe(IShare $share): array {
		$expiration = $share->getExpirationDate();

		return [
			// getShareById() attend l'identifiant COMPLET (« ocinternal:42 ») : c'est
			// donc celui-la qu'on expose, pour que la suppression fonctionne.
			'id' => $share->getFullId(),
			'token' => $share->getToken(),
			'url' => $this->urlGenerator->linkToRouteAbsolute(
				'files_sharing.Share.showShare',
				['token' => $share->getToken()],
			),
			'editable' => ($share->getPermissions() & Constants::PERMISSION_UPDATE) !== 0,
			'hasPassword' => $share->getPassword() !== null && $share->getPassword() !== '',
			'expiration' => $expiration?->format('Y-m-d'),
		];
	}
}
