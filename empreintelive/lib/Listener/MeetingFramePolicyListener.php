<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Autorise l'affichage de la reunion EMPREINTE dans une
 * iframe sur NOS pages, et l'acces camera / micro a l'interieur de cette iframe.
 *
 * Deux verrous distincts, et il faut les deux :
 *  - la CSP (frame-src) autorise le chargement de l'iframe ;
 *  - la FeaturePolicy autorise camera et micro DANS l'iframe.
 * Sans le second, l'iframe s'affiche et la reunion est muette.
 *
 * Portee volontairement etroite : la politique n'est ajoutee que sur les pages de
 * l'app (/apps/empreintelive/...). On ne desserre rien pour le reste de Nextcloud.
 */

namespace OCA\EmpreinteLive\Listener;

use OCA\EmpreinteLive\AppInfo\Application;
use OCA\EmpreinteLive\Service\MeetingDomainService;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\EmptyFeaturePolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Security\FeaturePolicy\AddFeaturePolicyEvent;
use function str_starts_with;

/** @template-implements IEventListener<Event> */
class MeetingFramePolicyListener implements IEventListener {
	public function __construct(
		private IRequest $request,
		private MeetingDomainService $meetingDomains,
	) {
	}

	public function handle(Event $event): void {
		if (!$this->isOwnPage()) {
			return;
		}

		$domains = $this->meetingDomains->domains();
		if ($domains === []) {
			return;
		}

		if ($event instanceof AddContentSecurityPolicyEvent) {
			$policy = new EmptyContentSecurityPolicy();
			foreach ($domains as $domain) {
				$policy->addAllowedFrameDomain($domain);
			}
			$event->addPolicy($policy);

			return;
		}

		if ($event instanceof AddFeaturePolicyEvent) {
			$policy = new EmptyFeaturePolicy();
			foreach ($domains as $domain) {
				$policy->addAllowedCameraDomain($domain);
				$policy->addAllowedMicrophoneDomain($domain);
				$policy->addAllowedFullScreenDomain($domain);
			}
			$event->addPolicy($policy);
		}
	}

	/**
	 * Les evenements de politique sont globaux : sans ce garde, on desserrerait la
	 * CSP de toutes les pages de l'instance, y compris Files et les partages.
	 */
	private function isOwnPage(): bool {
		return str_starts_with($this->request->getPathInfo() ?? '', '/apps/' . Application::APP_ID);
	}
}
