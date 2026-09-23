<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Garde le Live EMPREINTE synchronise avec son evenement Calendar. Le
 * liveId est lu DIRECTEMENT dans les donnees ICS de l'evenement (proprietes
 * X-EMPREINTE-*, LOCATION ou DESCRIPTION) : aucun stockage cote app n'est
 * necessaire, l'app reste independante de l'app Calendar (on ne reagit qu'aux
 * evenements publics OCP\Calendar\Events).
 *
 *  - mise en corbeille / suppression definitive -> suppression du Live ;
 *  - modification de l'evenement                 -> mise a jour du Live.
 *
 * On ecoute la mise en corbeille (et pas seulement la suppression definitive)
 * pour que la suppression cote UI se propage immediatement, comme le faisait la
 * version calendrier d'origine.
 *
 * @template-implements IEventListener<Event>
 */

namespace OCA\EmpreinteLive\Listener;

use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\EmpreinteLiveId;
use OCA\EmpreinteLive\Service\SyncGuard;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;
use Throwable;
use function is_string;
use function str_starts_with;

class CalendarObjectListener implements IEventListener {
	private const APP = 'empreintelive';

	public function __construct(
		private EmpreinteApiService $api,
		private SyncGuard $syncGuard,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof CalendarObjectMovedToTrashEvent
				|| $event instanceof CalendarObjectDeletedEvent) {
				// Suppression initiee par l'app (prune de reconciliation lors d'un
				// changement de compte) : on retire le miroir local mais on ne touche
				// PAS au Live EMPREINTE (il doit rester pour l'autre compte).
				if ($this->syncGuard->isSuppressed()) {
					return;
				}
				$this->handleDeletion($event);
			} elseif ($event instanceof CalendarObjectUpdatedEvent) {
				$this->handleUpdate($event);
			}
		} catch (Throwable $e) {
			// Best effort : ne jamais casser l'operation Calendar de l'utilisateur.
			$this->logger->warning('Echec de la synchronisation du Live EMPREINTE depuis le listener', [
				'app' => self::APP,
				'exception' => $e,
			]);
		}
	}

	/** Supprime le Live EMPREINTE associe a l'evenement corbeille/supprime. */
	private function handleDeletion(AbstractCalendarObjectEvent $event): void {
		[$liveId, $userId] = $this->resolveLiveAndUser($event);
		if ($liveId === null || $userId === null) {
			return;
		}

		$this->api->deleteLive($userId, $liveId);
		$this->logger->info('Live EMPREINTE supprime suite a la suppression de l\'evenement', [
			'app' => self::APP,
			'liveId' => $liveId,
			'user' => $userId,
		]);
	}

	/** Met a jour le Live EMPREINTE (dates, titre, description) apres modification. */
	private function handleUpdate(AbstractCalendarObjectEvent $event): void {
		[$liveId, $userId] = $this->resolveLiveAndUser($event);
		if ($liveId === null || $userId === null) {
			return;
		}

		$ics = (string)($event->getObjectData()['calendardata'] ?? '');
		$data = $this->extractEventData($ics);
		if ($data === null) {
			return;
		}

		$this->api->updateLive($userId, $liveId, $data);
		$this->logger->info('Live EMPREINTE mis a jour suite a la modification de l\'evenement', [
			'app' => self::APP,
			'liveId' => $liveId,
			'user' => $userId,
		]);
	}

	/**
	 * Lit le liveId (dans l'ICS) et l'UID proprietaire (dans le calendrier) de
	 * l'evenement. Retourne [null, null] si l'evenement n'est pas lie a EMPREINTE
	 * ou si le calendrier n'a pas de proprietaire identifiable.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function resolveLiveAndUser(AbstractCalendarObjectEvent $event): array {
		$ics = $event->getObjectData()['calendardata'] ?? null;
		if (!is_string($ics) || $ics === '') {
			return [null, null];
		}
		$liveId = EmpreinteLiveId::fromIcs($ics);
		if ($liveId === null) {
			return [null, null]; // Pas un evenement lie a EMPREINTE : rien a faire.
		}
		$userId = $this->extractUserId($event->getCalendarData());
		return [$liveId, $userId];
	}

	/**
	 * Extrait titre / description / dates de l'evenement pour la mise a jour du
	 * Live. La ligne "Visioconference EMPREINTE : <url>" ajoutee a la creation est
	 * retiree pour ne pas la reinjecter dans la description du Live.
	 *
	 * @return array{title: string, description: string, startTime: ?string, endTime: ?string}|null
	 */
	private function extractEventData(string $ics): ?array {
		try {
			$vobject = Reader::read($ics);
		} catch (Throwable $e) {
			return null;
		}

		foreach ($vobject->getComponents() as $component) {
			if ($component->name !== 'VEVENT') {
				continue;
			}
			return [
				'title' => $component->SUMMARY !== null ? (string)$component->SUMMARY : '',
				'description' => $this->cleanDescription($component->DESCRIPTION !== null ? (string)$component->DESCRIPTION : ''),
				'startTime' => $this->isoDate($component->DTSTART),
				'endTime' => $this->isoDate($component->DTEND),
			];
		}
		return null;
	}

	/** Formate une propriete date ICS (DTSTART/DTEND) en ISO8601, ou null. */
	private function isoDate($property): ?string {
		if ($property === null) {
			return null;
		}
		try {
			return $property->getDateTime()->format('c');
		} catch (Throwable $e) {
			return null;
		}
	}

	/** Retire la ligne "Visioconference EMPREINTE : <url>" ajoutee cote app. */
	private function cleanDescription(string $description): string {
		$stripped = preg_replace('/\R*Visioconf\x{00E9}rence EMPREINTE\s*:.*$/su', '', $description);
		$stripped = trim((string)$stripped);
		return $stripped !== '' ? $stripped : trim($description);
	}

	/**
	 * Deduit l'UID Nextcloud proprietaire a partir du principaluri du calendrier.
	 *
	 * @param array<string,mixed> $calendarData
	 */
	private function extractUserId(array $calendarData): ?string {
		$principal = (string)($calendarData['principaluri'] ?? '');
		$prefix = 'principals/users/';
		if (str_starts_with($principal, $prefix)) {
			$uid = substr($principal, strlen($prefix));
			return $uid !== '' ? $uid : null;
		}
		return null;
	}
}
