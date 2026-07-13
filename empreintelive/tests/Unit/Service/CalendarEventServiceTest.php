<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\EmpreinteLive\Service\CalendarEventService;
use OCA\EmpreinteLive\Service\SyncGuard;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Calendrier a la fois inscriptible (ICreateFromString) et introspectable (ICalendar). */
interface WritableTestCalendar extends ICreateFromString, ICalendar {
}

class CalendarEventServiceTest extends TestCase {
	private function calendar(string $uri, int $perms = Constants::PERMISSION_CREATE, bool $deleted = false, string $key = '7'): WritableTestCalendar {
		$calendar = $this->createMock(WritableTestCalendar::class);
		$calendar->method('isDeleted')->willReturn($deleted);
		$calendar->method('getPermissions')->willReturn($perms);
		$calendar->method('getUri')->willReturn($uri);
		$calendar->method('getKey')->willReturn($key);
		return $calendar;
	}

	/** ICS minimal dont le liveId (room=<id>) est resolvable depuis LOCATION. */
	private function icsWithLiveId(string $liveId): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$liveId\r\nLOCATION:https://join.empreinte.live/fr?room=$liveId-simple_live_x&token=t\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	private function builderWriting(ICreateFromString $target, string $uid = 'uid-123', ?string $expectedLocation = null): ICalendarEventBuilder {
		$builder = $this->createMock(ICalendarEventBuilder::class);
		$builder->method('setSummary')->willReturnSelf();
		$builder->method('setStartDate')->willReturnSelf();
		$builder->method('setEndDate')->willReturnSelf();
		$builder->method('setDescription')->willReturnSelf();
		if ($expectedLocation !== null) {
			$builder->expects($this->once())->method('setLocation')->with($expectedLocation)->willReturnSelf();
		} else {
			$builder->method('setLocation')->willReturnSelf();
		}
		$builder->expects($this->once())->method('createInCalendar')->with($target)->willReturn($uid);
		return $builder;
	}

	public function testUsesDedicatedCalendarWhenItAlreadyExists(): void {
		$empreinte = $this->calendar('empreinte-live');

		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->with('principals/users/u')->willReturn([$empreinte]);
		$manager->method('createEventBuilder')->willReturn($this->builderWriting($empreinte, 'uid-1', 'https://meet.empreinte.live/274'));

		$dav = $this->createMock(CalDavBackend::class);
		// Le calendrier existe deja : on ne doit jamais tenter de le recreer.
		$dav->expects($this->never())->method('createCalendar');

		$svc = new CalendarEventService($manager, $dav, $this->createMock(IUserManager::class), new SyncGuard(), $this->createMock(LoggerInterface::class));
		$uid = $svc->createEventForLive('u',
			['url' => 'https://meet.empreinte.live/274'],
			['title' => 'T', 'startTime' => '2026-01-01T10:00:00Z', 'endTime' => '2026-01-01T11:00:00Z'],
		);

		$this->assertSame('uid-1', $uid);
	}

	public function testCreatorGetsAdminUrlAndOthersGetParticipantUrl(): void {
		// created_by == email du compte connecte -> lien organisateur (admin_url).
		$live = [
			'admin_url' => 'https://join.empreinte.live/fr?room=401-admin',
			'participant_url' => 'https://join.empreinte.live/fr?room=401-participant',
			'created_by' => 'Empadmin@Empreinte.dev', // casse differente : compare insensible
		];

		$empreinte = $this->calendar('empreinte-live');
		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([$empreinte]);
		$manager->method('createEventBuilder')->willReturn(
			$this->builderWriting($empreinte, 'uid-admin', 'https://join.empreinte.live/fr?room=401-admin'),
		);
		$svc = new CalendarEventService($manager, $this->createMock(CalDavBackend::class), $this->createMock(IUserManager::class), new SyncGuard(), $this->createMock(LoggerInterface::class));
		$this->assertSame('uid-admin', $svc->createEventForLive(
			'u', $live, ['startTime' => '2026-01-01T10:00:00Z'], 'empadmin@empreinte.dev',
		));

		// Email different -> lien participant.
		$empreinte2 = $this->calendar('empreinte-live');
		$manager2 = $this->createMock(IManager::class);
		$manager2->method('getCalendarsForPrincipal')->willReturn([$empreinte2]);
		$manager2->method('createEventBuilder')->willReturn(
			$this->builderWriting($empreinte2, 'uid-part', 'https://join.empreinte.live/fr?room=401-participant'),
		);
		$svc2 = new CalendarEventService($manager2, $this->createMock(CalDavBackend::class), $this->createMock(IUserManager::class), new SyncGuard(), $this->createMock(LoggerInterface::class));
		$this->assertSame('uid-part', $svc2->createEventForLive(
			'u', $live, ['startTime' => '2026-01-01T10:00:00Z'], 'someone.else@corp.fr',
		));
	}

	public function testCreatesDedicatedCalendarWhenMissingThenWritesToIt(): void {
		$personal = $this->calendar('personal');
		$empreinte = $this->calendar('empreinte-live');

		$manager = $this->createMock(IManager::class);
		// 1er appel : le calendrier dedie n'existe pas encore. 2e appel (apres creation) : il est la.
		$manager->method('getCalendarsForPrincipal')->willReturnOnConsecutiveCalls([$personal], [$personal, $empreinte]);
		$manager->method('createEventBuilder')->willReturn($this->builderWriting($empreinte, 'uid-2'));

		$dav = $this->createMock(CalDavBackend::class);
		$dav->expects($this->once())->method('createCalendar')->with(
			'principals/users/u',
			'empreinte-live',
			$this->callback(fn (array $p) => ($p['{DAV:}displayname'] ?? null) === 'EMPREINTE Live'
				&& ($p['{http://apple.com/ns/ical/}calendar-color'] ?? null) === '#660099'),
		);

		$svc = new CalendarEventService($manager, $dav, $this->createMock(IUserManager::class), new SyncGuard(), $this->createMock(LoggerInterface::class));
		$uid = $svc->createEventForLive('u', ['url' => 'x'], ['startTime' => '2026-01-01T10:00:00Z']);

		$this->assertSame('uid-2', $uid);
	}

	public function testFallsBackToWritableCalendarWhenCreationFails(): void {
		$personal = $this->calendar('personal');

		$manager = $this->createMock(IManager::class);
		// Le calendrier dedie reste introuvable (creation en echec) -> fallback sur "personal".
		$manager->method('getCalendarsForPrincipal')->willReturn([$personal]);
		$manager->method('createEventBuilder')->willReturn($this->builderWriting($personal, 'uid-3'));

		$dav = $this->createMock(CalDavBackend::class);
		$dav->method('createCalendar')->willThrowException(new \RuntimeException('boom'));

		$svc = new CalendarEventService($manager, $dav, $this->createMock(IUserManager::class), new SyncGuard(), $this->createMock(LoggerInterface::class));
		$uid = $svc->createEventForLive('u', ['url' => 'x'], ['startTime' => '2026-01-01T10:00:00Z']);

		$this->assertSame('uid-3', $uid);
	}

	public function testReturnsNullWhenNoWritableCalendar(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([]);

		$dav = $this->createMock(CalDavBackend::class);
		$dav->method('createCalendar')->willThrowException(new \RuntimeException('boom'));

		$svc = new CalendarEventService($manager, $dav, $this->createMock(IUserManager::class), new SyncGuard(), $this->createMock(LoggerInterface::class));
		$this->assertNull($svc->createEventForLive('u', [], ['startTime' => '2026-01-01T10:00:00Z']));
	}

	public function testAttendeesAddedWithOrganizerWhenUserHasEmail(): void {
		$empreinte = $this->calendar('empreinte-live');

		$builder = $this->createMock(ICalendarEventBuilder::class);
		$builder->method('setSummary')->willReturnSelf();
		$builder->method('setStartDate')->willReturnSelf();
		$builder->method('setEndDate')->willReturnSelf();
		$builder->method('setDescription')->willReturnSelf();
		$builder->method('setLocation')->willReturnSelf();
		$builder->expects($this->once())->method('setOrganizer')->with('me@corp.fr', 'Moi')->willReturnSelf();
		// L'organisateur est exclu, les doublons aussi -> 2 participants distincts.
		$builder->expects($this->exactly(2))->method('addAttendee')->willReturnSelf();
		$builder->method('createInCalendar')->willReturn('uid-att');

		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([$empreinte]);
		$manager->method('createEventBuilder')->willReturn($builder);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('me@corp.fr');
		$user->method('getDisplayName')->willReturn('Moi');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('u')->willReturn($user);

		$svc = new CalendarEventService($manager, $this->createMock(CalDavBackend::class), $users, new SyncGuard(), $this->createMock(LoggerInterface::class));
		$uid = $svc->createEventForLive('u', ['url' => 'x'], [
			'startTime' => '2026-01-01T10:00:00Z',
			'attendees' => ['alice@corp.fr', 'ALICE@corp.fr', 'me@corp.fr', 'bob@corp.fr', 'not-an-email'],
		]);

		$this->assertSame('uid-att', $uid);
	}

	public function testAttendeesSkippedWhenUserHasNoEmail(): void {
		$empreinte = $this->calendar('empreinte-live');

		$builder = $this->createMock(ICalendarEventBuilder::class);
		$builder->method('setSummary')->willReturnSelf();
		$builder->method('setStartDate')->willReturnSelf();
		$builder->method('setEndDate')->willReturnSelf();
		$builder->method('setDescription')->willReturnSelf();
		$builder->method('setLocation')->willReturnSelf();
		// Pas d'email organisateur -> aucun ATTENDEE (sinon le planificateur echoue).
		$builder->expects($this->never())->method('setOrganizer');
		$builder->expects($this->never())->method('addAttendee');
		$builder->method('createInCalendar')->willReturn('uid-noatt');

		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([$empreinte]);
		$manager->method('createEventBuilder')->willReturn($builder);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);

		$svc = new CalendarEventService($manager, $this->createMock(CalDavBackend::class), $users, new SyncGuard(), $this->createMock(LoggerInterface::class));
		$uid = $svc->createEventForLive('u', ['url' => 'x'], [
			'startTime' => '2026-01-01T10:00:00Z',
			'attendees' => ['alice@corp.fr'],
		]);

		$this->assertSame('uid-noatt', $uid);
	}

	public function testPruneRemovesForeignEmpreinteEventsOnlyUnderGuard(): void {
		$empreinte = $this->calendar('empreinte-live', Constants::PERMISSION_CREATE, false, '7');

		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([$empreinte]);

		$dav = $this->createMock(CalDavBackend::class);
		$dav->method('getCalendarObjects')->with(7)->willReturn([
			['uri' => 'keep.ics'],     // liveId 100 -> conserve (dans keep)
			['uri' => 'foreign.ics'],  // liveId 200 -> retire (autre compte)
			['uri' => 'plain.ics'],    // pas une visio EMPREINTE -> jamais touche
		]);
		$dav->method('getCalendarObject')->willReturnMap([
			[7, 'keep.ics', CalDavBackend::CALENDAR_TYPE_CALENDAR, ['calendardata' => $this->icsWithLiveId('100')]],
			[7, 'foreign.ics', CalDavBackend::CALENDAR_TYPE_CALENDAR, ['calendardata' => $this->icsWithLiveId('200')]],
			[7, 'plain.ics', CalDavBackend::CALENDAR_TYPE_CALENDAR, ['calendardata' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:p\r\nLOCATION:Paris\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"]],
		]);
		// Seul l'evenement de l'autre compte est supprime, en dur (forceDeletePermanently=true).
		$dav->expects($this->once())->method('deleteCalendarObject')
			->with(7, 'foreign.ics', CalDavBackend::CALENDAR_TYPE_CALENDAR, true);

		$guard = new SyncGuard();
		$svc = new CalendarEventService($manager, $dav, $this->createMock(IUserManager::class), $guard, $this->createMock(LoggerInterface::class));

		$removed = $svc->pruneForeignEvents('u', ['100']);

		$this->assertSame(1, $removed);
		$this->assertFalse($guard->isSuppressed(), 'le garde doit etre relache apres le prune');
	}

	public function testReadOnlyCalendarIsSkipped(): void {
		$readonly = $this->calendar('contact_birthdays', Constants::PERMISSION_READ);

		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([$readonly]);
		// Ni calendrier dedie ni calendrier inscriptible -> pas de builder cree.
		$manager->expects($this->never())->method('createEventBuilder');

		$dav = $this->createMock(CalDavBackend::class);
		$dav->method('createCalendar')->willThrowException(new \RuntimeException('boom'));

		$svc = new CalendarEventService($manager, $dav, $this->createMock(IUserManager::class), new SyncGuard(), $this->createMock(LoggerInterface::class));
		$this->assertNull($svc->createEventForLive('u', ['url' => 'x'], ['startTime' => '2026-01-01T10:00:00Z']));
	}
}
