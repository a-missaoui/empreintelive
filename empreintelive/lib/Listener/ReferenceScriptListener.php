<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Charge le widget de la carte de reunion la ou Nextcloud affiche des
 * references (Talk, Text, Deck, Collectives, commentaires de fichiers).
 *
 * Bundle separe et leger, comme celui de Files : la carte seule, sans
 * l'interface de reunion.
 */

namespace OCA\EmpreinteLive\Listener;

use OCA\EmpreinteLive\AppInfo\Application;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<Event> */
class ReferenceScriptListener implements IEventListener {
	public function handle(Event $event): void {
		if ($event instanceof RenderReferenceEvent) {
			Util::addScript(Application::APP_ID, Application::APP_ID . '-reference');
		}
	}
}
