<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Talk n'est pas charge par les tests unitaires : on verifie que l'app ne fait
 * rien sans lui, puis, avec un evenement de substitution, ce qu'elle transmet.
 */

namespace OCA\EmpreinteLive\Tests\Unit\Talk;

use OCA\EmpreinteLive\Talk\TalkBot;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class TalkBotTest extends TestCase {
	public function testWithoutTalkNothingIsDispatched(): void {
		if (class_exists(TalkBot::INSTALL_EVENT)) {
			$this->markTestSkipped('Talk est charge dans cet environnement');
		}
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');

		$bot = new TalkBot($dispatcher, $appConfig, $this->createMock(ISecureRandom::class));

		$this->assertFalse($bot->register());
		$this->assertFalse($bot->unregister());
	}

	public function testUsesAnInProcessAppUrl(): void {
		$this->assertSame('nextcloudapp://empreintelive', TalkBot::URL);
	}
}
