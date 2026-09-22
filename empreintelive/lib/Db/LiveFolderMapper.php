<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Acces a la table de liaison. Aucune regle metier ici :
 * les controles d'appartenance sont dans LiveFolderService.
 *
 * @template-extends QBMapper<LiveFolder>
 */

namespace OCA\EmpreinteLive\Db;

use OCA\EmpreinteLive\Migration\Version011000Date20260915120000;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class LiveFolderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, Version011000Date20260915120000::TABLE, LiveFolder::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByLiveId(string $liveId): LiveFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('live_id', $qb->createNamedParameter($liveId, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * @return LiveFolder[]
	 */
	public function findByOwner(string $ownerUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Liens pointant vers un dossier donne. Sert au nettoyage quand le dossier
	 * disparait : en pratique un seul lien, mais on ne le presuppose pas.
	 *
	 * @return LiveFolder[]
	 */
	public function findByFolderId(int $folderId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}
}
