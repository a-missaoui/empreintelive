<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\MeetingDomainService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class MeetingDomainServiceTest extends TestCase {
	/** @dataProvider configProvider */
	public function testDomains(array $expected, string $raw): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('empreintelive', 'meeting_domains', '')->willReturn($raw);

		$this->assertSame($expected, (new MeetingDomainService($config))->domains());
	}

	public static function configProvider(): array {
		return [
			'defaut' => [MeetingDomainService::DEFAULT_DOMAINS, ''],
			'espaces seuls' => [MeetingDomainService::DEFAULT_DOMAINS, '   '],
			'virgules seules' => [MeetingDomainService::DEFAULT_DOMAINS, ' , ,'],
			'surcharge' => [['https://a.test', 'https://b.test'], 'https://a.test, https://b.test'],
		];
	}
}
