<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Formulaire de connexion / création de compte EMPREINTE.
  - Émet `connected` une fois le flux OAuth abouti côté serveur.
-->
<template>
	<div class="ec-conn">
		<div class="ec-tabs">
			<NcButton
				:variant="mode === 'login' ? 'primary' : 'tertiary'"
				@click="mode = 'login'">
				{{ t('empreintelive', 'Connexion') }}
			</NcButton>
			<NcButton
				:variant="mode === 'register' ? 'primary' : 'tertiary'"
				@click="mode = 'register'">
				{{ t('empreintelive', 'Inscription') }}
			</NcButton>
		</div>

		<form class="ec-form" @submit.prevent="submit">
			<NcTextField
				v-if="mode === 'register'"
				v-model="name"
				:label="t('empreintelive', 'Nom')"
				autocomplete="name" />

			<NcTextField
				v-model="email"
				type="email"
				:label="t('empreintelive', 'Adresse e-mail')"
				autocomplete="email"
				required />

			<NcPasswordField
				v-model="password"
				:label="t('empreintelive', 'Mot de passe')"
				autocomplete="current-password"
				required />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcButton
				variant="primary"
				type="submit"
				:disabled="busy || !email || !password">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ mode === 'login'
					? t('empreintelive', 'Se connecter')
					: t('empreintelive', 'Créer le compte') }}
			</NcButton>
		</form>

		<ConsentDialog
			:show="showConsent"
			:scopes="scopes"
			:busy="approving"
			@approve="approveConsent"
			@cancel="cancelConsent" />
	</div>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConsentDialog from './ConsentDialog.vue'
import api from '../services/api.js'

export default {
	name: 'ConnectionForm',
	components: {
		NcButton,
		NcTextField,
		NcPasswordField,
		NcNoteCard,
		NcLoadingIcon,
		ConsentDialog,
	},

	emits: ['connected'],
	data() {
		return {
			mode: 'login',
			name: '',
			email: '',
			password: '',
			busy: false,
			error: '',
			showConsent: false,
			scopes: [],
			approving: false,
		}
	},

	methods: {
		async submit() {
			this.busy = true
			this.error = ''
			try {
				if (this.mode === 'register') {
					await api.register({ email: this.email, password: this.password, name: this.name })
					showSuccess(this.t('empreintelive', 'Compte créé.'))
				}
				// login puis étape 1/2 du flux OAuth : demande de consentement.
				await api.login({ email: this.email, password: this.password })
				const res = await api.authorize({ email: this.email })
				this.scopes = res?.scopes || []
				this.password = ''
				this.showConsent = true
			} catch (e) {
				this.error = this.extractError(e)
			} finally {
				this.busy = false
			}
		},

		// Étape 2/2 : l'utilisateur a autorisé l'accès.
		async approveConsent() {
			this.approving = true
			this.error = ''
			try {
				await api.approve()
				this.showConsent = false
				this.$emit('connected')
			} catch (e) {
				this.error = this.extractError(e)
				this.showConsent = false
			} finally {
				this.approving = false
			}
		},

		// Refus : on révoque le token de session pour ne pas rester à moitié connecté.
		async cancelConsent() {
			this.showConsent = false
			this.scopes = []
			try {
				await api.logout()
			} catch {
				// best-effort
			}
		},

		extractError(e) {
			return e?.response?.data?.error
				|| this.t('empreintelive', 'Échec de la connexion. Vérifiez vos identifiants.')
		},
	},
}
</script>

<style scoped lang="scss">
.ec-tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.ec-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 400px;
}
</style>
