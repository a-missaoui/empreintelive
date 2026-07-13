<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lot 3 - Logique metier EMPREINTE Live (Lives).
 * Porte depuis empreinteLiveService.js (createLiveMeeting, updateLiveMeeting, ...).
 * Toutes les requetes sont authentifiees via OAuthService (refresh automatique sur 401).
 */

namespace OCA\EmpreinteLive\Service;

use RuntimeException;
use function is_array;

class EmpreinteApiService {
	public function __construct(
		private EmpreinteClient $client,
		private OAuthService $oauth,
	) {
	}

	/**
	 * Requete authentifiee, avec un retry apres refresh si le token est rejete (401).
	 *
	 * @param array<string,mixed>|null $body
	 * @return array{status:int, body:mixed, location:?string}
	 */
	private function authed(string $userId, string $path, string $method = 'GET', ?array $body = null): array {
		$headers = [
			'Authorization' => $this->oauth->getAuthorizationHeader($userId),
			'Content-Type' => 'application/json',
		];
		$res = $this->client->request($path, $method, $body, $headers);

		if ($res['status'] === 401) {
			$this->oauth->refresh($userId);
			$headers['Authorization'] = $this->oauth->getAuthorizationHeader($userId);
			$res = $this->client->request($path, $method, $body, $headers);
		}
		return $res;
	}

	/**
	 * @param array<string,mixed> $data title, description, startTime, endTime (ISO8601 UTC)
	 * @return array<string,mixed>
	 */
	public function createLive(string $userId, array $data): array {
		$res = $this->authed($userId, '/lives', 'POST', $this->livePayload($data));
		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->msg($res, 'Echec de la creation de la reunion'));
		}
		return $this->normalizeLive($res['body']);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function updateLive(string $userId, string $liveId, array $data): array {
		$res = $this->authed($userId, '/lives/' . rawurlencode($liveId), 'PUT', $this->livePayload($data));
		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->msg($res, 'Echec de la mise a jour de la reunion'));
		}
		return $this->normalizeLive($res['body']);
	}

	public function deleteLive(string $userId, string $liveId): void {
		$res = $this->authed($userId, '/lives/' . rawurlencode($liveId), 'DELETE');
		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->msg($res, 'Echec de la suppression de la reunion'));
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getLive(string $userId, string $liveId): array {
		$res = $this->authed($userId, '/lives/' . rawurlencode($liveId), 'GET');
		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->msg($res, 'Echec de la recuperation de la reunion'));
		}
		return $this->normalizeLive($res['body']);
	}

	/**
	 * Liste des visios de l'utilisateur (GET /lives). La reponse de liste utilise
	 * une forme differente de create/get (champs camelCase : title/startDate/
	 * endDate/organiserUrl/participantUrl) ; on la normalise vers les memes cles
	 * que le reste de l'app pour que l'UI (MeetingList.vue) s'y retrouve.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function getMeetings(string $userId): array {
		$res = $this->authed($userId, '/lives', 'GET');
		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->msg($res, 'Echec de la recuperation des reunions'));
		}
		$body = $res['body'];
		$items = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) ? $body : []);
		$out = [];
		foreach ($items as $item) {
			if (is_array($item)) {
				$out[] = $this->normalizeListItem($item);
			}
		}
		return $out;
	}

	/**
	 * Aplati un element de la liste /lives vers les cles communes de l'app
	 * (participant_url / admin_url / dateStartDiffusion...). Comme la liste ne
	 * contient que les visios de l'utilisateur (createur), le lien de jonction par
	 * defaut (url) est le lien organisateur.
	 *
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private function normalizeListItem(array $item): array {
		$organiser = $item['organiserUrl'] ?? $item['admin_url'] ?? null;
		$participant = $item['participantUrl'] ?? $item['participant_url'] ?? null;
		return array_merge($item, [
			'id' => $item['id'] ?? null,
			'title' => $item['title'] ?? '',
			'description' => $item['description'] ?? '',
			'admin_url' => $organiser,
			'participant_url' => $participant,
			'url' => $organiser ?? $participant,
			'dateStartDiffusion' => $item['startDate'] ?? $item['dateStartDiffusion'] ?? null,
			'dateEndDiffusion' => $item['endDate'] ?? $item['dateEndDiffusion'] ?? null,
		]);
	}

	/**
	 * @param array<string,mixed> $payload organiserEmail, participantEmails[]
	 */
	public function sendInvitations(string $userId, string $liveId, array $payload): void {
		$res = $this->authed($userId, '/lives/' . rawurlencode($liveId) . '/invitations', 'POST', $payload);
		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->msg($res, 'Echec de l\'envoi des invitations'));
		}
	}

	// --------------------------------------------------------------------- helpers

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function livePayload(array $data): array {
		return [
			'title' => $data['title'] ?? 'Untitled Meeting',
			'description' => $data['description'] ?? '',
			'dateStartDiffusion' => $data['startTime'] ?? null,
			'dateEndDiffusion' => $data['endTime'] ?? ($data['startTime'] ?? null),
		];
	}

	/**
	 * Aplati la reponse de l'API (data imbriquee + liveUrl) en un objet uniforme.
	 *
	 * @param mixed $body
	 * @return array<string,mixed>
	 */
	private function normalizeLive($body): array {
		$body = is_array($body) ? $body : [];
		$data = is_array($body['data'] ?? null) ? $body['data'] : $body;
		$id = $data['id'] ?? $body['id'] ?? $data['liveId'] ?? $data['live_id'] ?? null;
		return array_merge($data, [
			'id' => $id,
			'url' => $body['liveUrl'] ?? $data['url'] ?? $data['join_url'] ?? null,
			'admin_url' => $data['admin_url'] ?? null,
			'participant_url' => $data['participant_url'] ?? null,
			'created_by' => $data['created_by'] ?? null,
		]);
	}

	/**
	 * @param array{status:int, body:mixed, location:?string} $res
	 */
	private function msg(array $res, string $fallback): string {
		$body = $res['body'] ?? null;
		if (is_array($body)) {
			return (string)($body['message'] ?? $body['error'] ?? $fallback);
		}
		return $fallback;
	}
}
