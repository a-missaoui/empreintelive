<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Boîte de dialogue « Réunion sur ce document », ouverte depuis le menu
  - contextuel de l'app Files.
  -
  - Le titre et les participants sont pré-remplis par le serveur : le titre vient
  - du nom du fichier, les participants des personnes avec qui il est déjà
  - partagé dans Nextcloud. Tout reste modifiable.
-->
<template>
	<NcDialog
		:name="t('empreintelive', 'Meeting on {file}', { file: fileName })"
		size="normal"
		@closing="$emit('close')">
		<div class="ec-meet">
			<NcLoadingIcon v-if="loading" :size="24" />

			<!-- Réunion créée : on donne le lien et l'accès -->
			<div v-else-if="created" class="ec-meet__done">
				<NcNoteCard :type="created.documentSent ? 'success' : 'warning'">
					{{ created.documentSent
						? t('empreintelive', 'Meeting created, with the document loaded in it.')
						: t('empreintelive', 'Meeting created, but the document could not be loaded in it.') }}
				</NcNoteCard>
				<p v-if="created.eventCreated" class="ec-meet__hint">
					{{ t('empreintelive', 'The event was added to your calendar.') }}
				</p>
				<p v-if="created.invited" class="ec-meet__hint">
					{{ t('empreintelive', 'Participants have been invited.') }}
				</p>
				<div class="ec-meet__row">
					<NcButton variant="primary" @click="openMeeting">
						{{ t('empreintelive', 'Open the meeting') }}
					</NcButton>
					<NcButton v-if="meetingUrl" variant="secondary" @click="copyLink">
						{{ t('empreintelive', 'Copy link') }}
					</NcButton>
				</div>
			</div>

			<!-- Formulaire -->
			<div v-else class="ec-meet__form">
				<NcNoteCard v-if="!convertible" type="warning">
					{{ t('empreintelive', 'This format cannot be presented. The meeting will be created without the document.') }}
				</NcNoteCard>

				<NcTextField v-model="title" :label="t('empreintelive', 'Meeting title')" />

				<div class="ec-meet__row">
					<NcTextField v-model="start" type="datetime-local" :label="t('empreintelive', 'Start')" />
					<NcTextField v-model="end" type="datetime-local" :label="t('empreintelive', 'End')" />
				</div>

				<div class="ec-meet__attendees">
					<span class="ec-meet__label">{{ t('empreintelive', 'Participants') }}</span>
					<p class="ec-meet__hint">
						{{ t('empreintelive', 'Search for a user or a contact, or type an email address and press Enter.') }}
					</p>
					<NcSelect
						v-model="participants"
						:options="attendeeOptions"
						:multiple="true"
						:loading="attendeeLoading"
						:filterable="false"
						:closeOnSelect="false"
						:taggable="true"
						:createOption="asFreeEmail"
						label="displayName"
						trackBy="email"
						:placeholder="t('empreintelive', 'Name or email address')"
						@search="onAttendeeSearch">
						<template #no-options>
							{{ attendeeLoading
								? t('empreintelive', 'Searching…')
								: t('empreintelive', 'Type a name to search, or a full email address') }}
						</template>
					</NcSelect>
				</div>

				<NcButton variant="primary" :disabled="busy || !title" @click="submit">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="20" />
					</template>
					{{ t('empreintelive', 'Create the meeting') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../services/api.js'

/**
 * Format attendu par les champs datetime-local : « AAAA-MM-JJTHH:MM ».
 *
 * @param {Date} date - L'instant à formater, exprimé dans le fuseau local.
 * @return {string} La valeur acceptée par un champ datetime-local.
 */
function localInput(date) {
	const pad = (v) => String(v).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
		+ `T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

export default {
	name: 'CreateMeetingDialog',

	components: { NcDialog, NcButton, NcTextField, NcNoteCard, NcSelect, NcLoadingIcon },

	props: {
		fileId: {
			type: [Number, String],
			required: true,
		},

		fileName: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		const now = new Date()
		const later = new Date(now.getTime() + 60 * 60 * 1000)
		return {
			loading: true,
			busy: false,
			convertible: true,
			title: '',
			start: localInput(now),
			end: localInput(later),
			participants: [],
			attendeeOptions: [],
			attendeeLoading: false,
			searchTimer: null,
			created: null,
		}
	},

	computed: {
		meetingUrl() {
			const live = this.created?.data ?? {}
			return live.admin_url ?? live.organiserUrl ?? live.participant_url ?? live.participantUrl ?? null
		},
	},

	async mounted() {
		try {
			const s = await api.meetingSuggestion(this.fileId)
			this.title = s.title
			// Les personnes deja destinataires du document, pre-selectionnees.
			this.participants = (Array.isArray(s.attendees) ? s.attendees : [])
				.map((email) => ({ email, displayName: email }))
			this.convertible = s.convertible !== false
		} catch {
			this.title = this.fileName
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Recherche débouncée des participants (utilisateurs / contacts Nextcloud).
		 *
		 * @param {string} query - Texte saisi dans le champ.
		 */
		onAttendeeSearch(query) {
			clearTimeout(this.searchTimer)
			if (!query || query.trim().length < 1) {
				this.attendeeOptions = []
				return
			}
			this.searchTimer = setTimeout(async () => {
				this.attendeeLoading = true
				try {
					const results = await api.searchAttendees(query.trim())
					this.attendeeOptions = results.map((r) => ({
						...r,
						displayName: r.name && r.name !== r.email ? `${r.name} (${r.email})` : r.email,
					}))
				} catch {
					this.attendeeOptions = []
				} finally {
					this.attendeeLoading = false
				}
			}, 300)
		},

		/**
		 * Saisie libre : une adresse tapée à la main devient un participant, au même
		 * titre qu'un compte trouvé par la recherche.
		 *
		 * @param {string} input - Texte saisi.
		 * @return {object} Le participant correspondant.
		 */
		asFreeEmail(input) {
			const email = String(input).trim()
			return { email, displayName: email }
		},

		async submit() {
			// Le serveur ecarte silencieusement les adresses invalides : autant le dire
			// ici plutot que de laisser croire que la personne a ete invitee.
			const invalid = this.participants
				.map((p) => p.email)
				.filter((e) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e))
			if (invalid.length) {
				showError(this.t('empreintelive', 'Invalid address: {mail}', { mail: invalid[0] }))
				return
			}

			this.busy = true
			try {
				this.created = await api.createMeetingForFile(this.fileId, {
					title: this.title,
					description: this.t('empreintelive', 'Meeting on the document {file}', { file: this.fileName }),
					// Le serveur attend de l'ISO 8601 UTC.
					startTime: new Date(this.start).toISOString(),
					endTime: new Date(this.end).toISOString(),
					attendees: this.participants.map((p) => p.email),
				})
			} catch (e) {
				showError(e?.response?.data?.error === 'not_found'
					? this.t('empreintelive', 'File not found.')
					: this.t('empreintelive', 'The meeting could not be created.'))
			} finally {
				this.busy = false
			}
		},

		openMeeting() {
			// On arrive directement dans la salle de CETTE réunion, pas sur la liste :
			// sinon il faut recliquer « Ouvrir ici » pour voir le document présenté.
			const id = this.created?.liveId ?? this.created?.data?.id
			const query = id ? '?live=' + encodeURIComponent(id) : ''
			window.open(generateUrl('/apps/empreintelive/') + query, '_blank')
			this.$emit('close')
		},

		async copyLink() {
			try {
				await navigator.clipboard.writeText(this.meetingUrl)
				showSuccess(this.t('empreintelive', 'Link copied.'))
			} catch {
				showError(this.t('empreintelive', 'Could not copy.'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.ec-meet {
	padding: 8px 4px;
	display: flex;
	flex-direction: column;
	gap: 14px;
	min-height: 140px;
}

.ec-meet__form,
.ec-meet__done {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.ec-meet__row {
	display: flex;
	gap: 8px;
	align-items: end;
}

.ec-meet__label {
	font-weight: bold;
	display: block;
	margin-bottom: 4px;
}

.ec-meet__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 6px;
}

.ec-meet__people {
	list-style: none;
	margin: 0 0 8px;
	padding: 0;
	max-height: 140px;
	overflow-y: auto;
}

.ec-meet__person {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 2px 0 2px 4px;
}
</style>
