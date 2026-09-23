<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Panneau documentaire de la réunion.
  -
  - Critère 4 : aucun contrôle de droits n'est fait ici. Le serveur lit via
  - getUserFolder(), donc Nextcloud applique ses propres permissions ; le panneau
  - se contente d'afficher ce qu'il reçoit (accessible, canWrite, canShare).
-->
<template>
	<div class="ec-docs">
		<div class="ec-docs__head">
			<h4 class="ec-docs__title">
				{{ t('empreintelive', 'Documents') }}
			</h4>
			<NcButton
				v-if="state.linked && state.accessible"
				variant="tertiary"
				:aria-label="t('empreintelive', 'Refresh')"
				:title="t('empreintelive', 'Refresh')"
				@click="reload">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Ce qui est deja dans la reunion, cote EMPREINTE -->
		<div v-if="!medias.available && medias.reason" class="ec-docs__live">
			<h5 class="ec-docs__live-title">
				{{ t('empreintelive', 'In the meeting') }}
			</h5>
			<p class="ec-docs__hint">
				{{ mediaUnavailableLabel }}
			</p>
		</div>

		<div v-if="medias.available" class="ec-docs__live">
			<h5 class="ec-docs__live-title">
				{{ t('empreintelive', 'In the meeting') }}
			</h5>
			<p v-if="!medias.documents.length" class="ec-docs__hint">
				{{ t('empreintelive', 'No document presented yet.') }}
			</p>
			<ul v-else class="ec-docs__live-items">
				<li v-for="doc in medias.documents" :key="doc.index" class="ec-docs__live-item">
					<Presentation :size="18" />
					<span class="ec-docs__name">{{ doc.name }}</span>
					<span class="ec-docs__meta">{{ n('empreintelive', '%n slide', '%n slides', doc.slides) }}</span>
					<NcButton
						variant="tertiary"
						:aria-label="t('empreintelive', 'Remove from the meeting')"
						:title="t('empreintelive', 'Remove from the meeting')"
						:disabled="removing === doc.index"
						@click="removeMedia(doc)">
						<template #icon>
							<NcLoadingIcon v-if="removing === doc.index" :size="18" />
							<Delete v-else :size="18" />
						</template>
					</NcButton>
				</li>
			</ul>
		</div>

		<NcLoadingIcon v-if="loading && !state.linked" :size="24" />

		<!-- Aucun dossier : proposition modifiable -->
		<div v-else-if="!state.linked" class="ec-docs__setup">
			<NcNoteCard v-if="state.reason === 'folder_missing'" type="warning">
				{{ t('empreintelive', 'The associated folder was deleted. Documents already sent to the meeting are not affected.') }}
			</NcNoteCard>
			<p class="ec-docs__hint">
				{{ t('empreintelive', 'Associate a Nextcloud folder with this meeting.') }}
			</p>
			<NcTextField
				v-model="path"
				:label="t('empreintelive', 'Folder')"
				:disabled="busy" />
			<div class="ec-docs__actions">
				<NcButton variant="primary" :disabled="busy || !path" @click="link">
					{{ t('empreintelive', 'Associate') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click="pick">
					{{ t('empreintelive', 'Browse…') }}
				</NcButton>
			</div>
		</div>

		<!-- Lié mais hors de portée de cet utilisateur -->
		<NcEmptyContent
			v-else-if="!state.accessible"
			:name="t('empreintelive', 'Folder not accessible')"
			:description="t('empreintelive', 'This folder belongs to another participant and is not shared with you.')">
			<template #icon>
				<LockIcon />
			</template>
		</NcEmptyContent>

		<!-- Contenu -->
		<template v-else>
			<div class="ec-docs__crumbs">
				<NcButton variant="tertiary" :disabled="!subPath" @click="go('')">
					{{ state.folder.name }}
				</NcButton>
				<span v-if="subPath" class="ec-docs__crumb-tail">/ {{ subPath }}</span>
			</div>

			<NcButton
				v-if="state.folder && state.folder.canShare"
				variant="secondary"
				class="ec-docs__folder-share"
				@click="shareItem = { id: state.folder.id, name: state.folder.name }">
				<template #icon>
					<ShareVariant :size="20" />
				</template>
				{{ t('empreintelive', 'Folder link') }}
			</NcButton>

			<NcButton v-if="subPath" variant="tertiary" @click="goUp">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('empreintelive', 'Parent folder') }}
			</NcButton>

			<NcEmptyContent
				v-if="!state.items.length"
				:name="t('empreintelive', 'Empty folder')"
				:description="t('empreintelive', 'Documents shared during the meeting will appear here.')">
				<template #icon>
					<FolderIcon />
				</template>
			</NcEmptyContent>

			<ul v-else class="ec-docs__items">
				<li v-for="item in state.items" :key="item.id" class="ec-docs__item">
					<button v-if="item.type === 'dir'" class="ec-docs__row" @click="go(item.path)">
						<FolderIcon :size="20" />
						<span class="ec-docs__name">{{ item.name }}</span>
					</button>
					<a
						v-else
						class="ec-docs__row"
						:href="fileUrl(item)"
						target="_blank">
						<img
							v-if="item.hasPreview"
							:src="previewUrl(item)"
							alt=""
							class="ec-docs__thumb">
						<FileIcon v-else :size="20" />
						<span class="ec-docs__name">{{ item.name }}</span>
						<span class="ec-docs__meta">{{ humanSize(item.size) }}</span>
					</a>
					<NcButton
						v-if="canPresent(item)"
						variant="tertiary"
						:aria-label="t('empreintelive', 'Send to the meeting')"
						:title="t('empreintelive', 'Send to the meeting')"
						:disabled="sending === item.id"
						@click="send(item)">
						<template #icon>
							<NcLoadingIcon v-if="sending === item.id" :size="20" />
							<Presentation v-else :size="20" />
						</template>
					</NcButton>
					<NcButton
						v-if="item.canShare"
						variant="tertiary"
						:aria-label="t('empreintelive', 'Share')"
						:title="t('empreintelive', 'Share')"
						@click="shareItem = item">
						<template #icon>
							<ShareVariant :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>

			<NcButton v-if="state.isOwner" variant="tertiary" @click="unlink">
				{{ t('empreintelive', 'Unlink folder') }}
			</NcButton>
		</template>

		<ShareDialog v-if="shareItem" :item="shareItem" @close="shareItem = null" />
	</div>
</template>

<script>
import { FilePickerClosed, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { formatFileSize } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import Presentation from 'vue-material-design-icons/Presentation.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import ShareDialog from './ShareDialog.vue'
import api from '../services/api.js'
import { isConvertible } from '../utils/meetings.js'

export default {
	name: 'DocumentsPanel',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		ArrowLeft,
		Delete,
		FileIcon,
		FolderIcon,
		LockIcon,
		Presentation,
		Refresh,
		ShareVariant,
		ShareDialog,
	},

	props: {
		liveId: {
			type: [String, Number],
			required: true,
		},

		title: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			busy: false,
			subPath: '',
			path: '',
			state: { linked: false, accessible: false, items: [] },
			// Fichier dont la boite de partage est ouverte, ou null.
			shareItem: null,
			// Medias deja presents dans la reunion, cote EMPREINTE.
			medias: { available: false, documents: [], reason: null },
			// Identifiant du fichier en cours d'envoi, ou null.
			sending: null,
			// Document en cours de retrait, ou null.
			removing: null,
			pollTimer: null,
		}
	},

	computed: {
		mediaUnavailableLabel() {
			const messages = {
				unreachable: this.t('empreintelive', 'The meeting cannot be reached right now.'),
				not_connected: this.t('empreintelive', 'Connect your EMPREINTE account to see the meeting\'s documents.'),
				unavailable: this.t('empreintelive', 'The meeting\'s document list is unavailable.'),
			}
			return messages[this.medias.reason] ?? this.t('empreintelive', 'Document list unavailable.')
		},
	},

	async mounted() {
		await Promise.all([this.reload(), this.reloadMedias()])

		// La liste des medias peut changer depuis LiveStudio sans qu'on en soit
		// averti : aucun canal ne nous previent. On la relit donc regulierement,
		// et au retour sur l'onglet. C'est peu couteux et ca suffit a garder les
		// deux listes coherentes.
		this.pollTimer = setInterval(() => {
			if (!document.hidden) {
				this.reloadMedias()
			}
		}, 10000)
		document.addEventListener('visibilitychange', this.onVisible)
	},

	beforeUnmount() {
		clearInterval(this.pollTimer)
		document.removeEventListener('visibilitychange', this.onVisible)
	},

	methods: {
		async reload() {
			this.loading = true
			try {
				this.state = await api.folder(this.liveId, this.subPath)
				if (!this.state.linked && !this.path) {
					const { path } = await api.suggestFolder(this.liveId, this.title)
					this.path = path
				}
			} catch {
				showError(this.t('empreintelive', 'Could not read the folder.'))
			} finally {
				this.loading = false
			}
		},

		onVisible() {
			if (!document.hidden) {
				this.reloadMedias()
			}
		},

		async removeMedia(doc) {
			this.removing = doc.index
			try {
				this.medias = await api.deleteMedia(this.liveId, doc.index)
				showSuccess(this.t('empreintelive', '{file} removed from the meeting.', { file: doc.name }))
			} catch {
				showError(this.t('empreintelive', 'Could not remove this document.'))
			} finally {
				this.removing = null
			}
		},

		async reloadMedias() {
			try {
				this.medias = await api.medias(this.liveId)
			} catch {
				// La liste des medias n'est pas indispensable au panneau : on la masque.
				this.medias = { available: false, documents: [], reason: 'unreachable' }
			}
		},

		// EMPREINTE ne convertit que ces trois formats (zone de depot du meet).
		canPresent(item) {
			return item.type === 'file' && isConvertible(item.name)
		},

		async send(item) {
			this.sending = item.id
			try {
				this.medias = await api.sendMedia(this.liveId, item.id)
				showSuccess(this.t('empreintelive', '{file} sent to the meeting.', { file: item.name }))
			} catch (e) {
				showError(this.mediaErrorLabel(e))
			} finally {
				this.sending = null
			}
		},

		mediaErrorLabel(e) {
			const messages = {
				unsupported_format: this.t('empreintelive', 'Only PDF, PPTX and DOCX files can be presented.'),
				rejected: this.t('empreintelive', 'EMPREINTE refused the request for this meeting.'),
				not_connected: this.t('empreintelive', 'Connect your EMPREINTE account first.'),
				not_found: this.t('empreintelive', 'File not found.'),
				too_large: this.t('empreintelive', 'This document is too large to be presented.'),
			}
			return messages[e?.response?.data?.error] ?? this.t('empreintelive', 'Could not send to the meeting.')
		},

		async go(path) {
			this.subPath = path
			await this.reload()
		},

		async goUp() {
			const parts = this.subPath.split('/')
			parts.pop()
			await this.go(parts.join('/'))
		},

		async link() {
			this.busy = true
			try {
				this.state = await api.linkFolder(this.liveId, this.path)
				this.subPath = ''
			} catch (e) {
				showError(this.errorLabel(e))
			} finally {
				this.busy = false
			}
		},

		async unlink() {
			this.busy = true
			try {
				await api.unlinkFolder(this.liveId)
				this.subPath = ''
				await this.reload()
			} catch {
				showError(this.t('empreintelive', 'Could not unlink the folder.'))
			} finally {
				this.busy = false
			}
		},

		async pick() {
			try {
				await getFilePickerBuilder(this.t('empreintelive', 'Choose a folder'))
					.setMultiSelect(false)
					.allowDirectories(true)
					// Les fichiers restent affiches : on veut voir ce que contient un
					// dossier avant de l'associer a la reunion. Seuls les dossiers
					// sont selectionnables.
					.setCanPick((node) => node.type === 'folder')
					// Sans bouton declare, le selecteur s'ouvre mais rien n'est validable.
					// Rien de selectionne = on associe le dossier ouvert, ce qui evite
					// de devoir remonter d'un cran pour choisir le dossier courant.
					.setButtonFactory((nodes, currentPath) => [{
						label: this.t('empreintelive', 'Choose this folder'),
						callback: () => {
							this.path = nodes?.[0]?.path || currentPath
						},
					}])
					.build()
					.pick()
			} catch (e) {
				// Fermeture sans choisir : normal, on ne dit rien. Toute autre erreur
				// doit etre visible plutot qu'avalee en silence.
				if (e instanceof FilePickerClosed) {
					return
				}
				showError(this.t('empreintelive', 'Could not open the folder picker.'))
			}
		},

		// Messages distincts : le serveur renvoie des codes, pas des phrases.
		errorLabel(e) {
			const code = e?.response?.data?.error
			if (code === 'not_permitted') {
				return this.t('empreintelive', 'You are not allowed to create this folder.')
			}
			if (code === 'root_not_allowed') {
				return this.t('empreintelive', 'Choose a specific folder rather than your whole personal space.')
			}
			if (code === 'already_linked') {
				return this.t('empreintelive', 'Another participant has already associated a folder with this meeting.')
			}
			return this.t('empreintelive', 'Could not associate this folder.')
		},

		fileUrl(item) {
			// Route cœur de Nextcloud : ouvre le fichier dans Files.
			return generateUrl('/f/{fileId}', { fileId: item.id })
		},

		previewUrl(item) {
			return generateUrl('/core/preview?fileId={fileId}&x=32&y=32&a=1', { fileId: item.id })
		},

		humanSize(bytes) {
			// Unités traduites par Nextcloud (« Ko » en français, « KB » en anglais).
			return typeof bytes === 'number' ? formatFileSize(bytes) : ''
		},
	},
}
</script>

<style scoped lang="scss">
.ec-docs {
	display: flex;
	flex-direction: column;
	gap: 8px;
	height: 100%;
	min-height: 0;
}

.ec-docs__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex: 0 0 auto;
}

.ec-docs__title {
	margin: 0;
}

.ec-docs__hint,
.ec-docs__crumb-tail,
.ec-docs__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	// « 23 Mo », « 263 diapos » : jamais coupes sur deux lignes.
	white-space: nowrap;
	flex: 0 0 auto;
}

.ec-docs__setup,
.ec-docs__actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ec-docs__actions {
	flex-direction: row;
}

.ec-docs__crumbs {
	display: flex;
	align-items: center;
	gap: 4px;
	flex: 0 0 auto;
	overflow: hidden;
}

.ec-docs__live {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 10px;
	flex: 0 0 auto;
}

.ec-docs__live-title {
	margin: 0 0 6px;
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
}

.ec-docs__live-items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.ec-docs__live-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 3px 0;
}

.ec-docs__folder-share {
	flex: 0 0 auto;
}

.ec-docs__item {
	display: flex;
	align-items: center;
	gap: 2px;
}

.ec-docs__items {
	list-style: none;
	margin: 0;
	padding: 0;
	overflow-y: auto;
	overflow-x: hidden;
	flex: 1 1 auto;
	min-height: 0;
}

.ec-docs__row {
	display: flex;
	align-items: center;
	gap: 8px;
	// Pas de width: 100% : la ligne partage la place avec les boutons d'action.
	// A 100% elle les poussait hors du panneau (barre de defilement horizontale).
	flex: 1 1 auto;
	min-width: 0;
	padding: 6px 8px;
	border: none;
	background: none;
	border-radius: var(--border-radius);
	color: inherit;
	text-decoration: none;
	cursor: pointer;
	text-align: start;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}
}

.ec-docs__name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ec-docs__thumb {
	width: 20px;
	height: 20px;
	object-fit: cover;
	border-radius: 2px;
}
</style>
