<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lecture du dossier Nextcloud lie a une reunion.
 *
 * Tout passe par getUserFolder($userId) : c'est Nextcloud qui applique ses propres
 * permissions. On ne reimplemente AUCUN controle d'acces : pas de duplication de
 * la gestion des droits.
 *
 * Consequence directe : un participant qui n'a pas acces au dossier lie recoit
 * simplement accessible=false. Le dossier est identifie par son ID interne, donc
 * il suit les renommages et les deplacements.
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\Db\LiveFolder;
use OCA\EmpreinteLive\Exception\LiveFolderConflictException;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IPreview;
use RuntimeException;
use function ltrim;
use function preg_replace;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

class DocumentService {
	/** Dossier parent des dossiers crees automatiquement. */
	private const ROOT_NAME = 'EMPREINTE Live';

	public function __construct(
		private IRootFolder $rootFolder,
		private LiveFolderService $links,
		private IPreview $preview,
	) {
	}

	/**
	 * Chemin propose par defaut : « /EMPREINTE Live/<titre de la reunion>/ ».
	 * L'utilisateur reste libre d'en choisir un autre.
	 */
	public function suggestedPath(string $title): string {
		$clean = trim(preg_replace('#[/\\\\:*?"<>|]+#', ' ', $title) ?? '');
		$clean = trim(preg_replace('#\s+#', ' ', $clean) ?? '');

		return '/' . self::ROOT_NAME . '/' . ($clean !== '' ? $clean : 'Reunion');
	}

	/**
	 * Lie un dossier a la reunion, en le creant s'il n'existe pas encore.
	 *
	 * @throws NotPermittedException si l'utilisateur ne peut pas creer le dossier
	 * @throws RuntimeException si le chemin designe un fichier
	 */
	public function linkFolder(string $userId, string $liveId, string $path): LiveFolder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$path = '/' . trim($path, '/');

		// L'espace personnel entier n'est pas un dossier de reunion : il serait
		// affiche a tous les participants qui y ont acces, et partageable.
		if ($path === '/') {
			throw new RuntimeException('root_not_allowed');
		}

		if ($userFolder->nodeExists($path)) {
			$node = $userFolder->get($path);
			if (!$node instanceof Folder) {
				throw new RuntimeException('Le chemin designe un fichier, pas un dossier.');
			}
		} else {
			// newFolder cree les parents manquants et leve NotPermittedException si
			// l'utilisateur n'a pas le droit d'ecrire a cet endroit.
			$node = $userFolder->newFolder($path);
		}

		return $this->links->link($userId, $liveId, $node->getId());
	}

	/**
	 * Dossier ou deposer un enregistrement de la reunion, vu par cet utilisateur.
	 *
	 * Le dossier lie s'il est accessible en ecriture ; sinon « /EMPREINTE Live/<titre> »
	 * dans l'espace de l'utilisateur, lie a la reunion si elle n'a encore aucun
	 * dossier (l'enregistrement apparait alors dans le panneau documentaire).
	 *
	 * @return array{path: string, linked: bool}
	 * @throws NotPermittedException si l'utilisateur ne peut rien creer
	 */
	public function recordingFolder(string $userId, string $liveId, string $title): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$link = $this->links->find($liveId);

		if ($link !== null) {
			$folder = $this->resolveFolder($userId, $link->getFolderId());
			if ($folder !== null
				&& $folder->getPath() !== $userFolder->getPath()
				&& ($folder->getPermissions() & Constants::PERMISSION_CREATE) !== 0) {
				return ['path' => $userFolder->getRelativePath($folder->getPath()) ?? '/', 'linked' => true];
			}
		}

		$path = $this->suggestedPath($title);
		$node = $userFolder->nodeExists($path) ? $userFolder->get($path) : $userFolder->newFolder($path);
		if (!$node instanceof Folder) {
			throw new NotPermittedException('Le chemin designe un fichier, pas un dossier.');
		}

		$linked = false;
		if ($link === null) {
			try {
				$this->links->link($userId, $liveId, $node->getId());
				$linked = true;
			} catch (LiveFolderConflictException) {
				// Lie entre-temps par quelqu'un d'autre : on garde le dossier personnel.
			}
		}

		return ['path' => $path, 'linked' => $linked];
	}

	public function unlink(string $userId, string $liveId): bool {
		return $this->links->unlink($userId, $liveId);
	}

	/**
	 * Etat du panneau documentaire pour une reunion.
	 *
	 * @param string $subPath sous-dossier relatif au dossier lie (navigation)
	 * @return array<string,mixed>
	 */
	public function describe(string $userId, string $liveId, string $subPath = ''): array {
		$link = $this->links->find($liveId);
		if ($link === null) {
			return $this->emptyState(false, null);
		}

		$root = $this->resolveFolder($userId, $link->getFolderId());
		if ($root === null) {
			// Deux situations tres differentes, a ne pas confondre dans l'interface :
			// soit le dossier existe et je n'y ai pas acces, soit il a disparu.
			// On tranche en regardant si le PROPRIETAIRE du lien le voit encore.
			if ($this->resolveFolder($link->getOwnerUid(), $link->getFolderId()) === null) {
				// Disparu : on delie, sinon le panneau reste bloque sur « non
				// accessible » sans jamais reproposer d'associer un dossier.
				$this->links->unlink($link->getOwnerUid(), $liveId);

				return $this->emptyState(false, 'folder_missing');
			}

			return $this->emptyState(true, 'no_access');
		}

		// Lien pose avant ce garde-fou vers la racine de l'utilisateur : on ne
		// l'affiche pas, on le retire et on repropose d'associer un dossier.
		if ($link->getOwnerUid() === $userId
			&& $root->getPath() === $this->rootFolder->getUserFolder($userId)->getPath()) {
			$this->links->unlink($userId, $liveId);

			return $this->emptyState(false, null);
		}

		$current = $this->resolveSubFolder($root, $subPath);
		if ($current === null) {
			return $this->emptyState(true, 'no_access');
		}

		$items = [];
		foreach ($current->getDirectoryListing() as $child) {
			$items[] = $this->describeNode($child, $root);
		}

		return [
			'linked' => true,
			'accessible' => true,
			'isOwner' => $link->getOwnerUid() === $userId,
			'folder' => [
				'id' => $root->getId(),
				'name' => $root->getName(),
				'path' => $this->relativePath($root, $root),
				// Permet le bouton « Lien du dossier » : un lien unique qui donne acces
				// a tout le dossier, y compris aux participants sans compte Nextcloud.
				'canShare' => ($root->getPermissions() & Constants::PERMISSION_SHARE) !== 0,
			],
			'subPath' => $this->relativePath($current, $root),
			'canWrite' => ($current->getPermissions() & Constants::PERMISSION_CREATE) !== 0,
			'items' => $items,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function emptyState(bool $linked, ?string $reason): array {
		return [
			'linked' => $linked,
			'accessible' => false,
			'folder' => null,
			'items' => [],
			'reason' => $reason,
		];
	}

	/** Noeud dossier correspondant a un ID, vu par cet utilisateur, ou null. */
	private function resolveFolder(string $userId, int $folderId): ?Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			return null;
		}

		// getById ne renvoie que les noeuds accessibles a cet utilisateur : c'est
		// precisement le controle d'acces qu'on ne veut pas ecrire nous-memes.
		foreach ($userFolder->getById($folderId) as $node) {
			if ($node instanceof Folder) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Sous-dossier de navigation, avec garde anti-traversee : le noeud obtenu doit
	 * rester strictement sous le dossier lie.
	 */
	private function resolveSubFolder(Folder $root, string $subPath): ?Folder {
		$subPath = trim($subPath, '/');
		if ($subPath === '') {
			return $root;
		}

		try {
			$node = $root->get($subPath);
		} catch (NotFoundException | NotPermittedException) {
			return null;
		}

		if (!$node instanceof Folder) {
			return null;
		}

		$rootPath = rtrim($root->getPath(), '/') . '/';
		if (!str_starts_with($node->getPath(), $rootPath)) {
			return null;
		}

		return $node;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function describeNode(Node $node, Folder $root): array {
		$isFolder = $node instanceof Folder;
		$permissions = $node->getPermissions();

		$data = [
			'id' => $node->getId(),
			'name' => $node->getName(),
			'path' => $this->relativePath($node, $root),
			'type' => $isFolder ? 'dir' : 'file',
			'mtime' => $node->getMTime(),
			'canWrite' => ($permissions & Constants::PERMISSION_UPDATE) !== 0,
			'canDelete' => ($permissions & Constants::PERMISSION_DELETE) !== 0,
			'canShare' => ($permissions & Constants::PERMISSION_SHARE) !== 0,
		];

		if ($node instanceof File) {
			$data['size'] = $node->getSize();
			$data['mime'] = $node->getMimeType();
			$data['hasPreview'] = $this->preview->isAvailable($node);
		}

		return $data;
	}

	/** Chemin du noeud relatif au dossier lie (jamais un chemin absolu cote serveur). */
	private function relativePath(Node $node, Folder $root): string {
		$rootPath = rtrim($root->getPath(), '/');
		$nodePath = $node->getPath();

		if ($nodePath === $rootPath) {
			return '';
		}

		return ltrim(str_starts_with($nodePath, $rootPath . '/')
			? substr($nodePath, strlen($rootPath) + 1)
			: $node->getName(), '/');
	}
}
