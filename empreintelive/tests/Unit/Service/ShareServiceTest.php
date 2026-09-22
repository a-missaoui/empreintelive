<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Exception\ShareNotAllowedException;
use OCA\EmpreinteLive\Service\ShareService;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\HintException;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShareServiceTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private IManager&MockObject $shareManager;
	private ShareService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->shareManager = $this->createMock(IManager::class);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.example/s/tok');

		$this->shareManager->method('shareApiAllowLinks')->willReturn(true);
		$this->service = new ShareService($this->rootFolder, $this->shareManager, $url);
	}

	/** Expose un noeud accessible a l'utilisateur, avec les permissions donnees. */
	private function node(int $permissions, bool $folder = false): File|Folder {
		$node = $this->createMock($folder ? Folder::class : File::class);
		$node->method('getPermissions')->willReturn($permissions);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$node]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		return $node;
	}

	private function captureShare(): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getFullId')->willReturn('ocinternal:42');
		$share->method('getToken')->willReturn('tok');
		$share->method('getPassword')->willReturn(null);
		$share->method('getExpirationDate')->willReturn(null);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$this->shareManager->method('newShare')->willReturn($share);
		$this->shareManager->method('createShare')->willReturn($share);

		return $share;
	}

	// ─────────────── refus ───────────────

	/** Critere 4 : sans droit de partage cote Nextcloud, on refuse. */
	public function testRefusesWhenNodeIsNotShareable(): void {
		$this->node(Constants::PERMISSION_READ);
		$this->shareManager->expects($this->never())->method('createShare');

		$this->expectException(ShareNotAllowedException::class);
		$this->expectExceptionMessage('not_shareable');

		$this->service->createLink('alice', 7);
	}

	public function testRefusesWhenFileIsNotVisible(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->expectException(ShareNotAllowedException::class);
		$this->expectExceptionMessage('not_found');

		$this->service->createLink('bob', 7);
	}

	/** On respecte le reglage d'instance plutot que de le contourner. */
	public function testRefusesWhenInstanceEnforcesPassword(): void {
		$this->node(Constants::PERMISSION_ALL);
		$this->shareManager->method('shareApiLinkEnforcePassword')->willReturn(true);
		$this->shareManager->expects($this->never())->method('createShare');

		$this->expectException(ShareNotAllowedException::class);
		$this->expectExceptionMessage('password_required');

		$this->service->createLink('alice', 7, ['password' => '  ']);
	}

	// ─────────────── creation ───────────────

	public function testCreatesReadOnlyLinkByDefault(): void {
		$this->node(Constants::PERMISSION_ALL);
		$share = $this->captureShare();

		$share->expects($this->once())->method('setShareType')->with(IShare::TYPE_LINK);
		$share->expects($this->once())->method('setSharedBy')->with('alice');
		$share->expects($this->once())->method('setPermissions')->with(Constants::PERMISSION_READ);
		$share->expects($this->never())->method('setPassword');

		$r = $this->service->createLink('alice', 7);

		$this->assertSame('https://nc.example/s/tok', $r['url']);
		$this->assertFalse($r['editable']);
		$this->assertFalse($r['hasPassword']);
	}

	public function testEditableFileGetsUpdateRight(): void {
		$this->node(Constants::PERMISSION_ALL);
		$share = $this->captureShare();

		$share->expects($this->once())->method('setPermissions')
			->with(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$this->service->createLink('alice', 7, ['editable' => true]);
	}

	public function testEditableFolderAlsoGetsCreateAndDelete(): void {
		$this->node(Constants::PERMISSION_ALL, true);
		$share = $this->captureShare();

		$share->expects($this->once())->method('setPermissions')->with(
			Constants::PERMISSION_READ
			| Constants::PERMISSION_CREATE
			| Constants::PERMISSION_UPDATE
			| Constants::PERMISSION_DELETE,
		);

		$this->service->createLink('alice', 7, ['editable' => true]);
	}

	public function testPasswordAndExpirationAreApplied(): void {
		$this->node(Constants::PERMISSION_ALL);
		$share = $this->captureShare();

		$share->expects($this->once())->method('setPassword')->with('secret');
		$share->expects($this->once())->method('setExpirationDate')
			->with($this->callback(static fn ($d) => $d->format('Y-m-d') === '2026-12-31'));

		$this->service->createLink('alice', 7, [
			'password' => 'secret',
			'expiration' => '2026-12-31',
		]);
	}

	/** Une date illisible ne doit pas poser une expiration fantaisiste. */
	public function testInvalidExpirationIsIgnored(): void {
		$this->node(Constants::PERMISSION_ALL);
		$share = $this->captureShare();
		$this->shareManager->method('shareApiLinkDefaultExpireDate')->willReturn(false);

		$share->expects($this->never())->method('setExpirationDate');

		$this->service->createLink('alice', 7, ['expiration' => 'pas-une-date']);
	}

	/**
	 * Regression : getShareById() attend « ocinternal:42 », pas « 42 ». Les mocks
	 * ne l'avaient pas revele, le test contre Nextcloud si.
	 */
	public function testExposesTheFullShareId(): void {
		$this->node(Constants::PERMISSION_ALL);
		$this->captureShare();

		$this->assertSame('ocinternal:42', $this->service->createLink('alice', 7)['id']);
	}

	/** Politique de mot de passe : erreur utilisateur, pas panne serveur. */
	public function testSurfacesNextcloudPasswordPolicyHint(): void {
		$this->node(Constants::PERMISSION_ALL);
		$share = $this->createMock(IShare::class);
		$this->shareManager->method('newShare')->willReturn($share);
		$this->shareManager->method('createShare')
			->willThrowException(new HintException('interne', 'Mot de passe trop courant.'));

		try {
			$this->service->createLink('alice', 7, ['password' => 'azerty']);
			$this->fail('une exception etait attendue');
		} catch (ShareNotAllowedException $e) {
			$this->assertSame('rejected', $e->getMessage());
			$this->assertSame('Mot de passe trop courant.', $e->getHint());
		}
	}

	// ─────────────── suppression ───────────────

	public function testDeleteRefusesAnotherUsersShare(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getSharedBy')->willReturn('alice');
		$this->shareManager->method('getShareById')->willReturn($share);
		$this->shareManager->expects($this->never())->method('deleteShare');

		$this->expectException(ShareNotAllowedException::class);
		$this->expectExceptionMessage('not_owner');

		$this->service->deleteLink('bob', '42');
	}

	public function testDeleteRemovesOwnShare(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getSharedBy')->willReturn('alice');
		$this->shareManager->method('getShareById')->willReturn($share);
		$this->shareManager->expects($this->once())->method('deleteShare')->with($share);

		$this->service->deleteLink('alice', '42');
	}
}
