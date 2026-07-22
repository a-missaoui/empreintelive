<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\EmpreinteClient;
use OCA\EmpreinteLive\Service\OAuthService;
use OCA\EmpreinteLive\Service\TokenService;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class OAuthServiceTest extends TestCase {
	private EmpreinteClient&MockObject $client;
	private TokenService&MockObject $tokens;
	private IConfig&MockObject $config;
	private ISession&MockObject $session;
	private IURLGenerator&MockObject $urlGenerator;
	private OAuthService $svc;

	protected function setUp(): void {
		// Seule request() est mockee : isOk() garde son implementation reelle.
		$this->client = $this->createPartialMock(EmpreinteClient::class, ['request']);
		$this->tokens = $this->createMock(TokenService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default = '') => match ($key) {
				'client_id' => 'cid',
				'redirect_uri' => 'https://cb',
				default => $default,
			}
		);
		// Session en memoire : approve() doit relire ce qu'authorize() a ecrit.
		$store = [];
		$this->session = $this->createMock(ISession::class);
		$this->session->method('set')->willReturnCallback(function (string $k, $v) use (&$store): void {
			$store[$k] = $v;
		});
		$this->session->method('get')->willReturnCallback(function (string $k) use (&$store) {
			return $store[$k] ?? null;
		});
		$this->session->method('remove')->willReturnCallback(function (string $k) use (&$store): void {
			unset($store[$k]);
		});
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.com');
		$this->svc = new OAuthService($this->client, $this->tokens, $this->config, $this->session, $this->urlGenerator);
	}

	public function testCodeChallengeMatchesRfc7636Vector(): void {
		$m = new ReflectionMethod(OAuthService::class, 'codeChallenge');
		$m->setAccessible(true);
		$challenge = $m->invoke($this->svc, 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');
		$this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $challenge);
	}

	public function testClientIdFallsBackToBundledDefaultWhenUnconfigured(): void {
		// Aucune config client_id -> le client_id public embarque doit etre utilise,
		// pour que l'app fonctionne sans configuration administrateur.
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default = '') => $default,
		);
		$svc = new OAuthService($this->client, $this->tokens, $config, $this->session, $this->urlGenerator);

		$m = new ReflectionMethod(OAuthService::class, 'clientId');
		$m->setAccessible(true);
		$clientId = (string)$m->invoke($svc);

		$this->assertNotSame('', $clientId, 'le client_id embarque ne doit jamais etre vide');
	}

	public function testRedirectUriDerivedFromInstanceHostWhenUnconfigured(): void {
		// Aucune config redirect_uri -> il doit etre derive du host de l'instance,
		// pour que le flux OAuth fonctionne sans configuration administrateur
		// (regression : un redirect_uri vide fait echouer /oauth/authorize en 400).
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default = '') => $default,
		);
		$svc = new OAuthService($this->client, $this->tokens, $config, $this->session, $this->urlGenerator);

		$m = new ReflectionMethod(OAuthService::class, 'redirectUri');
		$m->setAccessible(true);
		$redirectUri = (string)$m->invoke($svc);

		$this->assertSame('https://cloud.example.com/apps/calendar/empreinte-callback', $redirectUri);
	}

	public function testRedirectUriConfigOverrideTakesPrecedence(): void {
		// $this->config renvoie 'https://cb' pour redirect_uri : l'override gagne.
		$m = new ReflectionMethod(OAuthService::class, 'redirectUri');
		$m->setAccessible(true);
		$this->assertSame('https://cb', (string)$m->invoke($this->svc));
	}

	public function testLoginSuccessStoresToken(): void {
		$this->client->method('request')->willReturn([
			'status' => 200,
			'body' => ['access_token' => 'A', 'refresh_token' => 'R'],
			'location' => null,
		]);
		$this->tokens->expects($this->once())
			->method('saveToken')
			->with('u', $this->arrayHasKey('access_token'));

		$res = $this->svc->login('u', 'e@x', 'pw');
		$this->assertSame('A', $res['access_token']);
	}

	public function testLoginMissingAccessTokenThrows(): void {
		$this->client->method('request')->willReturn([
			'status' => 200, 'body' => ['foo' => 1], 'location' => null,
		]);
		$this->expectException(RuntimeException::class);
		$this->svc->login('u', 'e', 'p');
	}

	public function testLoginNonOkThrowsWithApiError(): void {
		$this->client->method('request')->willReturn([
			'status' => 401, 'body' => ['error' => 'bad creds'], 'location' => null,
		]);
		$this->expectExceptionMessage('bad creds');
		$this->svc->login('u', 'e', 'p');
	}

	public function testConnectRequiresSession(): void {
		$this->tokens->method('loadToken')->willReturn(null);
		$this->expectExceptionMessage('Non authentifie');
		$this->svc->connect('u', 'e@x');
	}

	public function testConnectHappyPathExchangesCodeForTokens(): void {
		$this->tokens->method('loadToken')->willReturn(['access_token' => 'sess', 'token_type' => 'Bearer']);

		$tokenBody = null;
		// L'etape approve renvoie le meme state que celui envoye (valide la verif anti-CSRF).
		$this->client->method('request')->willReturnCallback(
			static function (string $path, string $method, $body, array $headers) use (&$tokenBody): array {
				if (str_starts_with($path, '/oauth/authorize?')) {
					return ['status' => 200, 'body' => [], 'location' => null];
				}
				if ($path === '/oauth/authorize/approve') {
					parse_str((string)$body, $form);
					return ['status' => 200, 'body' => null, 'location' => 'https://cb?code=THECODE&state=' . $form['state']];
				}
				if ($path === '/oauth/token') {
					$tokenBody = $body;
					return ['status' => 200, 'body' => ['access_token' => 'FINAL', 'scope' => 'live:read'], 'location' => null];
				}
				return ['status' => 404, 'body' => null, 'location' => null];
			}
		);

		$this->tokens->expects($this->once())
			->method('saveToken')
			->with('u', $this->callback(static fn ($t) => ($t['access_token'] ?? null) === 'FINAL'));

		$res = $this->svc->connect('u', 'e@x');
		$this->assertSame('FINAL', $res['access_token']);
		$this->assertSame('live:read', $res['scope']);

		// Client public : aucun client_secret n'est envoye ; PKCE (code_verifier)
		// authentifie l'echange a la place.
		$this->assertIsArray($tokenBody);
		$this->assertArrayNotHasKey('client_secret', $tokenBody);
		$this->assertArrayHasKey('code_verifier', $tokenBody);
		$this->assertSame('cid', $tokenBody['client_id']);
	}

	public function testAuthorizeReturnsScopesAndStoresState(): void {
		$this->tokens->method('loadToken')->willReturn(['access_token' => 'sess', 'token_type' => 'Bearer']);
		$this->client->method('request')->willReturn(['status' => 200, 'body' => [], 'location' => null]);
		// authorize() memorise l'etat PKCE en session.
		$this->session->expects($this->exactly(3))->method('set');

		$scopes = $this->svc->authorize('u', 'e@x');
		$this->assertContains('live:read', $scopes);
		$this->assertContains('live:write', $scopes);
	}

	public function testApproveWithoutConsentThrows(): void {
		// Aucun authorize() prealable -> pas d'etat en session.
		$this->expectExceptionMessage('Consentement expire');
		$this->svc->approve('u');
	}

	public function testApproveClearsSessionState(): void {
		$this->tokens->method('loadToken')->willReturn(['access_token' => 'sess', 'token_type' => 'Bearer']);
		$this->client->method('request')->willReturnCallback(
			static function (string $path, string $method, $body): array {
				if (str_starts_with($path, '/oauth/authorize?')) {
					return ['status' => 200, 'body' => [], 'location' => null];
				}
				if ($path === '/oauth/authorize/approve') {
					parse_str((string)$body, $form);
					return ['status' => 200, 'body' => null, 'location' => 'https://cb?code=C&state=' . $form['state']];
				}
				return ['status' => 200, 'body' => ['access_token' => 'FINAL'], 'location' => null];
			}
		);
		$this->svc->authorize('u', 'e@x');
		// L'etat PKCE doit etre purge apres approbation.
		$this->session->expects($this->exactly(3))->method('remove');
		$res = $this->svc->approve('u');
		$this->assertSame('FINAL', $res['access_token']);
	}

	public function testConnectFailsOnStateMismatch(): void {
		$this->tokens->method('loadToken')->willReturn(['access_token' => 'sess', 'token_type' => 'Bearer']);
		$this->client->method('request')->willReturnCallback(
			static function (string $path): array {
				if (str_starts_with($path, '/oauth/authorize?')) {
					return ['status' => 200, 'body' => [], 'location' => null];
				}
				if ($path === '/oauth/authorize/approve') {
					return ['status' => 200, 'body' => null, 'location' => 'https://cb?code=THECODE&state=TAMPERED'];
				}
				return ['status' => 404, 'body' => null, 'location' => null];
			}
		);
		$this->expectExceptionMessage('state invalide');
		$this->svc->connect('u', 'e@x');
	}
}
