<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\AppInfo;

use OCA\EmpreinteLive\Listener\CalendarObjectListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'empreintelive';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Lot 7 - on garde le Live EMPREINTE synchronise avec l'evenement Calendar :
		// le liveId est toujours lu dans l'ICS de l'evenement, aucun stockage cote app.
		//  - mise en corbeille OU suppression definitive -> suppression du Live ;
		//  - modification (dates, titre, description)     -> mise a jour du Live.
		// On ecoute la mise en corbeille (et pas seulement la suppression definitive)
		// pour que l'action de suppression cote UI se propage immediatement, comme
		// dans la version calendrier d'origine.
		foreach ([
			CalendarObjectMovedToTrashEvent::class,
			CalendarObjectDeletedEvent::class,
			CalendarObjectUpdatedEvent::class,
		] as $eventClass) {
			$context->registerEventListener($eventClass, CalendarObjectListener::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
