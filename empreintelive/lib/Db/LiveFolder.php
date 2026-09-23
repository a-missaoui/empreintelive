<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lien entre un Live EMPREINTE et un dossier Nextcloud.
 *
 * folderId est l'identifiant INTERNE du dossier, pas son chemin : il survit a un
 * renommage et a un deplacement, ce qu'un chemin ne ferait pas. En revanche il ne
 * survit pas a une copie, ce qui est le comportement voulu (une copie de dossier
 * n'herite pas de la reunion).
 *
 * @method string getLiveId()
 * @method void setLiveId(string $liveId)
 * @method int getFolderId()
 * @method void setFolderId(int $folderId)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */

namespace OCA\EmpreinteLive\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class LiveFolder extends Entity implements \JsonSerializable {
	protected string $liveId = '';
	protected int $folderId = 0;
	protected string $ownerUid = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('liveId', Types::STRING);
		$this->addType('folderId', Types::BIGINT);
		$this->addType('ownerUid', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
	}

	/**
	 * Represenation exposee au frontend. On ne renvoie PAS ownerUid : le panneau
	 * n'en a pas besoin, et c'est une donnee de moins a faire transiter.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'liveId' => $this->getLiveId(),
			'folderId' => $this->getFolderId(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
