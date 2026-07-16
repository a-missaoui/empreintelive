<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ec-root ec-root--page">
		<NcSettingsSection
			:name="t('empreintelive', 'EMPREINTE Live')"
			:description="t('empreintelive', 'Connectez votre compte EMPREINTE pour créer et gérer vos visioconférences depuis Nextcloud.')">
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
						{{ t('empreintelive', 'Compte EMPREINTE connecté.') }}
					</NcNoteCard>

					<div class="ec-grid">
						<CreateMeeting @created="refreshMeetings" />
						<MeetingList ref="list" />
					</div>

					<NcButton variant="tertiary" @click="onLogout">
						{{ t('empreintelive', 'Déconnecter le compte') }}
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
	},

	data() {
		return {
			loading: true,
			connected: false,
		}
	},

	async mounted() {
		await this.fetchStatus()
	},

	methods: {
		async fetchStatus() {
			this.loading = true
			try {
				const { connected } = await api.status()
				this.connected = !!connected
			} catch {
				showError(this.t('empreintelive', 'Impossible de vérifier la connexion.'))
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
				showError(this.t('empreintelive', 'Échec de la déconnexion.'))
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
