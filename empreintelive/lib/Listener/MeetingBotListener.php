<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Messages remis par Talk au bot « EMPREINTE Live » (BotInvokeEvent).
 *
 * Inscrit par nom de classe en chaine : sans Talk, l'evenement n'existe pas et
 * cet ecouteur n'est jamais appele. L'evenement est lu par ses methodes, sans
 * importer la classe de Talk. Tous les bots applicatifs recoivent le meme
 * evenement : on ne repond qu'a celui qui porte notre URL.
 */

namespace OCA\EmpreinteLive\Listener;

use OCA\EmpreinteLive\Talk\MeetingBotHandler;
use OCA\EmpreinteLive\Talk\TalkBot;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use function method_exists;

/** @template-implements IEventListener<Event> */
class MeetingBotListener implements IEventListener {
	public function __construct(
		private MeetingBotHandler $handler,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getBotUrl') || $event->getBotUrl() !== TalkBot::URL) {
			return;
		}
		$answer = $this->handler->handle($event->getMessage());
		if ($answer !== null) {
			// En reponse au message de commande.
			$event->addAnswer($answer, true);
		}
	}
}
