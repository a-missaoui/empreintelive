<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Search;

use OCA\EmpreinteLive\Search\MeetingSearchProvider;
use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\TokenService;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MeetingSearchProviderTest extends TestCase {
	private EmpreinteApiService&MockObject $api;
	private TokenService&MockObject $tokens;
	private IUser&MockObject $user;
	private MeetingSearchProvider $provider;

	protected function setUp(): void {
		$this->api = $this->createMock(EmpreinteApiService::class);
		$this->tokens = $this->createMock(TokenService::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []): string => 'https://cloud.example.com/apps/empreintelive/?' . http_build_query($params),
		);
		$urlGenerator->method('imagePath')->willReturn('/custom_apps/empreintelive/img/app-dark.svg');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$dates = $this->createMock(IDateTimeFormatter::class);
		$dates->method('formatDateTime')->willReturn('1 oct. 2026 10:00');

		$this->provider = new MeetingSearchProvider(
			$this->api,
			$this->tokens,
			$urlGenerator,
			$l10n,
			$dates,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function query(string $term, int $limit = 5): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		return $query;
	}

	private function entries(string $term, int $limit = 5): array {
		return $this->provider->search($this->user, $this->query($term, $limit))->jsonSerialize()['entries'];
	}

	public function testFindsMeetingsByTitleAndOpensThemOnTheAppPage(): void {
		$this->tokens->method('hasToken')->willReturn(true);
		$this->api->method('getMeetings')->with('alice')->willReturn([
			['id' => 1, 'title' => 'Point hebdo', 'dateStartDiffusion' => '2026-10-01T10:00:00+02:00'],
			['id' => 2, 'title' => 'Revue de sprint', 'dateStartDiffusion' => '2026-10-02T10:00:00+02:00'],
		]);

		$entries = $this->entries('HEBDO');

		$this->assertCount(1, $entries);
		$this->assertSame('Point hebdo', $entries[0]->jsonSerialize()['title']);
		$this->assertSame('1 oct. 2026 10:00', $entries[0]->jsonSerialize()['subline']);
		$this->assertSame('https://cloud.example.com/apps/empreintelive/?live=1', $entries[0]->jsonSerialize()['resourceUrl']);
	}

	public function testMostRecentFirstAndLimitApplied(): void {
		$this->tokens->method('hasToken')->willReturn(true);
		$this->api->method('getMeetings')->willReturn([
			['id' => 1, 'title' => 'Point A', 'dateStartDiffusion' => '2026-09-01T10:00:00+02:00'],
			['id' => 2, 'title' => 'Point B', 'dateStartDiffusion' => '2026-10-01T10:00:00+02:00'],
			['id' => 3, 'title' => 'Point C', 'dateStartDiffusion' => '2026-08-01T10:00:00+02:00'],
		]);

		$titles = array_map(static fn ($e) => $e->jsonSerialize()['title'], $this->entries('point', 2));

		$this->assertSame(['Point B', 'Point A'], $titles);
	}

	public function testNotConnectedUserGetsNothingWithoutApiCall(): void {
		$this->tokens->method('hasToken')->willReturn(false);
		$this->api->expects($this->never())->method('getMeetings');

		$this->assertSame([], $this->entries('point'));
	}

	public function testEmptyTermGetsNothingWithoutApiCall(): void {
		$this->tokens->method('hasToken')->willReturn(true);
		$this->api->expects($this->never())->method('getMeetings');

		$this->assertSame([], $this->entries('  '));
	}

	public function testEmpreinteFailureDoesNotBreakTheGlobalSearch(): void {
		$this->tokens->method('hasToken')->willReturn(true);
		$this->api->method('getMeetings')->willThrowException(new RuntimeException('down'));

		$this->assertSame([], $this->entries('point'));
	}

	public function testFirstOnTheAppPage(): void {
		$this->assertSame(-1, $this->provider->getOrder('empreintelive.page.index', []));
		$this->assertSame(60, $this->provider->getOrder('files.view.index', []));
	}
}
