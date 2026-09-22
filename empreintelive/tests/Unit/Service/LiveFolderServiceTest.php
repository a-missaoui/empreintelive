<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Db\LiveFolder;
use OCA\EmpreinteLive\Db\LiveFolderMapper;
use OCA\EmpreinteLive\Exception\LiveFolderConflictException;
use OCA\EmpreinteLive\Service\LiveFolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LiveFolderServiceTest extends TestCase {
	private LiveFolderMapper&MockObject $mapper;
	private LiveFolderService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(LiveFolderMapper::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1789000000);

		$this->service = new LiveFolderService($this->mapper, $time);
	}

	private function entity(string $liveId, int $folderId, string $owner): LiveFolder {
		$entity = new LiveFolder();
		$entity->setLiveId($liveId);
		$entity->setFolderId($folderId);
		$entity->setOwnerUid($owner);
		$entity->setCreatedAt(1788000000);

		return $entity;
	}

	public function testLinkCreatesWhenAbsent(): void {
		$this->mapper->method('findByLiveId')->willThrowException(new DoesNotExistException('nope'));
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (LiveFolder $e): LiveFolder {
				$this->assertSame('274', $e->getLiveId());
				$this->assertSame(1042, $e->getFolderId());
				$this->assertSame('alice', $e->getOwnerUid());
				$this->assertSame(1789000000, $e->getCreatedAt());

				return $e;
			});
		$this->mapper->expects($this->never())->method('update');

		$this->service->link('alice', '274', 1042);
	}

	public function testLinkUpdatesWhenFolderChanges(): void {
		$this->mapper->method('findByLiveId')->willReturn($this->entity('274', 1042, 'alice'));
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnCallback(function (LiveFolder $e): LiveFolder {
				$this->assertSame(2000, $e->getFolderId());

				return $e;
			});

		$this->service->link('alice', '274', 2000);
	}

	/** Relier au meme dossier ne doit rien ecrire. */
	public function testLinkIsIdempotent(): void {
		$this->mapper->method('findByLiveId')->willReturn($this->entity('274', 1042, 'alice'));
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$result = $this->service->link('alice', '274', 1042);

		$this->assertSame(1042, $result->getFolderId());
	}

	/**
	 * Le lien appartient a la reunion : un participant ne peut pas se l'approprier
	 * en le repointant vers son propre dossier.
	 */
	public function testLinkRefusesAnotherUsersLive(): void {
		$this->mapper->method('findByLiveId')->willReturn($this->entity('274', 1042, 'alice'));
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(LiveFolderConflictException::class);

		$this->service->link('bob', '274', 9999);
	}

	public function testFindReturnsNullWhenAbsent(): void {
		$this->mapper->method('findByLiveId')->willThrowException(new DoesNotExistException('nope'));

		$this->assertNull($this->service->find('274'));
	}

	/** Lecture ouverte : un participant voit le dossier de la reunion. */
	public function testFindIgnoresOwnership(): void {
		$this->mapper->method('findByLiveId')->willReturn($this->entity('274', 1042, 'alice'));

		$this->assertSame(1042, $this->service->find('274')?->getFolderId());
	}

	public function testFindOwnedReturnsNullForAnotherUser(): void {
		$this->mapper->method('findByLiveId')->willReturn($this->entity('274', 1042, 'alice'));

		$this->assertNull($this->service->findOwned('bob', '274'));
		$this->assertNotNull($this->service->findOwned('alice', '274'));
	}

	public function testUnlinkRemovesOwnLink(): void {
		$this->mapper->method('findByLiveId')->willReturn($this->entity('274', 1042, 'alice'));
		$this->mapper->expects($this->once())->method('delete');

		$this->assertTrue($this->service->unlink('alice', '274'));
	}

	public function testUnlinkRefusesAnotherUsersLink(): void {
		$this->mapper->method('findByLiveId')->willReturn($this->entity('274', 1042, 'alice'));
		$this->mapper->expects($this->never())->method('delete');

		$this->assertFalse($this->service->unlink('bob', '274'));
	}

	public function testUnlinkReturnsFalseWhenAbsent(): void {
		$this->mapper->method('findByLiveId')->willThrowException(new DoesNotExistException('nope'));
		$this->mapper->expects($this->never())->method('delete');

		$this->assertFalse($this->service->unlink('alice', '274'));
	}

	public function testUnlinkByFolderRemovesEveryLink(): void {
		$this->mapper->method('findByFolderId')->willReturn([
			$this->entity('274', 1042, 'alice'),
			$this->entity('512', 1042, 'alice'),
		]);
		$this->mapper->expects($this->exactly(2))->method('delete');

		$this->assertSame(2, $this->service->unlinkByFolder(1042));
	}

	public function testUnlinkByFolderIsSafeWhenNothingLinked(): void {
		$this->mapper->method('findByFolderId')->willReturn([]);
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame(0, $this->service->unlinkByFolder(1042));
	}
}
