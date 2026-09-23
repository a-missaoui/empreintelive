/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Réception d'un enregistrement envoyé par le studio, et envoi dans Files.
 */
import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { candidateName, CHUNK_SIZE, uploadRecording } from '../src/services/recordingUpload.js'
import { createRecordingBridge, PROTOCOL, recordingExtension, recordingFileName, VERSION } from '../src/utils/recordingBridge.js'

vi.mock('@nextcloud/router', () => ({ generateRemoteUrl: () => '/dav' }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'bob' }) }))
vi.mock('@nextcloud/axios', () => ({
	default: { put: vi.fn(), request: vi.fn() },
}))

const ORIGIN = 'https://join.empreinte.live'

function setup() {
	const frame = { postMessage: vi.fn() }
	const onRecording = vi.fn()
	const handle = createRecordingBridge({
		getFrameWindow: () => frame,
		allowedOrigins: [ORIGIN],
		hostName: 'Nextcloud',
		onRecording,
	})
	const send = (data, { origin = ORIGIN, source = frame } = {}) => handle({ data: { protocol: PROTOCOL, version: VERSION, ...data }, origin, source })
	return { frame, onRecording, send }
}

describe('recordingBridge', () => {
	it('répond « sink » au studio qui se présente', () => {
		const { frame, send } = setup()
		send({ type: 'hello' })
		expect(frame.postMessage).toHaveBeenCalledWith({ protocol: PROTOCOL, version: VERSION, type: 'sink', name: 'Nextcloud' }, ORIGIN)
	})

	it('ignore une origine non autorisée', () => {
		const { frame, send } = setup()
		send({ type: 'hello' }, { origin: 'https://evil.test' })
		expect(frame.postMessage).not.toHaveBeenCalled()
	})

	it('ignore un message qui ne vient pas de notre iframe', () => {
		const { frame, send } = setup()
		send({ type: 'hello' }, { source: {} })
		expect(frame.postMessage).not.toHaveBeenCalled()
	})

	it('accuse réception puis transmet l\'enregistrement', () => {
		const { frame, onRecording, send } = setup()
		const blob = new Blob(['x'], { type: 'video/webm' })
		send({ type: 'recording', id: 'r1', blob, mimeType: 'video/webm;codecs=vp9,opus' })
		expect(frame.postMessage).toHaveBeenCalledWith({ protocol: PROTOCOL, version: VERSION, type: 'received', id: 'r1' }, ORIGIN)
		expect(onRecording).toHaveBeenCalledWith({ blob, mimeType: 'video/webm;codecs=vp9,opus', extension: 'webm' })
	})

	it('refuse un type inconnu ou un fichier vide, sans accusé : le studio télécharge', () => {
		const { frame, onRecording, send } = setup()
		send({ type: 'recording', id: 'r2', blob: new Blob(['x']), mimeType: 'text/html' })
		send({ type: 'recording', id: 'r3', blob: new Blob([]), mimeType: 'video/webm' })
		expect(frame.postMessage).not.toHaveBeenCalled()
		expect(onRecording).not.toHaveBeenCalled()
	})

	it('extension et nom de fichier', () => {
		expect(recordingExtension('video/mp4')).toBe('mp4')
		expect(recordingExtension('VIDEO/WEBM; codecs=vp8')).toBe('webm')
		expect(recordingExtension('')).toBeNull()
		expect(recordingFileName('Recording', 'webm', new Date(2026, 8, 18, 9, 5))).toBe('Recording 2026-09-18 09-05.webm')
	})
})

describe('recordingUpload', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('suffixe le nom en cas de conflit', () => {
		expect(candidateName('a.webm', 1)).toBe('a.webm')
		expect(candidateName('a.webm', 3)).toBe('a (3).webm')
		expect(candidateName('a', 2)).toBe('a (2)')
	})

	it('petit fichier : un PUT sans écraser, nom suivant si le fichier existe', async () => {
		axios.put
			.mockRejectedValueOnce({ response: { status: 412 } })
			.mockResolvedValueOnce({})
		const path = await uploadRecording({ blob: new Blob(['x']), folder: '/EMPREINTE Live/Revue', name: 'R.webm' })
		expect(path).toBe('/EMPREINTE Live/Revue/R (2).webm')
		expect(axios.put.mock.calls[0][0]).toBe('/dav/files/bob/EMPREINTE%20Live/Revue/R.webm')
		expect(axios.put.mock.calls[0][2].headers['If-None-Match']).toBe('*')
		expect(axios.put.mock.calls[1][0]).toBe('/dav/files/bob/EMPREINTE%20Live/Revue/R%20(2).webm')
	})

	it('gros fichier : MKCOL, morceaux numérotés depuis 1, puis MOVE', async () => {
		axios.put.mockResolvedValue({})
		axios.request.mockResolvedValue({})
		const progress = vi.fn()
		const blob = new Blob([new Uint8Array(CHUNK_SIZE * 2 + 1)])
		const path = await uploadRecording({ blob, folder: '/Projet', name: 'R.webm', onProgress: progress })

		expect(path).toBe('/Projet/R.webm')
		const [mkcol, move] = axios.request.mock.calls.map(([c]) => c)
		expect(mkcol.method).toBe('MKCOL')
		expect(axios.put.mock.calls.map(([u]) => u.slice(-2))).toEqual(['/1', '/2', '/3'])
		expect(move).toMatchObject({ method: 'MOVE', headers: { Destination: '/dav/files/bob/Projet/R.webm', Overwrite: 'F', 'OC-Total-Length': String(blob.size) } })
		expect(move.url).toBe(mkcol.url + '/.file')
		expect(progress).toHaveBeenLastCalledWith(1)
	})

	it('gros fichier en échec : les morceaux sont supprimés et l\'erreur remonte', async () => {
		axios.request.mockResolvedValue({})
		axios.put.mockRejectedValue({ response: { status: 507 } })
		await expect(uploadRecording({ blob: new Blob([new Uint8Array(CHUNK_SIZE + 1)]), folder: '/P', name: 'R.webm' }))
			.rejects.toEqual({ response: { status: 507 } })
		expect(axios.request.mock.calls.at(-1)[0].method).toBe('DELETE')
	})
})
