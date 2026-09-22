<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Quand un dossier disparait de Nextcloud, on retire les
 * liens qui le designaient.
 *
 * Sans cela, l'app continue de croire qu'un dossier est lie : le panneau affiche
 * « Dossier non accessible » — le meme message que pour un droit manquant — et
 * une ligne morte reste en base indefiniment. L'utilisateur ne peut meme plus
 * reassocier un dossier, puisque le panneau ne le propose plus.
 *
 * Comme partout dans le bridge : au mieux, et on ne supprime JAMAIS rien cote
 * EMPREINTE. La reunion survit a la disparition de son dossier.
 */

namespace OCA\EmpreinteLive\Listener;

use OCA\EmpreinteLive\Service\LiveFolderService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use Throwable;

/** @template-implements IEventListener<NodeDeletedEvent> */
class FolderDeletedListener implements IEventListener {
	public function __construct(
		private LiveFolderService $links,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof NodeDeletedEvent) {
			return;
		}

		$node = $event->getNode();
		if (!$node instanceof Folder) {
			return;
		}

		try {
			$removed = $this->links->unlinkByFolder($node->getId());
			if ($removed > 0) {
				$this->logger->info('Dossier supprime : {n} lien(s) de reunion retire(s)', [
					'n' => $removed,
				]);
			}
		} catch (Throwable $e) {
			// Au mieux : on ne bloque jamais la suppression d'un dossier par l'utilisateur.
			$this->logger->warning('Nettoyage des liens de reunion impossible', ['exception' => $e]);
		}
	}
}
