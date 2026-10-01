/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Carte de réunion, affichée par Nextcloud sous un lien EMPREINTE collé dans
 * Talk, Text, Deck, Collectives ou un commentaire de fichier ; et entrée
 * « EMPREINTE Live » du sélecteur de liens, qui insère le lien d'une réunion.
 *
 * Le registre des widgets est global (window._vue_richtext_widgets) : Talk et
 * Text, qui embarquent leur propre copie de @nextcloud/vue, y trouvent la carte
 * inscrite ici. Chaque carte est une petite app Vue, démontée avec son élément.
 *
 * Même principe pour le sélecteur (window._vue_richtext_custom_picker_elements).
 * Sa fenêtre embarque le formulaire de création : elle est chargée à la demande,
 * à l'ouverture, pour que ce bundle reste léger.
 *
 * On écrit directement dans ces registres, comme le font registerWidget() et
 * registerCustomPickerElement() de
 * @nextcloud/vue : c'est le contrat partagé par toutes les apps, quelle que soit
 * leur version de la bibliothèque. Importer registerWidget() embarquerait tout le
 * sélecteur de liens (axios, NcSelect, NcModal…) dans un bundle chargé sur
 * chaque page de Talk et de Text.
 */
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import { createApp } from 'vue'
import MeetingReferenceWidget from './components/MeetingReferenceWidget.vue'

// Même correctif que pour la page de l'app : l'installation peut être dans
// custom_apps/, où le chemin figé par le bundler ne mène nulle part.
// eslint-disable-next-line no-undef -- variable injectée par webpack
__webpack_public_path__ = generateFilePath('empreintelive', '', 'js/')

// Doivent rester identiques à MeetingReferenceProvider::RICH_OBJECT_TYPE et
// MeetingReferenceProvider::PICKER_ID.
const RICH_OBJECT_TYPE = 'empreintelive_meeting'
const PICKER_ID = 'empreintelive'

const apps = new WeakMap()

window._vue_richtext_widgets ??= {}
window._vue_richtext_widgets[RICH_OBJECT_TYPE] ??= {
	id: RICH_OBJECT_TYPE,
	// Carte statique : rendue d'emblée, sans bouton « Enable interactive view ».
	hasInteractiveView: false,
	fullWidth: false,
	callback(el, { richObject }) {
		const app = createApp(MeetingReferenceWidget, { meeting: richObject })
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
		app.mount(el)
		apps.set(el, app)
	},
	onDestroy(el) {
		apps.get(el)?.unmount()
		apps.delete(el)
	},
}

window._vue_richtext_custom_picker_elements ??= {}
window._vue_richtext_custom_picker_elements[PICKER_ID] ??= {
	id: PICKER_ID,
	size: 'normal',
	// Le sélecteur attend { element, object } ; « submit » sur l'élément insère
	// le lien reçu dans le message.
	async callback(el) {
		const { default: MeetingPicker } = await import(/* webpackChunkName: 'meeting-picker' */ './components/MeetingPicker.vue')
		const app = createApp(MeetingPicker, {
			onSubmit: (link) => el.dispatchEvent(new CustomEvent('submit', { detail: link })),
		})
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
		app.mount(el)
		apps.set(el, app)
		return { element: el, object: app }
	},
	onDestroy(el) {
		apps.get(el)?.unmount()
		apps.delete(el)
	},
}
