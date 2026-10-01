<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Talk active apres l'app : le bot est enregistre a ce moment-la, sans attendre
 * la prochaine mise a jour.
 */

namespace OCA\EmpreinteLive\Listener;

use OCA\EmpreinteLive\Talk\TalkBot;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<Event> */
class TalkEnabledListener implements IEventListener {
	public function __construct(
		private TalkBot $bot,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof AppEnableEvent && $event->getAppId() === 'spreed') {
			$this->bot->register();
		}
	}
}
