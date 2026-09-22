<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Db\LiveFolder;
use OCA\EmpreinteLive\Exception\LiveDocumentException;
use OCA\EmpreinteLive\Service\DocumentMeetingService;
use OCA\EmpreinteLive\Service\LiveDocumentService;
use OCA\EmpreinteLive\Service\LiveFolderService;
use OCA\EmpreinteLive\Service\MeetingCreationService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DocumentMeetingServiceTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private IManager&MockObject $shares;
	private IUserManager&MockObject $users;
	private MeetingCreationService&MockObject $meetings;
	private LiveDocumentService&MockObject $liveDocs;
	private LiveFolderService&MockObject $links;
	private Folder&MockObject $userFolder;
	private DocumentMeetingService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->shares = $this->createMock(IManager::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->meetings = $this->createMock(MeetingCreationService::class);
		$this->liveDocs = $this->createMock(LiveDocumentService::class);
		$this->links = $this->createMock(LiveFolderService::class);

		$this->userFolder = $this->createMock(Folder::class);
		$this->userFolder->method('getPath')->willReturn('/alice/files');
		$this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);

		// La vraie deduplication : c'est elle que la suggestion reutilise.
		$this->meetings->method('sanitizeEmails')->willReturnCallback(
			static fn (array $e) => array_values(array_unique($e)),
		);

		$this->service = new DocumentMeetingService(
			$this->rootFolder, $this->shares, $this->users, $this->meetings,
			$this->liveDocs, $this->links, $this->createMock(LoggerInterface::class),
		);
	}

	/** Place un fichier sous $parentPath et le rend visible a l'utilisateur. */
	private function file(string $name, string $parentPath): File&MockObject {
		$parent = $this->createMock(Folder::class);
		$parent->method('getPath')->willReturn($parentPath);
		$parent->method('getId')->willReturn(900);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getParent')->willReturn($parent);
		$this->userFolder->method('getById')->willReturn([$file]);

		return $file;
	}

	private function created(): void {
		$this->meetings->method('create')->willReturn([
			'data' => ['id' => 657], 'eventCreated' => true, 'invited' => false,
		]);
	}

	// ─────────────── suggestion ───────────────

	public function testSuggestTakesTitleFromFileName(): void {
		$this->file('Revue trimestrielle.pdf', '/alice/files/Projets');
		$this->shares->method('getSharesBy')->willReturn([]);

		$s = $this->service->suggest('alice', 7);

		$this->assertSame('Revue trimestrielle', $s['title']);
		$this->assertTrue($s['convertible']);
	}

	/** Les participants proposes sont ceux qui ont deja le document. */
	public function testSuggestProposesPeopleTheFileIsSharedWith(): void {
		$this->file('Rapport.pdf', '/alice/files/Projets');

		$userShare = $this->createMock(IShare::class);
		$userShare->method('getSharedWith')->willReturn('bob');
		$mailShare = $this->createMock(IShare::class);
		$mailShare->method('getSharedWith')->willReturn('externe@example.test');
		$this->shares->method('getSharesBy')->willReturnCallback(
			static fn ($uid, int $type) => match ($type) {
				IShare::TYPE_USER => [$userShare],
				IShare::TYPE_EMAIL => [$mailShare],
				default => [],
			},
		);
		$bob = $this->createMock(IUser::class);
		$bob->method('getEMailAddress')->willReturn('bob@example.test');
		$this->users->method('get')->willReturn($bob);

		$this->assertSame(
			['bob@example.test', 'externe@example.test'],
			$this->service->suggest('alice', 7)['attendees'],
		);
	}

	/** Un groupe peut compter des centaines de personnes : pas une liste d'invites. */
	public function testSuggestNeverQueriesGroupShares(): void {
		$this->file('Rapport.pdf', '/alice/files/Projets');
		$types = [];
		$this->shares->method('getSharesBy')->willReturnCallback(
			function ($uid, int $type) use (&$types) {
				$types[] = $type;
				return [];
			},
		);

		$this->service->suggest('alice', 7);

		$this->assertNotContains(IShare::TYPE_GROUP, $types);
	}

	public function testSuggestUnknownFile(): void {
		$this->userFolder->method('getById')->willReturn([]);
		$this->expectException(LiveDocumentException::class);
		$this->service->suggest('bob', 7);
	}

	// ─────────────── creation ───────────────

	public function testCreateSendsDocumentAndLinksParentFolder(): void {
		$this->file('Rapport.pdf', '/alice/files/Projets');
		$this->created();
		$this->liveDocs->expects($this->once())->method('sendDocument')->with('alice', '657', 7);
		$this->links->expects($this->once())->method('link')->with('alice', '657', 900)
			->willReturn(new LiveFolder());

		$r = $this->service->createForFile('alice', 7, 'T', 'D', 's', 'e');

		$this->assertTrue($r['documentSent']);
		$this->assertTrue($r['folderLinked']);
		$this->assertSame('657', $r['liveId']);
	}

	/**
	 * Regression : un document pose a la racine associait TOUT l'espace personnel
	 * a la reunion, affiche dans le panneau et partageable.
	 */
	public function testDocumentAtRootDoesNotLinkTheWholeHome(): void {
		$this->file('Manuel.pdf', '/alice/files');
		$this->created();
		$this->links->expects($this->never())->method('link');

		$r = $this->service->createForFile('alice', 7, 'T', 'D', 's', 'e');

		$this->assertFalse($r['folderLinked']);
		$this->assertTrue($r['documentSent'], 'le document est quand meme envoye');
	}

	/** Une fois la reunion creee, un echec d'envoi du document ne l'annule pas. */
	public function testDocumentFailureKeepsTheMeeting(): void {
		$this->file('Rapport.pdf', '/alice/files/Projets');
		$this->created();
		$this->liveDocs->method('sendDocument')->willThrowException(new LiveDocumentException('too_large'));

		$r = $this->service->createForFile('alice', 7, 'T', 'D', 's', 'e');

		$this->assertFalse($r['documentSent']);
		$this->assertSame('too_large', $r['documentError']);
		$this->assertSame('657', $r['liveId']);
	}

	public function testUnsupportedFormatIsNotSent(): void {
		$this->file('notes.txt', '/alice/files/Projets');
		$this->created();
		$this->liveDocs->expects($this->never())->method('sendDocument');

		$this->assertSame('unsupported_format', $this->service->createForFile('alice', 7, 'T', 'D', 's', 'e')['documentError']);
	}

	/** L'API EMPREINTE refuse une description vide : on en fournit une. */
	public function testEmptyDescriptionGetsADefault(): void {
		$this->file('Rapport.pdf', '/alice/files/Projets');
		$this->meetings->expects($this->once())->method('create')
			->with('alice', 'T', $this->stringContains('Rapport.pdf'))
			->willReturn(['data' => ['id' => 657], 'eventCreated' => true, 'invited' => false]);

		$this->service->createForFile('alice', 7, 'T', '   ', 's', 'e');
	}
}
