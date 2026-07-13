<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Écran de consentement OAuth : l'utilisateur voit les permissions demandées
  - (dérivées des scopes) et autorise explicitement l'accès avant l'obtention des
  - tokens. Émet `approve` sur « Autoriser l'accès », `cancel` sinon.
-->
<template>
	<NcModal
		:show="show"
		size="small"
		:canClose="!busy"
		@close="$emit('cancel')">
		<div class="ec-consent">
			<div class="ec-consent__header">
				<img class="ec-consent__logo" :src="logoUrl" alt="">
				<h2 class="ec-consent__title">
					{{ t('empreintelive', 'EMPREINTE Live') }}
				</h2>
				<p class="ec-consent__subtitle">
					{{ t('empreintelive', 'Accédez à votre compte pour créer des visioconférences') }}
				</p>
			</div>

			<p class="ec-consent__label">
				{{ t('empreintelive', 'Permissions demandées') }}
			</p>
			<ul class="ec-consent__permissions">
				<li v-for="perm in permissions" :key="perm.title" class="ec-consent__permission">
					<CheckCircle :size="20" class="ec-consent__check" />
					<div>
						<strong>{{ perm.title }}</strong>
						<span>{{ perm.description }}</span>
					</div>
				</li>
			</ul>

			<div class="ec-consent__security">
				<ShieldCheck :size="18" />
				<span>{{ t('empreintelive', 'Nous n\'accédons qu\'aux données nécessaires aux visioconférences.') }}</span>
			</div>

			<div class="ec-consent__footer">
				<NcButton :disabled="busy" @click="$emit('cancel')">
					{{ t('empreintelive', 'Annuler') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy" @click="$emit('approve')">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('empreintelive', 'Autoriser l\'accès') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { imagePath } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'

export default {
	name: 'ConsentDialog',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		CheckCircle,
		ShieldCheck,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},

		scopes: {
			type: Array,
			default: () => [],
		},

		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['approve', 'cancel'],
	computed: {
		logoUrl() {
			return imagePath('empreintelive', 'app.svg')
		},

		/** Traduit les scopes techniques en permissions lisibles. */
		permissions() {
			const has = (s) => this.scopes.includes(s)
			const perms = []
			if (has('live:write')) {
				perms.push({
					title: this.t('empreintelive', 'Créer des visioconférences'),
					description: this.t('empreintelive', 'Démarrer des visioconférences depuis vos événements'),
				})
			}
			if (has('live:read')) {
				perms.push({
					title: this.t('empreintelive', 'Voir les visioconférences'),
					description: this.t('empreintelive', 'Consulter vos visioconférences planifiées'),
				})
			}
			if (has('live:update') || has('live:delete')) {
				perms.push({
					title: this.t('empreintelive', 'Gérer les visioconférences'),
					description: this.t('empreintelive', 'Modifier ou supprimer des visioconférences existantes'),
				})
			}
			return perms
		},
	},
}
</script>

<style scoped lang="scss">
.ec-consent {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 24px;

	&__header {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 6px;
	}

	&__logo {
		width: 56px;
		height: 56px;
	}

	&__title {
		margin: 0;
		font-size: 20px;
		font-weight: 700;
	}

	&__subtitle {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__label {
		margin: 0;
		font-size: 12px;
		font-weight: 600;
		letter-spacing: 0.04em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}

	&__permissions {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__permission {
		display: flex;
		align-items: flex-start;
		gap: 12px;

		div {
			display: flex;
			flex-direction: column;
		}

		span {
			color: var(--color-text-maxcontrast);
			font-size: 13px;
		}
	}

	&__check {
		color: var(--color-success);
		flex-shrink: 0;
	}

	&__security {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 10px 12px;
		border-radius: var(--border-radius);
		background: var(--color-background-hover);
		font-size: 13px;
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 4px;
	}
}
</style>
