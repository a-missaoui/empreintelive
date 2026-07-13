<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Listener;

use OCA\EmpreinteLive\Listener\CalendarObjectListener;
use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\SyncGuard;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class CalendarObjectListenerTest extends TestCase {
	private EmpreinteApiService&MockObject $api;
	private SyncGuard $guard;
	private CalendarObjectListener $listener;

	protected function setUp(): void {
		$this->api = $this->createMock(EmpreinteApiService::class);
		$this->guard = new SyncGuard();
		$this->listener = new CalendarObjectListener($this->api, $this->guard, $this->createMock(LoggerInterface::class));
	}

	/** ICS d'un evenement EMPREINTE resolvant le liveId 274. */
	private function empreinteEvent(): CalendarObjectDeletedEvent {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nLOCATION:https://meet.empreinte.live/274\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		return new CalendarObjectDeletedEvent(1, ['principaluri' => 'principals/users/u'], [], ['calendardata' => $ics]);
	}

	public function testHandleIgnoresUnrelatedEvents(): void {
		$this->api->expects($this->never())->method('deleteLive');
		$this->listener->handle(new Event());
	}

	public function testDeletionDeletesLiveWhenNotSuppressed(): void {
		$this->api->expects($this->once())->method('deleteLive')->with('u', '274');
		$this->listener->handle($this->empreinteEvent());
	}

	public function testDeletionSkippedWhenGuardSuppressed(): void {
		// Prune initie par l'app : le garde est actif -> on ne touche PAS au Live.
		$this->guard->suppress();
		$this->api->expects($this->never())->method('deleteLive');
		$this->listener->handle($this->empreinteEvent());
	}

	public function testExtractUserIdFromPrincipal(): void {
		$m = new ReflectionMethod(CalendarObjectListener::class, 'extractUserId');
		$m->setAccessible(true);
		$this->assertSame('admin', $m->invoke($this->listener, ['principaluri' => 'principals/users/admin']));
		$this->assertNull($m->invoke($this->listener, ['principaluri' => 'principals/system/foo']));
		$this->assertNull($m->invoke($this->listener, []));
	}
}
