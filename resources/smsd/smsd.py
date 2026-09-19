# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.

import logging
import sys
import os
import time
import argparse
import signal
import traceback
import json
from typing import Optional
from gsmmodem.exceptions import TimeoutException
from gsmmodem.modem import GsmModem, StatusReport

try:
    from jeedom.jeedom import jeedom_com, jeedom_socket, jeedom_utils, JEEDOM_SOCKET_MESSAGE

    # Type hints for global instances (initialized later)
    j_com_instance: Optional[jeedom_com] = None
    j_socket_instance: Optional[jeedom_socket] = None

except ImportError as e:
    print("Error: importing module from jeedom folder: %s", e)
    sys.exit(1)

# PARAMETERS #

gsm: Optional[GsmModem] = None


def handleSms(sms):
    logging.info("Got SMS message : %s", sms)
    if not sms.text:
        logging.debug("No text so nothing to do")
        return
    message = sms.text.replace('"', '')
    # message = jeedom_utils.remove_accents(sms.text.replace('"', ''))
    if j_com_instance:
        j_com_instance.add_changes('devices::' + str(sms.number), {'number': sms.number, 'message': message})


def handleStatusReport(report):
    status = 'delivered' if report.deliveryStatus == StatusReport.DELIVERED else 'failed'
    logging.info("Delivery report for %s : %s (ref %s)", report.number, status, report.reference)
    if j_com_instance:
        j_com_instance.send_change_immediate({'number': 'delivery_report', 'destination': report.number, 'status': status, 'reference': report.reference})


def _backoffDelay(attempt):
    return min(_reconnect_base_delay * (2 ** (attempt - 1)), _reconnect_max_delay)


def _isTransientNetworkError(e):
    return isinstance(e, TimeoutException) or str(e) in ('Device not searching for network operator', 'Timeout')


_modem_status = None


def _setModemStatus(status, **kwargs):
    """Push the modem connection status to Jeedom (deduplicated, PHP does the display translation)"""
    global _modem_status
    if status == _modem_status:
        return
    _modem_status = status
    if j_com_instance:
        change = {'number': 'modem_status', 'status': status}
        change.update(kwargs)
        j_com_instance.send_change_immediate(change)


def _createAndConnectModem():
    if _device is None:
        raise ValueError('Device not found')
    modem = GsmModem(
        _device, int(_serial_rate),
        smsReceivedCallbackFunc=handleSms,
        smsStatusReportCallback=handleStatusReport,
        requestDelivery=(_delivery_report == 'yes'),
    )
    logging.debug("Text mode %s", _text_mode == 'yes')
    modem.smsTextMode = (_text_mode == 'yes')
    if _pin != 'None':
        logging.debug("Enter pin code : %s ", _pin)
        modem.connect(_pin, 1)
    else:
        modem.connect(None, 1)
    if _force_4g == 'yes' and modem.isSimComModem:
        try:
            modem.write('AT+CNMP=38')
            logging.debug("Forced LTE-only network mode (AT+CNMP=38)")
        except Exception as e:
            logging.error("Failed to force LTE-only network mode (AT+CNMP=38) : %s", e)
    if _smsc != 'None':
        logging.debug("Configure smsc : %s", _smsc)
        modem.write(f'AT+CSCA="{_smsc}"')
    logging.debug("Waiting for network...")
    modem.waitForNetworkCoverage()
    logging.debug("Network coverage acquired")
    if modem.isSimComModem:
        try:
            # First field of the response is the actual RAT in use (LTE/WCDMA/GSM/NO SERVICE...) -
            # useful to spot a fallback to 2G/3G when force_4g is set, or just to see the current mode otherwise
            cpsi = modem.write('AT+CPSI?')
            logging.info("Network system info (AT+CPSI?) : %s", cpsi)
        except Exception as e:
            logging.error("Failed to query network system info (AT+CPSI?) : %s", e)
    try:
        if j_com_instance:
            j_com_instance.send_change_immediate({'number': 'network_name', 'message': str(modem.networkName)})
    except Exception as e:
        logging.error("Exception during send_change_immediate: %s", e)
    for mem in ('ME', 'SM'):
        try:
            modem.write(f'AT+CPMS="{mem}","{mem}","{mem}"')
            modem.write('AT+CMGD=1,4')
        except Exception as e:
            logging.error("Exception clearing '%s' storage: %s", mem, e)
    return modem


def _reconnectLoop():
    global gsm
    try:
        if gsm:
            gsm.close()
    except Exception:
        pass
    attempt = 0
    while attempt < _reconnect_max_attempts:
        attempt += 1
        delay = _backoffDelay(attempt)
        _setModemStatus('reconnecting', attempt=attempt, max_attempts=_reconnect_max_attempts)
        logging.warning("Attempting modem reconnection %d/%d in %.0fs", attempt, _reconnect_max_attempts, delay)
        time.sleep(delay)
        try:
            gsm = _createAndConnectModem()
            logging.info("Modem reconnection successful after %d attempt(s)", attempt)
            _setModemStatus('connected')
            return True
        except Exception as e:
            logging.error("Reconnection attempt %d/%d failed : %s", attempt, _reconnect_max_attempts, e)
    logging.error("Maximum number of reconnection attempts reached (%d), giving up", _reconnect_max_attempts)
    _setModemStatus('disconnected')
    return False


def listen():
    global gsm
    if j_socket_instance:
        j_socket_instance.open()
    logging.debug("Start listening...")
    logging.debug("Connecting to GSM Modem...")
    _setModemStatus('connecting')
    try:
        gsm = _createAndConnectModem()
        _setModemStatus('connected')
    except Exception as e:
        logging.error("Unexpected error while starting to listen (%s): %s", type(e).__name__, e)
        if j_com_instance:
            j_com_instance.send_change_immediate({'number': 'none', 'message': str(e)})
        logging.error("Initial connection failed, entering reconnection loop")
        if not _reconnectLoop():
            shutdown()
            return
    signal_strength_store = 0
    consecutive_network_failures = 0
    sleep_duration = _cycle
    try:
        while 1:
            time.sleep(sleep_duration)
            sleep_duration = _cycle
            try:
                if gsm:
                    gsm.waitForNetworkCoverage()
                    consecutive_network_failures = 0
                    _setModemStatus('connected')
                    gsm.processStoredSms(True)
                    if signal_strength_store != gsm.signalStrength:
                        signal_strength_store = gsm.signalStrength
                    if j_com_instance:
                        j_com_instance.send_change_immediate({'number': 'signal_strength', 'message': str(gsm.signalStrength)})
            except Exception as e:
                if _isTransientNetworkError(e):
                    consecutive_network_failures += 1
                    sleep_duration = _backoffDelay(consecutive_network_failures)
                    _setModemStatus('searching')
                    logging.warning("Temporary network loss (%s), rechecking in %.0fs (attempt %d)", e, sleep_duration, consecutive_network_failures)
                else:
                    logging.error("Exception on GSM : %s", e)
                    logging.error("Modem connection lost, attempting reconnection...")
                    if not _reconnectLoop():
                        shutdown()
                        return
                    consecutive_network_failures = 0
            try:
                read_socket()
            except Exception as e:
                logging.error("Exception on socket : %s", e)
    except KeyboardInterrupt:
        shutdown()


def read_socket():
    if not JEEDOM_SOCKET_MESSAGE.empty():
        logging.debug("Message received from Jeedom socket")
        message = json.loads(JEEDOM_SOCKET_MESSAGE.get().decode("utf-8"))
        if message['apikey'] != _apikey:
            logging.error("Invalid apikey from socket : %s", message)
            return
        if gsm:
            gsm.waitForNetworkCoverage()
            logging.info("Sending message to %s: %s", message['number'], message['message'])
            try:
                gsm.sendSms(message['number'], message['message'])
            except Exception as e:
                logging.error("Failed to send SMS to %s : %s", message['number'], e)
                if j_com_instance:
                    j_com_instance.send_change_immediate({'number': 'delivery_report', 'destination': message['number'], 'status': 'failed'})


def handler(signum=None, frame=None):
    logging.debug("Signal %i caught, exiting...", signum)
    shutdown()


def shutdown():
    logging.debug("Shutdown")
    # Envoi synchrone borné (pas thread_change/send_change_immediate) : os._exit() plus bas tuerait
    # un thread avant l'envoi, et thread_change() pourrait bloquer l'arrêt jusqu'à 6min (retry x 120s)
    if j_com_instance:
        j_com_instance.send_change_sync({'number': 'modem_status', 'status': 'disconnected'})
    logging.debug("Removing PID file %s", _pidfile)
    try:
        os.remove(_pidfile)
    except Exception:
        pass
    try:
        if j_socket_instance:
            j_socket_instance.close()
    except Exception:
        pass
    logging.debug("Exit 0")
    sys.stdout.flush()
    os._exit(0)

# ----------------------------------------------------------------------------


_log_level = "error"
_socket_port = 55002
_socket_host = '127.0.0.1'
_device = 'auto'
_pidfile = '/tmp/smsd.pid'
_apikey = ''
_callback = ''
_cycle = 30
_serial_rate = 9600
_pin = 'None'
_text_mode = 'no'
_smsc = 'None'
_force_4g = 'no'
_delivery_report = 'no'
_reconnect_base_delay = 5.0
_reconnect_max_delay = 300.0
_reconnect_max_attempts = 10


parser = argparse.ArgumentParser(description='SMS Daemon for Jeedom plugin')
parser.add_argument("--device", help="Device", type=str)
parser.add_argument("--socketport", help="Socketport for server", type=str)
parser.add_argument("--loglevel", help="Log Level for the daemon", type=str)
parser.add_argument("--callback", help="Callback", type=str)
parser.add_argument("--apikey", help="Apikey", type=str)
parser.add_argument("--cycle", help="Cycle to send event", type=str)
parser.add_argument("--serialrate", help="Serial rate of device", type=str)
parser.add_argument("--pin", help="Pin sim code", type=str)
parser.add_argument("--textmode", help="Force text mode", type=str)
parser.add_argument("--smsc", help="Smsc number", type=str)
parser.add_argument("--force4g", help="Force LTE-only network mode (SimCom modems only)", type=str)
parser.add_argument("--deliveryreport", help="Request SMS delivery status report", type=str)
parser.add_argument("--reconnectbasedelay", help="Base delay (s) before first reconnect attempt", type=str)
parser.add_argument("--reconnectmaxdelay", help="Max delay (s) between reconnect attempts", type=str)
parser.add_argument("--reconnectmaxattempts", help="Max number of reconnect attempts before giving up", type=str)
parser.add_argument("--pid", help="Pid file", type=str)
args = parser.parse_args()

if args.device:
    _device = args.device
if args.socketport:
    _socket_port = int(args.socketport)
if args.loglevel:
    _log_level = args.loglevel
if args.callback:
    _callback = args.callback
if args.apikey:
    _apikey = args.apikey
if args.cycle:
    _cycle = float(args.cycle)
if args.serialrate:
    _serial_rate = int(args.serialrate)
if args.pin:
    _pin = args.pin
if args.textmode:
    _text_mode = args.textmode
if args.smsc:
    _smsc = args.smsc
if args.force4g:
    _force_4g = args.force4g
if args.deliveryreport:
    _delivery_report = args.deliveryreport
if args.reconnectbasedelay:
    _reconnect_base_delay = float(args.reconnectbasedelay)
if args.reconnectmaxdelay:
    _reconnect_max_delay = float(args.reconnectmaxdelay)
if args.reconnectmaxattempts:
    _reconnect_max_attempts = int(args.reconnectmaxattempts)
if args.pid:
    _pidfile = args.pid

_socket_port = int(_socket_port)
_cycle = float(_cycle)

jeedom_utils.set_log_level(_log_level)

logging.info('Start smsd')
logging.info('Log level : %s', _log_level)
logging.info('Socket port : %s', _socket_port)
logging.info('Socket host : %s', _socket_host)
logging.info('PID file : %s', _pidfile)
logging.info('Device : %s', _device)
logging.info('Callback : %s', _callback)
logging.info('Cycle : %s', _cycle)
logging.info('Serial rate : %s', _serial_rate)
logging.info('Pin : %s', _pin)
logging.info('Text mode : %s', _text_mode)
logging.info('SMSC : %s', _smsc)
logging.info('Force 4G only : %s', _force_4g)
logging.info('Delivery report : %s', _delivery_report)
logging.info('Reconnect base delay : %s', _reconnect_base_delay)
logging.info('Reconnect max delay : %s', _reconnect_max_delay)
logging.info('Reconnect max attempts : %s', _reconnect_max_attempts)


if _device == 'auto':
    know_sticks = [{'idVendor': '12d1', 'idProduct': '1003', 'name': 'Huawei'},
                   {'idVendor': '12d1', 'idProduct': '1f01', 'name': 'Huawei'},
                   {'idVendor': '12d1', 'idProduct': '1001', 'name': 'Huawei'},
                   {'idVendor': '0403', 'idProduct': '6001', 'name': 'Gsm'}]
    for stick in know_sticks:
        _device = jeedom_utils.find_tty_usb(stick['idVendor'], stick['idProduct'], stick['name'])
        if _device is not None:
            logging.info('Find device : %s', _device)
            break


if _device is None:
    logging.error('No device found')
    shutdown()

signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

try:
    jeedom_utils.write_pid(str(_pidfile))
    j_com_instance = jeedom_com(apikey=_apikey, url=_callback, cycle=_cycle)
    if not j_com_instance.test():
        logging.error('Network communication issues. Please fix your Jeedom network configuration.')
        shutdown()
    j_socket_instance = jeedom_socket(port=_socket_port, address=_socket_host)
    listen()
except Exception as e:
    logging.error('Fatal error : %s', e)
    logging.debug(traceback.format_exc())
    shutdown()
