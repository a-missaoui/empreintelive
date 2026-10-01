/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Logique pure autour des réponses de l'API de Talk, sans réseau ni Vue.
 */

/**
 * Membres invitables d'une conversation, tels que Talk les renvoie à
 * l'utilisateur, avec ses droits :
 *  - comptes Nextcloud (« users ») : le serveur de l'app résout leur adresse ;
 *  - invités par e-mail (« emails ») : Talk ne donne l'adresse qu'aux
 *    modérateurs, un simple membre ne les verra donc pas ;
 *  - invités anonymes, comptes fédérés, bots, groupes : pas d'adresse, ignorés.
 *
 * @param {object[]} participants - Réponse de …/room/{token}/participants.
 * @param {string} selfId - Identifiant Nextcloud de l'utilisateur courant.
 * @return {{ key: string, name: string, userId?: string, email?: string }[]}
 */
export function invitableMembers(participants, selfId) {
	const out = []
	for (const p of participants ?? []) {
		if (p?.actorType === 'users' && p.actorId && p.actorId !== selfId) {
			out.push({ key: 'users/' + p.actorId, name: p.displayName || p.actorId, userId: p.actorId })
		} else if (p?.actorType === 'emails' && typeof p.invitedActorId === 'string' && p.invitedActorId.includes('@')) {
			out.push({ key: 'emails/' + p.invitedActorId, name: p.displayName || p.invitedActorId, email: p.invitedActorId })
		}
	}
	return out.sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * Conversations où l'utilisateur peut écrire : ni en lecture seule, ni sans le
 * droit de publier (bit « chat » des permissions de Talk), ni le fil des
 * nouveautés de Talk.
 *
 * @param {object[]} rooms - Réponse de …/api/v4/room.
 * @return {object[]} Les conversations, la plus récemment active en tête.
 */
export function writableRooms(rooms) {
	const CHAT = 128
	const CHANGELOG = 4
	return (rooms ?? [])
		.filter((r) => r && r.readOnly !== 1 && r.type !== CHANGELOG
			&& (typeof r.permissions !== 'number' || (r.permissions & CHAT) !== 0))
		.sort((a, b) => (b.lastActivity ?? 0) - (a.lastActivity ?? 0))
}
