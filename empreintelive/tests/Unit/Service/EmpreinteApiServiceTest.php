<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\EmpreinteApiService;
use OCA\EmpreinteLive\Service\EmpreinteClient;
use OCA\EmpreinteLive\Service\OAuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EmpreinteApiServiceTest extends TestCase {
	private EmpreinteClient&MockObject $client;
	private OAuthService&MockObject $oauth;
	private EmpreinteApiService $api;

	protected function setUp(): void {
		$this->client = $this->createPartialMock(EmpreinteClient::class, ['request']);
		$this->oauth = $this->createMock(OAuthService::class);
		$this->oauth->method('getAuthorizationHeader')->willReturn('Bearer tok');
		$this->api = new EmpreinteApiService($this->client, $this->oauth);
	}

	public function testCreateLiveNormalizesResponseAndMapsPayload(): void {
		$captured = null;
		$this->client->method('request')->willReturnCallback(
			function (string $path, string $method, $body, array $headers) use (&$captured): array {
				$captured = ['path' => $path, 'method' => $method, 'body' => $body];
				return [
					'status' => 201,
					'body' => ['data' => ['id' => 42, 'admin_url' => 'A'], 'liveUrl' => 'https://join/42'],
					'location' => null,
				];
			}
		);

		$res = $this->api->createLive('u', ['title' => 'T', 'startTime' => '2026-01-01T10:00:00Z']);

		// Reponse aplatie
		$this->assertSame(42, $res['id']);
		$this->assertSame('https://join/42', $res['url']);
		$this->assertSame('A', $res['admin_url']);

		// Mapping du payload metier -> champs API
		$this->assertSame('/lives', $captured['path']);
		$this->assertSame('POST', $captured['method']);
		$this->assertSame('T', $captured['body']['title']);
		$this->assertSame('2026-01-01T10:00:00Z', $captured['body']['dateStartDiffusion']);
		// endTime absent -> retombe sur startTime
		$this->assertSame('2026-01-01T10:00:00Z', $captured['body']['dateEndDiffusion']);
	}

	public function testDeleteLiveSucceedsOn2xx(): void {
		$this->client->method('request')->willReturn(['status' => 204, 'body' => null, 'location' => null]);
		$this->api->deleteLive('u', '42');
		$this->addToAssertionCount(1);
	}

	public function testDeleteLiveThrowsWithApiMessage(): void {
		$this->client->method('request')->willReturn(['status' => 500, 'body' => ['message' => 'boom'], 'location' => null]);
		$this->expectExceptionMessage('boom');
		$this->api->deleteLive('u', '42');
	}

	public function testAuthedRetriesAfterRefreshOn401(): void {
		$calls = 0;
		$this->client->method('request')->willReturnCallback(
			function () use (&$calls): array {
				$calls++;
				if ($calls === 1) {
					return ['status' => 401, 'body' => null, 'location' => null];
				}
				return ['status' => 200, 'body' => ['data' => ['id' => 7], 'liveUrl' => null], 'location' => null];
			}
		);
		$this->oauth->expects($this->once())->method('refresh')->with('u');

		$res = $this->api->getLive('u', '7');
		$this->assertSame(7, $res['id']);
		$this->assertSame(2, $calls);
	}
}
