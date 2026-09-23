<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Le partage demande est refuse. Le message porte un code
 * stable (not_shareable, password_required, links_disabled...) que le controleur
 * relaie tel quel : c'est le frontend qui le traduit.
 */

namespace OCA\EmpreinteLive\Exception;

class ShareNotAllowedException extends \RuntimeException {
	/**
	 * @param string $code code stable relaye au frontend
	 * @param string $hint message lisible venant de Nextcloud (politique de mot
	 *                     de passe par exemple), a afficher tel quel
	 */
	public function __construct(string $code, private string $hint = '') {
		parent::__construct($code);
	}

	public function getHint(): string {
		return $this->hint;
	}
}
