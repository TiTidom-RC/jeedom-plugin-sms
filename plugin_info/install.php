<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function sms_install() {
	if (config::byKey('api::sms::mode') == '') {
		config::save('api::sms::mode', 'localhost');
	}
}

function sms_update() {
	if (config::byKey('api::sms::mode') == '') {
		config::save('api::sms::mode', 'localhost');
	}
	// Crée les commandes manquantes sur les équipements/contacts créés avant l'ajout de ces fonctionnalités
	foreach (eqLogic::byType('sms') as $eqLogic) {
		foreach ($eqLogic->getCmd('action') as $cmd) {
			if ($cmd->getSubType() == 'message' && (!is_object($eqLogic->getCmd(null, 'delivery_status_' . $cmd->getId())) || !is_object($eqLogic->getCmd(null, 'delivery_success_' . $cmd->getId())))) {
				$cmd->save();
			}
		}
		if (!is_object($eqLogic->getCmd(null, 'connection')) || !is_object($eqLogic->getCmd(null, 'connection_state')) || !is_object($eqLogic->getCmd(null, 'online'))) {
			$eqLogic->save();
		}
	}
	$paths = array(
		'resources/smsd/gsmmodem/compat.py',
	);
	foreach ($paths as $path) {
		$file = dirname(__FILE__) . '/../' . $path;
		if (file_exists($file)) {
			if (is_dir($file)) {
				exec('rm -rf ' . escapeshellarg($file), $output, $return_var);
				if ($return_var != 0) {
					log::add('sms', 'error', 'Failed to remove ' . $file . ' (return code: ' . $return_var . ')');
				}
			} else {
				if (!unlink($file)) {
					log::add('sms', 'error', 'Failed to remove ' . $file);
				}
			}
		}
	}
}

function sms_remove() {

}

?>
