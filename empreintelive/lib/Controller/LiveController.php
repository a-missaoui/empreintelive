<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lot 3/4 - Endpoints de gestion des Lives (couche fine : delegue a EmpreinteApiService).
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Service\CalendarEventService;
use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\OAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_values;
use function filter_var;
use function is_array;

class LiveController extends Controller {
	public function __construct(
		IRequest $request,
		private EmpreinteApiService $api,
		private CalendarEventService $calendarEvents,
		private OAuthService $oauth,
		private IUserSession $userSession,
		private LoggerInterface $logger,
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
			$userId = $this->userId();
			$emails = $this->sanitizeEmails($attendees);
			$data = [
				'title' => $title,
				'description' => $description,
				'startTime' => $startTime,
				'endTime' => $endTime,
				'attendees' => $emails,
			];
			$live = $this->api->createLive($userId, $data);
			// Reverse flow : on ecrit aussi l'evenement dans le calendrier Nextcloud
			// (best-effort, sans jamais toucher l'app Calendar officielle). Les
			// participants y sont ajoutes en ATTENDEE -> le planificateur natif
			// gere l'envoi des invitations et l'affichage des statuts. L'email du
			// compte EMPREINTE sert a poser le lien organisateur (admin_url) pour le
			// createur, plutot que le lien participant.
			$accountEmail = $this->oauth->getAccountEmail($userId);
			$eventUid = $this->calendarEvents->createEventForLive($userId, $live, $data, $accountEmail);
			// Invitations cote EMPREINTE (complementaire, best-effort).
			$invited = $this->sendInvitations($userId, $live, $emails);
			return new JSONResponse([
				'data' => $live,
				'eventCreated' => $eventUid !== null,
				'invited' => $invited,
			]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * Envoie les invitations EMPREINTE. Best-effort : ne fait jamais echouer la
	 * creation de la visio (deja creee cote EMPREINTE).
	 *
	 * @param array<string,mixed> $live
	 * @param list<string> $emails
	 */
	private function sendInvitations(string $userId, array $live, array $emails): bool {
		$liveId = isset($live['id']) ? (string)$live['id'] : '';
		$organiser = $this->oauth->getAccountEmail($userId);
		if ($liveId === '' || $organiser === '' || $emails === []) {
			return false;
		}
		try {
			$this->api->sendInvitations($userId, $liveId, [
				'organiserEmail' => $organiser,
				'participantEmails' => $emails,
			]);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('Echec de l\'envoi des invitations EMPREINTE', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Filtre une liste d'emails : ne garde que les adresses valides, dedupliquees.
	 *
	 * @param array<mixed> $attendees
	 * @return list<string>
	 */
	private function sanitizeEmails(array $attendees): array {
		$out = [];
		$seen = [];
		foreach ($attendees as $entry) {
			// Tolere une liste de chaines OU une liste d'objets {email: ...}.
			$email = is_array($entry) ? (string)($entry['email'] ?? '') : (string)$entry;
			$email = trim($email);
			$key = strtolower($email);
			if ($email === '' || isset($seen[$key]) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $email;
		}
		return array_values($out);
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
