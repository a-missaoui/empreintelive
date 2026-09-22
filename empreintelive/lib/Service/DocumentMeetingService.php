<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * « Reunion sur ce document » : cree une visioconference depuis l'app Files, a
 * partir d'un document precis, et y charge ce document pour qu'il soit presente
 * aux participants.
 *
 * L'enchainement, dans cet ordre :
 *  1. le Live doit exister avant tout, car l'envoi du document se fait sur son
 *     identifiant ;
 *  2. le document est envoye dans la reunion ;
 *  3. le dossier parent est associe a la reunion, pour que le panneau permette
 *     d'envoyer les documents voisins pendant la seance.
 *
 * Seule l'etape 1 est bloquante. Si l'envoi du document echoue, la reunion
 * existe et reste utilisable : on le signale sans tout annuler.
 *
 * Le fichier est lu via getUserFolder() : ce sont les permissions Nextcloud qui
 * decident, ici comme ailleurs.
 */

namespace OCA\EmpreinteLive\Service;

use OCA\EmpreinteLive\Exception\LiveDocumentException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;
use function in_array;
use function pathinfo;
use function strtolower;
use function trim;

class DocumentMeetingService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private IUserManager $userManager,
		private MeetingCreationService $meetings,
		private LiveDocumentService $liveDocuments,
		private LiveFolderService $links,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Ce qu'on propose dans la boite de dialogue, avant que l'utilisateur ne
	 * confirme : un titre tire du nom du fichier, et les personnes avec qui il est
	 * deja partage.
	 *
	 * @return array{title:string, attendees:list<string>, convertible:bool, name:string}
	 */
	public function suggest(string $userId, int $fileId): array {
		$file = $this->resolveFile($userId, $fileId);
		if ($file === null) {
			throw new LiveDocumentException('not_found');
		}

		$name = $file->getName();
		$base = (string)pathinfo($name, PATHINFO_FILENAME);

		return [
			'name' => $name,
			'title' => $base !== '' ? $base : $name,
			'attendees' => $this->attendeesFromShares($userId, $file),
			'convertible' => $this->isConvertible($name),
		];
	}

	/**
	 * @param array<mixed> $attendees
	 * @return array<string,mixed>
	 * @throws LiveDocumentException
	 */
	public function createForFile(
		string $userId,
		int $fileId,
		string $title,
		string $description,
		string $startTime,
		string $endTime,
		array $attendees = [],
	): array {
		$file = $this->resolveFile($userId, $fileId);
		if ($file === null) {
			throw new LiveDocumentException('not_found');
		}

		// L'API EMPREINTE refuse une description vide (champ obligatoire cote
		// serveur) : on en fournit une par defaut plutot que d'echouer.
		if (trim($description) === '') {
			$description = 'Reunion sur le document ' . $file->getName();
		}

		$result = $this->meetings->create($userId, $title, $description, $startTime, $endTime, $attendees);
		$liveId = isset($result['data']['id']) ? (string)$result['data']['id'] : '';
		if ($liveId === '') {
			throw new LiveDocumentException('missing_live_id');
		}

		// A partir d'ici la reunion existe : plus rien ne doit l'annuler.
		$result['documentSent'] = false;
		$result['documentError'] = null;

		if ($this->isConvertible($file->getName())) {
			try {
				$this->liveDocuments->sendDocument($userId, $liveId, $fileId);
				$result['documentSent'] = true;
			} catch (LiveDocumentException $e) {
				$result['documentError'] = $e->getMessage();
				$this->logger->warning('Reunion creee, mais le document n\'a pas pu y etre charge', [
					'liveId' => $liveId,
					'reason' => $e->getMessage(),
				]);
			}
		} else {
			$result['documentError'] = 'unsupported_format';
		}

		// Le dossier parent devient le dossier de la reunion : le panneau permettra
		// d'envoyer les documents voisins pendant la seance.
		//
		// SAUF s'il s'agit de la racine de l'utilisateur : un document pose a la
		// racine entrainerait l'association de TOUT l'espace personnel a la reunion,
		// affiche dans le panneau et proposable au partage. Dans ce cas on n'associe
		// rien ; l'utilisateur choisira un dossier s'il en veut un.
		$result['folderLinked'] = false;
		try {
			$parent = $file->getParent();
			if ($parent->getPath() !== $this->rootFolder->getUserFolder($userId)->getPath()) {
				$this->links->link($userId, $liveId, $parent->getId());
				$result['folderLinked'] = true;
			}
		} catch (Throwable $e) {
			$this->logger->warning('Association du dossier parent impossible', ['exception' => $e]);
		}

		$result['liveId'] = $liveId;

		return $result;
	}

	/**
	 * Personnes avec qui le document est deja partage dans Nextcloud. Sert a
	 * pre-remplir la liste des participants : une reunion sur un document concerne
	 * d'abord ceux qui l'ont deja.
	 *
	 * Les partages de groupe sont volontairement ignores : un groupe peut compter
	 * des centaines de personnes, ce n'est pas une liste d'invites raisonnable.
	 *
	 * @return list<string>
	 */
	private function attendeesFromShares(string $userId, File $file): array {
		$emails = [];

		foreach ([IShare::TYPE_USER, IShare::TYPE_EMAIL] as $type) {
			try {
				$shares = $this->shareManager->getSharesBy($userId, $type, $file, false, 50);
			} catch (Throwable) {
				continue;
			}

			foreach ($shares as $share) {
				$with = trim((string)$share->getSharedWith());
				if ($with === '') {
					continue;
				}

				// TYPE_EMAIL porte deja une adresse ; TYPE_USER porte un identifiant.
				$email = $type === IShare::TYPE_EMAIL
					? $with
					: (string)($this->userManager->get($with)?->getEMailAddress() ?? '');

				if ($email !== '') {
					$emails[] = $email;
				}
			}
		}

		// La deduplication et la validation sont deja faites une fois pour toutes.
		return $this->meetings->sanitizeEmails($emails);
	}

	private function isConvertible(string $name): bool {
		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));

		return in_array($extension, LiveDocumentService::ALLOWED_EXTENSIONS, true);
	}

	private function resolveFile(string $userId, int $fileId): ?File {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (Throwable) {
			return null;
		}

		foreach ($userFolder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}
}
