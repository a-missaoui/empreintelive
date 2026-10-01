<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Etape de reparation declaree a l'installation ET apres chaque mise a jour :
 * le bot Talk arrive par une mise a jour standard, sans configuration.
 */

namespace OCA\EmpreinteLive\Migration;

use OCA\EmpreinteLive\Talk\TalkBot;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RegisterTalkBot implements IRepairStep {
	public function __construct(
		private TalkBot $bot,
	) {
	}

	public function getName(): string {
		return 'Register the EMPREINTE Live bot in Talk';
	}

	public function run(IOutput $output): void {
		$output->info($this->bot->register()
			? 'EMPREINTE Live bot registered in Talk'
			: 'Talk is not installed: no bot to register');
	}
}
