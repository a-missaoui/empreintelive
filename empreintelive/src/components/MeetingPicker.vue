<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Entrée « EMPREINTE Live » du sélecteur de liens (Talk, Text…) : choisir une
  - réunion à venir, ou en créer une, et insérer son lien dans le message.
  -
  - Le lien inséré est toujours le lien participant : il est lu par tous ceux qui
  - voient le message, et le lien organisateur donnerait les droits d'animation.
  -->
<template>
	<!--
		@submit.stop : le formulaire de création émet un « submit » natif, qui
		remonte le DOM. Le sélecteur de Nextcloud écoute « submit » sur notre
		élément pour recevoir le lien (event.detail) : sans cet arrêt, il insérerait
		« undefined ». Notre propre événement est émis directement sur l'élément
		(src/reference.js), il n'est pas concerné.
	-->
	<div class="ec-picker" @submit.stop>
		<h2 class="ec-picker__title">
			{{ t('empreintelive', 'EMPREINTE Live') }}
		</h2>

		<NcLoadingIcon v-if="loading" :size="32" />

		<!--
			Compte absent, ou refusé par EMPREINTE (compte supprimé, jeton expiré) :
			connexion proposée ici même, comme dans Files, avec le motif de l'API.
		-->
		<template v-else-if="!connected">
			<NcNoteCard type="warning">
				<p>{{ t('empreintelive', 'Connect your EMPREINTE account first.') }}</p>
				<p v-if="connectionReason">
					{{ connectionReason }}
				</p>
			</NcNoteCard>
			<ConnectionForm @connected="onConnected" />
		</template>

		<!-- Resté monté pendant la connexion : la saisie est conservée. -->
		<CreateMeeting
			v-if="creating"
			v-show="connected && !loading"
			@created="onCreated"
			@notConnected="onRejected" />

		<template v-if="connected && !loading && !creating">
			<div class="ec-picker__head">
				<span>{{ t('empreintelive', 'Choose a meeting') }}</span>
				<NcButton variant="secondary" @click="creating = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('empreintelive', 'New meeting') }}
				</NcButton>
			</div>

			<NcEmptyContent
				v-if="upcoming.length === 0"
				:name="t('empreintelive', 'No upcoming meetings')"
				:description="t('empreintelive', 'Create one to insert its link.')">
				<template #icon>
					<VideoIcon />
				</template>
			</NcEmptyContent>

			<ul v-else class="ec-picker__list">
				<li v-for="m in upcoming" :key="m.id">
					<button
						type="button"
						class="ec-picker__item"
						:disabled="!participantLink(m)"
						@click="insert(participantLink(m))">
						<span class="ec-picker__name">{{ m.title || t('empreintelive', 'Untitled') }}</span>
						<span class="ec-picker__when">
							<span v-if="status(m) === 'live'" class="ec-picker__badge">
								{{ t('empreintelive', 'In progress') }}
							</span>
							{{ when(m) }}
						</span>
					</button>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { getCanonicalLocale } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Plus from 'vue-material-design-icons/Plus.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import ConnectionForm from './ConnectionForm.vue'
import CreateMeeting from './CreateMeeting.vue'
import api from '../services/api.js'
import { meetingStatus, meetingWhen, participantLink, sortMeetings } from '../utils/meetings.js'

export default {
	name: 'MeetingPicker',
	components: {
		ConnectionForm,
		CreateMeeting,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Plus,
		VideoIcon,
	},

	emits: ['submit'],

	data() {
		return {
			loading: true,
			connected: false,
			connectionReason: '',
			creating: false,
			meetings: [],
			now: Date.now(),
		}
	},

	computed: {
		/** En cours puis à venir ; une réunion terminée n'a rien à faire dans un message. */
		upcoming() {
			return sortMeetings(this.meetings, this.now)
				.filter((m) => ['live', 'upcoming'].includes(meetingStatus(m, this.now)))
		},
	},

	async mounted() {
		try {
			const { connected } = await api.status()
			this.connected = !!connected
			if (this.connected) {
				await this.load()
			}
		} catch {
			showError(this.t('empreintelive', 'Could not check the connection.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		async load() {
			try {
				this.meetings = await api.listMeetings()
			} catch (e) {
				if (e?.response?.data?.error === 'not_connected') {
					this.onRejected(e.response.data.message ?? '')
					return
				}
				showError(this.t('empreintelive', 'Could not load the video meetings.'))
			}
		},

		/**
		 * EMPREINTE a refusé le compte : l'app l'a déconnecté côté serveur, on
		 * propose la connexion avec le motif renvoyé.
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
			if (this.creating) {
				// Retour au formulaire, tel qu'il avait été rempli.
				return
			}
			this.loading = true
			await this.load()
			this.loading = false
		},

		onCreated(live) {
			const link = participantLink(live)
			if (link) {
				this.insert(link)
				return
			}
			// Réponse sans lien participant : on revient à la liste, rechargée.
			this.creating = false
			this.load()
		},

		insert(link) {
			this.$emit('submit', link)
		},

		participantLink(m) {
			return participantLink(m)
		},

		status(m) {
			return meetingStatus(m, this.now)
		},

		when(m) {
			return meetingWhen(m, this.now, this.t, getCanonicalLocale())
		},
	},
}
</script>

<style scoped lang="scss">
.ec-picker {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 100%;
	padding: 16px;
	box-sizing: border-box;

	&__title {
		margin: 0;
		font-size: 1.2em;
	}

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-height: 50vh;
		margin: 0;
		padding: 0;
		overflow-y: auto;
		list-style: none;
	}

	&__item {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		width: 100%;
		height: auto;
		margin: 0;
		padding: 8px 12px;
		border: none;
		border-radius: var(--border-radius-large);
		background: transparent;
		text-align: start;
		cursor: pointer;

		&:hover:not(:disabled),
		&:focus-visible {
			background: var(--color-background-hover);
		}

		&:disabled {
			opacity: 0.5;
			cursor: default;
		}
	}

	&__name {
		font-weight: bold;
	}

	&__when {
		color: var(--color-text-maxcontrast);
	}

	&__badge {
		margin-inline-end: 4px;
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		background: var(--color-success);
		color: var(--color-primary-element-text);
	}
}
</style>
