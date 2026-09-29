<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Le compte EMPREINTE de l'utilisateur n'est pas (ou plus) utilisable : aucun
 * token, token impossible a rafraichir, ou token refuse par l'API (compte
 * supprime, scopes manquants). Le message reprend, quand il existe, celui de
 * l'API (ex. « User not found »).
 *
 * Etend RuntimeException : les appelants qui ne la distinguent pas continuent
 * de la traiter comme une erreur ordinaire.
 */

namespace OCA\EmpreinteLive\Exception;

class NotConnectedException extends \RuntimeException {
}
