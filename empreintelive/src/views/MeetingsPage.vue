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

		<!-- Lien publié par le bot Talk : ?live=<id>&talk=<token> -->
		<InviteMembersDialog
			v-if="invite"
			:liveId="invite.liveId"
			:token="invite.token"
			@close="invite = null" />

		<NcSettingsSection
			v-else
			:name="t('empreintelive', 'EMPREINTE Live')"
			:description="t('empreintelive', 'Connect your EMPREINTE account to create and manage your video meetings from Nextcloud.')">
			<div v-if="loading" class="ec-center">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else>
				<!-- Non connecté : formulaire login / register -->
				<template v-if="!connected">
					<!-- Compte refusé par EMPREINTE : motif renvoyé par l'API. -->
					<NcNoteCard v-if="connectionReason" type="warning">
						{{ connectionReason }}
					</NcNoteCard>
					<ConnectionForm @connected="onConnected" />
				</template>

				<!-- Connecté : gestion des réunions -->
				<div v-else class="ec-connected">
					<!-- Compte connecté et déconnexion, en tête : visibles sans défiler. -->
					<div class="ec-account">
						<CheckCircle :size="20" class="ec-account__icon" />
						<span class="ec-account__label">
							{{ accountEmail
								? t('empreintelive', 'Connected as {email}', { email: accountEmail })
								: t('empreintelive', 'EMPREINTE account connected.') }}
						</span>
						<NcButton variant="tertiary" @click="onLogout">
							<template #icon>
								<Logout :size="20" />
							</template>
							{{ t('empreintelive', 'Disconnect account') }}
						</NcButton>
					</div>

					<div class="ec-grid">
						<CreateMeeting @created="refreshMeetings" @notConnected="onRejected" />
						<MeetingList
							ref="list"
							@open="activeMeeting = $event"
							@notConnected="onRejected" />
					</div>
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
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Logout from 'vue-material-design-icons/Logout.vue'
import ConnectionForm from '../components/ConnectionForm.vue'
import CreateMeeting from '../components/CreateMeeting.vue'
import InviteMembersDialog from '../components/InviteMembersDialog.vue'
import MeetingList from '../components/MeetingList.vue'
import MeetingRoom from '../components/MeetingRoom.vue'
import api from '../services/api.js'

export default {
	name: 'MeetingsPage',
	components: {
		NcSettingsSection,
		CheckCircle,
		Logout,
		NcNoteCard,
		NcButton,
		NcLoadingIcon,
		ConnectionForm,
		CreateMeeting,
		InviteMembersDialog,
		MeetingList,
		MeetingRoom,
	},

	data() {
		return {
			loading: true,
			connected: false,
			// Reunion affichee dans la page composite, ou null pour la liste.
			activeMeeting: null,
			// Invitation des membres d'une conversation Talk, ou null.
			invite: null,
			// Motif donné par EMPREINTE quand il refuse le compte.
			connectionReason: '',
			// Adresse du compte EMPREINTE connecté.
			accountEmail: '',
		}
	},

	async mounted() {
		await this.fetchStatus()
		await this.openRequestedMeeting()
		this.openRequestedInvitation()
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

		/**
		 * « ?live=<id>&talk=<token> » : lien « Inviter les membres » publié par le
		 * bot dans une conversation Talk.
		 */
		openRequestedInvitation() {
			const params = new URLSearchParams(window.location.search)
			const liveId = params.get('live')
			const token = params.get('talk')
			if (this.connected && liveId && token) {
				this.invite = { liveId, token }
			}
		},

		async fetchStatus() {
			this.loading = true
			try {
				const { connected, email } = await api.status()
				this.connected = !!connected
				this.accountEmail = email ?? ''
			} catch {
				showError(this.t('empreintelive', 'Could not check the connection.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * EMPREINTE a refusé le compte (supprimé, jeton expiré) : l'app l'a
		 * déconnecté côté serveur, on repropose la connexion.
		 *
		 * @param {string} reason - Motif donné par EMPREINTE.
		 */
		onRejected(reason) {
			this.connected = false
			this.connectionReason = reason
		},

		async onConnected() {
			this.connected = true
			this.connectionReason = ''
			this.openRequestedInvitation()
			// Adresse du compte qui vient d'être connecté, pour l'en-tête.
			try {
				this.accountEmail = (await api.status()).email ?? ''
			} catch {
				this.accountEmail = ''
			}
		},

		async onLogout() {
			try {
				await api.logout()
				this.connected = false
				this.accountEmail = ''
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

// Bandeau du compte : état et déconnexion sur une ligne, en tête de page.
.ec-account {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 4px 4px 4px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);

	&__icon {
		color: var(--color-success-text, var(--color-success));
	}

	&__label {
		flex: 1;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
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
