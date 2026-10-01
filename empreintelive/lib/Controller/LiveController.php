<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Endpoints de gestion des Lives (couche fine : delegue a EmpreinteApiService).
 *
 * Les echecs repondent 500, jamais 502 : en production, Cloudflare remplace toute
 * reponse 502 de l'origine par sa propre page d'erreur, et le message de l'API
 * n'atteindrait jamais l'interface.
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Exception\NotConnectedException;
use OCA\EmpreinteLive\Service\CalendarEventService;
use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\MeetingCreationService;
use OCA\EmpreinteLive\Service\MemberInvitationService;
use OCA\EmpreinteLive\Service\TokenService;
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
		private MemberInvitationService $memberInvitations,
		private TokenService $tokens,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Compte EMPREINTE absent ou refuse par l'API (compte supprime, jeton expire) :
	 * l'interface propose alors la connexion, avec le motif de l'API, au lieu d'un
	 * simple message d'echec. Meme reponse que DocumentMeetingController.
	 */
	private function notConnected(NotConnectedException $e): JSONResponse {
		return new JSONResponse(['error' => 'not_connected', 'message' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
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
		} catch (NotConnectedException $e) {
			return $this->notConnected($e);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
		} catch (NotConnectedException $e) {
			return $this->notConnected($e);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
		} catch (NotConnectedException $e) {
			return $this->notConnected($e);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Invite les membres d'une conversation Talk, lus par le navigateur avec les
	 * droits Talk de l'utilisateur. Reserve au createur de la reunion.
	 *
	 * @param list<string>|array<mixed> $userIds comptes Nextcloud membres du salon
	 * @param list<string>|array<mixed> $emails  invites du salon par adresse
	 */
	#[NoAdminRequired]
	public function inviteMembers(string $id, array $userIds = [], array $emails = []): JSONResponse {
		try {
			$userId = $this->userId();
			if (!$this->tokens->hasToken($userId)) {
				return new JSONResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
			}
			$result = $this->memberInvitations->invite($userId, $id, $userIds, $emails);
			if ($result === null) {
				return new JSONResponse(['error' => 'not_your_meeting'], Http::STATUS_FORBIDDEN);
			}
			return new JSONResponse($result);
		} catch (NotConnectedException $e) {
			return $this->notConnected($e);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
		} catch (NotConnectedException $e) {
			return $this->notConnected($e);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
