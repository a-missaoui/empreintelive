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

	// --- Réunion créée depuis un document (app Files) ---
	async meetingSuggestion(fileId) {
		const { data } = await axios.get(url('/files/' + encodeURIComponent(fileId) + '/meeting/suggest'))
		return data // { name, title, attendees, convertible }
	},
	async createMeetingForFile(fileId, payload) {
		const { data } = await axios.post(url('/files/' + encodeURIComponent(fileId) + '/meeting'), payload)
		return data
	},

	// --- Documents : dossier Nextcloud lié à une réunion ---
	async folder(liveId, path = '') {
		const { data } = await axios.get(url('/lives/' + encodeURIComponent(liveId) + '/folder'), {
			params: { path },
		})
		return data
	},
	async suggestFolder(liveId, title = '') {
		const { data } = await axios.get(
			url('/lives/' + encodeURIComponent(liveId) + '/folder/suggest'),
			{ params: { title } },
		)
		return data // { path }
	},
	async linkFolder(liveId, path) {
		const { data } = await axios.post(url('/lives/' + encodeURIComponent(liveId) + '/folder'), { path })
		return data
	},
	async unlinkFolder(liveId) {
		const { data } = await axios.delete(url('/lives/' + encodeURIComponent(liveId) + '/folder'))
		return data
	},

	// --- Médias de la réunion : documents présentés ---
	async recordingFolder(liveId, title = '') {
		// { path, linked } : dossier ou déposer un enregistrement de la réunion.
		const { data } = await axios.post(url('/lives/' + encodeURIComponent(liveId) + '/recording-folder'), { title })
		return data
	},
	async medias(liveId) {
		const { data } = await axios.get(url('/lives/' + encodeURIComponent(liveId) + '/medias'))
		return data // { available, documents: [{ name, index, slides }] }
	},
	async deleteMedia(liveId, docIndex) {
		const { data } = await axios.delete(url('/lives/' + encodeURIComponent(liveId) + '/medias/' + encodeURIComponent(docIndex)))
		return data
	},
	async sendMedia(liveId, fileId) {
		const { data } = await axios.post(url('/lives/' + encodeURIComponent(liveId) + '/medias'), { fileId })
		return data
	},

	// --- Partages : liens Nextcloud ---
	async shares(fileId) {
		const { data } = await axios.get(url('/files/' + encodeURIComponent(fileId) + '/shares'))
		return Array.isArray(data?.shares) ? data.shares : []
	},
	async createShare(fileId, { editable = false, password = null, expiration = null } = {}) {
		const { data } = await axios.post(url('/files/' + encodeURIComponent(fileId) + '/shares'), {
			editable,
			password,
			expiration,
		})
		return data
	},
	async deleteShare(shareId) {
		const { data } = await axios.delete(url('/shares/' + encodeURIComponent(shareId)))
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
