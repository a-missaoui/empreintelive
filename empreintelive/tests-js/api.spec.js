import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tests unitaires du wrapper API front (src/services/api.js).
 * axios (@nextcloud/axios) et generateUrl (@nextcloud/router) sont mockes :
 * on verifie l'URL appelee, le payload transmis et la normalisation des reponses.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import api from '../src/services/api.js'

// generateUrl renvoie son argument tel quel -> on peut asserter l'URL construite.
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const base = '/apps/empreintelive'

beforeEach(() => {
	vi.clearAllMocks()
})

describe('api — OAuth / connexion', () => {
	it('status() appelle le bon endpoint et renvoie data', async () => {
		axios.get.mockResolvedValue({ data: { connected: true } })
		const res = await api.status()
		expect(axios.get).toHaveBeenCalledWith(base + '/oauth/status')
		expect(res).toEqual({ connected: true })
	})

	it('login() poste email/password', async () => {
		axios.post.mockResolvedValue({ data: { ok: 1 } })
		const res = await api.login({ email: 'e@x', password: 'pw' })
		expect(axios.post).toHaveBeenCalledWith(base + '/oauth/login', { email: 'e@x', password: 'pw' })
		expect(res).toEqual({ ok: 1 })
	})

	it('register() poste email/password/name', async () => {
		axios.post.mockResolvedValue({ data: {} })
		await api.register({ email: 'e@x', password: 'pw', name: 'N' })
		expect(axios.post).toHaveBeenCalledWith(base + '/oauth/register', { email: 'e@x', password: 'pw', name: 'N' })
	})

	it('connect() poste email', async () => {
		axios.post.mockResolvedValue({ data: {} })
		await api.connect({ email: 'e@x' })
		expect(axios.post).toHaveBeenCalledWith(base + '/oauth/connect', { email: 'e@x' })
	})

	it('authorize() poste email et renvoie les scopes', async () => {
		axios.post.mockResolvedValue({ data: { success: true, scopes: ['live:read', 'live:write'] } })
		const res = await api.authorize({ email: 'e@x' })
		expect(axios.post).toHaveBeenCalledWith(base + '/oauth/authorize', { email: 'e@x' })
		expect(res.scopes).toEqual(['live:read', 'live:write'])
	})

	it('approve() poste sans corps', async () => {
		axios.post.mockResolvedValue({ data: { connected: true } })
		await api.approve()
		expect(axios.post).toHaveBeenCalledWith(base + '/oauth/approve')
	})

	it('logout() poste sans corps', async () => {
		axios.post.mockResolvedValue({ data: {} })
		await api.logout()
		expect(axios.post).toHaveBeenCalledWith(base + '/oauth/logout')
	})
})

describe('api — Lives', () => {
	it('createLive() renvoie la réponse complète (live + eventCreated)', async () => {
		axios.post.mockResolvedValue({ data: { data: { id: 1 }, eventCreated: true } })
		const res = await api.createLive({ title: 'T' })
		expect(axios.post).toHaveBeenCalledWith(base + '/lives', { title: 'T' })
		expect(res).toEqual({ data: { id: 1 }, eventCreated: true })
	})

	it('createLive() expose eventCreated=false quand l\'événement n\'a pas été créé', async () => {
		axios.post.mockResolvedValue({ data: { data: { id: 2 }, eventCreated: false } })
		const res = await api.createLive({ title: 'T' })
		expect(res.eventCreated).toBe(false)
	})

	it('updateLive() encode l\'identifiant dans l\'URL', async () => {
		axios.put.mockResolvedValue({ data: { data: { id: 'a b' } } })
		await api.updateLive('a b', { title: 'T' })
		expect(axios.put).toHaveBeenCalledWith(base + '/lives/a%20b', { title: 'T' })
	})

	it('deleteLive() encode l\'identifiant et renvoie data', async () => {
		axios.delete.mockResolvedValue({ data: { ok: true } })
		const res = await api.deleteLive('x/y')
		expect(axios.delete).toHaveBeenCalledWith(base + '/lives/x%2Fy')
		expect(res).toEqual({ ok: true })
	})
})

describe('api — searchAttendees', () => {
	it('appelle /attendees/search avec le paramètre search et renvoie data', async () => {
		axios.get.mockResolvedValue({ data: { data: [{ name: 'Yann', email: 'yann@x' }] } })
		const res = await api.searchAttendees('yan')
		expect(axios.get).toHaveBeenCalledWith(base + '/attendees/search', { params: { search: 'yan' } })
		expect(res).toEqual([{ name: 'Yann', email: 'yann@x' }])
	})

	it('renvoie [] quand la réponse n\'est pas un tableau', async () => {
		axios.get.mockResolvedValue({ data: { data: null } })
		expect(await api.searchAttendees('x')).toEqual([])
	})
})

describe('api — normalizeList (via listMeetings)', () => {
	const cases = [
		['tableau direct', [1, 2], [1, 2]],
		['enveloppe { data: [...] }', { data: ['a'] }, ['a']],
		['double enveloppe { data: { data: [...] } }', { data: { data: ['x'] } }, ['x']],
		['{ items: [...] }', { items: ['i'] }, ['i']],
		['{ meetings: [...] }', { meetings: ['m'] }, ['m']],
		['objet non reconnu -> []', { foo: 1 }, []],
		['null -> []', null, []],
	]

	it.each(cases)('%s', async (_label, payload, expected) => {
		axios.get.mockResolvedValue({ data: payload })
		const res = await api.listMeetings()
		expect(axios.get).toHaveBeenCalledWith(base + '/lives')
		expect(res).toEqual(expected)
	})
})

describe('api — dossier lié à une réunion', () => {
	it('folder() transmet le sous-chemin', async () => {
		axios.get.mockResolvedValue({ data: { linked: true } })
		await api.folder('646', 'pieces')
		expect(axios.get).toHaveBeenCalledWith(base + '/lives/646/folder', { params: { path: 'pieces' } })
	})

	it('suggestFolder() transmet le titre', async () => {
		axios.get.mockResolvedValue({ data: { path: '/EMPREINTE Live/Revue' } })
		const res = await api.suggestFolder('646', 'Revue')
		expect(axios.get).toHaveBeenCalledWith(base + '/lives/646/folder/suggest', { params: { title: 'Revue' } })
		expect(res).toEqual({ path: '/EMPREINTE Live/Revue' })
	})

	it('linkFolder() poste le chemin, unlinkFolder() supprime', async () => {
		axios.post.mockResolvedValue({ data: {} })
		axios.delete.mockResolvedValue({ data: {} })
		await api.linkFolder('646', '/Projet')
		await api.unlinkFolder('646')
		expect(axios.post).toHaveBeenCalledWith(base + '/lives/646/folder', { path: '/Projet' })
		expect(axios.delete).toHaveBeenCalledWith(base + '/lives/646/folder')
	})

	it("encode l'identifiant de réunion", async () => {
		axios.get.mockResolvedValue({ data: {} })
		await api.folder('a/b', '')
		expect(axios.get.mock.calls[0][0]).toBe(base + '/lives/a%2Fb/folder')
	})
})

describe('api — partages', () => {
	it('shares() renvoie un tableau, même sur réponse inattendue', async () => {
		axios.get.mockResolvedValue({ data: {} })
		expect(await api.shares(7)).toEqual([])
		axios.get.mockResolvedValue({ data: { shares: [{ id: 'ocinternal:1' }] } })
		expect(await api.shares(7)).toEqual([{ id: 'ocinternal:1' }])
	})

	it('createShare() envoie lecture seule par défaut', async () => {
		axios.post.mockResolvedValue({ data: { url: 'u' } })
		await api.createShare(7)
		expect(axios.post).toHaveBeenCalledWith(base + '/files/7/shares', {
			editable: false,
			password: null,
			expiration: null,
		})
	})

	it('deleteShare() encode l\'identifiant complet', async () => {
		axios.delete.mockResolvedValue({ data: {} })
		await api.deleteShare('ocinternal:42')
		expect(axios.delete).toHaveBeenCalledWith(base + '/shares/ocinternal%3A42')
	})
})

describe('api — médias de la réunion', () => {
	it('medias() lit la liste', async () => {
		axios.get.mockResolvedValue({ data: { available: true, documents: [] } })
		const res = await api.medias('646')
		expect(axios.get).toHaveBeenCalledWith(base + '/lives/646/medias')
		expect(res.available).toBe(true)
	})

	it('sendMedia() poste le fichier', async () => {
		axios.post.mockResolvedValue({ data: {} })
		await api.sendMedia('646', 580)
		expect(axios.post).toHaveBeenCalledWith(base + '/lives/646/medias', { fileId: 580 })
	})

	it('deleteMedia() encode un index avec caractères spéciaux', async () => {
		axios.delete.mockResolvedValue({ data: {} })
		await api.deleteMedia('646', '646_guide_—_v4.11_1789')
		expect(axios.delete.mock.calls[0][0]).toBe(base + '/lives/646/medias/' + encodeURIComponent('646_guide_—_v4.11_1789'))
	})
})

describe('api — réunion créée depuis un document', () => {
	it('meetingSuggestion() lit la proposition', async () => {
		axios.get.mockResolvedValue({ data: { title: 'Rapport', attendees: [] } })
		const res = await api.meetingSuggestion(580)
		expect(axios.get).toHaveBeenCalledWith(base + '/files/580/meeting/suggest')
		expect(res.title).toBe('Rapport')
	})

	it('createMeetingForFile() poste la charge utile telle quelle', async () => {
		axios.post.mockResolvedValue({ data: { liveId: '657' } })
		const payload = { title: 'Rapport', attendees: ['bob@example.test'] }
		const res = await api.createMeetingForFile(580, payload)
		expect(axios.post).toHaveBeenCalledWith(base + '/files/580/meeting', payload)
		expect(res.liveId).toBe('657')
	})
})
