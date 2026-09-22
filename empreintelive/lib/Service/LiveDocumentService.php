<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Envoi d'un document Nextcloud dans la liste
 * des medias d'une reunion EMPREINTE, pour qu'il y soit presente aux participants.
 *
 * C'est le coeur de l'integration demandee : le panneau ne sert pas seulement a
 * consulter les fichiers a cote de la reunion, il les y injecte. EMPREINTE
 * convertit ensuite le document en diapositives (« convert-document »).
 *
 * Le fichier est lu via getUserFolder() : ce sont les permissions Nextcloud qui
 * decident, on n'en reimplemente aucune.
 *
 * API EMPREINTE, authentifiee par le jeton OAuth de l'utilisateur, comme les
 * reunions elles-memes :
 *   POST   /lives/{liveId}/convert-document      multipart: file, extension
 *   GET    /lives/{liveId}/docs                  -> { data: [ { doc_name, doc_index, slides } ] }
 *   DELETE /lives/{liveId}/doc-slides/{docIndex}
 *
 * Chaque appel porte donc un utilisateur : plus de cle partagee, et plus
 * d'identifiant client a deviner dans l'URL.
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Exception\LiveDocumentException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use function in_array;
use function is_array;
use function json_decode;
use function pathinfo;
use function rtrim;
use function strtolower;

class LiveDocumentService {
	/** Formats acceptes par la conversion EMPREINTE (cf. la zone de depot du meet). */
	public const ALLOWED_EXTENSIONS = ['pdf', 'pptx', 'docx'];

	private const DEFAULT_BASE_URL = 'https://api.empreinte.live';

	/** Taille maximale d'un support envoye en reunion (100 Mo par defaut). */
	private const DEFAULT_MAX_BYTES = 104857600;

	public function __construct(
		private IRootFolder $rootFolder,
		private IClientService $clientService,
		private OAuthService $oauth,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Envoie un fichier Nextcloud dans la liste des medias de la reunion.
	 *
	 * @return array<string,mixed> liste des medias apres envoi
	 * @throws LiveDocumentException
	 */
	public function sendDocument(string $userId, string $liveId, int $fileId): array {
		$file = $this->resolveFile($userId, $fileId);
		if ($file === null) {
			throw new LiveDocumentException('not_found');
		}

		$extension = strtolower((string)pathinfo($file->getName(), PATHINFO_EXTENSION));
		if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
			throw new LiveDocumentException('unsupported_format');
		}

		// Refuser AVANT de televerser : inutile de pousser 500 Mo sur le reseau
		// pour se faire jeter a l'arrivee.
		$max = (int)$this->config->getAppValue(
			Application::APP_ID,
			'max_document_bytes',
			(string)self::DEFAULT_MAX_BYTES,
		);
		if ($max > 0 && $file->getSize() > $max) {
			throw new LiveDocumentException('too_large');
		}

		$path = '/lives/' . $this->encodeSegment($liveId) . '/convert-document';

		try {
			$response = $this->clientService->newClient()->post($this->baseUrl() . $path, [
				'timeout' => 120,
				'http_errors' => false,
				'headers' => $this->headers($userId),
				'multipart' => [
					[
						'name' => 'file',
						// Flux plutot que contenu en memoire : un support de reunion
						// peut peser plusieurs dizaines de Mo.
						'contents' => $file->fopen('r'),
						'filename' => $file->getName(),
						'headers' => ['Content-Type' => $file->getMimeType()],
					],
					['name' => 'extension', 'contents' => $extension],
				],
			]);
		} catch (Throwable $e) {
			$this->logger->error('Envoi du document vers la reunion impossible', ['exception' => $e]);

			throw new LiveDocumentException('upload_failed');
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			$this->logger->warning('EMPREINTE a refuse le document', [
				'status' => $status,
				'liveId' => $liveId,
			]);

			throw new LiveDocumentException($status === 401 || $status === 403 ? 'rejected' : 'upload_failed');
		}

		return $this->listDocuments($userId, $liveId);
	}

	/**
	 * Liste des medias de la reunion.
	 *
	 * @return array<string,mixed>
	 */
	public function listDocuments(string $userId, string $liveId): array {
		$path = '/lives/' . $this->encodeSegment($liveId) . '/docs';

		// Hors du try : un compte non connecte n'est pas une panne de l'API, et le
		// panneau doit le dire clairement.
		try {
			$headers = $this->headers($userId);
		} catch (LiveDocumentException) {
			return ['available' => false, 'documents' => [], 'reason' => 'not_connected'];
		}

		try {
			$response = $this->clientService->newClient()->get($this->baseUrl() . $path, [
				'timeout' => 30,
				'http_errors' => false,
				'headers' => $headers,
			]);
		} catch (Throwable $e) {
			$this->logger->error('Lecture des medias de la reunion impossible', ['exception' => $e]);

			return ['available' => false, 'documents' => [], 'reason' => 'unreachable'];
		}

		if ($response->getStatusCode() !== 200) {
			return [
				'available' => false,
				'documents' => [],
				'reason' => $response->getStatusCode() === 401 ? 'not_connected' : 'unavailable',
			];
		}

		$decoded = json_decode((string)$response->getBody(), true);
		$documents = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

		$out = [];
		foreach ($documents as $doc) {
			$out[] = [
				'name' => $doc['doc_name'] ?? '',
				'index' => $doc['doc_index'] ?? '',
				'slides' => is_array($doc['slides'] ?? null) ? count($doc['slides']) : 0,
			];
		}

		return ['available' => true, 'documents' => $out, 'reason' => null];
	}

	/**
	 * Supprime un document de la reunion.
	 *
	 * @return array<string,mixed> liste des medias apres suppression
	 * @throws LiveDocumentException
	 */
	public function deleteDocument(string $userId, string $liveId, string $docIndex): array {
		if ($docIndex === '') {
			throw new LiveDocumentException('not_found');
		}

		$path = '/lives/' . $this->encodeSegment($liveId) . '/doc-slides/' . $this->encodeSegment($docIndex);

		try {
			$response = $this->clientService->newClient()->delete($this->baseUrl() . $path, [
				'timeout' => 30,
				'http_errors' => false,
				'headers' => $this->headers($userId),
			]);
		} catch (Throwable $e) {
			$this->logger->error('Suppression du document impossible', ['exception' => $e]);

			throw new LiveDocumentException('delete_failed');
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new LiveDocumentException($status === 401 || $status === 403 ? 'rejected' : 'delete_failed');
		}

		return $this->listDocuments($userId, $liveId);
	}

	/** Un identifiant de document contient le nom du fichier : il doit etre encode. */
	private function encodeSegment(string $segment): string {
		return rawurlencode($segment);
	}

	private function resolveFile(string $userId, int $fileId): ?File {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (Throwable) {
			return null;
		}

		foreach ($userFolder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}

	private function baseUrl(): string {
		$url = $this->config->getAppValue(Application::APP_ID, 'api_base_url', self::DEFAULT_BASE_URL);

		return rtrim($url !== '' ? $url : self::DEFAULT_BASE_URL, '/');
	}

	/**
	 * @return array<string,string>
	 * @throws LiveDocumentException si le compte EMPREINTE n'est pas connecte
	 */
	private function headers(string $userId): array {
		try {
			$authorization = $this->oauth->getAuthorizationHeader($userId);
		} catch (Throwable) {
			throw new LiveDocumentException('not_connected');
		}

		return [
			'Accept' => 'application/json',
			'Authorization' => $authorization,
		];
	}
}
