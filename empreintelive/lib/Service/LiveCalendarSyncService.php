<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Reconciliation : le calendrier "EMPREINTE Live" reflete le compte connecte.
 * A la connexion, on aligne le calendrier sur la liste /lives du compte :
 *   - back-fill : on cree les evenements manquants (visios du compte sans miroir) ;
 *   - prune : on retire les evenements des AUTRES comptes (miroir local uniquement).
 *
 * Les Lives EMPREINTE ne sont JAMAIS supprimes (le prune passe par SyncGuard) :
 * chaque compte retrouve ses visios en se reconnectant, le back-fill les recree.
 * Best-effort : ne fait jamais echouer la connexion.
 */

namespace OCA\EmpreinteLive\Service;

use Psr\Log\LoggerInterface;
use Throwable;

class LiveCalendarSyncService {
	private const APP = 'empreintelive';

	public function __construct(
		private EmpreinteApiService $api,
		private CalendarEventService $calendarEvents,
		private OAuthService $oauth,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Aligne le calendrier "EMPREINTE Live" sur le compte connecte (best-effort).
	 * getMeetings() leve une exception en cas d'echec API -> on ne prune alors PAS
	 * (evite d'effacer des evenements sur une liste partielle/erronee).
	 */
	public function reconcile(string $userId): void {
		try {
			$accountEmail = $this->oauth->getAccountEmail($userId);
			$lives = $this->api->getMeetings($userId);

			$keep = [];
			foreach ($lives as $live) {
				if (is_array($live) && isset($live['id'])) {
					$keep[] = (string)$live['id'];
				}
			}

			$created = $this->calendarEvents->backfillMissingEvents($userId, $lives, $accountEmail);
			$removed = $this->calendarEvents->pruneForeignEvents($userId, $keep);
			if ($created > 0 || $removed > 0) {
				$this->logger->info('Reconciliation calendrier EMPREINTE : ' . $created . ' cree(s), ' . $removed . ' retire(s)', [
					'app' => self::APP,
					'user' => $userId,
				]);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Echec de la reconciliation calendrier des visios', [
				'app' => self::APP,
				'exception' => $e,
			]);
		}
	}

	/**
	 * A la deconnexion : vide le calendrier "EMPREINTE Live" (miroir local
	 * uniquement). On ne garde aucun liveId -> pruneForeignEvents retire tous les
	 * evenements EMPREINTE. Les Lives cote EMPREINTE ne sont PAS supprimes (le prune
	 * passe par SyncGuard) : la reconnexion les recree par back-fill. Best-effort.
	 */
	public function clearAll(string $userId): void {
		try {
			$removed = $this->calendarEvents->pruneForeignEvents($userId, []);
			if ($removed > 0) {
				$this->logger->info('Deconnexion EMPREINTE : ' . $removed . ' evenement(s) miroir retire(s)', [
					'app' => self::APP,
					'user' => $userId,
				]);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Echec du nettoyage du calendrier a la deconnexion', [
				'app' => self::APP,
				'exception' => $e,
			]);
		}
	}
}
