<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - « Un lien de partage Nextcloud peut être
  - créé et copié depuis l'interface, avec les options de droits standard
  - (lecture seule, mot de passe, expiration) ».
  -
  - Par défaut : un clic, lecture seule. Les options restent repliées.
  - Aucun contrôle de droits ici : le serveur refuse si Nextcloud refuse.
-->
<template>
	<NcDialog
		:name="t('empreintelive', 'Share {file}', { file: item.name })"
		size="normal"
		@closing="$emit('close')">
		<div class="ec-share">
			<NcLoadingIcon v-if="loading" :size="24" />

			<template v-else>
				<!-- Lien existant -->
				<div v-if="share" class="ec-share__result">
					<NcTextField
						:modelValue="share.url"
						:label="t('empreintelive', 'Share link')"
						readonly
						@focus="$event.target.select()" />
					<div class="ec-share__row">
						<NcButton variant="primary" @click="copy">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('empreintelive', 'Copy') }}
						</NcButton>
						<NcButton variant="tertiary" @click="remove">
							{{ t('empreintelive', 'Delete link') }}
						</NcButton>
					</div>
					<p class="ec-share__summary">
						{{ summary }}
					</p>
				</div>

				<!-- Création -->
				<div v-else class="ec-share__form">
					<p class="ec-share__hint">
						{{ t('empreintelive', 'The link will be read-only unless stated otherwise.') }}
						{{ t('empreintelive', 'Anyone with the link can access it, including people without a Nextcloud account.') }}
					</p>

					<NcButton variant="tertiary" @click="showOptions = !showOptions">
						{{ showOptions ? t('empreintelive', 'Hide options') : t('empreintelive', 'Options') }}
					</NcButton>

					<template v-if="showOptions">
						<NcCheckboxRadioSwitch :modelValue="editable" @update:modelValue="editable = $event">
							{{ t('empreintelive', 'Allow editing') }}
						</NcCheckboxRadioSwitch>
						<p v-if="editable" class="ec-share__hint">
							{{ t('empreintelive', 'Participants will be able to upload and edit files.') }}
						</p>
						<NcTextField
							v-model="password"
							type="password"
							autocomplete="new-password"
							:label="t('empreintelive', 'Password (optional)')" />
						<NcTextField
							v-model="expiration"
							type="date"
							:label="t('empreintelive', 'Expiration (optional)')" />
					</template>

					<NcButton variant="primary" :disabled="busy" @click="create">
						{{ t('empreintelive', 'Create link') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import api from '../services/api.js'

export default {
	name: 'ShareDialog',

	components: { NcDialog, NcButton, NcTextField, NcCheckboxRadioSwitch, NcLoadingIcon, ContentCopy },

	props: {
		item: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			busy: false,
			showOptions: false,
			editable: false,
			password: '',
			expiration: '',
			share: null,
		}
	},

	computed: {
		summary() {
			const parts = [
				this.share.editable
					? this.t('empreintelive', 'Editing allowed')
					: this.t('empreintelive', 'Read-only'),
			]
			if (this.share.hasPassword) {
				parts.push(this.t('empreintelive', 'password protected'))
			}
			if (this.share.expiration) {
				parts.push(this.t('empreintelive', 'expires on {date}', { date: this.share.expiration }))
			}
			return parts.join(' · ')
		},
	},

	async mounted() {
		try {
			const existing = await api.shares(this.item.id)
			this.share = existing[0] ?? null
		} catch {
			// Pas de lien lisible : on propose simplement d'en créer un.
		} finally {
			this.loading = false
		}
	},

	methods: {
		async create() {
			this.busy = true
			try {
				this.share = await api.createShare(this.item.id, {
					editable: this.editable,
					password: this.password || null,
					expiration: this.expiration || null,
				})
				showSuccess(this.t('empreintelive', 'Share link created.'))
			} catch (e) {
				showError(this.errorLabel(e))
			} finally {
				this.busy = false
			}
		},

		async remove() {
			try {
				await api.deleteShare(this.share.id)
				this.share = null
				showSuccess(this.t('empreintelive', 'Link deleted.'))
			} catch {
				showError(this.t('empreintelive', 'Could not delete the link.'))
			}
		},

		async copy() {
			try {
				await navigator.clipboard.writeText(this.share.url)
				showSuccess(this.t('empreintelive', 'Link copied.'))
			} catch {
				showError(this.t('empreintelive', 'Could not copy, select the link manually.'))
			}
		},

		// Le serveur renvoie des codes stables, pas des phrases — sauf « hint »,
		// qui vient de Nextcloud et est déjà lisible (politique de mot de passe).
		errorLabel(e) {
			const hint = e?.response?.data?.hint
			if (hint) {
				return hint
			}
			const code = e?.response?.data?.error
			const messages = {
				not_shareable: this.t('empreintelive', 'You are not allowed to share this file.'),
				password_required: this.t('empreintelive', 'This instance requires a password on links.'),
				links_disabled: this.t('empreintelive', 'Link sharing is disabled on this instance.'),
				not_found: this.t('empreintelive', 'File not found.'),
				rejected: this.t('empreintelive', 'Nextcloud refused this share.'),
			}
			return messages[code] ?? this.t('empreintelive', 'Could not create the link.')
		},
	},
}
</script>

<style scoped lang="scss">
.ec-share {
	padding: 8px 4px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 120px;
}

.ec-share__form,
.ec-share__result {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ec-share__row {
	display: flex;
	gap: 8px;
}

.ec-share__hint,
.ec-share__summary {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}
</style>
