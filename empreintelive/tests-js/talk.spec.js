/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lecture des réponses de l'API de Talk (src/utils/talk.js).
 */
import { describe, expect, it } from 'vitest'
import { invitableMembers, writableRooms } from '../src/utils/talk.js'

describe('invitableMembers', () => {
	const participants = [
		{ actorType: 'users', actorId: 'alice', displayName: 'Alice' },
		{ actorType: 'users', actorId: 'bob', displayName: 'Bob' },
		{ actorType: 'emails', actorId: 'h4sh', displayName: 'Invitée', invitedActorId: 'guest@example.org' },
		{ actorType: 'emails', actorId: 'h4sh2', displayName: 'Masquée' },
		{ actorType: 'guests', actorId: 'g1', displayName: 'Anonyme' },
		{ actorType: 'federated_users', actorId: 'carol@other.example', displayName: 'Carol' },
		{ actorType: 'bots', actorId: 'bot-1', displayName: 'EMPREINTE Live' },
		{ actorType: 'groups', actorId: 'admin', displayName: 'admin' },
	]

	it("garde les comptes Nextcloud et les invités dont Talk donne l'adresse", () => {
		expect(invitableMembers(participants, 'alice')).toEqual([
			{ key: 'users/bob', name: 'Bob', userId: 'bob' },
			{ key: 'emails/guest@example.org', name: 'Invitée', email: 'guest@example.org' },
		])
	})

	it("n'invite pas l'utilisateur lui-même", () => {
		expect(invitableMembers(participants, 'bob').map((m) => m.key)).not.toContain('users/bob')
	})

	it('tolère une réponse vide', () => {
		expect(invitableMembers(undefined, 'alice')).toEqual([])
	})
})

describe('writableRooms', () => {
	it('écarte la lecture seule, le fil des nouveautés et les salons sans droit de publier', () => {
		const rooms = [
			{ token: 'a', type: 2, readOnly: 0, permissions: 254, lastActivity: 10 },
			{ token: 'b', type: 2, readOnly: 1, permissions: 254, lastActivity: 50 },
			{ token: 'c', type: 4, readOnly: 0, permissions: 254, lastActivity: 60 },
			{ token: 'd', type: 3, readOnly: 0, permissions: 1, lastActivity: 70 },
			{ token: 'e', type: 1, readOnly: 0, permissions: 254, lastActivity: 20 },
		]
		expect(writableRooms(rooms).map((r) => r.token)).toEqual(['e', 'a'])
	})
})
