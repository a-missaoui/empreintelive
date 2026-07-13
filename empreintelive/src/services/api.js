/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Wrapper axios vers les endpoints serveur de l'app.
 * Le front ne manipule JAMAIS de token : tout est géré côté serveur.
 * axios (@nextcloud/axios) ajoute automatiquement le requesttoken Nextcloud.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/empreintelive' + path)

export default {
	// --- OAuth / connexion ---
	async status() {
		const { data } = await axios.get(url('/oauth/status'))
		return data // { connected: bool }
	},
	async register({ email, password, name }) {
		const { data } = await axios.post(url('/oauth/register'), { email, password, name })
		return data
	},
	async login({ email, password }) {
		const { data } = await axios.post(url('/oauth/login'), { email, password })
		return data
	},
	async authorize({ email }) {
		// Étape 1/2 : demande de consentement. Renvoie { success, scopes }.
		const { data } = await axios.post(url('/oauth/authorize'), { email })
		return data
	},
	async approve() {
		// Étape 2/2 : l'utilisateur a autorisé l'accès -> tokens.
		const { data } = await axios.post(url('/oauth/approve'))
		return data
	},
	async connect({ email }) {
		const { data } = await axios.post(url('/oauth/connect'), { email })
		return data
	},
	async logout() {
		const { data } = await axios.post(url('/oauth/logout'))
		return data
	},

	// --- Lives / réunions ---
	async listMeetings() {
		const { data } = await axios.get(url('/lives'))
		return normalizeList(data)
	},
	async createLive(payload) {
		// Réponse serveur : { data: <live>, eventCreated: <bool> }.
		const { data } = await axios.post(url('/lives'), payload)
		return data
	},
	async updateLive(id, payload) {
		const { data } = await axios.put(url('/lives/' + encodeURIComponent(id)), payload)
		return data.data ?? data
	},
	async deleteLive(id) {
		const { data } = await axios.delete(url('/lives/' + encodeURIComponent(id)))
		return data
	},

	// --- Participants (autocomplétion utilisateurs / contacts Nextcloud) ---
	async searchAttendees(search) {
		const { data } = await axios.get(url('/attendees/search'), { params: { search } })
		return Array.isArray(data?.data) ? data.data : [] // [{ name, email }]
	},
}

/**
 * La liste renvoyée par l'API EMPREINTE peut être imbriquée de plusieurs façons.
 * On aplatit défensivement vers un tableau.
 *
 * @param {object} data - Réponse brute renvoyée par l'API EMPREINTE.
 */
function normalizeList(data) {
	const body = data?.data ?? data
	if (Array.isArray(body)) {
		return body
	}
	if (Array.isArray(body?.data)) {
		return body.data
	}
	if (Array.isArray(body?.items)) {
		return body.items
	}
	if (Array.isArray(body?.meetings)) {
		return body.meetings
	}
	return []
}
