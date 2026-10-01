<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Reponse du bot a la commande /empreinte, au nom de l'auteur du message.
 *
 *  - seul un utilisateur Nextcloud peut creer une reunion (pas un invite ni un
 *    compte federe) ; il faut que son compte EMPREINTE soit connecte ;
 *  - la reunion est creee avec son jeton, comme depuis la page de l'app
 *    (calendrier compris) ;
 *  - le bot publie le lien PARTICIPANT, et un lien vers la page de l'app pour
 *    inviter les membres du salon.
 *
 * Aucune invitation directe : une mention ne prouve pas l'appartenance au salon,
 * et Talk ne fournit pas les adresses des membres. La page de l'app lit la liste
 * reelle des membres, avec les droits Talk de l'utilisateur.
 *
 * Le bot recoit tous les messages des salons ou il est active : leur contenu
 * n'est jamais journalise.
 */

namespace OCA\EmpreinteLive\Talk;

use DateTimeZone;
use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Exception\NotConnectedException;
use OCA\EmpreinteLive\Service\MeetingCreationService;
use OCA\EmpreinteLive\Service\TokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use function date_default_timezone_get;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function str_starts_with;
use function substr;

class MeetingBotHandler {
	public function __construct(
		private MeetingCreationService $meetings,
		private TokenService $tokens,
		private IUserManager $userManager,
		private IConfig $config,
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IDateTimeFormatter $dateFormatter,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,mixed> $message charge utile de BotInvokeEvent::getMessage()
	 * @return string|null la reponse a publier, ou null si le message ne concerne pas le bot
	 */
	public function handle(array $message): ?string {
		if (($message['type'] ?? '') !== 'Create') {
			return null;
		}
		$content = json_decode((string)($message['object']['content'] ?? ''), true);
		$text = is_array($content) && is_string($content['message'] ?? null) ? $content['message'] : '';

		$user = $this->author((string)($message['actor']['id'] ?? ''));
		$l = $this->l10nFactory->get(Application::APP_ID, $user !== null ? $this->l10nFactory->getUserLanguage($user) : null);
		$timeZone = $this->timeZone($user);
		$command = MeetingCommand::parse($text, $this->timeFactory->now()->setTimezone($timeZone));
		if ($command === null) {
			return null;
		}

		if ($command->help) {
			return $this->help($l);
		}
		if ($user === null) {
			return $l->t('Only Nextcloud users can create an EMPREINTE Live meeting from a conversation.');
		}
		if (!$this->tokens->hasToken($user->getUID())) {
			return $this->connectFirst($l);
		}

		$conversation = (string)($message['target']['name'] ?? '');
		$title = $command->title !== ''
			? $command->title
			: $l->t('Meeting in %s', [$conversation !== '' ? $conversation : 'Talk']);

		try {
			$result = $this->meetings->create(
				$user->getUID(),
				$title,
				$l->t('Created from the conversation "%s" in Nextcloud Talk.', [$conversation]),
				MeetingCommand::toApi($command->start),
				MeetingCommand::toApi($command->end),
			);
		} catch (NotConnectedException) {
			return $this->connectFirst($l);
		} catch (Throwable $e) {
			$this->logger->warning('Reunion non creee depuis Talk', ['app' => Application::APP_ID, 'exception' => $e]);
			return $l->t('The meeting could not be created: %s', [$e->getMessage()]);
		}

		return $this->created($l, $title, $command, $timeZone, $result['data'], (string)($message['target']['id'] ?? ''));
	}

	/**
	 * Seuls les utilisateurs Nextcloud (« users/<uid> ») agissent : un invite ou
	 * un compte federe n'a pas de compte EMPREINTE connecte dans cette instance.
	 */
	private function author(string $actorId): ?IUser {
		if (!str_starts_with($actorId, 'users/')) {
			return null;
		}
		return $this->userManager->get(substr($actorId, 6));
	}

	private function timeZone(?IUser $user): DateTimeZone {
		$default = date_default_timezone_get();
		$name = $user !== null ? $this->config->getUserValue($user->getUID(), 'core', 'timezone', $default) : $default;
		try {
			return new DateTimeZone($name !== '' ? $name : $default);
		} catch (Throwable) {
			return new DateTimeZone('UTC');
		}
	}

	/**
	 * @param array<string,mixed> $live
	 */
	private function created(IL10N $l, string $title, MeetingCommand $command, DateTimeZone $timeZone, array $live, string $roomToken): string {
		$when = $this->dateFormatter->formatDateTime($command->start->getTimestamp(), 'medium', 'short', $timeZone, $l);
		$lines = ['**' . $title . '** · ' . $when];

		$participant = $live['participant_url'] ?? null;
		// Lien seul sur sa ligne : Talk l'affiche en carte de reunion.
		$lines[] = is_string($participant) && $participant !== ''
			? $participant
			: $l->t('The meeting was created. Its link is on the EMPREINTE Live page.');

		$liveId = isset($live['id']) ? (string)$live['id'] : '';
		if ($liveId !== '' && $roomToken !== '') {
			$lines[] = '';
			$lines[] = $l->t('Invite the members of this conversation: %s', [
				$this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index', ['live' => $liveId, 'talk' => $roomToken]),
			]);
		}
		return implode("\n", $lines);
	}

	private function connectFirst(IL10N $l): string {
		return $l->t('Connect your EMPREINTE account first: %s', [
			$this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index'),
		]);
	}

	private function help(IL10N $l): string {
		return implode("\n", [
			'**' . $l->t('Create an EMPREINTE Live meeting') . '**',
			'`/empreinte ' . $l->t('Title') . ' [' . $l->t('tomorrow') . '|DD/MM] [14:30] [45min]`',
			$l->t('Without a time, the meeting starts now. Default length: one hour.'),
			$l->t('Example: /empreinte Weekly review tomorrow 10:00 30min'),
		]);
	}
}
