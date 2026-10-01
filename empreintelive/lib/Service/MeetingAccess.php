<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Reunion de l'utilisateur, ou rien.
 *
 * L'API EMPREINTE renvoie une reunion a tout compte authentifie qui en connait
 * l'identifiant : son succes ne vaut pas droit d'acces. L'app ne donne donc le
 * detail d'une reunion, ou n'agit dessus, que si le compte EMPREINTE connecte en
 * est le createur. Partage par la carte de reunion et par les invitations.
 */

namespace OCA\EmpreinteLive\Service;

use Psr\Log\LoggerInterface;
use Throwable;
use function strtolower;

class MeetingAccess {
	public function __construct(
		private EmpreinteApiService $api,
		private OAuthService $oauth,
		private TokenService $tokens,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string,mixed>|null la reunion si l'utilisateur en est le
	 *                                  createur ; null s'il n'est pas connecte,
	 *                                  si elle est introuvable, ou si elle est a
	 *                                  un autre
	 */
	public function ownMeeting(string $userId, string $liveId): ?array {
		if (!$this->tokens->hasToken($userId)) {
			return null;
		}
		$accountEmail = strtolower($this->oauth->getAccountEmail($userId));
		if ($accountEmail === '') {
			return null;
		}
		try {
			$meeting = $this->api->getLive($userId, $liveId);
		} catch (Throwable $e) {
			$this->logger->debug('Reunion ' . $liveId . ' illisible', ['exception' => $e]);
			return null;
		}
		$creator = strtolower((string)($meeting['created_by'] ?? ''));
		return $creator === $accountEmail ? $meeting : null;
	}
}
