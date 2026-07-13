<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\EmpreinteLiveId;
use PHPUnit\Framework\TestCase;

class EmpreinteLiveIdTest extends TestCase {
	/**
	 * @dataProvider urlProvider
	 */
	public function testFromUrl(?string $expected, string $url): void {
		$this->assertSame($expected, EmpreinteLiveId::fromUrl($url));
	}

	public static function urlProvider(): array {
		return [
			'meet subdomain' => ['274', 'https://meet.empreinte.live/274'],
			'join room param' => ['512', 'https://join.empreinte.live/room?room=512'],
			'live path' => ['999', 'https://empreinte.live/live/999'],
			'generic room param' => ['321', 'https://app.example.com/x?room=321&foo=1'],
			'room with slug suffix' => ['407', 'https://join.empreinte.live/fr?room=407-simple_live_x&token=abc&demo=1'],
			'non empreinte' => [null, 'https://zoom.us/j/123456'],
			'empty' => [null, ''],
		];
	}

	public function testFromIcsLocation(): void {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nLOCATION:https://meet.empreinte.live/274\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$this->assertSame('274', EmpreinteLiveId::fromIcs($ics));
	}

	public function testFromIcsNonEmpreinteReturnsNull(): void {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nLOCATION:Paris\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$this->assertNull(EmpreinteLiveId::fromIcs($ics));
	}

	public function testFromMalformedIcsReturnsNull(): void {
		$this->assertNull(EmpreinteLiveId::fromIcs('not-an-ics'));
		$this->assertNull(EmpreinteLiveId::fromIcs(''));
	}
}
