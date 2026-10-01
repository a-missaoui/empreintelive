<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Reference;

use OCA\EmpreinteLive\Exception\NotConnectedException;
use OCA\EmpreinteLive\Reference\MeetingReferenceProvider;
use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\MeetingAccess;
use OCA\EmpreinteLive\Service\MeetingDomainService;
use OCA\EmpreinteLive\Service\OAuthService;
use OCA\EmpreinteLive\Service\TokenService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MeetingReferenceProviderTest extends TestCase {
	private const APP_PAGE = 'https://cloud.example.com/index.php/apps/empreintelive/';
	private const ADMIN_URL = 'https://join.empreinte.live/fr?room=713-simple_live_x&token=ADMINTOKEN&demo=1';
	private const PARTICIPANT_URL = 'https://join.empreinte.live/fr?room=713-simple_live_x&token=PARTTOKEN&demo=1';

	private EmpreinteApiService&MockObject $api;
	private OAuthService&MockObject $oauth;
	private TokenService&MockObject $tokens;
	private IUserSession&MockObject $userSession;
	private MeetingReferenceProvider $provider;

	protected function setUp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		$this->api = $this->createMock(EmpreinteApiService::class);
		$this->oauth = $this->createMock(OAuthService::class);
		$this->tokens = $this->createMock(TokenService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturnCallback(
			static fn (string $app, string $image): string => '/custom_apps/' . $app . '/img/' . $image,
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $url): string => 'https://cloud.example.com' . $url,
		);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []): string => self::APP_PAGE
				. ($params === [] ? '' : '?' . http_build_query($params)),
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->provider = new MeetingReferenceProvider(
			new MeetingDomainService($config),
			new MeetingAccess($this->api, $this->oauth, $this->tokens, $this->createMock(LoggerInterface::class)),
			$this->userSession,
			$urlGenerator,
			$l10n,
		);
	}

	private function loggedInAs(?string $uid, string $accountEmail = 'orga@example.com', bool $connected = true): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$this->userSession->method('getUser')->willReturn($user);
		$this->tokens->method('hasToken')->willReturn($connected);
		$this->oauth->method('getAccountEmail')->willReturn($accountEmail);
	}

	private function meeting(string $createdBy): array {
		return [
			'id' => '713',
			'title' => 'Point hebdo',
			'dateStartDiffusion' => '2026-10-01T10:00:00+02:00',
			'dateEndDiffusion' => '2026-10-01T11:00:00+02:00',
			'created_by' => $createdBy,
			'admin_url' => self::ADMIN_URL,
			'participant_url' => self::PARTICIPANT_URL,
		];
	}

	public function testPickerEntryUsesTheDarkIconOnLightBackgrounds(): void {
		$entry = $this->provider->jsonSerialize();

		$this->assertSame(MeetingReferenceProvider::PICKER_ID, $entry['id']);
		$this->assertSame('EMPREINTE Live', $entry['title']);
		$this->assertSame('https://cloud.example.com/custom_apps/empreintelive/img/app-dark.svg', $entry['icon_url']);
	}

	/**
	 * @dataProvider matchProvider
	 */
	public function testMatchReference(bool $expected, string $url): void {
		$this->assertSame($expected, $this->provider->matchReference($url));
	}

	public static function matchProvider(): array {
		return [
			'join participant link' => [true, self::PARTICIPANT_URL],
			'meet link' => [true, 'https://meet.empreinte.live/274'],
			'app page link' => [true, self::APP_PAGE . '?live=713'],
			'app page link without index.php' => [true, 'https://cloud.example.com/apps/empreintelive/?live=713'],
			'app page link without trailing slash' => [true, 'https://cloud.example.com/index.php/apps/empreintelive?live=713'],
			'app page without live' => [false, self::APP_PAGE],
			'app page non numeric live' => [false, self::APP_PAGE . '?live=abc'],
			'other app on same host' => [false, 'https://cloud.example.com/index.php/apps/files/?live=713'],
			'room param on another host' => [false, 'https://app.example.com/x?room=321&foo=1'],
			'http downgrade of a meeting domain' => [false, 'http://join.empreinte.live/fr?room=713'],
			'unrelated link' => [false, 'https://zoom.us/j/123456'],
			'not a url' => [false, 'Point hebdo 713'],
		];
	}

	public function testCreatorGetsDetailedCardJoiningFromAppPage(): void {
		$this->loggedInAs('alice', 'Orga@Example.com');
		$this->api->method('getLive')->with('alice', '713')->willReturn($this->meeting('orga@example.com'));

		$reference = $this->provider->resolveReference(self::PARTICIPANT_URL);
		$rich = $reference->getRichObject();

		$this->assertSame(MeetingReferenceProvider::RICH_OBJECT_TYPE, $reference->getRichObjectType());
		$this->assertTrue($rich['detailed']);
		$this->assertSame('Point hebdo', $rich['title']);
		$this->assertSame('2026-10-01T10:00:00+02:00', $rich['start']);
		$this->assertSame(self::APP_PAGE . '?live=713', $rich['joinUrl']);
		$this->assertFalse($rich['organizerLinkShared']);
		$this->assertSame('Point hebdo', $reference->getTitle());
		$this->assertStringNotContainsString('ADMINTOKEN', json_encode($reference));
	}

	public function testOtherAccountGetsMinimalCardEvenWhenApiAnswers(): void {
		// L'API renvoie la reunion a tout compte authentifie : ce n'est pas un droit.
		$this->loggedInAs('bob', 'bob@example.com');
		$this->api->method('getLive')->willReturn($this->meeting('orga@example.com'));

		$reference = $this->provider->resolveReference(self::PARTICIPANT_URL);
		$rich = $reference->getRichObject();

		$this->assertFalse($rich['detailed']);
		$this->assertArrayNotHasKey('title', $rich);
		$this->assertArrayNotHasKey('start', $rich);
		$this->assertSame(self::PARTICIPANT_URL, $rich['joinUrl']);
		$this->assertSame('EMPREINTE Live meeting', $reference->getTitle());
		$json = json_encode($reference);
		$this->assertStringNotContainsString('ADMINTOKEN', $json);
		$this->assertStringNotContainsString('Point hebdo', $json);
	}

	public function testNotConnectedViewerGetsMinimalCardWithoutApiCall(): void {
		$this->loggedInAs('carol', 'carol@example.com', false);
		$this->api->expects($this->never())->method('getLive');

		$rich = $this->provider->resolveReference(self::PARTICIPANT_URL)->getRichObject();

		$this->assertFalse($rich['detailed']);
		$this->assertSame('713', $rich['liveId']);
	}

	public function testUnknownAccountEmailGetsMinimalCardWithoutApiCall(): void {
		$this->loggedInAs('dave', '');
		$this->api->expects($this->never())->method('getLive');

		$this->assertFalse($this->provider->resolveReference(self::PARTICIPANT_URL)->getRichObject()['detailed']);
	}

	public function testAnonymousViewerGetsMinimalCard(): void {
		$this->loggedInAs(null);
		$this->api->expects($this->never())->method('getLive');

		$this->assertFalse($this->provider->resolveReference(self::PARTICIPANT_URL)->getRichObject()['detailed']);
	}

	public function testApiFailureFallsBackToMinimalCard(): void {
		$this->loggedInAs('alice');
		$this->api->method('getLive')->willThrowException(new NotConnectedException('User not found'));

		$this->assertFalse($this->provider->resolveReference(self::PARTICIPANT_URL)->getRichObject()['detailed']);
	}

	public function testCreatorIsWarnedWhenTheOrganizerLinkWasShared(): void {
		$this->loggedInAs('alice');
		$this->api->method('getLive')->willReturn($this->meeting('orga@example.com'));

		$reference = $this->provider->resolveReference(self::ADMIN_URL);

		$this->assertTrue($reference->getRichObject()['organizerLinkShared']);
	}

	public function testUnrelatedLinkIsNotResolved(): void {
		$this->assertNull($this->provider->resolveReference('https://zoom.us/j/123456'));
	}

	public function testCachePrefixIsTheMeetingSoUpdatesClearEveryCard(): void {
		$this->assertSame('713', $this->provider->getCachePrefix(self::PARTICIPANT_URL));
		$this->assertSame('713', $this->provider->getCachePrefix(self::ADMIN_URL));
	}

	public function testCacheIsSplitPerViewerAndPerLink(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$this->userSession->method('getUser')->willReturnOnConsecutiveCalls($alice, $alice, $bob);

		$aliceParticipant = $this->provider->getCacheKey(self::PARTICIPANT_URL);
		$aliceOrganizer = $this->provider->getCacheKey(self::ADMIN_URL);
		$bobParticipant = $this->provider->getCacheKey(self::PARTICIPANT_URL);

		$this->assertNotSame($aliceParticipant, $aliceOrganizer);
		$this->assertNotSame($aliceParticipant, $bobParticipant);
	}
}
