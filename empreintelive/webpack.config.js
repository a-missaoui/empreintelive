/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Basé sur la config Vue standard de Nextcloud. On déclare une seule entrée
 * (la page de paramètres) dont le bundle sortira dans js/.
 */
const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	// Page d'app plein écran (top-bar) → js/empreintelive-main.js
	// (chargé par PageController::index() via Util::addScript).
	// webpack-vue-config préfixe déjà l'app id → produit js/empreintelive-main.js.
	main: path.join(__dirname, 'src', 'main.js'),
}

webpackConfig.resolve = {
	...webpackConfig.resolve,
	alias: {
		...webpackConfig.resolve.alias,
		'@': path.resolve(__dirname, 'src'),
	},
}

module.exports = webpackConfig
