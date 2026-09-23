<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Premiere table de l'app.
 *
 * Contrairement au volet calendrier, ou le liveId voyage dans l'ICS de
 * l'evenement (aucun stockage necessaire), le volet documentaire n'a pas de
 * porteur equivalent : on ne peut pas ecrire dans le contenu d'un document sans
 * risquer de le corrompre. Il faut donc une table de liaison explicite entre un
 * Live EMPREINTE et un dossier Nextcloud.
 *
 * L'unicite porte sur live_id : un Live pointe vers UN dossier, partage par tous
 * ses participants. C'est le dossier de la reunion, pas celui d'un utilisateur.
 */

namespace OCA\EmpreinteLive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version011000Date20260915120000 extends SimpleMigrationStep {
	public const TABLE = 'empreintelive_folders';

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string,mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE)) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('live_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('folder_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('owner_uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'default' => 0,
		]);

		$table->setPrimaryKey(['id']);
		// Un Live = un dossier. C'est cette contrainte qui porte la regle metier.
		$table->addUniqueIndex(['live_id'], 'emplive_folders_live_uniq');
		// Purge des liens d'un compte, et nettoyage quand un dossier disparait.
		$table->addIndex(['owner_uid'], 'emplive_folders_owner_idx');
		$table->addIndex(['folder_id'], 'emplive_folders_fid_idx');

		return $schema;
	}
}
