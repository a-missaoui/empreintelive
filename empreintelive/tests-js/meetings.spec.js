/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Logique pure des réunions et des documents (src/utils/meetings.js).
 */
import { describe, expect, it } from 'vitest'
import {
	CONVERTIBLE,
	defaultEnd,
	endOf,
	extensionOf,
	isConvertible,
	meetingStatus,
	meetingWhen,
	participantLink,
	sortMeetings,
	startOf,
} from '../src/utils/meetings.js'

const at = (iso) => new Date(iso).getTime()
const meeting = (start, end, extra = {}) => ({ dateStartDiffusion: start, dateEndDiffusion: end, ...extra })

describe('formats présentables', () => {
	it('suivent la zone de dépôt du meet : PDF, PPTX, DOCX', () => {
		expect(CONVERTIBLE).toEqual(['pdf', 'pptx', 'docx'])
	})

	it.each([
		['Rapport.pdf', true],
		['Rapport.PDF', true],
		['slides.pptx', true],
		['contrat.docx', true],
		['notes.txt', false],
		['schema.png', false],
		['archive.pdf.zip', false],
		['README', false],
		['.pdf', false],
		['', false],
		[null, false],
	])('%s -> %s', (name, expected) => {
		expect(isConvertible(name)).toBe(expected)
	})

	it("l'extension est celle du dernier point", () => {
		expect(extensionOf('a.b.PdF')).toBe('pdf')
		expect(extensionOf('sans-extension')).toBe('')
	})
})

describe('dates de réunion', () => {
	it("lisent les différentes formes renvoyées par l'API", () => {
		expect(startOf({ dateStartDiffusion: '2026-09-18T10:00:00Z' })?.toISOString()).toBe('2026-09-18T10:00:00.000Z')
		expect(startOf({ startTime: '2026-09-18T10:00:00Z' })).not.toBeNull()
		expect(endOf({ dateEndDiffusion: '2026-09-18T11:00:00Z' })).not.toBeNull()
	})

	it('une date absente ou illisible donne null, pas une date invalide', () => {
		expect(startOf({})).toBeNull()
		expect(startOf({ dateStartDiffusion: 'pas-une-date' })).toBeNull()
		expect(startOf(null)).toBeNull()
	})
})

describe('état d\'une réunion', () => {
	const m = meeting('2026-09-18T10:00:00Z', '2026-09-18T11:00:00Z')

	it.each([
		['avant le début', '2026-09-18T09:59:00Z', 'upcoming'],
		['au début exact', '2026-09-18T10:00:00Z', 'live'],
		['pendant', '2026-09-18T10:30:00Z', 'live'],
		['à la fin exacte', '2026-09-18T11:00:00Z', 'live'],
		['après la fin', '2026-09-18T11:01:00Z', 'past'],
	])('%s -> %s', (_, now, expected) => {
		expect(meetingStatus(m, at(now))).toBe(expected)
	})

	it('sans date de fin, une réunion commencée reste en cours', () => {
		expect(meetingStatus(meeting('2026-09-18T10:00:00Z', null), at('2026-09-19T10:00:00Z'))).toBe('live')
	})

	it('sans date de début, l\'état est inconnu', () => {
		expect(meetingStatus({}, at('2026-09-18T10:00:00Z'))).toBe('unknown')
	})
})

describe('tri de la liste', () => {
	const now = at('2026-09-18T10:30:00Z')
	const live = meeting('2026-09-18T10:00:00Z', '2026-09-18T11:00:00Z', { id: 'live' })
	const soon = meeting('2026-09-18T14:00:00Z', '2026-09-18T15:00:00Z', { id: 'soon' })
	const later = meeting('2026-09-20T09:00:00Z', '2026-09-20T10:00:00Z', { id: 'later' })
	const recent = meeting('2026-09-17T09:00:00Z', '2026-09-17T10:00:00Z', { id: 'recent' })
	const old = meeting('2026-09-01T09:00:00Z', '2026-09-01T10:00:00Z', { id: 'old' })
	const undated = { id: 'undated' }

	it('en cours, puis à venir au plus proche, puis terminées au plus récent', () => {
		const ids = sortMeetings([old, later, undated, recent, live, soon], now).map((x) => x.id)
		expect(ids).toEqual(['live', 'soon', 'later', 'recent', 'old', 'undated'])
	})

	it("ne modifie pas le tableau d'origine", () => {
		const input = [old, live]
		sortMeetings(input, now)
		expect(input.map((x) => x.id)).toEqual(['old', 'live'])
	})
})

describe('meetingWhen', () => {
	// Traduction factice : renvoie le texte source, variables substituées.
	const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k])
	const local = (y, mo, d, h, mi) => new Date(y, mo - 1, d, h, mi).toISOString()
	const now = new Date(2026, 8, 30, 9, 0).getTime()

	it('dit « Today » pour une réunion du jour, avec la plage horaire', () => {
		const m = meeting(local(2026, 9, 30, 10, 35), local(2026, 9, 30, 11, 35))
		expect(meetingWhen(m, now, t, 'fr-FR')).toBe('Today, 10:35 – 11:35')
	})

	it('dit « Tomorrow » et « Yesterday » pour les jours voisins', () => {
		expect(meetingWhen(meeting(local(2026, 10, 1, 9, 0), null), now, t, 'fr-FR')).toBe('Tomorrow, 09:00')
		expect(meetingWhen(meeting(local(2026, 9, 29, 9, 0), null), now, t, 'fr-FR')).toMatch(/^Yesterday, /)
	})

	it('affiche la date au-delà', () => {
		const m = meeting(local(2026, 9, 18, 10, 35), local(2026, 9, 18, 11, 35))
		expect(meetingWhen(m, now, t, 'fr-FR')).toBe('18 sept. 2026, 10:35 – 11:35')
	})

	it('lit aussi les clés start / end de la carte de réunion', () => {
		const card = { start: local(2026, 9, 30, 10, 35), end: local(2026, 9, 30, 11, 35) }
		expect(meetingWhen(card, now, t, 'fr-FR')).toBe('Today, 10:35 – 11:35')
		expect(meetingStatus(card, now)).toBe('upcoming')
	})

	it('renvoie une chaîne vide sans date de début', () => {
		expect(meetingWhen({}, now, t, 'fr-FR')).toBe('')
	})
})

describe('participantLink', () => {
	it('prend le lien participant, sous ses deux formes', () => {
		expect(participantLink({ participant_url: 'P', admin_url: 'A' })).toBe('P')
		expect(participantLink({ participantUrl: 'P', organiserUrl: 'A', url: 'A' })).toBe('P')
	})

	it('ne retombe jamais sur le lien organisateur', () => {
		expect(participantLink({ admin_url: 'A', url: 'A', organiserUrl: 'A' })).toBeNull()
		expect(participantLink(undefined)).toBeNull()
	})
})

describe('réunion sans heure de fin', () => {
	const start = '2026-10-01T13:41:00+01:00'

	it('une fin égale au début vaut une heure par défaut', () => {
		const m = meeting(start, start)
		expect(endOf(m).getTime() - startOf(m).getTime()).toBe(3600000)
	})

	it("reste « en cours » pendant l'heure, puis « terminée »", () => {
		const m = meeting(start, start)
		expect(meetingStatus(m, at(start) + 60000)).toBe('live')
		expect(meetingStatus(m, at(start) + 3600000 + 60000)).toBe('past')
	})

	it('ne touche pas une vraie heure de fin', () => {
		const m = meeting(start, '2026-10-01T14:00:00+01:00')
		expect(endOf(m).toISOString()).toBe(new Date('2026-10-01T14:00:00+01:00').toISOString())
	})

	it('defaultEnd ajoute une heure', () => {
		expect(defaultEnd(new Date(start)).getTime()).toBe(at(start) + 3600000)
	})
})
