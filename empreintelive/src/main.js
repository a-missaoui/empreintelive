import { translatePlural as n, translate as t } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Point d'entrée du bundle chargé dans la page d'app plein écran (top-bar).
 * Vue 3 (aligné sur @nextcloud/vue 9).
 */
import { createApp } from 'vue'
import App from './App.vue'

const app = createApp(App)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#empreintelive-app')
