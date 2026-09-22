<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Endpoints OAuth (couche fine : delegue a OAuthService).
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Service\LiveCalendarSyncService;
use OCA\EmpreinteLive\Service\OAuthService;
use OCA\EmpreinteLive\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

class OAuthController extends Controller {
	public function __construct(
		IRequest $request,
		private OAuthService $oauthService,
		private TokenService $tokenService,
		private LiveCalendarSyncService $calendarSync,
		private IUserSession $userSession,
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
	public function status(): JSONResponse {
		return new JSONResponse(['connected' => $this->tokenService->hasScopedToken($this->userId())]);
	}

	#[NoAdminRequired]
	public function register(string $email = '', string $password = '', string $name = ''): JSONResponse {
		try {
			$body = $this->oauthService->register($this->userId(), $email, $password, $name);
			return new JSONResponse(['success' => true, 'user' => $body]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function login(string $email = '', string $password = ''): JSONResponse {
		try {
			$this->oauthService->login($this->userId(), $email, $password);
			return new JSONResponse(['success' => true]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}

	/**
	 * Etape 1/2 : demande de consentement. Renvoie les scopes a afficher dans la
	 * modale de consentement cote UI.
	 */
	#[NoAdminRequired]
	public function authorize(string $email = ''): JSONResponse {
		try {
			$scopes = $this->oauthService->authorize($this->userId(), $email);
			return new JSONResponse(['success' => true, 'scopes' => $scopes]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Etape 2/2 : l'utilisateur a autorise l'acces -> approbation + tokens.
	 */
	#[NoAdminRequired]
	public function approve(): JSONResponse {
		try {
			$this->oauthService->approve($this->userId());
			// Reconciliation calendrier <- liste : back-fill des visios du compte
			// qui n'ont pas encore d'evenement (best-effort, non bloquant).
			$this->calendarSync->reconcile($this->userId());
			return new JSONResponse(['success' => true, 'connected' => true]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Realise le flux OAuth complet (consent -> approve -> tokens) en un appel.
	 * Conserve pour compatibilite ; l'UI utilise desormais authorize + approve.
	 */
	#[NoAdminRequired]
	public function connect(string $email = ''): JSONResponse {
		try {
			$this->oauthService->connect($this->userId(), $email);
			$this->calendarSync->reconcile($this->userId());
			return new JSONResponse(['success' => true, 'connected' => true]);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function logout(): JSONResponse {
		$userId = $this->userId();
		$this->oauthService->logout($userId);
		// Symetrie avec approve/connect : on vide le calendrier miroir a la
		// deconnexion (les Lives EMPREINTE restent, la reconnexion les recree).
		$this->calendarSync->clearAll($userId);
		return new JSONResponse(['success' => true]);
	}
}
