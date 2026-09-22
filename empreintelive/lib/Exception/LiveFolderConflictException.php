<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Levee quand un Live est deja lie a un dossier par un
 * AUTRE compte Nextcloud. Le lien appartient a la reunion, pas au participant :
 * seul son createur peut le poser ou le changer.
 */

namespace OCA\EmpreinteLive\Exception;

class LiveFolderConflictException extends \RuntimeException {
}
