<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Encart « Créer une réunion depuis Talk » sur la page de l'app.
  -
  - Le bot Talk est installé avec l'app mais reste inactif tant qu'un modérateur
  - ne l'a pas activé dans un salon, et rien dans Talk ne l'annonce. C'est ici,
  - là où l'utilisateur gère ses réunions, qu'on lui dit que le bot existe,
  - comment l'activer et quelle commande taper.
  -
  - Masquable : le choix est gardé dans le navigateur, sans réglage côté serveur.
  -->
<template>
	<NcNoteCard v-if="visible" type="info" class="ec-talk-hint">
		<p class="ec-talk-hint__title">
			{{ t('empreintelive', 'Create meetings from a Talk conversation') }}
		</p>
		<ol class="ec-talk-hint__steps">
			<li>
				{{ t('empreintelive', 'In the conversation, a moderator opens "···", then "Conversation settings", then "Bots", and enables "EMPREINTE Live".') }}
			</li>
			<li>
				{{ t('empreintelive', 'Anyone in the conversation with a connected EMPREINTE account can then type:') }}
				<code class="ec-talk-hint__command">{{ command }}</code>
			</li>
		</ol>
		<p class="ec-talk-hint__more">
			{{ t('empreintelive', 'The bot posts the meeting link in the conversation. Type "/empreinte help" there to see all the options.') }}
		</p>
		<NcButton variant="tertiary" class="ec-talk-hint__dismiss" @click="dismiss">
			{{ t('empreintelive', 'Got it, hide this') }}
		</NcButton>
	</NcNoteCard>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

// Clé de stockage dans le navigateur : l'encart reste masqué une fois lu.
const STORAGE_KEY = 'empreintelive-talk-hint-dismissed'

export default {
	name: 'TalkBotHint',
	components: {
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			visible: !wasDismissed(),
		}
	},

	computed: {
		// Même forme que l'aide du bot (/empreinte help), dans la langue de l'utilisateur.
		command() {
			return '/empreinte ' + this.t('empreintelive', 'Title')
				+ ' [' + this.t('empreintelive', 'tomorrow') + '|DD/MM] [14:30] [45min]'
		},
	},

	methods: {
		dismiss() {
			this.visible = false
			try {
				window.localStorage.setItem(STORAGE_KEY, '1')
			} catch {
				// Stockage indisponible (navigation privée stricte) : l'encart
				// reviendra au prochain chargement, sans autre conséquence.
			}
		},
	},
}

/**
 * @return {boolean} Vrai si l'utilisateur a déjà masqué l'encart dans ce navigateur.
 */
function wasDismissed() {
	try {
		return window.localStorage.getItem(STORAGE_KEY) === '1'
	} catch {
		return false
	}
}
</script>

<style scoped lang="scss">
.ec-talk-hint {
	&__title {
		margin: 0 0 4px;
		font-weight: bold;
	}

	&__steps {
		margin: 0 0 4px;
		padding-inline-start: 20px;
	}

	&__command {
		display: block;
		width: fit-content;
		max-width: 100%;
		margin-top: 4px;
		padding: 2px 8px;
		border-radius: var(--border-radius);
		background: var(--color-background-dark);
		font-family: monospace;
		overflow-wrap: anywhere;
	}

	&__more {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__dismiss {
		margin-top: 4px;
	}
}
</style>
