<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Db\LiveFolder;
use OCA\EmpreinteLive\Service\DocumentService;
use OCA\EmpreinteLive\Service\LiveFolderService;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IPreview;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentServiceTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private LiveFolderService&MockObject $links;
	private IPreview&MockObject $preview;
	private DocumentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->links = $this->createMock(LiveFolderService::class);
		$this->preview = $this->createMock(IPreview::class);
		$this->service = new DocumentService($this->rootFolder, $this->links, $this->preview);
	}

	private function link(int $folderId, string $owner = 'alice'): LiveFolder {
		$e = new LiveFolder();
		$e->setLiveId('274');
		$e->setFolderId($folderId);
		$e->setOwnerUid($owner);

		return $e;
	}

	// ─────────────── chemin propose ───────────────

	/** @dataProvider titleProvider */
	public function testSuggestedPath(string $expected, string $title): void {
		$this->assertSame($expected, $this->service->suggestedPath($title));
	}

	public static function titleProvider(): array {
		return [
			'simple' => ['/EMPREINTE Live/Reunion produit', 'Reunion produit'],
			'slashes neutralises' => ['/EMPREINTE Live/a b', 'a/b'],
			'caracteres interdits' => ['/EMPREINTE Live/rapport Q3', 'rapport:Q3'],
			'espaces multiples' => ['/EMPREINTE Live/a b', "a \t  b"],
			'titre vide' => ['/EMPREINTE Live/Reunion', ''],
		];
	}

	// ─────────────── etat du panneau ───────────────

	public function testDescribeWithoutLink(): void {
		$this->links->method('find')->willReturn(null);

		$r = $this->service->describe('alice', '274');

		$this->assertFalse($r['linked']);
		$this->assertFalse($r['accessible']);
		$this->assertNull($r['reason']);
		$this->assertSame([], $r['items']);
	}

	/**
	 * Cas central : le dossier existe (le proprietaire le voit), mais pas
	 * pour CET utilisateur. On ne divulgue rien, on ne leve pas d'erreur, et
	 * surtout on ne delie pas : le lien appartient au proprietaire.
	 */
	public function testDescribeWhenUserHasNoAccess(): void {
		$this->links->method('find')->willReturn($this->link(1042, 'alice'));

		$visible = $this->createMock(Folder::class);
		$visible->method('getById')->willReturn([$this->createMock(Folder::class)]);
		$invisible = $this->createMock(Folder::class);
		$invisible->method('getById')->willReturn([]);

		$this->rootFolder->method('getUserFolder')->willReturnCallback(
			static fn (string $uid) => $uid === 'alice' ? $visible : $invisible,
		);
		$this->links->expects($this->never())->method('unlink');

		$r = $this->service->describe('bob', '274');

		$this->assertTrue($r['linked']);
		$this->assertFalse($r['accessible']);
		$this->assertSame('no_access', $r['reason']);
		$this->assertSame([], $r['items']);
	}

	/**
	 * Personne ne voit plus le dossier, pas meme son proprietaire. Il a
	 * donc ete supprime. On delie, sinon le panneau reste bloque sur « non
	 * accessible » sans jamais reproposer d'en associer un.
	 */
	public function testDescribeUnlinksWhenFolderIsGone(): void {
		$this->links->method('find')->willReturn($this->link(1042, 'alice'));

		$empty = $this->createMock(Folder::class);
		$empty->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($empty);

		$this->links->expects($this->once())->method('unlink')->with('alice', '274');

		$r = $this->service->describe('alice', '274');

		$this->assertFalse($r['linked'], 'le panneau doit reproposer d\'associer un dossier');
		$this->assertSame('folder_missing', $r['reason']);
	}

	public function testDescribeListsChildren(): void {
		$this->links->method('find')->willReturn($this->link(1042));

		$child = $this->createMock(File::class);
		$child->method('getId')->willReturn(7);
		$child->method('getName')->willReturn('note.pdf');
		$child->method('getPath')->willReturn('/alice/files/Reunion/note.pdf');
		$child->method('getMTime')->willReturn(1789000000);
		$child->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$child->method('getSize')->willReturn(2048);
		$child->method('getMimeType')->willReturn('application/pdf');
		$this->preview->method('isAvailable')->willReturn(true);

		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1042);
		$folder->method('getName')->willReturn('Reunion');
		$folder->method('getPath')->willReturn('/alice/files/Reunion');
		$folder->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$folder->method('getDirectoryListing')->willReturn([$child]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$folder]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$r = $this->service->describe('alice', '274');

		$this->assertTrue($r['accessible']);
		$this->assertTrue($r['isOwner']);
		$this->assertTrue($r['canWrite']);
		$this->assertSame(1042, $r['folder']['id']);
		$this->assertTrue($r['folder']['canShare'], 'le bouton « Lien du dossier » depend de ce drapeau');
		$this->assertCount(1, $r['items']);

		$item = $r['items'][0];
		$this->assertSame('note.pdf', $item['name']);
		$this->assertSame('note.pdf', $item['path'], 'le chemin doit etre relatif au dossier lie');
		$this->assertSame('file', $item['type']);
		$this->assertSame(2048, $item['size']);
		$this->assertTrue($item['hasPreview']);
	}

	/** Un lecteur seul ne doit pas se voir proposer d'ecriture. */
	public function testDescribeReadOnlyFolder(): void {
		$this->links->method('find')->willReturn($this->link(1042));

		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1042);
		$folder->method('getName')->willReturn('Reunion');
		$folder->method('getPath')->willReturn('/bob/files/Reunion');
		$folder->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$folder->method('getDirectoryListing')->willReturn([]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$folder]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$r = $this->service->describe('bob', '274');

		$this->assertTrue($r['accessible']);
		$this->assertFalse($r['isOwner'], 'bob n\'est pas le proprietaire du lien');
		$this->assertFalse($r['canWrite']);
		$this->assertFalse($r['folder']['canShare'], 'lecture seule : pas de lien de dossier propose');
	}

	/** Garde anti-traversee : un sous-chemin qui sort du dossier lie est refuse. */
	public function testDescribeRefusesPathTraversal(): void {
		$this->links->method('find')->willReturn($this->link(1042));

		$outside = $this->createMock(Folder::class);
		$outside->method('getPath')->willReturn('/alice/files/Prive');

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files/Reunion');
		$folder->method('get')->willReturn($outside);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$folder]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$r = $this->service->describe('alice', '274', '../Prive');

		$this->assertFalse($r['accessible']);
	}

	public function testDescribeHandlesMissingSubFolder(): void {
		$this->links->method('find')->willReturn($this->link(1042));

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files/Reunion');
		$folder->method('get')->willThrowException(new NotFoundException());

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$folder]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->assertFalse($this->service->describe('alice', '274', 'absent')['accessible']);
	}

	// ─────────────── liaison ───────────────

	public function testLinkFolderCreatesWhenMissing(): void {
		$created = $this->createMock(Folder::class);
		$created->method('getId')->willReturn(2001);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->expects($this->once())->method('newFolder')
			->with('/EMPREINTE Live/Reunion produit')->willReturn($created);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->links->expects($this->once())->method('link')
			->with('alice', '274', 2001)->willReturn($this->link(2001));

		$this->service->linkFolder('alice', '274', '/EMPREINTE Live/Reunion produit');
	}

	public function testLinkFolderReusesExisting(): void {
		$existing = $this->createMock(Folder::class);
		$existing->method('getId')->willReturn(1042);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($existing);
		$userFolder->expects($this->never())->method('newFolder');
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->links->expects($this->once())->method('link')->with('alice', '274', 1042)
			->willReturn($this->link(1042));

		$this->service->linkFolder('alice', '274', '/Documents/Projet');
	}

	public function testLinkFolderRefusesAFile(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($this->createMock(File::class));
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->links->expects($this->never())->method('link');

		$this->expectException(RuntimeException::class);

		$this->service->linkFolder('alice', '274', '/Documents/note.pdf');
	}

	// ─────────────── dossier des enregistrements ───────────────

	/** Dossier lie accessible en ecriture : l'enregistrement y va, sans rien creer. */
	public function testRecordingFolderUsesWritableLinkedFolder(): void {
		$this->links->method('find')->willReturn($this->link(1042, 'alice'));

		$linked = $this->createMock(Folder::class);
		$linked->method('getPath')->willReturn('/bob/files/Projet');
		$linked->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_CREATE);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getPath')->willReturn('/bob/files');
		$userFolder->method('getById')->with(1042)->willReturn([$linked]);
		$userFolder->method('getRelativePath')->with('/bob/files/Projet')->willReturn('/Projet');
		$userFolder->expects($this->never())->method('newFolder');
		$this->rootFolder->method('getUserFolder')->with('bob')->willReturn($userFolder);
		$this->links->expects($this->never())->method('link');

		$this->assertSame(['path' => '/Projet', 'linked' => true], $this->service->recordingFolder('bob', '274', 'Revue'));
	}

	/**
	 * Critere 4 : lecture seule sur le dossier lie. On n'ecrit pas dedans, on ne
	 * touche pas au lien du proprietaire : l'enregistrement va dans l'espace de
	 * l'utilisateur.
	 */
	public function testRecordingFolderFallsBackWhenLinkedFolderIsReadOnly(): void {
		$this->links->method('find')->willReturn($this->link(1042, 'alice'));

		$linked = $this->createMock(Folder::class);
		$linked->method('getPath')->willReturn('/bob/files/Projet');
		$linked->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$own = $this->createMock(Folder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getPath')->willReturn('/bob/files');
		$userFolder->method('getById')->willReturn([$linked]);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->expects($this->once())->method('newFolder')->with('/EMPREINTE Live/Revue')->willReturn($own);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->links->expects($this->never())->method('link');

		$this->assertSame(['path' => '/EMPREINTE Live/Revue', 'linked' => false], $this->service->recordingFolder('bob', '274', 'Revue'));
	}

	/** Aucun dossier : on cree le dossier de la reunion et on le lie. */
	public function testRecordingFolderCreatesAndLinksWhenNoneLinked(): void {
		$this->links->method('find')->willReturn(null);

		$own = $this->createMock(Folder::class);
		$own->method('getId')->willReturn(77);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->with('/EMPREINTE Live/Revue')->willReturn($own);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->links->expects($this->once())->method('link')->with('alice', '274', 77);

		$this->assertSame(['path' => '/EMPREINTE Live/Revue', 'linked' => true], $this->service->recordingFolder('alice', '274', 'Revue'));
	}

	public function testRecordingFolderRefusesAFile(): void {
		$this->links->method('find')->willReturn(null);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($this->createMock(File::class));
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->expectException(NotPermittedException::class);
		$this->service->recordingFolder('alice', '274', 'Revue');
	}
}
