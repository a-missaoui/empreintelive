<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ec-root" :class="activeMeeting ? 'ec-root--room' : 'ec-root--page'">
		<!-- Reunion ouverte -> page composite (reunion + panneau documents) -->
		<MeetingRoom
			v-if="activeMeeting"
			:meeting="activeMeeting"
			@close="activeMeeting = null" />

		<NcSettingsSection
			v-else
			:name="t('empreintelive', 'EMPREINTE Live')"
			:description="t('empreintelive', 'Connect your EMPREINTE account to create and manage your video meetings from Nextcloud.')">
			<div v-if="loading" class="ec-center">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<!-- Non connecté : formulaire login / register -->
				<ConnectionForm
					v-if="!connected"
					@connected="onConnected" />

				<!-- Connecté : gestion des réunions -->
				<div v-else class="ec-connected">
					<NcNoteCard type="success">
						{{ t('empreintelive', 'EMPREINTE account connected.') }}
					</NcNoteCard>

					<div class="ec-grid">
						<CreateMeeting @created="refreshMeetings" />
						<MeetingList ref="list" @open="activeMeeting = $event" />
					</div>

					<NcButton variant="tertiary" @click="onLogout">
						{{ t('empreintelive', 'Disconnect account') }}
					</NcButton>
				</div>
			</template>
		</NcSettingsSection>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import ConnectionForm from '../components/ConnectionForm.vue'
import CreateMeeting from '../components/CreateMeeting.vue'
import MeetingList from '../components/MeetingList.vue'
import MeetingRoom from '../components/MeetingRoom.vue'
import api from '../services/api.js'

export default {
	name: 'MeetingsPage',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcButton,
		NcLoadingIcon,
		ConnectionForm,
		CreateMeeting,
		MeetingList,
		MeetingRoom,
	},

	data() {
		return {
			loading: true,
			connected: false,
			// Reunion affichee dans la page composite, ou null pour la liste.
			activeMeeting: null,
		}
	},

	async mounted() {
		await this.fetchStatus()
		await this.openRequestedMeeting()
	},

	methods: {
		/**
		 * « ?live=<id> » : ouverture directe d'une réunion, depuis l'action
		 * « Réunion sur ce document » de l'app Files.
		 */
		async openRequestedMeeting() {
			const id = new URLSearchParams(window.location.search).get('live')
			if (!id || !this.connected) {
				return
			}
			try {
				const meetings = await api.listMeetings()
				const match = meetings.find((m) => String(m?.id ?? m?.liveId ?? m?.live_id) === id)
				if (match) {
					this.activeMeeting = match
				}
			} catch {
				// Réunion introuvable ou liste indisponible : on reste sur la liste.
			}
		},

		async fetchStatus() {
			this.loading = true
			try {
				const { connected } = await api.status()
				this.connected = !!connected
			} catch {
				showError(this.t('empreintelive', 'Could not check the connection.'))
			} finally {
				this.loading = false
			}
		},

		onConnected() {
			this.connected = true
		},

		async onLogout() {
			try {
				await api.logout()
				this.connected = false
			} catch {
				showError(this.t('empreintelive', 'Could not disconnect.'))
			}
		},

		refreshMeetings() {
			this.$refs.list?.reload()
		},
	},
}
</script>

<style scoped lang="scss">
.ec-center {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.ec-connected > * {
	margin-bottom: 16px;
}

.ec-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 20px;
	align-items: start;
}

// Mode salle : on libère toute la largeur ET toute la hauteur disponibles.
// .app-content de Nextcloud est un élément flex à hauteur définie, donc un
// height: 100% ici se résout correctement jusqu'à l'iframe.
.ec-root--room {
	height: 100%;
	min-height: 0;
	max-width: none;
	margin: 0;
	padding: 0;
}

// Page d'app plein écran : contenu centré + grille 2 colonnes sur large écran.
.ec-root--page {
	max-width: 1100px;
	margin-inline: auto;
	padding: 8px 16px 32px;

	.ec-grid {
		@media (min-width: 900px) {
			grid-template-columns: minmax(0, 460px) minmax(0, 1fr);
		}
	}

	// Le formulaire remplit sa colonne (au lieu du max-width: 500px fixe).
	:deep(.ec-create) {
		max-width: none;
	}
}
</style>
