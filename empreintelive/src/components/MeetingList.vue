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
				v-for="m in visibleMeetings"
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
							v-if="participantLink(m)"
							:closeAfterClick="true"
							@click="copyLink(m)">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('empreintelive', 'Copy link') }}
						</NcActionButton>
						<template v-if="talkAvailable && participantLink(m)">
							<NcActionButton :closeAfterClick="true" @click="createConversation(m)">
								<template #icon>
									<ChatPlus :size="20" />
								</template>
								{{ t('empreintelive', 'Create a Talk conversation') }}
							</NcActionButton>
							<NcActionButton :closeAfterClick="true" @click="sharing = participantLink(m)">
								<template #icon>
									<Send :size="20" />
								</template>
								{{ t('empreintelive', 'Post in a conversation') }}
							</NcActionButton>
						</template>
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

		<p v-if="meetings.length && !visibleMeetings.length" class="ec-list__none">
			{{ t('empreintelive', 'No upcoming meetings') }}
		</p>

		<!--
			Terminées repliées : la liste reste courte, et ce qu'on cherche (en
			cours, à venir) est en haut. Masqué s'il n'y en a aucune.
		-->
		<NcButton
			v-if="pastMeetings.length"
			variant="tertiary"
			class="ec-list__past-toggle"
			@click="showPast = !showPast">
			<template #icon>
				<ChevronUp v-if="showPast" :size="20" />
				<ChevronDown v-else :size="20" />
			</template>
			{{ showPast
				? t('empreintelive', 'Hide ended meetings')
				: n('empreintelive', 'Show %n ended meeting', 'Show %n ended meetings', pastMeetings.length) }}
		</NcButton>

		<ShareInConversationDialog
			v-if="sharing"
			:link="sharing"
			@close="sharing = null" />
	</div>
</template>

<script>
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ChatPlus from 'vue-material-design-icons/ChatPlus.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Send from 'vue-material-design-icons/Send.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import ShareInConversationDialog from './ShareInConversationDialog.vue'
import api from '../services/api.js'
import talk from '../services/talk.js'
import { endOf, meetingStatus, meetingWhen, participantLink, sortMeetings, startOf } from '../utils/meetings.js'

export default {
	name: 'MeetingList',
	components: {
		NcActionButton,
		NcActionLink,
		NcActions,
		NcActionSeparator,
		NcButton,
		ChatPlus,
		ChevronDown,
		ChevronUp,
		ContentCopy,
		NcLoadingIcon,
		NcEmptyContent,
		Refresh,
		Send,
		ShareInConversationDialog,
		Delete,
		VideoIcon,
		OpenInNew,
	},

	emits: ['open', 'notConnected'],

	data() {
		return {
			loading: false,
			meetings: [],
			// Rafraîchi chaque minute : une réunion passe « en cours » puis
			// « terminée » sans qu'on recharge la page.
			now: Date.now(),
			clock: null,
			// Talk activé pour l'utilisateur : sans lui, aucun bouton Talk.
			talkAvailable: loadState('empreintelive', 'talk-available', false),
			// Lien participant en cours de publication dans une conversation.
			sharing: null,
			// Réunions terminées dépliées.
			showPast: false,
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

		pastMeetings() {
			return this.sortedMeetings.filter((m) => meetingStatus(m, this.now) === 'past')
		},

		visibleMeetings() {
			return this.showPast
				? this.sortedMeetings
				: this.sortedMeetings.filter((m) => meetingStatus(m, this.now) !== 'past')
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
			} catch (e) {
				if (e?.response?.data?.error === 'not_connected') {
					this.$emit('notConnected', e.response.data.message ?? '')
					return
				}
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
				// Lien participant : c'est le lien qu'on colle dans un message ou une
				// conversation, il ne doit pas donner les droits d'organisateur.
				await navigator.clipboard.writeText(participantLink(m))
				showSuccess(this.t('empreintelive', 'Link copied.'))
			} catch {
				showError(this.t('empreintelive', 'Could not copy.'))
			}
		},

		/**
		 * Conversation Talk de la réunion : créée au nom de l'utilisateur (Talk
		 * applique ses restrictions de création), avec le lien participant en
		 * premier message, puis ouverte dans un nouvel onglet.
		 *
		 * @param {object} m - La visioconférence.
		 */
		async createConversation(m) {
			try {
				const room = await talk.createRoom(this.meetingTitle(m))
				await talk.postMessage(room.token, participantLink(m))
				window.open(generateUrl('/call/{token}', { token: room.token }), '_blank')
			} catch {
				showError(this.t('empreintelive', 'The Talk conversation could not be created.'))
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

		participantLink(m) {
			return participantLink(m)
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

		meetingWhen(m) {
			return meetingWhen(m, this.now, this.t, getCanonicalLocale())
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

.ec-list__none {
	margin: 8px 0;
	color: var(--color-text-maxcontrast);
}

.ec-list__past-toggle {
	margin-top: 8px;
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
