/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Logique pure des réunions et des documents, sans Vue ni DOM : partagée par
 * plusieurs écrans, et testable directement.
 */

/** Durée par défaut d'une réunion sans heure de fin : une heure. */
const DEFAULT_LENGTH_MS = 60 * 60 * 1000

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
	const end = toDate(m?.dateEndDiffusion ?? m?.endTime ?? m?.end)
	const start = startOf(m)
	// Une réunion créée sans heure de fin porte une fin égale à son début : on lui
	// donne la durée par défaut, sinon elle serait « terminée » dès son début.
	if (end && start && end.getTime() <= start.getTime()) {
		return new Date(start.getTime() + DEFAULT_LENGTH_MS)
	}
	return end
}

/**
 * Fin par défaut d'une réunion dont on ne connaît que le début : une heure, comme
 * le bot Talk et l'événement d'agenda.
 *
 * @param {Date} start - Le début.
 * @return {Date}
 */
export function defaultEnd(start) {
	return new Date(start.getTime() + DEFAULT_LENGTH_MS)
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
 * Lien à partager avec les participants. Jamais le lien organisateur : les deux
 * ne diffèrent que par leur jeton, et le lien organisateur donne les droits
 * d'animation à quiconque l'ouvre.
 *
 * @param {object} m - La réunion, sous l'une des formes renvoyées par l'API.
 * @return {string|null} Le lien participant, ou null s'il est inconnu.
 */
export function participantLink(m) {
	return m?.participant_url ?? m?.participantUrl ?? null
}

/**
 * « Aujourd'hui, 10:35 – 11:35 », « Demain, 09:00 – 10:00 »,
 * « 18 sept. 2026, 10:35 – 11:35 ». Partagé par la liste des réunions et la
 * carte de réunion.
 *
 * @param {object} m - La réunion.
 * @param {number} now - Instant de référence, en millisecondes.
 * @param {(app: string, text: string, vars?: object) => string} t - Traduction (translate de @nextcloud/l10n).
 * @param {string} locale - Locale d'affichage (getCanonicalLocale()).
 * @return {string} Le libellé, ou une chaîne vide.
 */
export function meetingWhen(m, now, t, locale) {
	const start = startOf(m)
	if (!start) {
		return ''
	}
	const time = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', hour12: false })
	const end = endOf(m)
	const range = end ? `${time.format(start)} – ${time.format(end)}` : time.format(start)

	const day = new Date(start)
	day.setHours(0, 0, 0, 0)
	const today = new Date(now)
	today.setHours(0, 0, 0, 0)
	const diff = Math.round((day - today) / 86400000)

	if (diff === 0) {
		return t('empreintelive', 'Today, {range}', { range })
	}
	if (diff === 1) {
		return t('empreintelive', 'Tomorrow, {range}', { range })
	}
	if (diff === -1) {
		return t('empreintelive', 'Yesterday, {range}', { range })
	}
	const date = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric' })
	return `${date.format(start)}, ${range}`
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
