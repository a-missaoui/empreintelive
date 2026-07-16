<!--
  - SPDX-FileCopyrightText: 2026 EMPREINTE
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Coquille de la page d'app plein écran (menu d'apps / top-bar) : connexion du
  - compte EMPREINTE puis création / gestion des réunions (MeetingsPage.vue).
-->
<template>
	<NcContent appName="empreintelive">
		<NcAppContent>
			<MeetingsPage />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import MeetingsPage from './views/MeetingsPage.vue'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		MeetingsPage,
	},
}
</script>
