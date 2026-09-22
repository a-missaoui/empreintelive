<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Db;

use OCA\EmpreinteLive\Db\LiveFolder;
use PHPUnit\Framework\TestCase;

class LiveFolderTest extends TestCase {
	/**
	 * La base rend des chaines : l'entite doit retyper folder_id et created_at en
	 * entiers, sinon les comparaisons strictes du service echouent silencieusement.
	 */
	public function testFromRowCastsTypes(): void {
		$entity = LiveFolder::fromRow([
			'id' => '7',
			'live_id' => '274',
			'folder_id' => '1042',
			'owner_uid' => 'alice',
			'created_at' => '1789000000',
		]);

		$this->assertSame(7, $entity->getId());
		$this->assertSame('274', $entity->getLiveId());
		$this->assertSame(1042, $entity->getFolderId());
		$this->assertSame('alice', $entity->getOwnerUid());
		$this->assertSame(1789000000, $entity->getCreatedAt());
	}

	/** Le liveId reste une chaine : certains identifiants EMPREINTE ne sont pas numeriques. */
	public function testLiveIdStaysAString(): void {
		$entity = new LiveFolder();
		$entity->setLiveId('407-simple_live_x');

		$this->assertSame('407-simple_live_x', $entity->getLiveId());
	}

	public function testJsonSerializeOmitsOwner(): void {
		$entity = new LiveFolder();
		$entity->setLiveId('274');
		$entity->setFolderId(1042);
		$entity->setOwnerUid('alice');
		$entity->setCreatedAt(1789000000);

		$this->assertSame([
			'liveId' => '274',
			'folderId' => 1042,
			'createdAt' => 1789000000,
		], $entity->jsonSerialize());
	}

	public function testDefaultsAreUsable(): void {
		$entity = new LiveFolder();

		$this->assertSame('', $entity->getLiveId());
		$this->assertSame(0, $entity->getFolderId());
		$this->assertSame('', $entity->getOwnerUid());
		$this->assertSame(0, $entity->getCreatedAt());
	}
}
