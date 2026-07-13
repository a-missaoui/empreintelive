<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Drapeau partage (singleton par requete) qui distingue une suppression
 * d'evenement INITIEE PAR L'APP (prune de reconciliation lors d'un changement de
 * compte) d'une suppression faite par l'utilisateur.
 *
 * Necessaire car supprimer un evenement via CalDavBackend declenche, dans la MEME
 * requete et de facon SYNCHRONE, CalendarObjectDeletedEvent -> CalendarObjectListener
 * -> deleteLive(). Sans ce garde, le prune supprimerait les Lives EMPREINTE de
 * l'autre compte, alors qu'on veut seulement retirer leur miroir local (chaque
 * compte doit retrouver ses visios en se reconnectant).
 */

namespace OCA\EmpreinteLive\Service;

class SyncGuard {
	private bool $suppressed = false;

	public function suppress(): void {
		$this->suppressed = true;
	}

	public function release(): void {
		$this->suppressed = false;
	}

	public function isSuppressed(): bool {
		return $this->suppressed;
	}
}
