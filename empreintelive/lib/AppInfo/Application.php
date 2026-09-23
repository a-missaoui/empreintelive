<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\AppInfo;

use OCA\EmpreinteLive\Listener\CalendarObjectListener;
use OCA\EmpreinteLive\Listener\FilesScriptListener;
use OCA\EmpreinteLive\Listener\FolderDeletedListener;
use OCA\EmpreinteLive\Listener\MeetingFramePolicyListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Security\FeaturePolicy\AddFeaturePolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'empreintelive';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// On garde le Live EMPREINTE synchronise avec l'evenement Calendar :
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

		// La reunion s'affiche dans une iframe sur la page
		// de l'app. Il faut autoriser le domaine en frame-src (CSP) ET camera/micro
		// a l'interieur de l'iframe (FeaturePolicy) : sans le second, la reunion
		// s'affiche mais reste muette. Le listener se limite a nos propres pages.
		foreach ([
			AddContentSecurityPolicyEvent::class,
			AddFeaturePolicyEvent::class,
		] as $policyEvent) {
			$context->registerEventListener($policyEvent, MeetingFramePolicyListener::class);
		}

		// Un dossier supprime ne doit pas laisser de lien
		// mort. Evenement du socle Nextcloud, aucune dependance a une autre app.
		$context->registerEventListener(NodeDeletedEvent::class, FolderDeletedListener::class);

		// Action « Reunion sur ce document » dans le menu contextuel de Files.
		// FQCN en chaine : aucune classe d'une autre app n'est resolue si l'app
		// Files venait a ne pas etre chargee.
		$context->registerEventListener(
			'OCA\\Files\\Event\\LoadAdditionalScriptsEvent',
			FilesScriptListener::class,
		);
	}

	public function boot(IBootContext $context): void {
	}
}
