<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Porte depuis le fork Calendar (lib/Controller/EmpreinteAuthController.php).
 * Renomme dans le namespace de l'app autonome ; logique inchangee.
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function method_exists;
use function parse_url;
use function preg_match;
use function strtoupper;
use const JSON_ERROR_NONE;
use const JSON_THROW_ON_ERROR;

class ProxyController extends Controller {
	private const API_BASE_URL = 'https://api.empreinte.live';

	public function __construct(
		IRequest $request,
		private IClientService $clientService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Proxy request to EMPREINTE Live API to avoid browser CORS errors.
	 */
	#[NoAdminRequired]
	public function proxyRequest(): JSONResponse {
		$endpoint = (string)$this->request->getParam('endpoint', '');
		$path = $this->extractPath($endpoint);
		$method = strtoupper((string)$this->request->getParam('method', 'GET'));
		$headers = $this->request->getParam('headers', []);
		$body = $this->request->getParam('body', null);

		if (!is_array($headers)) {
			$headers = [];
		}

		if ($endpoint === '' || !$this->isAllowedEndpoint($path)) {
			return new JSONResponse(['error' => 'Endpoint not allowed'], Http::STATUS_BAD_REQUEST);
		}

		if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
			return new JSONResponse(['error' => 'Method not allowed'], Http::STATUS_BAD_REQUEST);
		}

		try {
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

			$url = self::API_BASE_URL . $endpoint;
			$response = match ($method) {
				'POST' => $client->post($url, $options),
				'PUT' => $client->put($url, $options),
				'DELETE' => $client->delete($url, $options),
				default => $client->get($url, $options),
			};

			$responseBodyRaw = $response->getBody();
			$responseBody = json_decode((string)$responseBodyRaw, true);
			if ($responseBody === null && $responseBodyRaw !== '' && json_last_error() !== JSON_ERROR_NONE) {
				$responseBody = $responseBodyRaw;
			}

			return new JSONResponse([
				'status' => $response->getStatusCode(),
				'body' => $responseBody,
				'headers' => [
					'location' => method_exists($response, 'getHeader') ? $response->getHeader('Location') : null,
				],
			]);
		} catch (Throwable $e) {
			$this->logger->error('EMPREINTE proxy request failed', [
				'endpoint' => $endpoint,
				'method' => $method,
				'exception' => $e,
			]);
			$debug = $this->config->getSystemValue('debug', false);
			$message = $debug
				? ('EMPREINTE proxy request failed: ' . $e->getMessage())
				: 'EMPREINTE proxy request failed';
			return new JSONResponse(['error' => $message], Http::STATUS_BAD_GATEWAY);
		}
	}

	private function isAllowedEndpoint(string $endpoint): bool {
		if (in_array($endpoint, [
			'/oauth/users/register',
			'/oauth/users/login',
			'/oauth/authorize',
			'/oauth/authorize/approve',
			'/oauth/token',
			'/oauth/revoke',
			'/lives',
			'/lives/',
			'/live/meetings',
		], true)) {
			return true;
		}

		if (preg_match('/^\/lives\/[0-9]+$/', $endpoint) === 1) {
			return true;
		}

		if (preg_match('/^\/lives\/[0-9]+\/invitations$/', $endpoint) === 1) {
			return true;
		}

		return preg_match('/^\/live\/meetings\/[A-Za-z0-9-]+$/', $endpoint) === 1;
	}

	private function extractPath(string $endpoint): string {
		$path = parse_url($endpoint, PHP_URL_PATH);
		return is_string($path) ? $path : '';
	}
}
