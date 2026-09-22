<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Exception\LiveDocumentException;
use OCA\EmpreinteLive\Service\LiveDocumentService;
use OCA\EmpreinteLive\Service\OAuthService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LiveDocumentServiceTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private IClientService&MockObject $clientService;
	private OAuthService&MockObject $oauth;
	private LiveDocumentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->clientService = $this->createMock(IClientService::class);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnArgument(2);

		$this->oauth = $this->createMock(OAuthService::class);
		$this->oauth->method('getAuthorizationHeader')->willReturn('Bearer jeton-de-test');

		$this->service = new LiveDocumentService(
			$this->rootFolder,
			$this->clientService,
			$this->oauth,
			$config,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function fileNamed(string $name): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(1024);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
	}

	/**
	 * EMPREINTE ne convertit que PDF, PPTX et DOCX. On refuse AVANT l'appel
	 * reseau : inutile de televerser un fichier qui sera rejete.
	 *
	 * @dataProvider refusedProvider
	 */
	public function testRefusesUnsupportedFormats(string $name): void {
		$this->fileNamed($name);
		$this->clientService->expects($this->never())->method('newClient');

		$this->expectException(LiveDocumentException::class);
		$this->expectExceptionMessage('unsupported_format');

		$this->service->sendDocument('alice', '646', 7);
	}

	public static function refusedProvider(): array {
		return [
			'texte' => ['notes.txt'],
			'image' => ['schema.png'],
			'tableur' => ['budget.csv'],
			'archive' => ['support.zip'],
			'sans extension' => ['README'],
		];
	}

	/** Un fichier que l'utilisateur ne voit pas n'existe pas pour lui. */
	public function testRefusesFileTheUserCannotSee(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->clientService->expects($this->never())->method('newClient');

		$this->expectException(LiveDocumentException::class);
		$this->expectExceptionMessage('not_found');

		$this->service->sendDocument('bob', '646', 7);
	}

	/** L'extension est insensible a la casse : « Rapport.PDF » doit passer. */
	public function testExtensionIsCaseInsensitive(): void {
		$this->fileNamed('Rapport.PDF');
		// On s'arrete a l'appel reseau : ce qui compte est qu'on y arrive.
		$this->clientService->expects($this->once())->method('newClient')
			->willThrowException(new \RuntimeException('reseau coupe'));

		$this->expectException(LiveDocumentException::class);
		$this->expectExceptionMessage('upload_failed');

		$this->service->sendDocument('alice', '646', 7);
	}

	/** Refuser avant de televerser, pas apres. */
	public function testRefusesOversizedDocument(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('enorme.pdf');
		$file->method('getSize')->willReturn(500 * 1024 * 1024);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->clientService->expects($this->never())->method('newClient');

		$this->expectException(LiveDocumentException::class);
		$this->expectExceptionMessage('too_large');

		$this->service->sendDocument('alice', '646', 7);
	}

	public function testAllowedExtensionsMatchTheMeetDropZone(): void {
		$this->assertSame(['pdf', 'pptx', 'docx'], LiveDocumentService::ALLOWED_EXTENSIONS);
	}

	/**
	 * Compte EMPREINTE non connecte : on ne tente aucun appel, et le panneau
	 * affiche « compte non connecte » plutot qu'une panne de l'API.
	 */
	public function testListWithoutConnectedAccount(): void {
		$oauth = $this->createMock(OAuthService::class);
		$oauth->method('getAuthorizationHeader')->willThrowException(new \RuntimeException('non authentifie'));
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnArgument(2);
		$this->clientService->expects($this->never())->method('newClient');

		$service = new LiveDocumentService(
			$this->rootFolder,
			$this->clientService,
			$oauth,
			$config,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(
			['available' => false, 'documents' => [], 'reason' => 'not_connected'],
			$service->listDocuments('alice', '646'),
		);
	}

	/** L'envoi porte le jeton de l'utilisateur : plus de cle partagee. */
	public function testUploadSendsTheUserToken(): void {
		$this->fileNamed('Rapport.pdf');
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->expects($this->once())->method('post')->with(
			'https://api.empreinte.live/lives/646/convert-document',
			$this->callback(static fn (array $o): bool
				=> ($o['headers']['Authorization'] ?? '') === 'Bearer jeton-de-test'),
		)->willThrowException(new \RuntimeException('coupe apres verification'));
		$this->clientService->method('newClient')->willReturn($client);

		$this->expectException(LiveDocumentException::class);
		$this->expectExceptionMessage('upload_failed');

		$this->service->sendDocument('alice', '646', 7);
	}
}
