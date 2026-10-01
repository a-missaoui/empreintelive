<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Talk;

use DateTimeImmutable;
use DateTimeZone;
use OCA\EmpreinteLive\Exception\NotConnectedException;
use OCA\EmpreinteLive\Service\MeetingCreationService;
use OCA\EmpreinteLive\Service\TokenService;
use OCA\EmpreinteLive\Talk\MeetingBotHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MeetingBotHandlerTest extends TestCase {
	private const APP_PAGE = 'https://cloud.example.com/apps/empreintelive/';

	private MeetingCreationService&MockObject $meetings;
	private TokenService&MockObject $tokens;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private MeetingBotHandler $handler;

	protected function setUp(): void {
		$this->meetings = $this->createMock(MeetingCreationService::class);
		$this->tokens = $this->createMock(TokenService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('Europe/Paris');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, $params = []): string => vsprintf($text, (array)$params));
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l);
		$l10nFactory->method('getUserLanguage')->willReturn('fr');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []): string => self::APP_PAGE . ($params === [] ? '' : '?' . http_build_query($params)),
		);
		$dates = $this->createMock(IDateTimeFormatter::class);
		$dates->method('formatDateTime')->willReturn('30 sept. 2026 10:00');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-09-29 14:07:00', new DateTimeZone('UTC')));

		$this->handler = new MeetingBotHandler(
			$this->meetings,
			$this->tokens,
			$this->userManager,
			$config,
			$l10nFactory,
			$urlGenerator,
			$dates,
			$time,
			$this->logger,
		);
	}

	private function message(string $text, string $actor = 'users/alice', string $type = 'Create'): array {
		return [
			'type' => $type,
			'actor' => ['type' => 'Person', 'id' => $actor, 'name' => 'Alice'],
			'object' => ['type' => 'Note', 'id' => '42', 'name' => 'message', 'content' => json_encode(['message' => $text, 'parameters' => []])],
			'target' => ['type' => 'Collection', 'id' => 'room123', 'name' => 'Équipe produit'],
		];
	}

	private function alice(bool $connected = true): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->tokens->method('hasToken')->willReturn($connected);
	}

	public function testOrdinaryMessagesGetNoAnswerAndNothingIsLogged(): void {
		$this->alice();
		$this->meetings->expects($this->never())->method('create');
		$this->logger->expects($this->never())->method($this->anything());

		$this->assertNull($this->handler->handle($this->message('bonjour à tous')));
	}

	public function testReactionsAndSystemMessagesAreIgnored(): void {
		$this->assertNull($this->handler->handle($this->message('/empreinte Point', 'users/alice', 'Like')));
		$this->assertNull($this->handler->handle($this->message('/empreinte Point', 'users/alice', 'Activity')));
	}

	public function testCreatesTheMeetingAndPostsTheParticipantLinkAndTheInviteLink(): void {
		$this->alice();
		$this->meetings->expects($this->once())->method('create')
			->with('alice', 'Revue', 'Created from the conversation "Équipe produit" in Nextcloud Talk.', '2026-09-30T08:00:00Z', '2026-09-30T08:30:00Z')
			->willReturn(['data' => ['id' => 713, 'participant_url' => 'https://join.empreinte.live/fr?room=713-x&token=PART', 'admin_url' => 'https://join.empreinte.live/fr?room=713-x&token=ADMIN'], 'eventCreated' => true, 'invited' => false]);

		$answer = $this->handler->handle($this->message('/empreinte Revue demain 10:00 30min'));

		$this->assertStringContainsString('**Revue** · 30 sept. 2026 10:00', $answer);
		$this->assertStringContainsString("\nhttps://join.empreinte.live/fr?room=713-x&token=PART\n", $answer);
		$this->assertStringContainsString(self::APP_PAGE . '?live=713&talk=room123', $answer);
		$this->assertStringNotContainsString('ADMIN', $answer);
	}

	public function testUntitledMeetingIsNamedAfterTheConversation(): void {
		$this->alice();
		$this->meetings->expects($this->once())->method('create')
			->with('alice', 'Meeting in Équipe produit', $this->anything(), $this->anything(), $this->anything())
			->willReturn(['data' => ['id' => 1, 'participant_url' => 'P']]);

		$this->handler->handle($this->message('/empreinte'));
	}

	public function testGuestsCannotCreateMeetings(): void {
		$this->meetings->expects($this->never())->method('create');

		$answer = $this->handler->handle($this->message('/empreinte Point', 'guests/abc'));

		$this->assertSame('Only Nextcloud users can create an EMPREINTE Live meeting from a conversation.', $answer);
	}

	public function testFederatedUsersCannotCreateMeetings(): void {
		$this->meetings->expects($this->never())->method('create');

		$this->assertStringStartsWith('Only Nextcloud users', $this->handler->handle($this->message('/empreinte Point', 'federated_users/bob@other.example')));
	}

	public function testNotConnectedAuthorIsSentToTheAppPage(): void {
		$this->alice(false);
		$this->meetings->expects($this->never())->method('create');

		$this->assertSame('Connect your EMPREINTE account first: ' . self::APP_PAGE, $this->handler->handle($this->message('/empreinte Point')));
	}

	public function testAccountRejectedByEmpreinteIsSentToTheAppPage(): void {
		$this->alice();
		$this->meetings->method('create')->willThrowException(new NotConnectedException('User not found'));

		$this->assertStringStartsWith('Connect your EMPREINTE account first', $this->handler->handle($this->message('/empreinte Point')));
	}

	public function testFailureGivesTheReason(): void {
		$this->alice();
		$this->meetings->method('create')->willThrowException(new RuntimeException('quota atteint'));

		$this->assertSame('The meeting could not be created: quota atteint', $this->handler->handle($this->message('/empreinte Point')));
	}

	public function testHelp(): void {
		$this->alice();
		$this->meetings->expects($this->never())->method('create');

		$this->assertStringContainsString('/empreinte', $this->handler->handle($this->message('/empreinte aide')));
	}
}
