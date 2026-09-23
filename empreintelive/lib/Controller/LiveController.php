<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Endpoints de gestion des Lives (couche fine : delegue a EmpreinteApiService).
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Service\CalendarEventService;
use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\MeetingCreationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

class LiveController extends Controller {
	public function __construct(
		IRequest $request,
		private EmpreinteApiService $api,
		private MeetingCreationService $meetings,
		private CalendarEventService $calendarEvents,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No user session');
		}
		return $user->getUID();
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		try {
			return new JSONResponse(['data' => $this->api->getMeetings($this->userId())]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * @param list<string>|array<mixed> $attendees Emails des participants (autocompletion)
	 */
	#[NoAdminRequired]
	public function create(
		string $title = '',
		string $description = '',
		string $startTime = '',
		string $endTime = '',
		array $attendees = [],
	): JSONResponse {
		try {
			// La sequence (Live, evenement calendrier, invitations) est partagee avec
			// l'action « Reunion sur ce document » de l'app Files.
			return new JSONResponse($this->meetings->create(
				$this->userId(),
				$title,
				$description,
				$startTime,
				$endTime,
				$attendees,
			));
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function update(
		string $id,
		string $title = '',
		string $description = '',
		string $startTime = '',
		string $endTime = '',
	): JSONResponse {
		try {
			$live = $this->api->updateLive($this->userId(), $id, [
				'title' => $title,
				'description' => $description,
				'startTime' => $startTime,
				'endTime' => $endTime,
			]);
			return new JSONResponse(['data' => $live]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		try {
			$userId = $this->userId();
			$this->api->deleteLive($userId, $id);
			// Sens live -> calendrier : retire l'evenement-miroir (best-effort).
			$this->calendarEvents->deleteEventForLive($userId, $id);
			return new JSONResponse(['success' => true]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}
}
