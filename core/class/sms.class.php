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

/* * ***************************Includes********************************* */

class sms extends eqLogic {
	/*     * ***********************Méthode static*************************** */

	public static $_encryptConfigKey = array('pin');

	public static function deamon_info() {
		$return = array();
		$return['log'] = 'sms';
		$return['state'] = 'nok';
		$pid_file = jeedom::getTmpFolder('sms') . '/deamon.pid';
		if (file_exists($pid_file)) {
			if (@posix_getsid(trim(file_get_contents($pid_file)))) {
				$return['state'] = 'ok';
			} else {
				shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
			}
		}
		$return['launchable'] = 'ok';
		$port = config::byKey('port', 'sms');
		if ($port != 'auto') {
			$port = jeedom::getUsbMapping($port);
			if (is_string($port)) {
				if (@!file_exists($port)) {
					$return['launchable'] = 'nok';
					$return['launchable_message'] = __('Le port n\'est pas configuré', __FILE__);
				}
				exec(system::getCmdSudo() . 'chmod 777 ' . $port . ' > /dev/null 2>&1');
			}
		}
		return $return;
	}

	public static function deamon_start() {
		self::deamon_stop();
		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') {
			throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
		}
		$port = config::byKey('port', 'sms');
		if ($port != 'auto') {
			$port = jeedom::getUsbMapping($port);
		}
		$sms_path = realpath(__DIR__ . '/../../resources/smsd');
		$cmd = system::getCmdPython3(__CLASS__) . " {$sms_path}/smsd.py";
		$cmd .= ' --device ' . $port;
		$cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel('sms'));
		$cmd .= ' --socketport ' . config::byKey('socketport', 'sms');
		$cmd .= ' --serialrate ' . config::byKey('serial_rate', 'sms');
		$cmd .= ' --pin ' . config::byKey('pin', 'sms', 'None');
		$cmd .= ' --textmode ';
		$cmd .= (config::byKey('text_mode', 'sms') == 1) ? 'yes' : 'no';
		$cmd .= ' --smsc ' . config::byKey('smsc', 'sms', 'None');
		$cmd .= ' --cycle ' . config::byKey('cycle', 'sms');
		$cmd .= ' --deliveryreport ';
		$cmd .= (config::byKey('delivery_report', 'sms', 0) == 1) ? 'yes' : 'no';
		$cmd .= ' --reconnectbasedelay ' . config::byKey('reconnect_base_delay', 'sms', 5);
		$cmd .= ' --reconnectmaxdelay ' . config::byKey('reconnect_max_delay', 'sms', 300);
		$cmd .= ' --reconnectmaxattempts ' . config::byKey('reconnect_max_attempts', 'sms', 10);
		$cmd .= ' --callback ' . network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp') . '/plugins/sms/core/php/jeeSMS.php';
		$cmd .= ' --apikey ' . jeedom::getApiKey('sms');
		$cmd .= ' --pid ' . jeedom::getTmpFolder('sms') . '/deamon.pid';
		log::add('sms', 'info', 'Lancement démon sms : ' . $cmd);
		$result = exec($cmd . ' >> ' . log::getPathToLog('smsd') . ' 2>&1 &');
		$i = 0;
		while ($i < 30) {
			$deamon_info = self::deamon_info();
			if ($deamon_info['state'] == 'ok') {
				break;
			}
			sleep(1);
			$i++;
		}
		if ($i >= 30) {
			log::add('sms', 'error', 'Impossible de lancer le démon sms, vérifiez le port', 'unableStartDeamon');
			return false;
		}
		message::removeAll('sms', 'unableStartDeamon');
		return true;
	}

	public static function deamon_stop() {
		$pid_file = jeedom::getTmpFolder('sms') . '/deamon.pid';
		if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			system::kill($pid);
		}
		system::kill('smsd.py');
		system::fuserk(config::byKey('socketport', 'sms'));
		$port = config::byKey('port', 'sms');
		if ($port != 'auto') {
			system::fuserk(jeedom::getUsbMapping($port));
		}
		sleep(1);
	}

	/*     * *********************Méthode d'instance************************* */
	public function preSave() {
		if ($this->getConfiguration('allowUnknownOrigin', 0) == 0) {
			$this->setConfiguration('autoAddNewNumber', 0);
		}
	}

	public function postSave() {
		$signal = $this->getCmd(null, 'signal');
		if (!is_object($signal)) {
			$signal = new smsCmd();
			$signal->setEqLogic_id($this->getId());
			$signal->setLogicalId('signal');
			$signal->setIsVisible(0);
			$signal->setName(__('Signal', __FILE__));
		}
		$signal->setType('info');
		$signal->setSubType('numeric');
		$signal->save();

		$sms = $this->getCmd(null, 'sms');
		if (!is_object($sms)) {
			$sms = new smsCmd();
			$sms->setEqLogic_id($this->getId());
			$sms->setLogicalId('sms');
			$sms->setIsVisible(0);
			$sms->setName(__('Message', __FILE__));
		}
		$sms->setType('info');
		$sms->setSubType('string');
		$sms->save();

		$sender = $this->getCmd(null, 'sender');
		if (!is_object($sender)) {
			$sender = new smsCmd();
			$sender->setEqLogic_id($this->getId());
			$sender->setLogicalId('sender');
			$sender->setIsVisible(0);
			$sender->setName(__('Expediteur', __FILE__));
		}
		$sender->setType('info');
		$sender->setSubType('string');
		$sender->save();

		$customNumber = $this->getCmd(null, 'send_to_custom_number');
		if (!is_object($customNumber)) {
			$customNumber = new smsCmd();
			$customNumber->setEqLogic_id($this->getId());
			$customNumber->setLogicalId('send_to_custom_number');
			$customNumber->setIsVisible(0);
			$customNumber->setName(__('Envoyer message à', __FILE__));
			$customNumber->setType('action');
			$customNumber->setSubType('message');
			$customNumber->setDisplay('title_placeholder', __('Numéro', __FILE__));
			$customNumber->save();
		}
	}
}

class smsCmd extends cmd {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Méthode static*************************** */

	public static function cleanSMS(string $_message) {
		$caracteres = array(
			'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ä' => 'a', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', '@' => 'a',
			'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', '€' => 'e',
			'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
			'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
			'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'µ' => 'u',
			'Œ' => 'oe', 'œ' => 'oe',
			'$' => 's'
		);
		return preg_replace('#[^A-Za-z0-9 \n\.\'=\*:]+#', '', strtr($_message, $caracteres));
	}

	/*     * *********************Méthode d'instance************************* */

	public function dontRemoveCmd() {
		if ($this->getLogicalId() == 'signal') {
			return true;
		}
		if (strpos($this->getLogicalId(), 'delivery_status_') === 0 || strpos($this->getLogicalId(), 'delivery_success_') === 0) {
			return true;
		}
		return false;
	}

	/**
	 * Crée les commandes compagnon de cette commande d'action de type message, si elles n'existent
	 * pas déjà : "Statut" (texte, pour affichage) et "Remis" (binaire 1/0, pour condition de scénario).
	 *
	 * @return void
	 */
	public function postSave() {
		if ($this->getType() != 'action' || $this->getSubType() != 'message') {
			return;
		}
		$eqLogic = $this->getEqLogic();
		// send_to_custom_number n'a pas de destinataire fixe : la destination réelle est
		// affichée dans la valeur des commandes (cf jeeSMS.php), pas dans leur nom
		$label = ($this->getLogicalId() == 'send_to_custom_number') ? 'Custom' : $this->getName();
		$this->syncDeliveryCompanionCmd($eqLogic, 'delivery_status_', __('Statut', __FILE__) . ' - ' . $label, 'string');
		$this->syncDeliveryCompanionCmd($eqLogic, 'delivery_success_', __('Remis', __FILE__) . ' - ' . $label, 'binary');
	}

	/**
	 * Crée une commande info compagnon (identifiée par $_logicalIdPrefix . $this->getId()) si elle
	 * n'existe pas encore. Ne touche jamais au nom d'une commande existante : l'utilisateur reste
	 * libre de le personnaliser, seul le logicalId identifie la commande de façon stable.
	 *
	 * @return void
	 */
	private function syncDeliveryCompanionCmd($_eqLogic, $_logicalIdPrefix, $_expectedName, $_subType) {
		$logicalId = $_logicalIdPrefix . $this->getId();
		if (is_object($_eqLogic->getCmd(null, $logicalId))) {
			return;
		}
		$companion = new smsCmd();
		$companion->setEqLogic_id($this->getEqLogic_id());
		$companion->setLogicalId($logicalId);
		$companion->setIsVisible(0);
		$companion->setType('info');
		$companion->setSubType($_subType);
		$companion->setName($_expectedName);
		$companion->save();
	}

	/**
	 * Supprime la commande compagnon "accusé de réception" lorsque cette commande d'action
	 * de type message est supprimée. Utilise preRemove() (et non postRemove()) car DB::remove()
	 * réinitialise l'id de l'objet à null avant d'appeler postRemove().
	 *
	 * @return void
	 */
	public function preRemove() {
		if ($this->getType() != 'action' || $this->getSubType() != 'message') {
			return;
		}
		$eqLogic = $this->getEqLogic();
		foreach (array('delivery_status_', 'delivery_success_') as $prefix) {
			$companion = $eqLogic->getCmd(null, $prefix . $this->getId());
			if (is_object($companion)) {
				$companion->remove();
			}
		}
	}

	public function preSave() {
		if ($this->getSubtype() == 'message' && $this->getLogicalId() != 'send_to_custom_number') {
			$this->setDisplay('title_disable', 1);
		}
	}

	public function execute($_options = null) {
		$number = $this->getConfiguration('phonenumber');
		if ($this->getLogicalId() == 'send_to_custom_number' && isset($_options['title'])) {
			$number = $_options['title'];
		}
		if (isset($_options['number'])) {
			$number = $_options['number'];
		}
		if (isset($_options['answer'])) {
			$_options['message'] .= ' (' . implode(';', $_options['answer']) . ')';
		}
		$values = array();
		if (isset($_options['message']) && $_options['message'] != '') {
			$message = trim($_options['message']);
		} else {
			$message = trim($_options['title'] . ' ' . $_options['message']);
		}
		if (config::byKey('text_mode', 'sms') == 1) {
			$message = self::cleanSMS(trim($message));
		}
		if (strlen($message) > config::byKey('maxChartByMessage', 'sms')) {
			$messages = str_split($message, config::byKey('maxChartByMessage', 'sms'));
			foreach ($messages as $message_split) {
				$values[] = json_encode(array('apikey' => jeedom::getApiKey('sms'), 'number' => $number, 'message' => $message_split));
			}
		} else {
			$values[] = json_encode(array('apikey' => jeedom::getApiKey('sms'), 'number' => $number, 'message' => $message));
		}
		if (!isset($_options['number'])) {
			$phonenumbers = explode(';', $this->getConfiguration('phonenumber'));
			if (is_array($phonenumbers) && count($phonenumbers) > 1) {
				$tmp_values = array();
				foreach ($values as $value) {
					$value = json_decode($value, true);
					foreach ($phonenumbers as $phonenumber) {
						if (is_array($value)) {
							$tmp_values[] = json_encode(array('apikey' => jeedom::getApiKey('sms'), 'number' => $phonenumber, 'message' => $value['message']));
						}
					}
				}
				$values = $tmp_values;
			}
		}
		foreach ($values as $value) {
			$socket = socket_create(AF_INET, SOCK_STREAM, 0);
			socket_connect($socket, '127.0.0.1', config::byKey('socketport', 'sms'));
			socket_write($socket, $value, strlen($value));
			socket_close($socket);
		}
	}
}
