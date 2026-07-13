<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EmpreinteLive\Tests\Unit\Service;

use OCA\EmpreinteLive\Service\ContactSearchService;
use OCP\Contacts\IManager;
use PHPUnit\Framework\TestCase;

class ContactSearchServiceTest extends TestCase {
	public function testEmptyQueryReturnsEmptyWithoutHittingContacts(): void {
		$contacts = $this->createMock(IManager::class);
		$contacts->expects($this->never())->method('search');

		$svc = new ContactSearchService($contacts);
		$this->assertSame([], $svc->search('   '));
	}

	public function testFlattensNamesAndEmailsAndDeduplicates(): void {
		$contacts = $this->createMock(IManager::class);
		$contacts->method('search')->willReturn([
			['FN' => 'Yann', 'EMAIL' => 'yann@empreinte.dev'],
			['FN' => 'François', 'EMAIL' => ['francois@corp.fr', 'FRANCOIS@corp.fr']], // doublon casse-insensible
			['FN' => '', 'EMAIL' => 'noname@corp.fr'], // pas de nom -> email comme libellé
			['FN' => 'Sans email'], // aucun email -> ignoré
		]);

		$svc = new ContactSearchService($contacts);
		$res = $svc->search('y');

		$this->assertSame([
			['name' => 'Yann', 'email' => 'yann@empreinte.dev'],
			['name' => 'François', 'email' => 'francois@corp.fr'],
			['name' => 'noname@corp.fr', 'email' => 'noname@corp.fr'],
		], $res);
	}
}
