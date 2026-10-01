<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Enregistrement du bot « EMPREINTE Live » dans Talk, sans commande
 * d'administration : l'app diffuse l'evenement d'installation de Talk.
 *
 * Talk reste optionnel. Ses classes sont nommees en chaine et testees par
 * class_exists(), comme l'evenement de Files dans Application::register() :
 * sans Talk, rien n'est resolu et rien ne se passe.
 *
 * URL « nextcloudapp:// » : Talk remet les messages au bot par un evenement PHP
 * (BotInvokeEvent), dans le processus. Aucun appel HTTP, aucune URL publique,
 * aucune signature a verifier. Talk exige malgre tout un secret : il est genere
 * ici, une fois, et ne sert qu'a identifier le bot aupres de Talk.
 *
 * L'enregistrement est idempotent : Talk met a jour un bot deja connu.
 */

namespace OCA\EmpreinteLive\Talk;

use OCA\EmpreinteLive\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use function class_exists;

class TalkBot {
	public const URL = 'nextcloudapp://' . Application::APP_ID;
	public const INSTALL_EVENT = 'OCA\\Talk\\Events\\BotInstallEvent';
	public const UNINSTALL_EVENT = 'OCA\\Talk\\Events\\BotUninstallEvent';
	public const INVOKE_EVENT = 'OCA\\Talk\\Events\\BotInvokeEvent';

	private const SECRET_KEY = 'talk_bot_secret';

	public function __construct(
		private IEventDispatcher $dispatcher,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
	) {
	}

	/**
	 * @return bool vrai si Talk est present et que le bot a ete enregistre
	 */
	public function register(): bool {
		if (!class_exists(self::INSTALL_EVENT)) {
			return false;
		}
		$class = self::INSTALL_EVENT;
		$this->dispatcher->dispatchTyped(new $class(
			'EMPREINTE Live',
			$this->secret(),
			self::URL,
			// Affiche aux moderateurs dans les parametres du salon.
			'Create an EMPREINTE Live meeting from the conversation: /empreinte <title>',
		));
		return true;
	}

	/**
	 * @return bool vrai si Talk est present et que le bot a ete retire
	 */
	public function unregister(): bool {
		if (!class_exists(self::UNINSTALL_EVENT)) {
			return false;
		}
		$class = self::UNINSTALL_EVENT;
		$this->dispatcher->dispatchTyped(new $class($this->secret(), self::URL));
		return true;
	}

	private function secret(): string {
		$secret = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY);
		if ($secret === '') {
			// 64 caracteres : Talk exige entre 40 et 128.
			$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SECRET_KEY, $secret, sensitive: true);
		}
		return $secret;
	}
}
