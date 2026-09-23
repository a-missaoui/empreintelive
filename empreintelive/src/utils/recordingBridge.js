/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Réception d'un enregistrement envoyé par la réunion embarquée.
 *
 * L'enregistrement est produit dans le navigateur par le studio EMPREINTE. Le
 * studio le passe à notre page par postMessage, sans réseau : aucun envoi
 * cross-origin vers Nextcloud, que le navigateur bloquerait (Nextcloud n'autorise
 * pas CORS sur WebDAV).
 *
 * Échange :
 *   studio → page  { type: 'hello' }
 *   page → studio  { type: 'sink', name }
 *   studio → page  { type: 'recording', id, blob, mimeType }
 *   page → studio  { type: 'received', id }   la page devient responsable du fichier
 */

export const PROTOCOL = 'empreinte-live-recording'
export const VERSION = 1

/** Types acceptés, avec l'extension du fichier créé dans Nextcloud. */
const EXTENSIONS = {
	'video/webm': 'webm',
	'video/mp4': 'mp4',
}

/**
 * Extension correspondant au type MIME (paramètres codecs ignorés), ou null.
 *
 * @param {string} mimeType ex. « video/webm;codecs=vp9,opus »
 * @return {string|null}
 */
export function recordingExtension(mimeType) {
	const base = String(mimeType || '').split(';')[0].trim().toLowerCase()
	return EXTENSIONS[base] ?? null
}

/**
 * @param {object} options paramètres du pont
 * @param {() => Window|null} options.getFrameWindow fenêtre de l'iframe de réunion
 * @param {string[]} options.allowedOrigins origines EMPREINTE autorisées
 * @param {string} options.hostName nom affiché par le studio (« Nextcloud »)
 * @param {(recording: {blob: Blob, mimeType: string, extension: string}) => void} options.onRecording appelé avec l'enregistrement reçu
 * @return {(event: MessageEvent) => void} gestionnaire à brancher sur « message »
 */
export function createRecordingBridge({ getFrameWindow, allowedOrigins, hostName, onRecording }) {
	const reply = (event, message) => {
		event.source.postMessage({ protocol: PROTOCOL, version: VERSION, ...message }, event.origin)
	}

	return (event) => {
		const frame = getFrameWindow()
		// Deux vérifications : le message vient de NOTRE iframe, et d'un domaine EMPREINTE.
		if (!frame || event.source !== frame || !allowedOrigins.includes(event.origin)) {
			return
		}

		const data = event.data
		if (!data || typeof data !== 'object' || data.protocol !== PROTOCOL || data.version !== VERSION) {
			return
		}

		if (data.type === 'hello') {
			reply(event, { type: 'sink', name: hostName })
			return
		}

		if (data.type === 'recording') {
			const extension = recordingExtension(data.mimeType)
			if (!(data.blob instanceof Blob) || data.blob.size === 0 || extension === null) {
				// Pas d'accusé de réception : le studio retombe sur le téléchargement.
				return
			}

			reply(event, { type: 'received', id: data.id })
			onRecording({ blob: data.blob, mimeType: data.mimeType, extension })
		}
	}
}

/**
 * Nom du fichier, horodaté en heure locale : « Recording 2026-09-18 14-05.webm ».
 * Pas de « : », interdit dans les noms de fichiers sous Windows.
 *
 * @param {string} prefix libellé traduit
 * @param {string} extension extension sans point
 * @param {Date} date instant de l'enregistrement
 * @return {string}
 */
export function recordingFileName(prefix, extension, date) {
	const pad = (n) => String(n).padStart(2, '0')
	const stamp = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}-${pad(date.getMinutes())}`
	return `${prefix} ${stamp}.${extension}`
}
