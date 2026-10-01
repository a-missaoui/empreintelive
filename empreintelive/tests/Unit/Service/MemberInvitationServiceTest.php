<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\MeetingAccess;
use OCA\EmpreinteLive\Service\MeetingCreationService;
use OCA\EmpreinteLive\Service\MemberInvitationService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MemberInvitationServiceTest extends TestCase {
	private MeetingAccess&MockObject $access;
	private MeetingCreationService&MockObject $meetings;
	private IUserManager&MockObject $userManager;
	private MemberInvitationService $service;

	protected function setUp(): void {
		$this->access = $this->createMock(MeetingAccess::class);
		$this->meetings = $this->createPartialMock(MeetingCreationService::class, ['sendInvitations']);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(function (string $uid): ?IUser {
			$emails = ['bob' => 'bob@example.com', 'carol' => '', 'alice' => 'alice@example.com'];
			if (!isset($emails[$uid])) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getEMailAddress')->willReturn($emails[$uid]);
			return $user;
		});
		$this->service = new MemberInvitationService($this->access, $this->meetings, $this->userManager);
	}

	public function testOnlyTheCreatorCanInvite(): void {
		$this->access->method('ownMeeting')->willReturn(null);
		$this->meetings->expects($this->never())->method('sendInvitations');

		$this->assertNull($this->service->invite('mallory', '713', ['bob'], []));
	}

	public function testResolvesMembersAndSkipsTheAuthorAndMembersWithoutEmail(): void {
		$this->access->method('ownMeeting')->with('alice', '713')->willReturn(['id' => '713', 'title' => 'Point']);
		$this->meetings->expects($this->once())->method('sendInvitations')
			->with('alice', $this->callback(static fn (array $live): bool => $live['id'] === '713'), ['guest@example.org', 'bob@example.com'])
			->willReturn(true);

		$result = $this->service->invite('alice', '713', ['alice', 'bob', 'carol', 'ghost', 42], ['guest@example.org', 'not-an-email']);

		$this->assertSame(['invited' => 2, 'withoutEmail' => 2], $result);
	}

	public function testNothingToSendWhenNobodyHasAnAddress(): void {
		$this->access->method('ownMeeting')->willReturn(['id' => '713']);
		$this->meetings->expects($this->never())->method('sendInvitations');

		$this->assertSame(['invited' => 0, 'withoutEmail' => 1], $this->service->invite('alice', '713', ['carol'], []));
	}

	public function testFailedSendingReportsNobodyInvited(): void {
		$this->access->method('ownMeeting')->willReturn(['id' => '713']);
		$this->meetings->method('sendInvitations')->willReturn(false);

		$this->assertSame(['invited' => 0, 'withoutEmail' => 0], $this->service->invite('alice', '713', ['bob'], []));
	}
}
