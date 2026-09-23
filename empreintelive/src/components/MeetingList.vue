<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Liste des visioconférences existantes, avec suppression.
-->
<template>
	<div class="ec-list">
		<div class="ec-list__head">
			<h4>{{ t('empreintelive', 'My video meetings') }}</h4>
			<NcButton variant="tertiary" :aria-label="t('empreintelive', 'Refresh')" @click="reload">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading && !meetings.length" :size="24" />

		<NcEmptyContent
			v-else-if="!meetings.length"
			:name="t('empreintelive', 'No video meetings')"
			:description="t('empreintelive', 'Create your first meeting above.')">
			<template #icon>
				<VideoIcon />
			</template>
		</NcEmptyContent>

		<ul v-else class="ec-list__items">
			<li
				v-for="m in sortedMeetings"
				:key="meetingId(m)"
				class="ec-item"
				:class="'ec-item--' + status(m)">
				<div class="ec-item__info">
					<div class="ec-item__head">
						<span class="ec-item__title">{{ meetingTitle(m) }}</span>
						<span v-if="status(m) === 'live'" class="ec-item__badge ec-item__badge--live">
							{{ t('empreintelive', 'In progress') }}
						</span>
						<span v-else-if="status(m) === 'past'" class="ec-item__badge">
							{{ t('empreintelive', 'Ended') }}
						</span>
					</div>
					<span v-if="meetingWhen(m)" class="ec-item__date">{{ meetingWhen(m) }}</span>
				</div>

				<div class="ec-item__actions">
					<!-- Une seule action visible : la plus probable. Le reste est rangé
					     dans le menu, et la suppression n'est plus à un clic du pouce. -->
					<NcButton
						v-if="meetingUrl(m)"
						:variant="status(m) === 'live' ? 'primary' : 'secondary'"
						@click="$emit('open', m)">
						{{ status(m) === 'live' ? t('empreintelive', 'Join') : t('empreintelive', 'Open') }}
					</NcButton>

					<NcActions :aria-label="t('empreintelive', 'More actions')">
						<NcActionLink
							v-if="meetingUrl(m)"
							:href="meetingUrl(m)"
							target="_blank"
							:closeAfterClick="true">
							<template #icon>
								<OpenInNew :size="20" />
							</template>
							{{ t('empreintelive', 'Open in a new tab') }}
						</NcActionLink>
						<NcActionButton
							v-if="meetingUrl(m)"
							:closeAfterClick="true"
							@click="copyLink(m)">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('empreintelive', 'Copy link') }}
						</NcActionButton>
						<NcActionSeparator />
						<NcActionButton :closeAfterClick="true" @click="confirmRemove(m)">
							<template #icon>
								<Delete :size="20" />
							</template>
							{{ t('empreintelive', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import { getCanonicalLocale } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import api from '../services/api.js'
import { endOf, meetingStatus, sortMeetings, startOf } from '../utils/meetings.js'

export default {
	name: 'MeetingList',
	components: {
		NcActionButton,
		NcActionLink,
		NcActions,
		NcActionSeparator,
		NcButton,
		ContentCopy,
		NcLoadingIcon,
		NcEmptyContent,
		Refresh,
		Delete,
		VideoIcon,
		OpenInNew,
	},

	emits: ['open'],

	data() {
		return {
			loading: false,
			meetings: [],
			// Rafraîchi chaque minute : une réunion passe « en cours » puis
			// « terminée » sans qu'on recharge la page.
			now: Date.now(),
			clock: null,
		}
	},

	computed: {
		/**
		 * En cours d'abord, puis à venir (la plus proche en tête), puis terminées
		 * (la plus récente en tête). C'est l'ordre dans lequel on les cherche.
		 */
		sortedMeetings() {
			return sortMeetings(this.meetings, this.now)
		},
	},

	async mounted() {
		await this.reload()
		this.clock = setInterval(() => {
			this.now = Date.now()
		}, 60000)
	},

	beforeUnmount() {
		clearInterval(this.clock)
	},

	methods: {
		async reload() {
			this.loading = true
			try {
				this.meetings = await api.listMeetings()
			} catch {
				showError(this.t('empreintelive', 'Could not load the video meetings.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Supprimer une visio la supprime aussi côté EMPREINTE : ce n'est pas un
		 * geste qu'on fait par erreur en visant le bouton voisin.
		 *
		 * @param {object} m - La visioconférence.
		 */
		async confirmRemove(m) {
			const ok = await showConfirmation({
				name: this.t('empreintelive', 'Delete the video meeting?'),
				text: this.t('empreintelive', '"{title}" will be deleted for all participants.', { title: this.meetingTitle(m) }),
				labelConfirm: this.t('empreintelive', 'Delete'),
				labelReject: this.t('empreintelive', 'Cancel'),
				severity: 'warning',
			})
			if (ok) {
				await this.remove(m)
			}
		},

		async copyLink(m) {
			try {
				await navigator.clipboard.writeText(this.meetingUrl(m))
				showSuccess(this.t('empreintelive', 'Link copied.'))
			} catch {
				showError(this.t('empreintelive', 'Could not copy.'))
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
				showSuccess(this.t('empreintelive', 'Video meeting deleted.'))
			} catch {
				showError(this.t('empreintelive', 'Could not delete.'))
			}
		},

		// Accès défensif : la forme exacte de l'objet dépend de l'API EMPREINTE.
		meetingId(m) {
			return m?.id ?? m?.liveId ?? m?.live_id ?? null
		},

		meetingTitle(m) {
			return m?.title ?? m?.name ?? this.t('empreintelive', 'Untitled')
		},

		meetingUrl(m) {
			return m?.url ?? m?.liveUrl ?? m?.join_url ?? m?.participant_url ?? null
		},

		startOf(m) {
			return startOf(m)
		},

		endOf(m) {
			return endOf(m)
		},

		status(m) {
			return meetingStatus(m, this.now)
		},

		/**
		 * « Aujourd'hui, 10:35 – 11:35 », « Demain, 09:00 – 10:00 »,
		 * « 18 sept. 2026, 10:35 – 11:35 ».
		 *
		 * @param {object} m - La visioconférence.
		 * @return {string} Le libellé, ou une chaîne vide.
		 */
		meetingWhen(m) {
			const start = this.startOf(m)
			if (!start) {
				return ''
			}
			const time = new Intl.DateTimeFormat(getCanonicalLocale(), { hour: '2-digit', minute: '2-digit', hour12: false })
			const end = this.endOf(m)
			const range = end ? `${time.format(start)} – ${time.format(end)}` : time.format(start)

			const day = new Date(start)
			day.setHours(0, 0, 0, 0)
			const today = new Date(this.now)
			today.setHours(0, 0, 0, 0)
			const diff = Math.round((day - today) / 86400000)

			if (diff === 0) {
				return this.t('empreintelive', 'Today, {range}', { range })
			}
			if (diff === 1) {
				return this.t('empreintelive', 'Tomorrow, {range}', { range })
			}
			if (diff === -1) {
				return this.t('empreintelive', 'Yesterday, {range}', { range })
			}
			const date = new Intl.DateTimeFormat(getCanonicalLocale(), { day: 'numeric', month: 'short', year: 'numeric' })
			return `${date.format(start)}, ${range}`
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
	padding: 10px 8px;
	border-bottom: 1px solid var(--color-border);
	border-radius: var(--border-radius);

	&:last-child {
		border-bottom: none;
	}

	&:hover {
		background: var(--color-background-hover);
	}

	// Une réunion terminée reste consultable, mais ne doit pas attirer l'œil.
	&--past {
		opacity: 0.6;
	}

	// L'info prend toute la largeur disponible ; les actions gardent la leur.
	// Sans min-width: 0, un titre long repousse les boutons au lieu de se couper.
	&__info {
		display: flex;
		flex-direction: column;
		gap: 2px;
		flex: 1 1 auto;
		min-width: 0;
	}

	&__head {
		display: flex;
		align-items: center;
		gap: 8px;
		min-width: 0;
	}

	&__title {
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		min-width: 0;
	}

	&__badge {
		flex: 0 0 auto;
		font-size: 0.75em;
		font-weight: 600;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 999px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		white-space: nowrap;

		&--live {
			background: var(--color-success);
			color: var(--color-primary-element-text, #fff);
		}
	}

	&__date {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__actions {
		display: flex;
		align-items: center;
		gap: 2px;
		flex: 0 0 auto;
	}
}
</style>
