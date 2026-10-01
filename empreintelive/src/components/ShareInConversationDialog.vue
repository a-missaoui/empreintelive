<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - « Publier dans une conversation » : le lien participant d'une réunion est
  - envoyé dans une conversation Talk choisie, où il s'affiche en carte.
  -
  - Liste et publication passent par l'API de Talk, au nom de l'utilisateur :
  - seules les conversations où il peut écrire sont proposées, et Talk refuse de
  - lui-même toute publication qu'il n'autorise pas.
  -->
<template>
	<NcDialog
		:name="t('empreintelive', 'Post in a conversation')"
		size="normal"
		@closing="$emit('close')">
		<div class="ec-share-talk">
			<NcLoadingIcon v-if="loading" :size="32" />

			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcEmptyContent
				v-else-if="rooms.length === 0"
				:name="t('empreintelive', 'No conversation')"
				:description="t('empreintelive', 'You cannot write in any Talk conversation yet.')" />

			<ul v-else class="ec-share-talk__list">
				<li v-for="room in rooms" :key="room.token">
					<button
						type="button"
						class="ec-share-talk__item"
						:disabled="busy"
						@click="post(room)">
						<span class="ec-share-talk__name">{{ room.displayName || room.name }}</span>
					</button>
				</li>
			</ul>
		</div>
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import talk from '../services/talk.js'
import { writableRooms } from '../utils/talk.js'

export default {
	name: 'ShareInConversationDialog',
	components: {
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** Lien participant de la réunion. */
		link: {
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
			rooms: [],
		}
	},

	async mounted() {
		try {
			this.rooms = writableRooms(await talk.rooms())
		} catch {
			this.error = this.t('empreintelive', 'The Talk conversations could not be loaded.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		async post(room) {
			this.busy = true
			try {
				await talk.postMessage(room.token, this.link)
				showSuccess(this.t('empreintelive', 'Meeting link posted in "{conversation}".', { conversation: room.displayName || room.name }))
				this.$emit('close')
			} catch {
				showError(this.t('empreintelive', 'The link could not be posted in this conversation.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.ec-share-talk {
	min-height: 80px;

	&__list {
		max-height: 50vh;
		margin: 0;
		padding: 0;
		overflow-y: auto;
		list-style: none;
	}

	&__item {
		width: 100%;
		height: auto;
		margin: 0;
		padding: 10px 12px;
		border: none;
		border-radius: var(--border-radius-large);
		background: transparent;
		text-align: start;
		cursor: pointer;

		&:hover:not(:disabled),
		&:focus-visible {
			background: var(--color-background-hover);
		}
	}

	&__name {
		font-weight: bold;
	}
}
</style>
