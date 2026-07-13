/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tests unitaires du wrapper API front (src/services/api.js).
 * axios (@nextcloud/axios) et generateUrl (@nextcloud/router) sont mockes :
 * on verifie l'URL appelee, le payload transmis et la normalisation des reponses.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import axios from '@nextcloud/axios'
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
