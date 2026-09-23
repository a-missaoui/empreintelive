<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Envoi d'un document vers la reunion refuse. Le message
 * porte un code stable (not_found, unsupported_format, too_large, not_connected,
 * rejected, upload_failed, delete_failed) que le frontend traduit.
 */

namespace OCA\EmpreinteLive\Exception;

class LiveDocumentException extends \RuntimeException {
}
