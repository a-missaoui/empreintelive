<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lot 2 - Stockage des tokens OAuth COTE SERVEUR, par utilisateur Nextcloud.
 * Remplace le localStorage du navigateur utilise dans le fork :
 * les tokens ne sont plus exposes au JS (protection contre le XSS).
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\AppInfo\Application;
use OCP\IConfig;
use function is_array;
use function json_decode;
use function json_encode;
use function time;

class TokenService {
	private const KEY = 'tokens';
	/** Marge de securite (s) avant l'expiration reelle pour declencher un refresh. */
	private const EXPIRY_LEEWAY = 30;

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * Enregistre la reponse de token de l'API EMPREINTE.
	 * Calcule expires_at (epoch secondes) a partir de expires_in.
	 *
	 * @param array<string,mixed> $token
	 */
	public function saveToken(string $userId, array $token): void {
		$expiresIn = (int)($token['expires_in'] ?? 0);
		$data = [
			'access_token' => $token['access_token'] ?? null,
			'refresh_token' => $token['refresh_token'] ?? null,
			'token_type' => $token['token_type'] ?? 'Bearer',
			'scope' => $token['scope'] ?? null,
			'expires_at' => $expiresIn > 0 ? (time() + $expiresIn) : null,
		];
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY, json_encode($data));
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function loadToken(string $userId): ?array {
		$raw = $this->config->getUserValue($userId, Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	public function deleteToken(string $userId): void {
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY);
	}

	public function hasToken(string $userId): bool {
		$token = $this->loadToken($userId);
		return $token !== null && !empty($token['access_token']);
	}

	/**
	 * Vrai uniquement si un token *scope* est present. Un simple `login` renvoie un
	 * token a scope vide, insuffisant pour les operations `live:*` : le consentement
	 * OAuth (authorize + approve) est requis pour obtenir les scopes. On distingue
	 * donc "un token existe" (hasToken) de "un token reellement utilisable"
	 * (hasScopedToken) -> sans ca, l'UI se croit connectee mais `/lives` renvoie
	 * `insufficient_scope`, sans jamais reproposer l'ecran de consentement.
	 */
	public function hasScopedToken(string $userId): bool {
		$token = $this->loadToken($userId);
		if ($token === null || empty($token['access_token'])) {
			return false;
		}
		return str_contains((string)($token['scope'] ?? ''), 'live:');
	}

	/**
	 * @param array<string,mixed> $token
	 */
	public function isExpired(array $token): bool {
		$expiresAt = $token['expires_at'] ?? null;
		if ($expiresAt === null) {
			return false;
		}
		return time() >= ((int)$expiresAt - self::EXPIRY_LEEWAY);
	}
}
