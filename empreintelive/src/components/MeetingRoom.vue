<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Page composite : la réunion EMPREINTE embarquée dans Nextcloud, avec le
  - panneau documentaire à côté.
  -
  - La page est servie par Nextcloud (l'utilisateur y est déjà authentifié), et
  - c'est la réunion qui est embarquée — jamais l'inverse.
  - Nextcloud refuse d'être affiché dans une iframe tierce (X-Frame-Options et
  - cookies SameSite), donc le sens inverse est techniquement impossible.
  -
  - La vue occupe toute la hauteur disponible : une visio dans un cadre de 520 px
  - tronque le lobby, et le bouton « Rejoindre » passe sous la ligne de flottaison.
-->
<template>
	<div class="ec-room">
		<div class="ec-room__bar">
			<NcButton variant="tertiary" @click="$emit('close')">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('empreintelive', 'Back to video meetings') }}
			</NcButton>

			<span class="ec-room__title">{{ title }}</span>

			<span v-if="saving" class="ec-room__saving" role="status">
				<NcLoadingIcon :size="16" />
				{{ t('empreintelive', 'Saving the recording to Nextcloud… {percent} %', { percent: savingPercent }) }}
			</span>

			<NcButton
				variant="tertiary"
				:aria-label="panelLabel"
				:title="panelLabel"
				@click="showPanel = !showPanel">
				<template #icon>
					<DockRight :size="20" />
				</template>
			</NcButton>

			<NcButton
				v-if="url"
				variant="tertiary"
				:aria-label="t('empreintelive', 'Full screen')"
				:title="t('empreintelive', 'Full screen')"
				@click="goFullscreen">
				<template #icon>
					<Fullscreen :size="20" />
				</template>
			</NcButton>

			<NcButton
				v-if="url"
				variant="tertiary"
				:href="url"
				target="_blank"
				:aria-label="t('empreintelive', 'Open in a tab')"
				:title="t('empreintelive', 'Open in a tab')">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
			</NcButton>
		</div>

		<div class="ec-room__body">
			<div class="ec-room__stage">
				<iframe
					v-if="url"
					ref="frame"
					:src="url"
					:title="title"
					class="ec-room__frame"
					allow="camera; microphone; display-capture; fullscreen"
					allowfullscreen />
				<NcEmptyContent
					v-else
					:name="t('empreintelive', 'Meeting link not found')"
					:description="t('empreintelive', 'This video meeting does not provide a usable link.')">
					<template #icon>
						<VideoOff />
					</template>
				</NcEmptyContent>
			</div>

			<aside v-if="showPanel" class="ec-room__aside">
				<DocumentsPanel
					v-if="liveId"
					ref="panel"
					:liveId="liveId"
					:title="title" />
			</aside>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import DockRight from 'vue-material-design-icons/DockRight.vue'
import Fullscreen from 'vue-material-design-icons/Fullscreen.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import VideoOff from 'vue-material-design-icons/VideoOff.vue'
import DocumentsPanel from './DocumentsPanel.vue'
import api from '../services/api.js'
import { downloadBlob, uploadRecording } from '../services/recordingUpload.js'
import { createRecordingBridge, recordingFileName } from '../utils/recordingBridge.js'

export default {
	name: 'MeetingRoom',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		DocumentsPanel,
		ArrowLeft,
		DockRight,
		Fullscreen,
		OpenInNew,
		VideoOff,
	},

	props: {
		meeting: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			showPanel: true,
			// Envois d'enregistrements en cours (plusieurs arrêts successifs possibles).
			uploads: 0,
			savingRatio: 0,
		}
	},

	computed: {
		// Mêmes replis défensifs que MeetingList : la forme de l'objet dépend de l'API.
		url() {
			const m = this.meeting
			return m?.url ?? m?.liveUrl ?? m?.join_url ?? m?.participant_url ?? null
		},

		title() {
			return this.meeting?.title ?? this.meeting?.name ?? this.t('empreintelive', 'Untitled')
		},

		liveId() {
			const m = this.meeting
			return m?.id ?? m?.liveId ?? m?.live_id ?? null
		},

		saving() {
			return this.uploads > 0
		},

		savingPercent() {
			return Math.round(this.savingRatio * 100)
		},

		panelLabel() {
			return this.showPanel
				? this.t('empreintelive', 'Hide documents')
				: this.t('empreintelive', 'Show documents')
		},
	},

	mounted() {
		this.onMessage = createRecordingBridge({
			getFrameWindow: () => this.$refs.frame?.contentWindow ?? null,
			allowedOrigins: loadState('empreintelive', 'meeting-origins', []),
			hostName: 'Nextcloud',
			onRecording: (recording) => this.saveRecording(recording),
		})
		window.addEventListener('message', this.onMessage)
		window.addEventListener('beforeunload', this.onBeforeUnload)
	},

	beforeUnmount() {
		window.removeEventListener('message', this.onMessage)
		window.removeEventListener('beforeunload', this.onBeforeUnload)
	},

	methods: {
		/**
		 * Le studio nous a confié l'enregistrement : il ne le télécharge plus.
		 * On doit donc soit le déposer dans Nextcloud, soit le proposer au
		 * téléchargement, mais jamais le perdre.
		 *
		 * @param {{blob: Blob, extension: string}} recording reçu du studio
		 */
		async saveRecording({ blob, extension }) {
			const name = recordingFileName(this.t('empreintelive', 'Recording'), extension, new Date())
			this.uploads++
			this.savingRatio = 0
			try {
				const { path: folder } = await api.recordingFolder(this.liveId, this.title)
				const path = await uploadRecording({
					blob,
					folder,
					name,
					onProgress: (ratio) => { this.savingRatio = ratio },
				})
				showSuccess(this.t('empreintelive', 'Recording saved in {path}', { path }))
				this.$refs.panel?.reload()
			} catch {
				downloadBlob(blob, name)
				showError(this.t('empreintelive', 'The recording could not be saved to Nextcloud. It was downloaded to your device instead.'))
			} finally {
				this.uploads--
			}
		},

		onBeforeUnload(event) {
			// Quitter pendant l'envoi perdrait l'enregistrement.
			if (this.saving) {
				event.preventDefault()
				event.returnValue = ''
			}
		},

		goFullscreen() {
			this.$refs.frame?.requestFullscreen?.()
		},
	},
}
</script>

<style scoped lang="scss">
.ec-room {
	display: flex;
	flex-direction: column;
	/* Le parent (.ec-root--room) porte la hauteur ; on la remplit. */
	height: 100%;
	min-height: 0;
}

.ec-room__bar {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	flex: 0 0 auto;
}

.ec-room__title {
	font-weight: 600;
	margin-inline: 8px auto;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ec-room__saving {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.ec-room__body {
	display: flex;
	gap: 8px;
	padding: 8px;
	flex: 1 1 auto;
	min-height: 0;
}

.ec-room__stage {
	flex: 1 1 auto;
	min-width: 0;
	min-height: 0;
	display: flex;
}

.ec-room__frame {
	flex: 1 1 auto;
	width: 100%;
	/* min-height: 0 est indispensable : sans lui l'iframe garde sa hauteur
	   intrinsèque de 150 px et refuse de suivre le conteneur flex. */
	min-height: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.ec-room__aside {
	// 300 px ne laissait qu'une poignee de caracteres aux noms de fichiers une
	// fois les deux actions (presenter, partager) affichees.
	flex: 0 0 340px;
	min-height: 0;
	min-width: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	overflow-y: auto;
	overflow-x: hidden;
}

.ec-room__aside-title {
	margin: 0 0 8px;
}

/* Sous 1024 px le panneau passe sous la réunion plutôt que de l'écraser. */
@media (max-width: 1024px) {
	.ec-room__body {
		flex-direction: column;
	}

	.ec-room__stage {
		min-height: 60vh;
	}

	.ec-room__aside {
		flex: 0 0 auto;
	}
}
</style>
