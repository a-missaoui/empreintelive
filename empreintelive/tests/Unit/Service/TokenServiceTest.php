<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\TokenService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase {
	private IConfig $config;
	private TokenService $service;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->service = new TokenService($this->config);
	}

	public function testSaveTokenComputesExpiresAt(): void {
		$captured = null;
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'empreintelive', 'tokens', $this->callback(function ($json) use (&$captured) {
				$captured = json_decode($json, true);
				return is_array($captured);
			}));

		$before = time();
		$this->service->saveToken('alice', [
			'access_token' => 'A',
			'refresh_token' => 'R',
			'token_type' => 'Bearer',
			'scope' => 'live:read',
			'expires_in' => 3600,
		]);

		$this->assertSame('A', $captured['access_token']);
		$this->assertSame('R', $captured['refresh_token']);
		$this->assertSame('Bearer', $captured['token_type']);
		$this->assertSame('live:read', $captured['scope']);
		$this->assertGreaterThanOrEqual($before + 3600, $captured['expires_at']);
		$this->assertLessThanOrEqual(time() + 3600, $captured['expires_at']);
	}

	public function testSaveTokenNullExpiresWhenNoExpiresIn(): void {
		$captured = null;
		$this->config->method('setUserValue')
			->willReturnCallback(function ($u, $a, $k, $v) use (&$captured): void {
				$captured = json_decode($v, true);
			});

		$this->service->saveToken('alice', ['access_token' => 'A']);
		$this->assertNull($captured['expires_at']);
	}

	public function testLoadTokenReturnsNullWhenEmpty(): void {
		$this->config->method('getUserValue')->willReturn('');
		$this->assertNull($this->service->loadToken('alice'));
	}

	public function testLoadTokenDecodes(): void {
		$this->config->method('getUserValue')->willReturn(json_encode(['access_token' => 'A']));
		$this->assertSame('A', $this->service->loadToken('alice')['access_token']);
	}

	public function testLoadTokenReturnsNullOnInvalidJson(): void {
		$this->config->method('getUserValue')->willReturn('{not json');
		$this->assertNull($this->service->loadToken('alice'));
	}

	public function testHasTokenTrue(): void {
		$this->config->method('getUserValue')->willReturn(json_encode(['access_token' => 'A']));
		$this->assertTrue($this->service->hasToken('alice'));
	}

	public function testHasTokenFalseWhenNoAccessToken(): void {
		$this->config->method('getUserValue')->willReturn(json_encode(['refresh_token' => 'R']));
		$this->assertFalse($this->service->hasToken('alice'));
	}

	public function testIsExpiredNullNeverExpires(): void {
		$this->assertFalse($this->service->isExpired(['expires_at' => null]));
	}

	public function testIsExpiredInThePast(): void {
		$this->assertTrue($this->service->isExpired(['expires_at' => time() - 10]));
	}

	public function testIsExpiredWithinLeeway(): void {
		// Leeway = 30s : un token expirant dans 15s est considere expire.
		$this->assertTrue($this->service->isExpired(['expires_at' => time() + 15]));
	}

	public function testIsExpiredFarInFuture(): void {
		$this->assertFalse($this->service->isExpired(['expires_at' => time() + 3600]));
	}
}
