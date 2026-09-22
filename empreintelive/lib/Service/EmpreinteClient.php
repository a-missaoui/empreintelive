<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client HTTP bas niveau vers l'API EMPREINTE Live.
 * Centralise tous les appels reseau (remplace le proxy + axios du fork).
 * Aucune logique metier ici : uniquement l'execution des requetes HTTP.
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function method_exists;
use const JSON_ERROR_NONE;
use const JSON_THROW_ON_ERROR;

class EmpreinteClient {
	private const DEFAULT_BASE_URL = 'https://api.empreinte.live';

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function baseUrl(): string {
		$url = $this->config->getAppValue(Application::APP_ID, 'api_base_url', self::DEFAULT_BASE_URL);
		return $url !== '' ? rtrim($url, '/') : self::DEFAULT_BASE_URL;
	}

	/**
	 * Execute une requete HTTP vers l'API EMPREINTE.
	 *
	 * @param string $path Chemin (ex: /oauth/token) ou query string complet
	 * @param string $method GET|POST|PUT|DELETE
	 * @param array<string,mixed>|string|null $body Corps JSON (array) ou brut (string, ex: form-urlencoded)
	 * @param array<string,string> $headers En-tetes a transmettre
	 * @return array{status:int, body:mixed, location:?string}
	 */
	public function request(string $path, string $method = 'GET', $body = null, array $headers = []): array {
		$client = $this->clientService->newClient();
		$options = [
			'timeout' => 30,
			'headers' => $headers,
			'allow_redirects' => false,
			'http_errors' => false,
		];

		if ($body !== null) {
			$options['body'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
		}

		$url = $this->baseUrl() . $path;

		try {
			$response = match (strtoupper($method)) {
				'POST' => $client->post($url, $options),
				'PUT' => $client->put($url, $options),
				'DELETE' => $client->delete($url, $options),
				default => $client->get($url, $options),
			};
		} catch (Throwable $e) {
			$this->logger->error('EMPREINTE API request failed', [
				'path' => $path,
				'method' => $method,
				'exception' => $e,
			]);
			throw $e;
		}

		$raw = (string)$response->getBody();
		$decoded = json_decode($raw, true);
		if ($decoded === null && $raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
			$decoded = $raw;
		}

		$location = null;
		if (method_exists($response, 'getHeader')) {
			$location = $response->getHeader('Location') ?: null;
		}

		return [
			'status' => $response->getStatusCode(),
			'body' => $decoded,
			'location' => $location,
		];
	}

	/**
	 * Indique si un code de statut HTTP est un succes (2xx).
	 */
	public function isOk(int $status): bool {
		return $status >= 200 && $status < 300;
	}
}
