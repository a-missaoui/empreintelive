<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Formulaire de création d'une visioconférence (Live).
-->
<template>
	<form class="ec-create" @submit.prevent="submit">
		<h4>{{ t('empreintelive', 'New video meeting') }}</h4>

		<NcTextField
			v-model="title"
			:label="t('empreintelive', 'Title')"
			required />

		<NcTextArea
			v-model="description"
			:label="t('empreintelive', 'Description')"
			required />

		<div class="ec-dates">
			<label class="ec-date">
				<span>{{ t('empreintelive', 'Start') }}</span>
				<input v-model="startTime" type="datetime-local" required>
			</label>
			<label class="ec-date">
				<span>{{ t('empreintelive', 'End') }}</span>
				<input v-model="endTime" type="datetime-local">
			</label>
		</div>

		<div class="ec-attendees">
			<span class="ec-attendees__label">{{ t('empreintelive', 'Participants') }}</span>
			<NcSelect
				v-model="participants"
				:options="attendeeOptions"
				:multiple="true"
				:loading="attendeeLoading"
				:filterable="false"
				:closeOnSelect="false"
				label="displayName"
				trackBy="email"
				:placeholder="t('empreintelive', 'Search for a user or a contact')"
				@search="onAttendeeSearch">
				<template #no-options>
					{{ attendeeLoading
						? t('empreintelive', 'Searching…')
						: t('empreintelive', 'Type a name to search for a user or a contact') }}
				</template>
			</NcSelect>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcButton
			variant="primary"
			type="submit"
			:disabled="busy || !title || !description || !startTime">
			<template v-if="busy" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ t('empreintelive', 'Create meeting') }}
		</NcButton>
	</form>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../services/api.js'

export default {
	name: 'CreateMeeting',
	components: {
		NcButton,
		NcTextField,
		NcTextArea,
		NcNoteCard,
		NcLoadingIcon,
		NcSelect,
	},

	emits: ['created'],
	data() {
		return {
			title: '',
			description: '',
			startTime: '',
			endTime: '',
			participants: [],
			attendeeOptions: [],
			attendeeLoading: false,
			searchTimer: null,
			busy: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Recherche débouncée des participants (utilisateurs / contacts Nextcloud).
		 *
		 * @param {string} query - Texte de recherche saisi par l'utilisateur.
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
		 * Validation côté client : renvoie un message d'erreur en français, ou une
		 * chaîne vide si le formulaire est valide. Évite d'afficher les messages
		 * bruts (en anglais) renvoyés par l'API EMPREINTE pour les cas courants.
		 */
		validate() {
			if (!this.title.trim()) {
				return this.t('empreintelive', 'Please enter a title.')
			}
			if (!this.description.trim()) {
				return this.t('empreintelive', 'Please enter a description.')
			}
			if (!this.startTime) {
				return this.t('empreintelive', 'Please enter a start date.')
			}
			if (this.endTime && new Date(this.endTime) <= new Date(this.startTime)) {
				return this.t('empreintelive', 'The end date must be after the start date.')
			}
			return ''
		},

		async submit() {
			this.error = this.validate()
			if (this.error) {
				return
			}
			this.busy = true
			try {
				const res = await api.createLive({
					title: this.title,
					description: this.description,
					// L'API attend de l'ISO8601 UTC ; l'input datetime-local est en heure locale.
					startTime: toIso(this.startTime),
					endTime: toIso(this.endTime || this.startTime),
					attendees: this.participants.map((p) => p.email),
				})
				showSuccess(res?.eventCreated
					? this.t('empreintelive', 'Video meeting created and added to your calendar.')
					: this.t('empreintelive', 'Video meeting created.'))
				this.reset()
				this.$emit('created')
			} catch (e) {
				this.error = this.friendlyError(e?.response?.data?.error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Traduit en français les messages d'erreur connus renvoyés par l'API
		 * EMPREINTE (en anglais / format technique). Tout message non reconnu est
		 * remplacé par un message générique plutôt que d'exposer du texte brut.
		 *
		 * @param {string} [raw] Message d'erreur brut renvoyé par le serveur.
		 * @return {string} Message d'erreur lisible en français.
		 */
		friendlyError(raw) {
			const generic = this.t('empreintelive', 'Could not create the video meeting.')
			if (!raw || typeof raw !== 'string') {
				return generic
			}
			const lower = raw.toLowerCase()
			if (lower.includes('end date') || lower.includes('dateend')) {
				return this.t('empreintelive', 'The end date must be after the start date.')
			}
			if (lower.includes('description') && lower.includes('required')) {
				return this.t('empreintelive', 'Please enter a description.')
			}
			if (lower.includes('title') && lower.includes('required')) {
				return this.t('empreintelive', 'Please enter a title.')
			}
			// Message technique (validateur Go, trace…) : on ne l'affiche pas tel quel.
			if (lower.includes('validation for') || lower.includes('error:field')) {
				return generic
			}
			return raw
		},

		reset() {
			this.title = ''
			this.description = ''
			this.startTime = ''
			this.endTime = ''
			this.participants = []
			this.attendeeOptions = []
		},
	},
}

/**
 * Convertit une valeur d'input datetime-local (heure locale) en ISO8601 UTC.
 *
 * @param {string} local - Valeur d'un input datetime-local (heure locale).
 */
function toIso(local) {
	if (!local) {
		return null
	}
	return new Date(local).toISOString()
}
</script>

<style scoped lang="scss">
.ec-create {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 500px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.ec-dates {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.ec-date {
	display: flex;
	flex-direction: column;
	gap: 4px;
	// Assez large pour afficher date + heure en entier ; sinon les deux champs
	// passent l'un sous l'autre (wrap) plutôt que de tronquer l'heure.
	flex: 1 1 240px;
	min-width: 240px;

	input {
		width: 100%;
		box-sizing: border-box;
		padding: 8px;
		border: 1px solid var(--color-border-dark);
		border-radius: var(--border-radius);
		background: var(--color-main-background);
		color: var(--color-main-text);
	}
}

.ec-attendees {
	display: flex;
	flex-direction: column;
	gap: 4px;

	&__label {
		font-weight: 500;
	}

	:deep(.v-select) {
		width: 100%;
	}
}
</style>
