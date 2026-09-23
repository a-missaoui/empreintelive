<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Sequence de creation d'une visioconference, partagee par tous les points
 * d'entree : la page de l'app, et l'action « Reunion sur ce document » de Files.
 *
 * Extraite de LiveController pour ne pas etre recopiee : l'ordre des etapes et
 * leur caractere « au mieux » sont delicats, et deux copies divergeraient.
 *
 *  1. creation du Live cote EMPREINTE ;
 *  2. ecriture de l'evenement dans le calendrier Nextcloud (au mieux) ;
 *  3. invitations cote EMPREINTE (au mieux).
 *
 * Seule l'etape 1 peut faire echouer l'ensemble : une fois le Live cree, ni un
 * calendrier indisponible ni un envoi d'invitation rate ne doivent l'annuler.
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_values;
use function filter_var;
use function is_array;
use function strtolower;
use function trim;

class MeetingCreationService {
	public function __construct(
		private EmpreinteApiService $api,
		private CalendarEventService $calendarEvents,
		private OAuthService $oauth,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<mixed> $attendees emails, ou objets {email: ...}
	 * @return array{data:array<string,mixed>, eventCreated:bool, invited:bool}
	 */
	public function create(
		string $userId,
		string $title,
		string $description,
		string $startTime,
		string $endTime,
		array $attendees = [],
	): array {
		$emails = $this->sanitizeEmails($attendees);
		$data = [
			'title' => $title,
			'description' => $description,
			'startTime' => $startTime,
			'endTime' => $endTime,
			'attendees' => $emails,
		];

		$live = $this->api->createLive($userId, $data);

		// L'email du compte EMPREINTE sert a poser le lien organisateur (admin_url)
		// pour le createur, plutot que le lien participant.
		$accountEmail = $this->oauth->getAccountEmail($userId);
		$eventUid = $this->calendarEvents->createEventForLive($userId, $live, $data, $accountEmail);

		return [
			'data' => $live,
			'eventCreated' => $eventUid !== null,
			'invited' => $this->sendInvitations($userId, $live, $emails),
		];
	}

	/**
	 * @param array<string,mixed> $live
	 * @param list<string> $emails
	 */
	public function sendInvitations(string $userId, array $live, array $emails): bool {
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
	 * Ne garde que les adresses valides, dedupliquees.
	 *
	 * @param array<mixed> $attendees
	 * @return list<string>
	 */
	public function sanitizeEmails(array $attendees): array {
		$out = [];
		$seen = [];
		foreach ($attendees as $entry) {
			// Tolere une liste de chaines OU une liste d'objets {email: ...}.
			$email = trim(is_array($entry) ? (string)($entry['email'] ?? '') : (string)$entry);
			$key = strtolower($email);
			if ($email === '' || isset($seen[$key]) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $email;
		}

		return array_values($out);
	}
}
