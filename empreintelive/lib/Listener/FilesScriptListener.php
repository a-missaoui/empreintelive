<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Charge le bundle de l'action « Reunion sur ce document » dans l'app Files.
 *
 * Bundle separe du reste : la page de l'app embarque toute l'interface de
 * reunion, il n'y a aucune raison de la faire telecharger a quiconque ouvre
 * simplement ses fichiers.
 */

namespace OCA\EmpreinteLive\Listener;

use OCA\EmpreinteLive\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<Event> */
class FilesScriptListener implements IEventListener {
	public function handle(Event $event): void {
		Util::addInitScript(Application::APP_ID, Application::APP_ID . '-files');
	}
}
