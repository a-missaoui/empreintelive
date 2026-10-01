<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lecture de la commande du bot :
 *
 *   /empreinte <titre> [demain|tomorrow|JJ/MM[/AAAA]] [HH:MM|14h30|14h] [45min]
 *   /empreinte aide
 *
 * Les options se lisent en fin de message, dans n'importe quel ordre ; le reste
 * est le titre. La duree ne s'ecrit qu'en minutes : « 10h » est toujours une
 * heure de debut, jamais une duree.
 *
 * Sans heure : maintenant (sans date) ou 09:00 (avec une date). Une heure deja
 * passee aujourd'hui, sans date, designe demain. Duree par defaut : une heure.
 * Les dates et heures sont lues dans le fuseau de l'auteur.
 */

namespace OCA\EmpreinteLive\Talk;

use DateTimeImmutable;
use DateTimeZone;
use function array_pop;
use function checkdate;
use function count;
use function implode;
use function in_array;
use function preg_match;
use function preg_replace;
use function preg_split;
use function strtolower;
use function trim;

final class MeetingCommand {
	public const DEFAULT_MINUTES = 60;
	private const MAX_MINUTES = 24 * 60;

	private function __construct(
		public readonly bool $help,
		public readonly string $title,
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end,
	) {
	}

	/**
	 * @return self|null null si le message n'est pas une commande du bot
	 */
	public static function parse(string $message, DateTimeImmutable $now): ?self {
		if (preg_match('#^\s*[/!]empreinte(?:\s+(.*))?$#isu', $message, $m) !== 1) {
			return null;
		}
		// Mentions Talk ({mention-user1}…) : ce sont des personnes, pas le titre.
		$rest = trim((string)preg_replace('/\{mention-[a-z]+\d+\}/i', ' ', $m[1] ?? ''));
		$tokens = $rest === '' ? [] : (preg_split('/\s+/u', $rest) ?: []);

		if (count($tokens) === 1 && in_array(strtolower($tokens[0]), ['aide', 'help', '?'], true)) {
			return new self(true, '', $now, $now);
		}

		$date = null;
		$time = null;
		$minutes = null;
		while ($tokens !== []) {
			$token = strtolower($tokens[count($tokens) - 1]);
			if ($minutes === null && ($value = self::minutes($token)) !== null) {
				$minutes = $value;
			} elseif ($time === null && ($value = self::time($token)) !== null) {
				$time = $value;
			} elseif ($date === null && ($value = self::date($token, $now)) !== null) {
				$date = $value;
			} else {
				break;
			}
			array_pop($tokens);
		}

		$start = self::start($now, $date, $time);
		$end = $start->modify('+' . ($minutes ?? self::DEFAULT_MINUTES) . ' minutes');

		return new self(false, trim(implode(' ', $tokens)), $start, $end);
	}

	private static function minutes(string $token): ?int {
		if (preg_match('/^(\d{1,4})\s?(?:min|mn|m)$/', $token, $m) !== 1) {
			return null;
		}
		$value = (int)$m[1];
		return $value > 0 && $value <= self::MAX_MINUTES ? $value : null;
	}

	/**
	 * @return array{int,int}|null heure, minute
	 */
	private static function time(string $token): ?array {
		if (preg_match('/^(\d{1,2})(?::(\d{2})|h(\d{2})?)$/', $token, $m) !== 1) {
			return null;
		}
		$hour = (int)$m[1];
		$minute = (int)(($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '0'));
		return $hour <= 23 && $minute <= 59 ? [$hour, $minute] : null;
	}

	private static function date(string $token, DateTimeImmutable $now): ?DateTimeImmutable {
		if (in_array($token, ['demain', 'tomorrow'], true)) {
			return $now->modify('+1 day')->setTime(0, 0);
		}
		if (in_array($token, ["aujourd'hui", 'today'], true)) {
			return $now->setTime(0, 0);
		}
		if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{4}))?$#', $token, $m) !== 1) {
			return null;
		}
		$day = (int)$m[1];
		$month = (int)$m[2];
		$year = isset($m[3]) ? (int)$m[3] : (int)$now->format('Y');
		if (!checkdate($month, $day, $year)) {
			return null;
		}
		$date = $now->setDate($year, $month, $day)->setTime(0, 0);
		// Sans annee, une date passee designe l'annee suivante.
		if (!isset($m[3]) && $date < $now->setTime(0, 0)) {
			$date = $date->modify('+1 year');
		}
		return $date;
	}

	/**
	 * @param array{int,int}|null $time
	 */
	private static function start(DateTimeImmutable $now, ?DateTimeImmutable $date, ?array $time): DateTimeImmutable {
		if ($date === null && $time === null) {
			return $now->setTime((int)$now->format('H'), (int)$now->format('i'));
		}
		[$hour, $minute] = $time ?? [9, 0];
		$start = ($date ?? $now)->setTime($hour, $minute);
		if ($date === null && $start < $now) {
			$start = $start->modify('+1 day');
		}
		return $start;
	}

	/** Date au format attendu par l'API EMPREINTE (ISO 8601, UTC). */
	public static function toApi(DateTimeImmutable $date): string {
		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}
}
