/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Point d'entrée du bundle chargé dans la page d'app plein écran (top-bar).
 * Vue 3 (aligné sur @nextcloud/vue 9).
 */
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import { createApp } from 'vue'
import App from './App.vue'

// @nextcloud/webpack-vue-config code en dur « /apps/<app>/js/ » comme chemin
// public. Or une app installée dans custom_apps/ est servie depuis
// « /custom_apps/<app>/js/ » : tout chunk chargé à la demande (le sélecteur de
// fichiers de @nextcloud/dialogs, par exemple) partait donc en 404.
//
// generateFilePath rend le vrai chemin, quel que soit le répertoire d'apps où
// l'instance a installé l'application. L'affectation s'exécute au chargement du
// bundle, donc bien avant le premier import dynamique (déclenché par un clic).
// eslint-disable-next-line no-undef -- variable injectee par webpack
__webpack_public_path__ = generateFilePath('empreintelive', '', 'js/')

const app = createApp(App)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#empreintelive-app')
