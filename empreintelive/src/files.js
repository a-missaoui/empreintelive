/**
 * SPDX-FileCopyrightText: 2026 EMPREINTE
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Action « Present in a meeting » dans le menu contextuel de l'app Files.
 *
 * C'est le point d'entrée : on part d'un document, pas d'une réunion. La visio
 * est créée, le document y est chargé, et les participants proposés sont ceux
 * avec qui le fichier est déjà partagé.
 *
 * DEUX INSCRIPTIONS, VOLONTAIREMENT. Les versions de Nextcloud ne lisent pas le
 * même registre d'actions, et chacune ignore l'autre :
 *   - Nextcloud 32 embarque @nextcloud/files 3.x -> window._nc_fileactions ;
 *   - Nextcloud 33 et 34 embarquent la 4.x      -> window._nc_files_scope.
 * Vérifié sur les trois versions. On inscrit donc l'action dans
 * les deux : chaque instance lit le sien, sans double affichage possible.
 */
import { FileAction, Permission, registerFileAction as registerV3 } from '@nextcloud/files'
import { registerFileAction as registerV4 } from '@nextcloud/files-v4'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import { createApp } from 'vue'
import CreateMeetingDialog from './components/CreateMeetingDialog.vue'
import { isConvertible } from './utils/meetings.js'

// Même correctif que pour la page de l'app : l'installation peut être dans
// custom_apps/, où le chemin figé par le bundler ne mène nulle part.
// eslint-disable-next-line no-undef -- variable injectée par webpack
__webpack_public_path__ = generateFilePath('empreintelive', '', 'js/')

const ID = 'empreintelive-meeting'
const ORDER = 25
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path d="M15,8V16H5V8H15M16,6H4A1,1 0 0,0 3,7V17A1,1 0 0,0 4,18H16A1,1 0 0,0 17,'
	+ '17V13.5L21,17.5V6.5L17,10.5V7A1,1 0 0,0 16,6Z" /></svg>'

const label = () => t('empreintelive', 'Present in a meeting')
const hint = () => t('empreintelive', 'Create a video meeting and present this document in it')

/**
 * Uniquement sur un document présentable, et seulement si l'utilisateur peut
 * le lire : inutile de proposer une action que le serveur refusera.
 *
 * @param {Array<object>} nodes - Les nœuds sélectionnés.
 * @return {boolean}
 */
function isEligible(nodes) {
	return nodes.length === 1
		&& nodes[0].type === 'file'
		&& isConvertible(nodes[0].basename)
		&& (nodes[0].permissions & Permission.READ) !== 0
}

/**
 * Monte la boîte de dialogue dans un conteneur jetable, retiré à la fermeture.
 *
 * @param {object} node - Le nœud Files sur lequel l'action a été déclenchée.
 */
function openDialog(node) {
	const mount = document.createElement('div')
	document.body.appendChild(mount)

	const app = createApp(CreateMeetingDialog, {
		fileId: node.fileid,
		fileName: node.basename,
		onClose: () => {
			app.unmount()
			mount.remove()
		},
	})
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(mount)
}

// Nextcloud 32 : instance de FileAction, rappels à arguments positionnels.
registerV3(new FileAction({
	id: ID,
	displayName: label,
	title: hint,
	iconSvgInline: () => ICON,
	enabled: (nodes) => isEligible(nodes),
	async exec(node) {
		openDialog(node)
		return null
	},
	order: ORDER,
}))

// Nextcloud 33 et 34 : objet simple, rappels recevant un contexte.
registerV4({
	id: ID,
	displayName: label,
	title: hint,
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => isEligible(nodes),
	async exec({ nodes }) {
		openDialog(nodes[0])
		return null
	},
	order: ORDER,
})
