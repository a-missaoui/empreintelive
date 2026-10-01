<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Etape de reparation a la desinstallation : retire le bot de Talk. Aucune
 * reunion n'est supprimee cote EMPREINTE.
 */

namespace OCA\EmpreinteLive\Migration;

use OCA\EmpreinteLive\Talk\TalkBot;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class UnregisterTalkBot implements IRepairStep {
	public function __construct(
		private TalkBot $bot,
	) {
	}

	public function getName(): string {
		return 'Remove the EMPREINTE Live bot from Talk';
	}

	public function run(IOutput $output): void {
		$output->info($this->bot->unregister()
			? 'EMPREINTE Live bot removed from Talk'
			: 'Talk is not installed: no bot to remove');
	}
}
