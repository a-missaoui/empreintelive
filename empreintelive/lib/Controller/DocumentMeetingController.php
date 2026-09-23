<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Points d'entree de l'action « Reunion sur ce document », depuis l'app Files.
 * L'utilisateur vient de la session ; les droits sur le fichier sont ceux de
 * Nextcloud, verifies dans le service.
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Exception\LiveDocumentException;
use OCA\EmpreinteLive\Service\DocumentMeetingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class DocumentMeetingController extends Controller {
	public function __construct(
		IRequest $request,
		private DocumentMeetingService $documentMeetings,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Ce qu'on pre-remplit dans la boite de dialogue : titre tire du nom du
	 * fichier, et personnes avec qui il est deja partage.
	 */
	#[NoAdminRequired]
	public function suggest(int $fileId): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->documentMeetings->suggest($userId, $fileId));
		} catch (LiveDocumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Cree la reunion, y charge le document, et associe le dossier parent.
	 *
	 * @param array<mixed> $attendees
	 */
	#[NoAdminRequired]
	public function create(
		int $fileId,
		string $title = '',
		string $description = '',
		string $startTime = '',
		string $endTime = '',
		array $attendees = [],
	): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->documentMeetings->createForFile(
				$userId,
				$fileId,
				$title,
				$description,
				$startTime,
				$endTime,
				$attendees,
			));
		} catch (LiveDocumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Creation de la reunion depuis un document impossible', ['exception' => $e]);

			return new JSONResponse(['error' => 'create_failed'], Http::STATUS_BAD_GATEWAY);
		}
	}

	private function userId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}
}
