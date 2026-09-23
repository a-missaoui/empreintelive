<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Flux OAuth EMPREINTE execute COTE SERVEUR.
 * Porte depuis src/services/empreinteLiveService.js du fork :
 *   - register / login
 *   - flux PKCE : authorize (consent) -> approve -> echange du code -> tokens
 *   - refresh / revoke
 * Client PUBLIC (RFC 7636/8252) : pas de client_secret. La securite du flux
 * repose sur PKCE (code_verifier/code_challenge). Le client_id est une valeur
 * publique embarquee (DEFAULT_CLIENT_ID) ; le redirect_uri est derive de l'URL
 * de l'instance Nextcloud courante (host + CALLBACK_PATH). Aucune configuration
 * requise cote administrateur.
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\AppInfo\Application;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use RuntimeException;
use function base64_encode;
use function hash;
use function http_build_query;
use function rtrim;
use function strtr;

class OAuthService {
	private const SCOPE = 'live:read live:write live:update live:delete';

	/**
	 * client_id du client public OAuth. Ce n'est PAS un secret : il circule en
	 * clair dans le flux d'autorisation. Il est donc embarque en dur pour offrir
	 * une experience « zero configuration » ; un administrateur peut malgre tout
	 * le surcharger via la config (occ config:app:set empreintelive client_id).
	 */
	private const DEFAULT_CLIENT_ID = 'client_0f17901e-dcee-4d';

	/**
	 * Chemin du callback OAuth, relatif au host de l'instance Nextcloud. Le
	 * redirect_uri par defaut vaut « <host>/apps/calendar/empreinte-callback »
	 * (le callback est valide/gere cote backend EMPREINTE, exact-match). Il n'y a
	 * pas de vraie redirection navigateur : le flux est execute cote serveur, le
	 * redirect_uri sert uniquement de valeur a faire correspondre.
	 */
	private const CALLBACK_PATH = '/apps/calendar/empreinte-callback';

	/** Prefixe des cles de session portant l'etat PKCE entre authorize et approve. */
	private const SESSION_PREFIX = Application::APP_ID . '.oauth.';

	public function __construct(
		private EmpreinteClient $client,
		private TokenService $tokenService,
		private IConfig $config,
		private ISession $session,
		private IURLGenerator $urlGenerator,
	) {
	}

	// --------------------------------------------------------------------- config

	/** client_id embarque, surchargeable par la config ; jamais vide. */
	private function clientId(): string {
		$id = $this->config->getAppValue(Application::APP_ID, 'client_id', self::DEFAULT_CLIENT_ID);
		return $id !== '' ? $id : self::DEFAULT_CLIENT_ID;
	}

	/**
	 * redirect_uri du flux OAuth. Par defaut derive du host de l'instance courante
	 * (« <host>/apps/calendar/empreinte-callback »), pour fonctionner sans config.
	 * Reste surchargeable via la config (occ config:app:set empreintelive redirect_uri).
	 */
	private function redirectUri(): string {
		$configured = $this->config->getAppValue(Application::APP_ID, 'redirect_uri', '');
		if ($configured !== '') {
			return $configured;
		}
		return rtrim($this->urlGenerator->getBaseUrl(), '/') . self::CALLBACK_PATH;
	}

	// --------------------------------------------------------------------- public

	/**
	 * Cree un compte EMPREINTE. Stocke les tokens si l'API en renvoie.
	 *
	 * @return array<string,mixed> Corps de reponse de l'API
	 */
	public function register(string $userId, string $email, string $password, string $name): array {
		$res = $this->client->request('/oauth/users/register', 'POST', [
			'email' => $email,
			'password' => $password,
			'name' => $name,
		], ['Content-Type' => 'application/json']);

		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->errorMessage($res, 'Echec de la creation du compte'));
		}

		$body = is_array($res['body']) ? $res['body'] : [];
		if (!empty($body['access_token'])) {
			$this->tokenService->saveToken($userId, $body);
		}
		return $body;
	}

	/**
	 * Authentifie l'utilisateur EMPREINTE et stocke le token de session.
	 *
	 * @return array<string,mixed>
	 */
	public function login(string $userId, string $email, string $password): array {
		$res = $this->client->request('/oauth/users/login', 'POST', [
			'email' => $email,
			'password' => $password,
		], ['Content-Type' => 'application/json']);

		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->errorMessage($res, 'Echec de la connexion'));
		}

		$tokens = is_array($res['body']) ? $res['body'] : [];
		if (empty($tokens['access_token'])) {
			throw new RuntimeException('Echec de la connexion : access_token manquant');
		}
		$this->tokenService->saveToken($userId, $tokens);
		// Memorise l'email du compte EMPREINTE : requis comme organiserEmail des invitations.
		$this->config->setUserValue($userId, Application::APP_ID, 'account_email', $email);
		return $tokens;
	}

	/**
	 * Email du compte EMPREINTE connecte (organisateur des invitations), ou '' si inconnu.
	 */
	public function getAccountEmail(string $userId): string {
		return $this->config->getUserValue($userId, Application::APP_ID, 'account_email', '');
	}

	/**
	 * Etape 1/2 du flux PKCE : demande de consentement (GET /oauth/authorize).
	 * Genere le couple code_verifier/state, les memorise en session (le temps que
	 * l'utilisateur voie l'ecran de consentement), et verifie que l'AS accepte.
	 * Requiert un token de session valide (login prealable).
	 *
	 * @return list<string> Scopes demandes (pour affichage cote UI)
	 */
	public function authorize(string $userId, string $email): array {
		$authHeader = $this->sessionAuthHeader($userId);

		$codeVerifier = $this->randomString(64);
		$state = $this->randomString(32);
		$codeChallenge = $this->codeChallenge($codeVerifier);

		// Consent (GET /oauth/authorize)
		$authorizeQuery = '/oauth/authorize?' . http_build_query([
			'client_id' => $this->clientId(),
			'redirect_uri' => $this->redirectUri(),
			'response_type' => 'code',
			'scope' => self::SCOPE,
			'state' => $state,
			'code_challenge' => $codeChallenge,
			'code_challenge_method' => 'S256',
		]);
		$consent = $this->client->request($authorizeQuery, 'GET', null, [
			'Authorization' => $authHeader,
			'Client-Email' => $email,
		]);
		if (!$this->client->isOk($consent['status'])) {
			throw new RuntimeException($this->errorMessage($consent, 'Echec de la demande de consentement'));
		}

		// Memorise l'etat PKCE jusqu'a l'approbation par l'utilisateur.
		$this->session->set(self::SESSION_PREFIX . 'code_verifier', $codeVerifier);
		$this->session->set(self::SESSION_PREFIX . 'state', $state);
		$this->session->set(self::SESSION_PREFIX . 'email', $email);

		return explode(' ', self::SCOPE);
	}

	/**
	 * Etape 2/2 du flux PKCE : l'utilisateur a accepte l'ecran de consentement.
	 * Rejoue l'approbation (POST approve) avec l'etat memorise, puis echange le code
	 * contre des tokens scopes. Purge l'etat de session dans tous les cas.
	 *
	 * @return array<string,mixed> Tokens finaux
	 */
	public function approve(string $userId): array {
		$codeVerifier = (string)$this->session->get(self::SESSION_PREFIX . 'code_verifier');
		$state = (string)$this->session->get(self::SESSION_PREFIX . 'state');
		$email = (string)$this->session->get(self::SESSION_PREFIX . 'email');
		if ($codeVerifier === '' || $state === '' || $email === '') {
			throw new RuntimeException('Consentement expire. Relancez la connexion.');
		}

		try {
			$authHeader = $this->sessionAuthHeader($userId);
			$codeChallenge = $this->codeChallenge($codeVerifier);

			// Approve (POST /oauth/authorize/approve, form-urlencoded)
			$form = http_build_query([
				'client_id' => $this->clientId(),
				'redirect_uri' => $this->redirectUri(),
				'scope' => self::SCOPE,
				'state' => $state,
				'email' => $email,
				'code_challenge' => $codeChallenge,
				'code_challenge_method' => 'S256',
			]);
			$approve = $this->client->request('/oauth/authorize/approve', 'POST', $form, [
				'Authorization' => $authHeader,
				'Content-Type' => 'application/x-www-form-urlencoded',
			]);
			$location = $approve['location'] ?? '';
			if (!$location) {
				throw new RuntimeException($this->errorMessage($approve, 'Echec de l\'approbation de l\'autorisation'));
			}

			// Extraire code + state de l'URL de redirection
			$parsed = [];
			parse_str((string)parse_url($location, PHP_URL_QUERY), $parsed);
			$code = $parsed['code'] ?? null;
			$returnedState = $parsed['state'] ?? null;
			if (!$code || $returnedState !== $state) {
				throw new RuntimeException('Autorisation echouee ou state invalide.');
			}

			return $this->exchangeCode($userId, (string)$code, $codeVerifier);
		} finally {
			$this->clearAuthorizeState();
		}
	}

	/**
	 * Realise le flux OAuth complet (consent + approve) en un appel. Conserve pour
	 * compatibilite ; l'UI privilegie desormais authorize() puis approve().
	 *
	 * @return array<string,mixed> Tokens finaux
	 */
	public function connect(string $userId, string $email): array {
		$this->authorize($userId, $email);
		return $this->approve($userId);
	}

	/** En-tete Authorization construit a partir du token de session EMPREINTE (login). */
	private function sessionAuthHeader(string $userId): string {
		$session = $this->tokenService->loadToken($userId);
		if ($session === null || empty($session['access_token'])) {
			throw new RuntimeException('Non authentifie. Connectez-vous d\'abord.');
		}
		return ($session['token_type'] ?? 'Bearer') . ' ' . $session['access_token'];
	}

	private function clearAuthorizeState(): void {
		$this->session->remove(self::SESSION_PREFIX . 'code_verifier');
		$this->session->remove(self::SESSION_PREFIX . 'state');
		$this->session->remove(self::SESSION_PREFIX . 'email');
	}

	/**
	 * Echange un code d'autorisation contre des tokens et les stocke.
	 *
	 * @return array<string,mixed>
	 */
	private function exchangeCode(string $userId, string $code, string $codeVerifier): array {
		// Client public : pas de client_secret. Le code_verifier (PKCE) authentifie
		// l'echange a la place du secret.
		$res = $this->client->request('/oauth/token', 'POST', [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'client_id' => $this->clientId(),
			'redirect_uri' => $this->redirectUri(),
			'code_verifier' => $codeVerifier,
		], ['Content-Type' => 'application/json']);

		if (!$this->client->isOk($res['status'])) {
			throw new RuntimeException($this->errorMessage($res, 'Echec de l\'echange du code'));
		}
		$tokens = is_array($res['body']) ? $res['body'] : [];
		$this->tokenService->saveToken($userId, $tokens);
		return $tokens;
	}

	/**
	 * Rafraichit le token d'acces de l'utilisateur. Supprime les tokens en cas d'echec.
	 *
	 * @return array<string,mixed>
	 */
	public function refresh(string $userId): array {
		$tokens = $this->tokenService->loadToken($userId);
		if ($tokens === null || empty($tokens['refresh_token'])) {
			throw new RuntimeException('Aucun refresh_token disponible');
		}

		$res = $this->client->request('/oauth/token', 'POST', [
			'grant_type' => 'refresh_token',
			'refresh_token' => $tokens['refresh_token'],
			'client_id' => $this->clientId(),
		], ['Content-Type' => 'application/json']);

		if (!$this->client->isOk($res['status'])) {
			$this->tokenService->deleteToken($userId);
			throw new RuntimeException('Echec du rafraichissement du token. Reconnectez-vous.');
		}
		$newTokens = is_array($res['body']) ? $res['body'] : [];
		$this->tokenService->saveToken($userId, $newTokens);
		return $newTokens;
	}

	/**
	 * Revoque le token cote API puis le supprime localement (logout).
	 */
	public function logout(string $userId): void {
		$tokens = $this->tokenService->loadToken($userId);
		if ($tokens !== null && !empty($tokens['access_token'])) {
			try {
				$this->client->request('/oauth/revoke', 'POST', [
					'token' => $tokens['access_token'],
					'token_type_hint' => 'access_token',
					'client_id' => $this->clientId(),
				], ['Content-Type' => 'application/json']);
			} catch (\Throwable $e) {
				// best effort : on supprime quand meme cote local
			}
		}
		$this->tokenService->deleteToken($userId);
	}

	/**
	 * Retourne un en-tete Authorization valide, en rafraichissant si necessaire.
	 */
	public function getAuthorizationHeader(string $userId): string {
		$tokens = $this->tokenService->loadToken($userId);
		if ($tokens === null || empty($tokens['access_token'])) {
			throw new RuntimeException('Non authentifie. Connectez votre compte EMPREINTE.');
		}
		if ($this->tokenService->isExpired($tokens)) {
			$tokens = $this->refresh($userId);
		}
		return ($tokens['token_type'] ?? 'Bearer') . ' ' . $tokens['access_token'];
	}

	// --------------------------------------------------------------------- PKCE

	private function randomString(int $length): string {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
		$max = strlen($chars) - 1;
		$out = '';
		for ($i = 0; $i < $length; $i++) {
			$out .= $chars[random_int(0, $max)];
		}
		return $out;
	}

	private function codeChallenge(string $verifier): string {
		$hash = hash('sha256', $verifier, true);
		return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
	}

	/**
	 * @param array{status:int, body:mixed, location:?string} $res
	 */
	private function errorMessage(array $res, string $fallback): string {
		$body = $res['body'] ?? null;
		if (is_array($body)) {
			return (string)($body['error'] ?? $body['message'] ?? $fallback);
		}
		return $fallback;
	}
}
