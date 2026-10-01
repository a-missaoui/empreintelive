<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\MeetingAccess;
use OCA\EmpreinteLive\Service\OAuthService;
use OCA\EmpreinteLive\Service\TokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MeetingAccessTest extends TestCase {
	private EmpreinteApiService&MockObject $api;
	private OAuthService&MockObject $oauth;
	private TokenService&MockObject $tokens;
	private MeetingAccess $access;

	protected function setUp(): void {
		$this->api = $this->createMock(EmpreinteApiService::class);
		$this->oauth = $this->createMock(OAuthService::class);
		$this->tokens = $this->createMock(TokenService::class);
		$this->access = new MeetingAccess($this->api, $this->oauth, $this->tokens, $this->createMock(LoggerInterface::class));
	}

	public function testCreatorGetsTheMeetingWhateverTheCase(): void {
		$this->tokens->method('hasToken')->willReturn(true);
		$this->oauth->method('getAccountEmail')->willReturn('Orga@Example.com');
		$this->api->method('getLive')->willReturn(['id' => '713', 'created_by' => 'orga@example.com']);

		$this->assertSame('713', $this->access->ownMeeting('alice', '713')['id']);
	}

	public function testSomeoneElsesMeetingIsRefusedEvenThoughTheApiAnswers(): void {
		$this->tokens->method('hasToken')->willReturn(true);
		$this->oauth->method('getAccountEmail')->willReturn('mallory@example.com');
		$this->api->method('getLive')->willReturn(['id' => '713', 'created_by' => 'orga@example.com']);

		$this->assertNull($this->access->ownMeeting('mallory', '713'));
	}

	public function testMeetingWithoutCreatorIsRefused(): void {
		$this->tokens->method('hasToken')->willReturn(true);
		$this->oauth->method('getAccountEmail')->willReturn('orga@example.com');
		$this->api->method('getLive')->willReturn(['id' => '713']);

		$this->assertNull($this->access->ownMeeting('alice', '713'));
	}

	public function testNotConnectedOrUnknownAccountNeverCallsTheApi(): void {
		$this->tokens->method('hasToken')->willReturnOnConsecutiveCalls(false, true);
		$this->oauth->method('getAccountEmail')->willReturn('');
		$this->api->expects($this->never())->method('getLive');

		$this->assertNull($this->access->ownMeeting('alice', '713'));
		$this->assertNull($this->access->ownMeeting('alice', '713'));
	}

	public function testApiFailureIsRefused(): void {
		$this->tokens->method('hasToken')->willReturn(true);
		$this->oauth->method('getAccountEmail')->willReturn('orga@example.com');
		$this->api->method('getLive')->willThrowException(new RuntimeException('not found'));

		$this->assertNull($this->access->ownMeeting('alice', '713'));
	}
}
