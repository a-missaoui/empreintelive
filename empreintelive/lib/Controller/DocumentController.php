<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Points d'entree du panneau documentaire.
 *
 * L'utilisateur vient TOUJOURS de la session Nextcloud, jamais du corps de la
 * requete. Aucun controle d'acces aux fichiers n'est ecrit ici : DocumentService
 * lit via getUserFolder(), donc Nextcloud applique ses propres permissions.
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Exception\LiveDocumentException;
use OCA\EmpreinteLive\Exception\LiveFolderConflictException;
use OCA\EmpreinteLive\Service\DocumentService;
use OCA\EmpreinteLive\Service\LiveDocumentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class DocumentController extends Controller {
	public function __construct(
		IRequest $request,
		private DocumentService $documents,
		private LiveDocumentService $liveDocuments,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Dossier de depot d'un enregistrement. Le navigateur y envoie ensuite le
	 * fichier en WebDAV, avec la session de l'utilisateur.
	 */
	#[NoAdminRequired]
	public function recordingFolder(string $liveId, string $title = ''): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->documents->recordingFolder($userId, $liveId, $title));
		} catch (NotPermittedException) {
			return new JSONResponse(['error' => 'not_permitted'], Http::STATUS_FORBIDDEN);
		} catch (Throwable $e) {
			$this->logger->error('Dossier d\'enregistrement indisponible', ['exception' => $e]);

			return new JSONResponse(['error' => 'recording_folder_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Etat du panneau : dossier lie, droits, contenu du sous-dossier courant.
	 */
	#[NoAdminRequired]
	public function show(string $liveId, string $path = ''): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->documents->describe($userId, $liveId, $path));
		} catch (Throwable $e) {
			$this->logger->error('Lecture du dossier lie impossible', ['exception' => $e]);

			return new JSONResponse(['error' => 'read_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Chemin propose par defaut pour une reunion, modifiable ensuite.
	 */
	#[NoAdminRequired]
	public function suggest(string $liveId, string $title = ''): JSONResponse {
		if ($this->userId() === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['path' => $this->documents->suggestedPath($title)]);
	}

	/**
	 * Lie un dossier a la reunion, en le creant si besoin.
	 */
	#[NoAdminRequired]
	public function link(string $liveId, string $path): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if (trim($path, " /\t") === '') {
			return new JSONResponse(['error' => 'empty_path'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->documents->linkFolder($userId, $liveId, $path);
		} catch (LiveFolderConflictException $e) {
			return new JSONResponse(['error' => 'already_linked'], Http::STATUS_CONFLICT);
		} catch (NotPermittedException $e) {
			return new JSONResponse(['error' => 'not_permitted'], Http::STATUS_FORBIDDEN);
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'root_not_allowed') {
				return new JSONResponse(['error' => 'root_not_allowed'], Http::STATUS_BAD_REQUEST);
			}
			$this->logger->error('Liaison du dossier impossible', ['exception' => $e]);

			return new JSONResponse(['error' => 'link_failed'], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Liaison du dossier impossible', ['exception' => $e]);

			return new JSONResponse(['error' => 'link_failed'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->documents->describe($userId, $liveId));
	}

	/**
	 * Retire le lien. Le dossier et son contenu ne sont jamais supprimes.
	 */
	#[NoAdminRequired]
	public function unlink(string $liveId): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['unlinked' => $this->documents->unlink($userId, $liveId)]);
	}

	/**
	 * Liste des medias deja presents dans la reunion (cote EMPREINTE).
	 */
	#[NoAdminRequired]
	public function medias(string $liveId): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->liveDocuments->listDocuments($userId, $liveId));
	}

	/**
	 * Envoie un fichier Nextcloud dans la liste des medias de la reunion, pour
	 * qu'il y soit presente.
	 */
	#[NoAdminRequired]
	public function sendMedia(string $liveId, int $fileId): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->liveDocuments->sendDocument($userId, $liveId, $fileId));
		} catch (LiveDocumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Envoi du media impossible', ['exception' => $e]);

			return new JSONResponse(['error' => 'upload_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Retire un document de la reunion. Le fichier Nextcloud d'origine n'est pas
	 * touche : on ne supprime que la copie presentee cote EMPREINTE.
	 */
	#[NoAdminRequired]
	public function deleteMedia(string $liveId, string $docIndex): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->liveDocuments->deleteDocument($userId, $liveId, $docIndex));
		} catch (LiveDocumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	private function userId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}
}
