<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Carte d'une réunion EMPREINTE Live. Deux formes :
  -  - détaillée, pour le créateur : titre, horaires, état ;
  -  - minimale, pour tout autre lecteur : aucun détail, seulement « Rejoindre ».
  - Le serveur décide de la forme ; la carte n'affiche que ce qu'il envoie.
  -->
<template>
	<div class="el-card" :class="['el-card--' + status, { 'el-card--beside-options': besideOptions }]">
		<div class="el-card__icon">
			<VideoIcon :size="24" />
		</div>
		<div class="el-card__body">
			<div class="el-card__head">
				<span class="el-card__title">{{ title }}</span>
				<span v-if="status === 'live'" class="el-card__badge el-card__badge--live">
					{{ t('empreintelive', 'In progress') }}
				</span>
				<span v-else-if="status === 'past'" class="el-card__badge">
					{{ t('empreintelive', 'Ended') }}
				</span>
			</div>
			<span v-if="when" class="el-card__when">{{ when }}</span>
			<span v-else class="el-card__when">{{ t('empreintelive', 'Video meeting') }}</span>
			<p v-if="meeting.organizerLinkShared" class="el-card__warning">
				{{ t('empreintelive', 'This is your organizer link: anyone who opens it joins as organizer.') }}
			</p>
		</div>
		<a
			v-if="meeting.joinUrl && status !== 'past'"
			class="el-card__join"
			:class="{ 'el-card__join--primary': status === 'live' }"
			:href="meeting.joinUrl"
			target="_blank"
			rel="noopener noreferrer">
			{{ t('empreintelive', 'Join') }}
		</a>
	</div>
</template>

<script>
import { getCanonicalLocale } from '@nextcloud/l10n'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import { meetingStatus, meetingWhen } from '../utils/meetings.js'

export default {
	name: 'MeetingReferenceWidget',
	components: {
		VideoIcon,
	},

	props: {
		/** Objet riche envoyé par MeetingReferenceProvider. */
		meeting: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			// Rafraîchi chaque minute : la carte passe « en cours » puis
			// « terminée » sans recharger la conversation. L'état est calculé ici,
			// pas côté serveur, parce que la carte résolue est mise en cache.
			now: Date.now(),
			clock: null,
			// Dans un bloc d'aperçu de Text, l'éditeur pose son bouton d'options
			// (⋮, fond opaque) sur le coin supérieur droit de la carte.
			besideOptions: false,
		}
	},

	computed: {
		title() {
			return this.meeting.detailed && this.meeting.title
				? this.meeting.title
				: this.t('empreintelive', 'EMPREINTE Live meeting')
		},

		status() {
			return this.meeting.detailed ? meetingStatus(this.meeting, this.now) : 'unknown'
		},

		when() {
			return this.meeting.detailed ? meetingWhen(this.meeting, this.now, this.t, getCanonicalLocale()) : ''
		},
	},

	mounted() {
		// Simple courtoisie de mise en page : sans cet attribut, la carte garde
		// sa forme normale. Aucun autre lien avec Text.
		this.besideOptions = !!this.$el.closest?.('[data-text-el="preview"]')
		this.clock = setInterval(() => {
			this.now = Date.now()
		}, 60000)
	},

	beforeUnmount() {
		clearInterval(this.clock)
	},
}
</script>

<style scoped lang="scss">
.el-card {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	padding: 12px;
	box-sizing: border-box;
	white-space: normal;

	&__icon {
		display: flex;
		flex-shrink: 0;
		align-items: center;
		justify-content: center;
		width: 44px;
		height: 44px;
		border-radius: var(--border-radius-large);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}

	&__body {
		display: flex;
		flex: 1;
		flex-direction: column;
		min-width: 0;
	}

	&__head {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__title {
		overflow: hidden;
		font-weight: bold;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__badge {
		flex-shrink: 0;
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;

		&--live {
			background: var(--color-success);
			color: var(--color-primary-element-text);
		}
	}

	&__when {
		color: var(--color-text-maxcontrast);
	}

	&__warning {
		margin: 4px 0 0;
		color: var(--color-warning-text);
	}

	// Lien natif plutôt que NcButton : le bundle est chargé sur chaque page de
	// Talk et de Text, il doit rester léger.
	&__join {
		display: inline-flex;
		flex-shrink: 0;
		align-items: center;
		justify-content: center;
		box-sizing: border-box;
		height: var(--default-clickable-area, 34px);
		// La carte s'affiche dans des éditeurs qui stylent leurs propres liens :
		// Text impose « div.ProseMirror a { padding: 0.5em 0; color; underline } »,
		// plus spécifique que cette règle. Le bouton fixe donc les siens.
		padding: 0 16px !important;
		border-radius: var(--border-radius-element, var(--border-radius-pill));
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text) !important;
		font-weight: bold;
		line-height: 1;
		text-decoration: none !important;
		white-space: nowrap;

		&:hover,
		&:focus-visible {
			background: var(--color-primary-element-light-hover);
		}

		&--primary {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text) !important;

			&:hover,
			&:focus-visible {
				background: var(--color-primary-element-hover);
			}
		}
	}

	&--past {
		opacity: 0.8;
	}

	// Place du bouton d'options de Text (12px du bord + sa largeur) : le bouton
	// « Rejoindre » reste entièrement visible à sa gauche.
	&--beside-options {
		padding-inline-end: calc(12px + var(--default-clickable-area, 34px) + 8px);
	}
}
</style>
