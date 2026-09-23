/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Logique pure des réunions et des documents, sans Vue ni DOM : partagée par
 * plusieurs écrans, et testable directement.
 */

/** Formats convertis en diapositives par EMPREINTE (zone de dépôt du meet). */
export const CONVERTIBLE = ['pdf', 'pptx', 'docx']

/**
 * @param {string} name - Nom de fichier.
 * @return {string} Extension en minuscules, ou chaîne vide.
 */
export function extensionOf(name) {
	const base = String(name ?? '')
	const dot = base.lastIndexOf('.')
	return dot > 0 ? base.slice(dot + 1).toLowerCase() : ''
}

/**
 * @param {string} name - Nom de fichier.
 * @return {boolean} Vrai si EMPREINTE sait présenter ce format.
 */
export function isConvertible(name) {
	return CONVERTIBLE.includes(extensionOf(name))
}

/**
 * Date de début, quelle que soit la forme renvoyée par l'API.
 *
 * @param {object} m - La réunion.
 * @return {Date|null}
 */
export function startOf(m) {
	return toDate(m?.dateStartDiffusion ?? m?.startTime ?? m?.start)
}

/**
 * @param {object} m - La réunion.
 * @return {Date|null}
 */
export function endOf(m) {
	return toDate(m?.dateEndDiffusion ?? m?.endTime ?? m?.end)
}

/**
 * @param {object} m - La réunion.
 * @param {number} now - Instant de référence, en millisecondes.
 * @return {'live'|'upcoming'|'past'|'unknown'}
 */
export function meetingStatus(m, now) {
	const start = startOf(m)
	if (!start) {
		return 'unknown'
	}
	if (now < start.getTime()) {
		return 'upcoming'
	}
	const end = endOf(m)
	if (end && now > end.getTime()) {
		return 'past'
	}
	return 'live'
}

/**
 * En cours d'abord, puis à venir (la plus proche en tête), puis terminées
 * (la plus récente en tête). C'est l'ordre dans lequel on les cherche.
 *
 * @param {object[]} meetings - Les réunions.
 * @param {number} now - Instant de référence, en millisecondes.
 * @return {object[]} Une copie triée ; l'entrée n'est pas modifiée.
 */
export function sortMeetings(meetings, now) {
	const rank = { live: 0, upcoming: 1, past: 2, unknown: 3 }
	return [...meetings].sort((a, b) => {
		const sa = meetingStatus(a, now)
		const sb = meetingStatus(b, now)
		if (sa !== sb) {
			return rank[sa] - rank[sb]
		}
		const ta = startOf(a)?.getTime() ?? 0
		const tb = startOf(b)?.getTime() ?? 0
		return sa === 'past' ? tb - ta : ta - tb
	})
}

/**
 * @param {string|number|Date|null|undefined} raw - Valeur de date venant de l'API.
 * @return {Date|null}
 */
function toDate(raw) {
	if (!raw) {
		return null
	}
	const d = new Date(raw)
	return isNaN(d) ? null : d
}
