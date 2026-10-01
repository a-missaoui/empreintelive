<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Talk;

use DateTimeImmutable;
use DateTimeZone;
use OCA\EmpreinteLive\Talk\MeetingCommand;
use PHPUnit\Framework\TestCase;

class MeetingCommandTest extends TestCase {
	private DateTimeImmutable $now;

	protected function setUp(): void {
		// Mardi 29 septembre 2026, 14:07:31, Paris.
		$this->now = new DateTimeImmutable('2026-09-29 14:07:31', new DateTimeZone('Europe/Paris'));
	}

	private function parse(string $message): ?MeetingCommand {
		return MeetingCommand::parse($message, $this->now);
	}

	/**
	 * @dataProvider notCommands
	 */
	public function testOtherMessagesAreIgnored(string $message): void {
		$this->assertNull($this->parse($message));
	}

	public static function notCommands(): array {
		return [
			'plain text' => ['bonjour tout le monde'],
			'command in the middle' => ['essaie /empreinte demain'],
			'other command' => ['/empreinteX test'],
			'empty' => [''],
		];
	}

	public function testTitleOnlyStartsNowForAnHour(): void {
		$command = $this->parse('/empreinte Point hebdo');

		$this->assertFalse($command->help);
		$this->assertSame('Point hebdo', $command->title);
		$this->assertSame('2026-09-29 14:07', $command->start->format('Y-m-d H:i'));
		$this->assertSame('2026-09-29 15:07', $command->end->format('Y-m-d H:i'));
	}

	public function testBangPrefixAndCaseAreAccepted(): void {
		$this->assertSame('Revue', $this->parse('!EMPREINTE Revue')->title);
	}

	public function testDateTimeAndDurationInAnyOrder(): void {
		$command = $this->parse('/empreinte Revue de sprint 45min 10:30 demain');

		$this->assertSame('Revue de sprint', $command->title);
		$this->assertSame('2026-09-30 10:30', $command->start->format('Y-m-d H:i'));
		$this->assertSame('2026-09-30 11:15', $command->end->format('Y-m-d H:i'));
	}

	/**
	 * @dataProvider timeFormats
	 */
	public function testTimeFormats(string $token, string $expected): void {
		$this->assertSame($expected, $this->parse('/empreinte Point ' . $token)->start->format('Y-m-d H:i'));
	}

	public static function timeFormats(): array {
		return [
			'colon' => ['16:30', '2026-09-29 16:30'],
			'french h with minutes' => ['16h30', '2026-09-29 16:30'],
			'french h alone' => ['16h', '2026-09-29 16:00'],
			'already passed today means tomorrow' => ['09:00', '2026-09-30 09:00'],
		];
	}

	public function testTenHIsAStartTimeNeverADuration(): void {
		$command = $this->parse('/empreinte Point 10h');

		$this->assertSame('10:00', $command->start->format('H:i'));
		$this->assertSame(MeetingCommand::DEFAULT_MINUTES, ($command->end->getTimestamp() - $command->start->getTimestamp()) / 60);
	}

	public function testDateWithoutTimeStartsAtNine(): void {
		$this->assertSame('2026-10-15 09:00', $this->parse('/empreinte Comité 15/10')->start->format('Y-m-d H:i'));
	}

	public function testPastDateWithoutYearIsNextYear(): void {
		$this->assertSame('2027-01-05 09:00', $this->parse('/empreinte Voeux 05/01')->start->format('Y-m-d H:i'));
	}

	public function testExplicitYearIsKept(): void {
		$this->assertSame('2026-12-01', $this->parse('/empreinte Bilan 01/12/2026')->start->format('Y-m-d'));
	}

	public function testInvalidTokensStayInTheTitle(): void {
		$command = $this->parse('/empreinte Salle 31/02 25:00');

		$this->assertSame('Salle 31/02 25:00', $command->title);
	}

	public function testMentionsAreNotPartOfTheTitle(): void {
		$this->assertSame('Point hebdo', $this->parse('/empreinte Point {mention-user1} hebdo {mention-user2}')->title);
	}

	public function testEmptyTitleIsLeftToTheCaller(): void {
		$this->assertSame('', $this->parse('/empreinte demain 10:00')->title);
	}

	/**
	 * @dataProvider helpWords
	 */
	public function testHelp(string $word): void {
		$this->assertTrue($this->parse('/empreinte ' . $word)->help);
	}

	public static function helpWords(): array {
		return [['aide'], ['help'], ['?']];
	}

	public function testApiDatesAreUtc(): void {
		$this->assertSame('2026-09-29T12:07:00Z', MeetingCommand::toApi($this->parse('/empreinte Point')->start));
	}
}
