<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regles metier du lien Live <-> dossier Nextcloud.
 *
 * Le lien appartient a la REUNION, pas au participant : un Live pointe vers un
 * seul dossier, que tous ses participants voient. Seul le compte qui a pose le
 * lien peut le modifier ou le retirer ; les autres peuvent le lire.
 *
 * Aucun controle d'acces au dossier lui-meme ici : c'est DocumentService qui s'en
 * noeud via IRootFolder au nom de l'utilisateur, que Nextcloud applique ses
 * propres permissions. On ne les reimplemente pas.
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\Db\LiveFolder;
use OCA\EmpreinteLive\Db\LiveFolderMapper;
use OCA\EmpreinteLive\Exception\LiveFolderConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

class LiveFolderService {
	public function __construct(
		private LiveFolderMapper $mapper,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Lie un Live a un dossier, ou met a jour le lien existant.
	 * Idempotent : relier au meme dossier n'ecrit rien.
	 *
	 * @throws LiveFolderConflictException si le Live est deja lie par un autre compte
	 */
	public function link(string $userId, string $liveId, int $folderId): LiveFolder {
		$existing = $this->find($liveId);

		if ($existing === null) {
			$entity = new LiveFolder();
			$entity->setLiveId($liveId);
			$entity->setFolderId($folderId);
			$entity->setOwnerUid($userId);
			$entity->setCreatedAt($this->timeFactory->getTime());

			return $this->mapper->insert($entity);
		}

		if ($existing->getOwnerUid() !== $userId) {
			throw new LiveFolderConflictException(
				'Le Live ' . $liveId . ' est deja lie a un dossier par un autre compte.'
			);
		}

		if ($existing->getFolderId() === $folderId) {
			return $existing;
		}

		$existing->setFolderId($folderId);

		return $this->mapper->update($existing);
	}

	/**
	 * Lien d'un Live, sans controle d'appartenance. L'appelant doit avoir verifie
	 * au prealable que l'utilisateur a bien acces a cette reunion.
	 */
	public function find(string $liveId): ?LiveFolder {
		try {
			return $this->mapper->findByLiveId($liveId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Lien d'un Live, uniquement s'il a ete pose par cet utilisateur.
	 */
	public function findOwned(string $userId, string $liveId): ?LiveFolder {
		$entity = $this->find($liveId);

		return ($entity !== null && $entity->getOwnerUid() === $userId) ? $entity : null;
	}

	/**
	 * Retire le lien. Sans effet si absent, ou s'il appartient a un autre compte.
	 *
	 * @return bool true si un lien a effectivement ete retire
	 */
	public function unlink(string $userId, string $liveId): bool {
		$entity = $this->findOwned($userId, $liveId);
		if ($entity === null) {
			return false;
		}

		$this->mapper->delete($entity);

		return true;
	}

	/**
	 * Retire les liens pointant vers un dossier disparu. Appele par le listener, sur
	 * NodeDeletedEvent. Ne supprime JAMAIS quoi que ce soit cote EMPREINTE : la
	 * reunion survit a la disparition de son dossier, comme dans le volet
	 * calendrier ou elle survit a la suppression de son evenement.
	 *
	 * @return int nombre de liens retires
	 */
	public function unlinkByFolder(int $folderId): int {
		$removed = 0;
		foreach ($this->mapper->findByFolderId($folderId) as $entity) {
			$this->mapper->delete($entity);
			$removed++;
		}

		return $removed;
	}
}
