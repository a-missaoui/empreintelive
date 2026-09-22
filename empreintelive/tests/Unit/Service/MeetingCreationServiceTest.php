<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\CalendarEventService;
use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\MeetingCreationService;
use OCA\EmpreinteLive\Service\OAuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MeetingCreationServiceTest extends TestCase {
	private EmpreinteApiService&MockObject $api;
	private CalendarEventService&MockObject $calendar;
	private OAuthService&MockObject $oauth;
	private MeetingCreationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->api = $this->createMock(EmpreinteApiService::class);
		$this->calendar = $this->createMock(CalendarEventService::class);
		$this->oauth = $this->createMock(OAuthService::class);
		$this->oauth->method('getAccountEmail')->willReturn('organisateur@example.test');
		$this->service = new MeetingCreationService(
			$this->api, $this->calendar, $this->oauth, $this->createMock(LoggerInterface::class),
		);
	}

	public function testSanitizeKeepsValidDedupedEmails(): void {
		$this->assertSame(
			['bob@example.test', 'alice@example.test'],
			$this->service->sanitizeEmails([
				'bob@example.test', 'BOB@example.test', ' alice@example.test ',
				'pas-une-adresse', '', ['email' => 'alice@example.test'],
			]),
		);
	}

	public function testSanitizeAcceptsObjects(): void {
		$this->assertSame(['c@example.test'], $this->service->sanitizeEmails([['email' => 'c@example.test']]));
	}

	public function testCreateChainsLiveCalendarAndInvitations(): void {
		$this->api->expects($this->once())->method('createLive')->willReturn(['id' => 657]);
		$this->calendar->expects($this->once())->method('createEventForLive')->willReturn('uid-1');
		$this->api->expects($this->once())->method('sendInvitations')
			->with('alice', '657', [
				'organiserEmail' => 'organisateur@example.test',
				'participantEmails' => ['bob@example.test'],
			]);

		$r = $this->service->create('alice', 'T', 'D', 's', 'e', ['bob@example.test', 'invalide']);

		$this->assertSame(657, $r['data']['id']);
		$this->assertTrue($r['eventCreated']);
		$this->assertTrue($r['invited']);
	}

	/** Une fois le Live cree, un echec d'invitation ne doit rien annuler. */
	public function testFailedInvitationDoesNotFailCreation(): void {
		$this->api->method('createLive')->willReturn(['id' => 1]);
		$this->api->method('sendInvitations')->willThrowException(new RuntimeException('smtp'));

		$r = $this->service->create('alice', 'T', 'D', 's', 'e', ['bob@example.test']);

		$this->assertSame(1, $r['data']['id']);
		$this->assertFalse($r['invited']);
	}

	public function testNoInvitationWithoutParticipants(): void {
		$this->api->method('createLive')->willReturn(['id' => 1]);
		$this->api->expects($this->never())->method('sendInvitations');

		$this->assertFalse($this->service->create('alice', 'T', 'D', 's', 'e', [])['invited']);
	}

	/** Seule la creation du Live est bloquante : son echec remonte. */
	public function testLiveCreationFailureBubbles(): void {
		$this->api->method('createLive')->willThrowException(new RuntimeException('api'));
		$this->calendar->expects($this->never())->method('createEventForLive');

		$this->expectException(RuntimeException::class);
		$this->service->create('alice', 'T', 'D', 's', 'e', []);
	}
}
