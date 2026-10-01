<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - « Inviter les membres de la conversation » : ouvert depuis le lien publié par
  - le bot Talk (?live=<id>&talk=<token>).
  -
  - La liste vient de Talk, lue avec les droits de l'utilisateur : ce sont les
  - membres réels du salon, et rien d'autre. Tous sont cochés ; l'utilisateur
  - relit la liste avant l'envoi des invitations.
  -->
<template>
	<NcDialog
		:name="t('empreintelive', 'Invite the members of the conversation')"
		size="normal"
		@closing="$emit('close')">
		<div class="ec-invite">
			<NcLoadingIcon v-if="loading" :size="32" />

			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcEmptyContent
				v-else-if="members.length === 0"
				:name="t('empreintelive', 'Nobody to invite')"
				:description="t('empreintelive', 'The conversation has no other member with an email address.')" />

			<template v-else>
				<p>{{ t('empreintelive', 'They will receive an invitation to the meeting by email.') }}</p>
				<ul class="ec-invite__list">
					<li v-for="m in members" :key="m.key">
						<NcCheckboxRadioSwitch v-model="selected" :value="m.key" type="checkbox">
							{{ m.name }}
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
			</template>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('empreintelive', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || selected.length === 0"
				@click="invite">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ n('empreintelive', 'Invite %n person', 'Invite %n people', selected.length) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import api from '../services/api.js'
import talk from '../services/talk.js'
import { invitableMembers } from '../utils/talk.js'

export default {
	name: 'InviteMembersDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		liveId: {
			type: String,
			required: true,
		},

		/** Jeton de la conversation Talk. */
		token: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			busy: false,
			error: '',
			members: [],
			selected: [],
		}
	},

	async mounted() {
		try {
			this.members = invitableMembers(await talk.participants(this.token), getCurrentUser()?.uid)
			this.selected = this.members.map((m) => m.key)
		} catch {
			// Talk désactivé, conversation supprimée, ou utilisateur qui n'en est pas membre.
			this.error = this.t('empreintelive', 'The members of this conversation could not be read. You must be a member of the conversation.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		async invite() {
			const chosen = this.members.filter((m) => this.selected.includes(m.key))
			this.busy = true
			try {
				const { invited, withoutEmail } = await api.inviteMembers(this.liveId, {
					userIds: chosen.filter((m) => m.userId).map((m) => m.userId),
					emails: chosen.filter((m) => m.email).map((m) => m.email),
				})
				if (invited > 0) {
					showSuccess(this.n('empreintelive', '%n person invited.', '%n people invited.', invited))
				}
				if (withoutEmail > 0) {
					showWarning(this.n('empreintelive', '%n member has no email address and was not invited.', '%n members have no email address and were not invited.', withoutEmail))
				}
				if (invited === 0 && withoutEmail === 0) {
					showError(this.t('empreintelive', 'The invitations could not be sent.'))
					return
				}
				this.$emit('close')
			} catch (e) {
				showError(e?.response?.status === 403
					? this.t('empreintelive', 'Only the creator of the meeting can invite participants.')
					: this.t('empreintelive', 'The invitations could not be sent.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.ec-invite {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 80px;

	&__list {
		max-height: 50vh;
		margin: 0;
		padding: 0;
		overflow-y: auto;
		list-style: none;
	}
}
</style>
