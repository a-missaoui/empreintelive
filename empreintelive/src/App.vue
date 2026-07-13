<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Coquille de la page d'app plein écran (menu d'apps / top-bar). Réutilise le
  - même contenu que la section « Paramètres personnels » (Settings.vue).
-->
<template>
	<NcContent appName="empreintelive">
		<NcAppContent>
			<Settings />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import Settings from './views/Settings.vue'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		Settings,
	},
}
</script>
