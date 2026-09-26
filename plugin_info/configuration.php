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
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
    <fieldset>
        <div>
            <legend><i class="fas fa-info"></i> {{Plugin}}</legend>
            <div class="form-group">
                <label class="col-sm-5 control-label">{{Version Plugin}}
                    <sup><i class="fas fa-question-circle tooltips" title="{{Version du Plugin (A indiquer sur Community)}}"></i></sup>
                </label>
                <div class="col-sm-5">
                    <input class="configKey form-control" data-l1key="pluginVersion" readonly />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-5 control-label">{{Version Python}}
                    <sup><i class="fas fa-question-circle tooltips" title="{{Version de Python utilisée par le Plugin (A indiquer sur Community)}}"></i></sup>
                </label>
                <div class="col-sm-5">
                    <input class="configKey form-control" data-l1key="pythonVersion" readonly />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-5 control-label">{{Version PyEnv}}
                    <sup><i class="fas fa-question-circle tooltips" title="{{Version de PyEnv utilisée par le Plugin (A indiquer sur Community)}}"></i></sup>
                </label>
                <div class="col-sm-5">
                    <input class="configKey form-control" data-l1key="pyenvVersion" readonly />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-5 control-label">{{Désactiver les messages de MàJ}}
                    <sup><i class="fas fa-question-circle tooltips" title="{{Cocher cette case désactivera les messages de mise à jour du plugin dans le centre de message}}"></i></sup>
                </label>
                <div class="col-sm-5">
                    <input type="checkbox" class="configKey" data-l1key="disableUpdateMsg" />
                </div>
            </div>
            <legend><i class="fas fa-code"></i> {{Dépendances}}</legend>
            <div class="form-group">
                <label class="col-sm-5 control-label">{{Force les mises à jour Systèmes}}
                    <sup><i class="fas fa-ban tooltips" style="color:var(--al-danger-color)!important;" title="{{Les dépendances devront être relancées après la sauvegarde de ce paramètre}}"></i></sup>
                    <sup><i class="fas fa-question-circle tooltips" title="{{Permet de forcer l'installation des mises à jour systèmes}}"></i></sup>
                </label>
                <div class="col-sm-5">
                    <input type="checkbox" class="configKey" data-l1key="debugInstallUpdates" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-5 control-label">{{Force la réinitialisation de PyEnv}}
                    <sup><i class="fas fa-ban tooltips" style="color:var(--al-danger-color)!important;" title="{{Les dépendances devront être relancées après la sauvegarde de ce paramètre}}"></i></sup>
                    <sup><i class="fas fa-question-circle tooltips" title="{{Permet de forcer la réinitialisation de l'environnement Python utilisé par le plugin}}"></i></sup>
                </label>
                <div class="col-sm-5">
                    <input type="checkbox" class="configKey" data-l1key="debugRestorePyEnv" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-5 control-label">{{Force la réinitialisation de Venv}}
                    <sup><i class="fas fa-ban tooltips" style="color:var(--al-danger-color)!important;" title="{{Les dépendances devront être relancées après la sauvegarde de ce paramètre}}"></i></sup>
                    <sup><i class="fas fa-question-circle tooltips" title="{{Permet de forcer la réinitialisation de l'environnement Venv utilisé par le plugin}}"></i></sup>
                </label>
                <div class="col-sm-5">
                    <input type="checkbox" class="configKey" data-l1key="debugRestoreVenv" />
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Port SMS}}</label>
            <div class="col-sm-5">
                <select class="configKey form-control" data-l1key="port">
                    <option value="auto">{{Auto}}</option>
                    <?php
                    foreach (jeedom::getUsbMapping() as $name => $value) {
                        echo '<option value="' . $name . '">' . $name . ' (' . $value . ')</option>';
                    }
                    foreach (ls('/dev/', 'tty*') as $value) {
                        echo '<option value="/dev/' . $value . '">/dev/' . $value . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Vitesse de communication (bauds)}}</label>
            <div class="col-sm-5">
                <select class="configKey form-control" data-l1key="serial_rate">
                    <option value="115200">115200</option>
                    <option value="9600">9600</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Code pin (laisser vide s'il n'y en a pas)}}</label>
            <div class="col-sm-5">
                <input type="password" class="configKey form-control" data-l1key="pin" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Texte mode}}</label>
            <div class="col-sm-5">
                <input type="checkbox" class="configKey" data-l1key="text_mode" title='{{A utiliser si vous ne recevez pas de message (compatibilité avec un maximum de modem) mais enleve le support des SMS multiple et des caractères spéciaux}}' />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Découper les messages par paquet de caractères}}</label>
            <div class="col-sm-5">
                <input class="configKey form-control" data-l1key="maxChartByMessage" title='{{Par defaut 140}}' />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Passerelle SMS / SMS Gateway (en cas d'erreur : CMS 330 SMSC number not set)}}</label>
            <div class="col-sm-5">
                <input class="configKey form-control" data-l1key="smsc" title='{{Utiliser le code #*#*4636#*#* sur un mobile pour trouver le SMSC de votre opérateur}}' />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Demander un accusé de réception}}</label>
            <div class="col-sm-5">
                <input type="checkbox" class="configKey" data-l1key="delivery_report" title='{{Le démon tentera de récupérer le statut de livraison (livré / échec) de chaque message envoyé}}' />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Forcer le mode 4G uniquement}}</label>
            <div class="col-sm-5">
                <input type="checkbox" class="configKey" data-l1key="force_4g_only" title='{{Recommandé si votre opérateur a coupé la 2G/3G : évite au modem de perdre du temps à les rechercher. Attention, si la couverture 4G est absente à un endroit, le modem ne se repliera pas sur 2G/3G. Uniquement pris en compte sur les modems SimCom (ex : SIM7600G-H)}}' />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Force du signal}}</label>
            <div class="col-sm-5" title='{{-1 = signal inconnu (pas de lecture disponible actuellement)}}'>
                <span class="configKey" data-l1key="signal_strength"></span> / 30
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Réseau}}</label>
            <div class="col-sm-5">
                <span class="configKey" data-l1key="network_name"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Port socket interne}}</label>
            <div class="col-sm-5">
                <input class="configKey form-control" data-l1key="socketport" placeholder="55114" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Cycle (s)}}</label>
            <div class="col-sm-5">
                <input class="configKey form-control" data-l1key="cycle" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Délai d'expiration des fragments SMS incomplets (s)}}</label>
            <div class="col-sm-5">
                <input class="configKey form-control" data-l1key="concat_parts_ttl" title="{{Si un SMS multi-parties (message long) n'est jamais reçu en entier, les fragments déjà reçus sont délivrés tels quels après ce délai, avec un marqueur aux emplacements manquants}}" />
            </div>
        </div>
        <legend>{{Reconnexion automatique en cas de perte du modem}}</legend>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Délai avant la première tentative (s)}}</label>
            <div class="col-sm-5">
                <input class="configKey form-control" data-l1key="reconnect_base_delay" title="{{Ce délai double à chaque nouvel échec, jusqu'au délai maximum ci-dessous}}" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Délai maximum entre deux tentatives (s)}}</label>
            <div class="col-sm-5">
                <input class="configKey form-control" data-l1key="reconnect_max_delay" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-5 control-label">{{Nombre maximum de tentatives avant redémarrage complet du démon}}</label>
            <div class="col-sm-5">
                <input class="configKey form-control" data-l1key="reconnect_max_attempts" />
            </div>
        </div>
    </fieldset>
</form>