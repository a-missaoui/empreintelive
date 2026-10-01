<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Invitation des membres d'une conversation Talk a une reunion.
 *
 * La liste des membres est lue par le navigateur, avec les droits Talk de
 * l'utilisateur : Talk ne l'expose a aucune API PHP publique, et c'est la seule
 * facon d'en garantir l'exactitude. Le serveur resout ici les comptes Nextcloud
 * en adresses, sans jamais les renvoyer au navigateur : l'utilisateur n'obtient
 * pas plus que ce que l'autocompletion des participants lui permet deja.
 *
 * Seul le createur de la reunion peut inviter (MeetingAccess) : l'API EMPREINTE
 * ne le verifie pas elle-meme.
 */

namespace OCA\EmpreinteLive\Service;

use OCP\IUserManager;
use function count;
use function is_string;

class MemberInvitationService {
	public function __construct(
		private MeetingAccess $access,
		private MeetingCreationService $meetings,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @param list<mixed> $userIds comptes Nextcloud membres du salon
	 * @param list<mixed> $emails  adresses des invites du salon (invites par e-mail)
	 * @return array{invited:int, withoutEmail:int}|null null si la reunion n'est
	 *                                                   pas a l'utilisateur
	 */
	public function invite(string $userId, string $liveId, array $userIds, array $emails): ?array {
		$live = $this->access->ownMeeting($userId, $liveId);
		if ($live === null) {
			return null;
		}

		$addresses = $emails;
		$withoutEmail = 0;
		foreach ($userIds as $memberId) {
			if (!is_string($memberId) || $memberId === $userId) {
				continue;
			}
			$email = $this->userManager->get($memberId)?->getEMailAddress();
			if ($email === null || $email === '') {
				$withoutEmail++;
				continue;
			}
			$addresses[] = $email;
		}

		$addresses = $this->meetings->sanitizeEmails($addresses);
		$live['id'] ??= $liveId;
		$sent = $addresses !== [] && $this->meetings->sendInvitations($userId, $live, $addresses);

		return ['invited' => $sent ? count($addresses) : 0, 'withoutEmail' => $withoutEmail];
	}
}
