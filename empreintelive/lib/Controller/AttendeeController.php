<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Autocompletion des participants pour le formulaire de creation de visio.
 */

namespace OCA\EmpreinteLive\Controller;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Service\ContactSearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AttendeeController extends Controller {
	public function __construct(
		IRequest $request,
		private ContactSearchService $contacts,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Renvoie les suggestions de participants (utilisateurs / contacts Nextcloud).
	 */
	#[NoAdminRequired]
	public function search(string $search = ''): JSONResponse {
		return new JSONResponse(['data' => $this->contacts->search($search)]);
	}
}
