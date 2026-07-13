<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Reverse flow" : au lieu d'ajouter un bouton dans la modal du Calendar officiel
 * (impossible sans forker Calendar), on cree la visio dans notre app PUIS on ecrit
 * l'evenement dans le calendrier de l'utilisateur via l'API publique Nextcloud
 * (OCP\Calendar). Le Calendar officiel n'est jamais modifie.
 *
 * Les visios sont regroupees dans un calendrier dedie "EMPREINTE Live" (couleur
 * de marque) cree a la volee : l'utilisateur les distingue d'un coup d'oeil et peut
 * afficher/masquer ce calendrier independamment de ses autres evenements.
 *
 * Le lien de la visio est place dans LOCATION + DESCRIPTION : le listener de
 * suppression (CalendarObjectListener) retrouve ainsi le liveId et supprime le Live
 * si l'evenement est supprime.
 */

namespace OCA\EmpreinteLive\Service;

use DateTimeImmutable;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Calendar\CalendarEventStatus;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;
use function filter_var;
use function is_array;
use function strcasecmp;

class CalendarEventService {
	private const APP = 'empreintelive';

	/** Calendrier dedie ou sont regroupees toutes les visios EMPREINTE. */
	private const CALENDAR_URI = 'empreinte-live';
	private const CALENDAR_NAME = 'EMPREINTE Live';
	private const CALENDAR_COLOR = '#660099';

	public function __construct(
		private IManager $calendarManager,
		private CalDavBackend $davBackend,
		private IUserManager $userManager,
		private SyncGuard $syncGuard,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Cree un evenement pour un Live EMPREINTE. Best-effort : retourne l'UID de
	 * l'evenement cree, ou null si aucun calendrier inscriptible / en cas d'echec
	 * (le Live existe deja cote EMPREINTE, on ne casse pas l'appel pour autant).
	 *
	 * @param array<string,mixed> $live Reponse normalisee de createLive (url, id...)
	 * @param array<string,mixed> $data title, description, startTime, endTime (ISO8601)
	 * @param string $accountEmail Email du compte EMPREINTE connecte (pour choisir
	 *                             le lien organisateur vs participant, cf resolveMeetingUrl).
	 */
	public function createEventForLive(string $userId, array $live, array $data, string $accountEmail = ''): ?string {
		try {
			// Calendrier dedie "EMPREINTE Live" (cree si besoin) ; a defaut, on
			// retombe sur le premier calendrier inscriptible pour ne jamais bloquer.
			$calendar = $this->empreinteCalendar($userId) ?? $this->firstWritableCalendar($userId);
			if ($calendar === null) {
				$this->logger->warning('Aucun calendrier inscriptible pour creer l\'evenement de visio', [
					'app' => self::APP,
					'user' => $userId,
				]);
				return null;
			}

			$start = $this->parseDate($data['startTime'] ?? null) ?? new DateTimeImmutable();
			$end = $this->parseDate($data['endTime'] ?? null) ?? $start->modify('+1 hour');

			$url = $this->resolveMeetingUrl($live, $accountEmail);
			$title = trim((string)($data['title'] ?? '')) ?: 'EMPREINTE Live';
			$description = trim((string)($data['description'] ?? ''));
			if ($url !== '') {
				$description = trim($description . "\n\n" . 'Visioconférence EMPREINTE : ' . $url);
			}

			$builder = $this->calendarManager->createEventBuilder();
			$builder->setSummary($title);
			// STATUS est requis : depuis NC 32.0.11 le builder derefereence
			// $this->status->value sans garde -> sans setStatus() la creation casse.
			$builder->setStatus(CalendarEventStatus::CONFIRMED);
			$builder->setStartDate($start);
			$builder->setEndDate($end);
			$builder->setDescription($description);
			if ($url !== '') {
				$builder->setLocation($url);
			}
			$this->addAttendees($builder, $userId, $data['attendees'] ?? []);

			return $builder->createInCalendar($calendar);
		} catch (Throwable $e) {
			$this->logger->warning('Echec de la creation de l\'evenement calendrier pour la visio', [
				'app' => self::APP,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Choisit le lien a placer dans l'evenement selon le role de l'utilisateur
	 * connecte. Porte de empreinteLive.js (resolveMeetingUrl) : le createur de la
	 * visio (dont l'email EMPREINTE == created_by) obtient le lien organisateur
	 * (admin_url) ; tout autre utilisateur obtient le lien participant. A defaut des
	 * trois champs, on retombe sur le lien generique (url/participant_url).
	 *
	 * @param array<string,mixed> $live
	 */
	private function resolveMeetingUrl(array $live, string $accountEmail): string {
		$admin = (string)($live['admin_url'] ?? '');
		$participant = (string)($live['participant_url'] ?? '');
		$createdBy = (string)($live['created_by'] ?? '');

		if ($admin !== '' && $participant !== '' && $createdBy !== '') {
			$isCreator = $accountEmail !== '' && strcasecmp($accountEmail, $createdBy) === 0;
			return $isCreator ? $admin : $participant;
		}
		return (string)($live['url'] ?? $participant);
	}

	/**
	 * Ajoute les participants comme ATTENDEE + un ORGANIZER (email du compte
	 * Nextcloud). L'ORGANIZER est indispensable : sans lui, le planificateur natif
	 * n'envoie pas les invitations. Si l'utilisateur n'a pas d'email, on saute les
	 * participants plutot que de faire echouer la creation de l'evenement.
	 *
	 * @param mixed $attendees Liste d'emails
	 */
	private function addAttendees(ICalendarEventBuilder $builder, string $userId, $attendees): void {
		if (!is_array($attendees) || $attendees === []) {
			return;
		}
		$user = $this->userManager->get($userId);
		$organiser = $user !== null ? (string)$user->getEMailAddress() : '';
		if ($organiser === '') {
			$this->logger->info('Participants ignores : le compte Nextcloud n\'a pas d\'email (organisateur requis)', [
				'app' => self::APP,
				'user' => $userId,
			]);
			return;
		}

		$builder->setOrganizer($organiser, $user->getDisplayName());
		$seen = [strtolower($organiser) => true];
		foreach ($attendees as $email) {
			$email = trim((string)$email);
			$key = strtolower($email);
			if ($email === '' || isset($seen[$key]) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				continue;
			}
			$seen[$key] = true;
			$builder->addAttendee($email, null);
		}
	}

	private function parseDate(?string $iso): ?DateTimeImmutable {
		if ($iso === null || $iso === '') {
			return null;
		}
		try {
			return new DateTimeImmutable($iso);
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * Calendrier dedie "EMPREINTE Live" : on le reutilise s'il existe deja, sinon
	 * on le cree (nom + couleur de marque) via le backend CalDAV du coeur puis on le
	 * relit. Best-effort : retourne null si la creation echoue (fallback en amont).
	 */
	private function empreinteCalendar(string $userId): ?ICreateFromString {
		$principal = 'principals/users/' . $userId;

		$existing = $this->findEmpreinteLive($principal);
		if ($existing !== null) {
			return $existing;
		}

		// Le calendrier dedie a pu etre mis en corbeille : son URI reste alors
		// reservee, donc createCalendar echouerait et on retomberait silencieusement
		// sur "Personal". On le restaure d'abord pour que les visios restent
		// regroupees dans leur calendrier (self-healing).
		if ($this->restoreTrashedEmpreinteLive($principal)) {
			$restored = $this->findEmpreinteLive($principal);
			if ($restored !== null) {
				return $restored;
			}
		}

		try {
			$this->davBackend->createCalendar($principal, self::CALENDAR_URI, [
				'{DAV:}displayname' => self::CALENDAR_NAME,
				'{http://apple.com/ns/ical/}calendar-color' => self::CALENDAR_COLOR,
			]);
		} catch (Throwable $e) {
			// Peut echouer si un calendrier de meme URI existe deja (course) : on
			// retente simplement la lecture ci-dessous avant d'abandonner.
			$this->logger->warning('Echec de la creation du calendrier EMPREINTE dedie', [
				'app' => self::APP,
				'exception' => $e,
			]);
		}

		return $this->findEmpreinteLive($principal);
	}

	/**
	 * Restaure le calendrier dedie s'il se trouve dans la corbeille : tant qu'il y
	 * est, son URI reste reservee et empeche sa recreation. Best-effort. Retourne
	 * true si un calendrier a ete restaure.
	 */
	private function restoreTrashedEmpreinteLive(string $principal): bool {
		try {
			foreach ($this->davBackend->getDeletedCalendars(PHP_INT_MAX) as $deleted) {
				$id = (int)($deleted['id'] ?? 0);
				if ($id === 0) {
					continue;
				}
				$calendar = $this->davBackend->getCalendarById($id);
				if ($calendar === null) {
					continue;
				}
				if (($calendar['uri'] ?? '') === self::CALENDAR_URI
					&& ($calendar['principaluri'] ?? '') === $principal) {
					$this->davBackend->restoreCalendar($id);
					$this->logger->info('Calendrier EMPREINTE dedie restaure depuis la corbeille', [
						'app' => self::APP,
					]);
					return true;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('Echec de la restauration du calendrier EMPREINTE depuis la corbeille', [
				'app' => self::APP,
				'exception' => $e,
			]);
		}
		return false;
	}

	/** Retrouve le calendrier dedie parmi ceux du principal, s'il est utilisable. */
	private function findEmpreinteLive(string $principal): ?ICreateFromString {
		foreach ($this->calendarManager->getCalendarsForPrincipal($principal) as $calendar) {
			if (!($calendar instanceof ICreateFromString) || !($calendar instanceof ICalendar)) {
				continue;
			}
			if ($calendar->getUri() === self::CALENDAR_URI && !$calendar->isDeleted()) {
				return $calendar;
			}
		}
		return null;
	}

	/**
	 * Premier calendrier inscriptible de l'utilisateur (priorite au calendrier
	 * "personal"), capable d'accueillir un evenement cree depuis une chaine ICS.
	 */
	private function firstWritableCalendar(string $userId): ?ICreateFromString {
		$calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $userId);
		$fallback = null;
		foreach ($calendars as $calendar) {
			if (!($calendar instanceof ICreateFromString) || !($calendar instanceof ICalendar)) {
				continue;
			}
			if ($calendar->isDeleted() || !$this->isWritable($calendar)) {
				continue;
			}
			if ($calendar->getUri() === 'personal') {
				return $calendar;
			}
			$fallback ??= $calendar;
		}
		return $fallback;
	}

	private function isWritable(ICalendar $calendar): bool {
		if ($calendar instanceof ICalendarIsWritable) {
			return $calendar->isWritable();
		}
		return ($calendar->getPermissions() & Constants::PERMISSION_CREATE) !== 0;
	}

	/**
	 * Cree les evenements manquants pour les visios du compte connecte : celles
	 * renvoyees par /lives qui n'ont pas encore d'evenement dans le calendrier.
	 * Sert a reconcilier calendrier et liste quand une visio a ete creee hors de
	 * cette app (plateforme EMPREINTE, autre session) ou apres un changement de
	 * compte. NON DESTRUCTIF : ne supprime jamais rien (chaque compte retrouve ses
	 * visios en se reconnectant). Best-effort. Retourne le nombre d'evenements crees.
	 *
	 * Les participants ne sont pas reajoutes ici : on evite de renvoyer des
	 * invitations iMIP a chaque reconciliation.
	 *
	 * @param list<array<string,mixed>> $lives Liste normalisee (EmpreinteApiService::getMeetings)
	 */
	public function backfillMissingEvents(string $userId, array $lives, string $accountEmail): int {
		if ($lives === []) {
			return 0;
		}
		$existing = $this->existingLiveIds($userId);
		$created = 0;
		foreach ($lives as $live) {
			if (!is_array($live)) {
				continue;
			}
			$liveId = isset($live['id']) ? (string)$live['id'] : '';
			if ($liveId === '' || isset($existing[$liveId])) {
				continue;
			}
			$data = [
				'title' => $live['title'] ?? '',
				'description' => $live['description'] ?? '',
				'startTime' => $live['dateStartDiffusion'] ?? null,
				'endTime' => $live['dateEndDiffusion'] ?? null,
			];
			// Ces visios appartiennent au compte connecte (createur) -> lien organisateur.
			$liveForUrl = array_merge($live, ['created_by' => $accountEmail]);
			if ($this->createEventForLive($userId, $liveForUrl, $data, $accountEmail) !== null) {
				$existing[$liveId] = true;
				$created++;
			}
		}
		return $created;
	}

	/**
	 * Retire du calendrier "EMPREINTE Live" les evenements dont le liveId n'est PAS
	 * dans $keepLiveIds (typiquement : visios d'un autre compte EMPREINTE). Le
	 * calendrier reflete ainsi uniquement le compte connecte.
	 *
	 * IMPORTANT : on ne supprime QUE le miroir local (suppression definitive, sans
	 * corbeille pour eviter tout evenement "vider la corbeille" ulterieur), sous
	 * SyncGuard pour que le listener ne supprime jamais le Live EMPREINTE associe.
	 * Les Lives restent donc intacts cote EMPREINTE et sont recrees par le back-fill
	 * quand leur compte se reconnecte. Les evenements non-EMPREINTE (sans liveId) ne
	 * sont jamais touches. Best-effort. Retourne le nombre d'evenements retires.
	 *
	 * @param list<string> $keepLiveIds liveId a conserver (compte connecte)
	 */
	public function pruneForeignEvents(string $userId, array $keepLiveIds): int {
		$calendar = $this->findEmpreinteLive('principals/users/' . $userId);
		if (!($calendar instanceof ICalendar)) {
			return 0;
		}
		$keep = [];
		foreach ($keepLiveIds as $id) {
			$keep[(string)$id] = true;
		}

		$removed = 0;
		$this->syncGuard->suppress();
		try {
			$calendarId = (int)$calendar->getKey();
			foreach ($this->davBackend->getCalendarObjects($calendarId) as $meta) {
				$uri = (string)($meta['uri'] ?? '');
				if ($uri === '') {
					continue;
				}
				$object = $this->davBackend->getCalendarObject($calendarId, $uri);
				$ics = is_array($object) ? (string)($object['calendardata'] ?? '') : '';
				$liveId = EmpreinteLiveId::fromIcs($ics);
				if ($liveId === null || isset($keep[$liveId])) {
					continue; // pas une visio EMPREINTE, ou visio du compte connecte : on garde.
				}
				$this->davBackend->deleteCalendarObject($calendarId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
				$removed++;
			}
		} catch (Throwable $e) {
			$this->logger->warning('Echec du prune des evenements EMPREINTE d\'un autre compte', [
				'app' => self::APP,
				'exception' => $e,
			]);
		} finally {
			$this->syncGuard->release();
		}
		return $removed;
	}

	/**
	 * Supprime l'evenement-miroir d'UNE visio precise dans le calendrier "EMPREINTE
	 * Visio". Appele quand la visio est supprimee depuis la liste (sens live ->
	 * calendrier). Encadre par SyncGuard pour que la suppression de l'evenement ne
	 * declenche pas le listener -> deleteLive() sur un Live deja parti cote EMPREINTE.
	 * Best-effort. Retourne le nombre d'evenements retires.
	 */
	public function deleteEventForLive(string $userId, string $liveId): int {
		$target = (string)$liveId;
		if ($target === '') {
			return 0;
		}
		$calendar = $this->findEmpreinteLive('principals/users/' . $userId);
		if (!($calendar instanceof ICalendar)) {
			return 0;
		}

		$removed = 0;
		$this->syncGuard->suppress();
		try {
			$calendarId = (int)$calendar->getKey();
			foreach ($this->davBackend->getCalendarObjects($calendarId) as $meta) {
				$uri = (string)($meta['uri'] ?? '');
				if ($uri === '') {
					continue;
				}
				$object = $this->davBackend->getCalendarObject($calendarId, $uri);
				$ics = is_array($object) ? (string)($object['calendardata'] ?? '') : '';
				if (EmpreinteLiveId::fromIcs($ics) !== $target) {
					continue;
				}
				$this->davBackend->deleteCalendarObject($calendarId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
				$removed++;
			}
		} catch (Throwable $e) {
			$this->logger->warning('Echec de la suppression de l\'evenement miroir EMPREINTE', [
				'app' => self::APP,
				'exception' => $e,
			]);
		} finally {
			$this->syncGuard->release();
		}
		return $removed;
	}

	/**
	 * Ensemble des liveId ayant deja un evenement dans le calendrier "EMPREINTE
	 * Visio" (cle = liveId). Le liveId est relu dans l'ICS de chaque evenement :
	 * aucun stockage separe, coherent avec le listener.
	 *
	 * @return array<string,true>
	 */
	private function existingLiveIds(string $userId): array {
		$calendar = $this->findEmpreinteLive('principals/users/' . $userId);
		if (!($calendar instanceof ICalendar)) {
			return [];
		}
		$ids = [];
		try {
			$calendarId = (int)$calendar->getKey();
			foreach ($this->davBackend->getCalendarObjects($calendarId) as $meta) {
				$uri = (string)($meta['uri'] ?? '');
				if ($uri === '') {
					continue;
				}
				$object = $this->davBackend->getCalendarObject($calendarId, $uri);
				$ics = is_array($object) ? (string)($object['calendardata'] ?? '') : '';
				$liveId = EmpreinteLiveId::fromIcs($ics);
				if ($liveId !== null) {
					$ids[$liveId] = true;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('Echec de la lecture des evenements EMPREINTE existants (back-fill)', [
				'app' => self::APP,
				'exception' => $e,
			]);
		}
		return $ids;
	}
}
