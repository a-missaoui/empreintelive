import { getCurrentUser } from '@nextcloud/auth'
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Envoi d'un enregistrement dans Nextcloud Files, en WebDAV, avec la session de
 * l'utilisateur : Nextcloud applique ses quotas et ses permissions.
 *
 * Une heure de réunion pèse plusieurs Go : au-delà d'un morceau, on utilise
 * l'envoi par morceaux de Nextcloud (chunking v2), qui contourne les limites
 * d'upload de PHP et du proxy.
 */
import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'

export const CHUNK_SIZE = 10 * 1024 * 1024

/** Tentatives de nom « name (2).webm », « name (3).webm »… avant d'abandonner. */
const MAX_NAME_ATTEMPTS = 20

const encodePath = (path) => path.split('/').filter(Boolean).map(encodeURIComponent).join('/')

/**
 * Nom candidat pour la tentative n (1 = nom d'origine).
 *
 * @param {string} name nom d'origine
 * @param {number} attempt numéro de tentative
 * @return {string}
 */
export function candidateName(name, attempt) {
	if (attempt <= 1) {
		return name
	}
	const dot = name.lastIndexOf('.')
	return dot > 0
		? `${name.slice(0, dot)} (${attempt})${name.slice(dot)}`
		: `${name} (${attempt})`
}

const isConflict = (error) => error?.response?.status === 412

/**
 * @param {object} options paramètres de l'envoi
 * @param {Blob} options.blob contenu
 * @param {string} options.folder dossier cible, relatif à l'espace de l'utilisateur
 * @param {string} options.name nom souhaité ; suffixé si le fichier existe déjà
 * @param {(ratio: number) => void} [options.onProgress] progression de 0 à 1
 * @return {Promise<string>} chemin du fichier créé, relatif à l'espace de l'utilisateur
 */
export async function uploadRecording({ blob, folder, name, onProgress = () => {} }) {
	const uid = getCurrentUser()?.uid
	if (!uid) {
		throw new Error('Not logged in')
	}

	const dav = generateRemoteUrl('dav')
	const destinationFor = (fileName) => `${dav}/files/${encodeURIComponent(uid)}/${encodePath(folder + '/' + fileName)}`

	if (blob.size <= CHUNK_SIZE) {
		for (let attempt = 1; attempt <= MAX_NAME_ATTEMPTS; attempt++) {
			const fileName = candidateName(name, attempt)
			try {
				// If-None-Match: * -> 412 si le fichier existe : on n'écrase jamais.
				await axios.put(destinationFor(fileName), blob, {
					headers: { 'Content-Type': blob.type || 'application/octet-stream', 'If-None-Match': '*' },
				})
				onProgress(1)
				return `${folder.replace(/\/$/, '')}/${fileName}`
			} catch (error) {
				if (!isConflict(error)) {
					throw error
				}
			}
		}
		throw new Error('No free file name')
	}

	const uploadDir = `${dav}/uploads/${encodeURIComponent(uid)}/empreintelive-${Date.now()}-${Math.random().toString(36).slice(2)}`
	const first = destinationFor(name)
	const total = String(blob.size)

	await axios.request({ method: 'MKCOL', url: uploadDir, headers: { Destination: first } })

	try {
		const count = Math.ceil(blob.size / CHUNK_SIZE)
		for (let index = 0; index < count; index++) {
			const chunk = blob.slice(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE)
			// Numérotation à partir de 1, imposée par le chunking v2.
			await axios.put(`${uploadDir}/${index + 1}`, chunk, {
				headers: { Destination: first, 'OC-Total-Length': total, 'Content-Type': 'application/octet-stream' },
			})
			onProgress((index + 1) / count)
		}

		for (let attempt = 1; attempt <= MAX_NAME_ATTEMPTS; attempt++) {
			const fileName = candidateName(name, attempt)
			try {
				await axios.request({
					method: 'MOVE',
					url: `${uploadDir}/.file`,
					headers: { Destination: destinationFor(fileName), 'OC-Total-Length': total, Overwrite: 'F' },
				})
				return `${folder.replace(/\/$/, '')}/${fileName}`
			} catch (error) {
				if (!isConflict(error)) {
					throw error
				}
			}
		}
		throw new Error('No free file name')
	} catch (error) {
		// Nettoyage des morceaux ; un échec ici ne doit pas masquer l'erreur d'origine.
		await axios.request({ method: 'DELETE', url: uploadDir }).catch(() => {})
		throw error
	}
}

/**
 * Dernier recours si l'envoi échoue : le fichier est proposé au téléchargement,
 * pour que l'enregistrement ne soit jamais perdu.
 *
 * @param {Blob} blob contenu
 * @param {string} name nom du fichier
 */
export function downloadBlob(blob, name) {
	const href = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = href
	link.download = name
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(href)
}
