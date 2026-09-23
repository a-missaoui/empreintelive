<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Liens de partage sur les fichiers du dossier
 * lie a une reunion. L'utilisateur vient de la session ; les droits sont ceux de
 * Nextcloud (verifies dans ShareService via getUserFolder).
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Exception\ShareNotAllowedException;
use OCA\EmpreinteLive\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class ShareController extends Controller {
	public function __construct(
		IRequest $request,
		private ShareService $shares,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $fileId): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['shares' => $this->shares->listLinks($userId, $fileId)]);
	}

	#[NoAdminRequired]
	public function create(
		int $fileId,
		bool $editable = false,
		?string $password = null,
		?string $expiration = null,
	): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$share = $this->shares->createLink($userId, $fileId, [
				'editable' => $editable,
				'password' => $password,
				'expiration' => $expiration,
			]);
		} catch (ShareNotAllowedException $e) {
			// Le message porte un code stable, traduit cote frontend. L'indice, lui,
			// vient de Nextcloud et est deja lisible : on le transmet tel quel.
			return new JSONResponse(
				array_filter(['error' => $e->getMessage(), 'hint' => $e->getHint()]),
				Http::STATUS_FORBIDDEN,
			);
		} catch (Throwable $e) {
			$this->logger->error('Creation du lien de partage impossible', ['exception' => $e]);

			return new JSONResponse(['error' => 'share_failed'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($share);
	}

	#[NoAdminRequired]
	public function destroy(string $shareId): JSONResponse {
		$userId = $this->userId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->shares->deleteLink($userId, $shareId);
		} catch (ShareNotAllowedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['deleted' => true]);
	}

	private function userId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}
}
