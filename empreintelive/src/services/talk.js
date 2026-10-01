/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * API publique de Talk (OCS), appelée depuis le navigateur au nom de
 * l'utilisateur : Talk applique lui-même ses droits (membres visibles, salon en
 * lecture seule, création de conversations restreinte…). L'app ne contourne
 * rien et ne dépend d'aucune classe de Talk.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const ocs = (path, params) => generateOcsUrl('apps/spreed/api/' + path, params)

export default {
	async participants(token) {
		const { data } = await axios.get(ocs('v4/room/{token}/participants', { token }))
		return data.ocs.data
	},

	async rooms() {
		const { data } = await axios.get(ocs('v4/room'))
		return data.ocs.data
	},

	/**
	 * @param {string} name - Nom de la conversation.
	 * @return {Promise<object>} La conversation créée (token, name…).
	 */
	async createRoom(name) {
		// 2 : conversation de groupe.
		const { data } = await axios.post(ocs('v4/room'), { roomType: 2, roomName: name })
		return data.ocs.data
	},

	async postMessage(token, message) {
		await axios.post(ocs('v1/chat/{token}', { token }), { message })
	},
}
