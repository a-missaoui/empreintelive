<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Liste des visioconférences existantes, avec suppression.
-->
<template>
	<div class="ec-list">
		<div class="ec-list__head">
			<h4>{{ t('empreintelive', 'Mes visioconférences') }}</h4>
			<NcButton variant="tertiary" :aria-label="t('empreintelive', 'Rafraîchir')" @click="reload">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading && !meetings.length" :size="24" />

		<NcEmptyContent
			v-else-if="!meetings.length"
			:name="t('empreintelive', 'Aucune visioconférence')"
			:description="t('empreintelive', 'Créez votre première visio ci-dessus.')">
			<template #icon>
				<VideoIcon />
			</template>
		</NcEmptyContent>

		<ul v-else class="ec-list__items">
			<li v-for="m in meetings" :key="meetingId(m)" class="ec-item">
				<div class="ec-item__info">
					<span class="ec-item__title">{{ meetingTitle(m) }}</span>
					<span v-if="meetingDate(m)" class="ec-item__date">{{ meetingDate(m) }}</span>
				</div>
				<div class="ec-item__actions">
					<NcButton
						v-if="meetingUrl(m)"
						variant="secondary"
						:href="meetingUrl(m)"
						target="_blank">
						{{ t('empreintelive', 'Rejoindre') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('empreintelive', 'Supprimer')"
						@click="remove(m)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import api from '../services/api.js'

export default {
	name: 'MeetingList',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		Refresh,
		Delete,
		VideoIcon,
	},

	data() {
		return {
			loading: false,
			meetings: [],
		}
	},

	async mounted() {
		await this.reload()
	},

	methods: {
		async reload() {
			this.loading = true
			try {
				this.meetings = await api.listMeetings()
			} catch {
				showError(this.t('empreintelive', 'Impossible de charger les visioconférences.'))
			} finally {
				this.loading = false
			}
		},

		async remove(m) {
			const id = this.meetingId(m)
			if (!id) {
				return
			}
			try {
				await api.deleteLive(id)
				this.meetings = this.meetings.filter((x) => this.meetingId(x) !== id)
				showSuccess(this.t('empreintelive', 'Visioconférence supprimée.'))
			} catch {
				showError(this.t('empreintelive', 'Échec de la suppression.'))
			}
		},

		// Accès défensif : la forme exacte de l'objet dépend de l'API EMPREINTE.
		meetingId(m) {
			return m?.id ?? m?.liveId ?? m?.live_id ?? null
		},

		meetingTitle(m) {
			return m?.title ?? m?.name ?? this.t('empreintelive', 'Sans titre')
		},

		meetingUrl(m) {
			return m?.url ?? m?.liveUrl ?? m?.join_url ?? m?.participant_url ?? null
		},

		meetingDate(m) {
			const raw = m?.dateStartDiffusion ?? m?.startTime ?? m?.start ?? null
			if (!raw) {
				return ''
			}
			const d = new Date(raw)
			if (isNaN(d)) {
				return ''
			}
			// Format FR + 24 h, cohérent avec le formulaire de création.
			return new Intl.DateTimeFormat('fr', {
				day: '2-digit',
				month: 'short',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
				hour12: false,
			}).format(d)
		},
	},
}
</script>

<style scoped lang="scss">
.ec-list {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.ec-list__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 4px;
}

.ec-list__items {
	list-style: none;
	padding: 0;
	margin: 0;
}

.ec-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);

	&__info {
		display: flex;
		flex-direction: column;
	}

	&__title {
		font-weight: 600;
	}

	&__date {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__actions {
		display: flex;
		gap: 4px;
	}
}
</style>
