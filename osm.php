<?php

# 2013 Jens Bretschneider, mail@jens-bretschneider.de

# All configuration is maintained in config.php file
require_once('./config.php');

# Hosts
$xmlServer 	= "http://" . $_SERVER['SERVER_ADDR'] . $_SERVER['SCRIPT_NAME'];
$backendServer 	= $use_test_backend ? "https://testhackerspacehb.appspot.com/" : "https://hackerspacehb.appspot.com/";

# Constants
define('OPEN', 		'OPEN');
define('CLOSED', 	'CLOSED');

# Requirements
require_once('php_classes/AastraIPPhoneTextScreen.class.php');
require_once('php_classes/AastraIPPhoneTextMenu.class.php');
require_once('php_classes/AastraIPPhoneInputScreen.class.php');
require_once('php_classes/AastraIPPhoneExecute.class.php');

# URI parameters
$action 	= $_GET['action'];
$msg_idx	= $_GET['msg_idx'];
$msg_text	= $_GET['msg_text'];
$softkey	= $_GET['softkey'];
$new_status	= $_GET['new_status'];
$do		= $_GET['do'];

# Override server locale
setlocale(LC_TIME, "de_DE.UTF8");

# Locking Class
class cLock{
	private $fp;
	function __construct(){
		$header = Aastra_decode_HTTP_header();
		$filename = sys_get_temp_dir() . "/" . $header['mac'] . ".lock";
		logme("Lockfile: " . $filename);
		$this->fp=fopen($filename, 'w');
		if (!flock($this->fp, LOCK_EX | LOCK_NB)) {
			logme("Lock vorhanden - raus!");
			exit();
		}       
	}
	function __destruct(){
		flock($this->fp,LOCK_UN);
		fclose($this->fp);  
	}
}

# Class to communicate with backend service
class cOSMBackend {
	public $unixTime	= 0;
	public $javaTime	= 0;
	public $status		= '';
	public $message		= '';

	protected $backendServer;
	protected $username;
	protected $password;

	public function __construct($aBackendServer, $aUsername, $aPassword) {
		$this->backendServer 	= $aBackendServer;
		$this->username 	= $aUsername;
		$this->password		= $aPassword;
	} // function __construct
	public function import() {
		logme("In import()");

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_URL, $this->backendServer . 'status');

		$body = curl_exec($ch);
		if (curl_errno($ch))
			displayErrorAndExit('CURL Fehler: ' . curl_error($ch));

		curl_close($ch);

		# Decode JSON in answer
		$result = json_decode ($body, TRUE);

		if (!$result['SUCCESS'])
			displayErrorAndExit('JSON Fehler: ' . $result['ERROR']);

		$this->unixTime	= $result['RESULT']['ST2'] / 1000;	// unixTime == javaTime / 1000
		$this->javaTime = $result['RESULT']['ST2'];
		$this->status 	= $result['RESULT']['ST3'];		// OPEN or CLOSED
		$this->message 	= $result['RESULT']['ST5'];

		$this->importedStatus = $this->status;
	} // function import
	public function export() {
		logme("In export()");
	
		if ($this->status == $this->importedStatus) {
			// No change of status, just update message
			$url = $this->backendServer . 'cmd/message';
			if ($this->message) {
				$postfields = sprintf('name=%s&pass=%s&message=%s&format=&time=%s',
					urlencode($this->username), urlencode($this->password), urlencode($this->message), $this->javaTime);
			} else {
				$postfields = sprintf('name=%s&pass=%s&format=&time=%s',
					urlencode($this->username), urlencode($this->password), $this->javaTime);
			};
		} else {
			if ($this->status == OPEN) {
				// Status transition: closed -> open
				$url = $this->backendServer . 'cmd/open';
			} else {
				// Status transition: open -> closed
				$url = $this->backendServer . 'cmd/close';
			}; // if status transition
			if ($this->message) {
				$postfields = sprintf('name=%s&pass=%s&message=%s',
					urlencode($this->username), urlencode($this->password), urlencode($this->message));
			} else {
				$postfields = sprintf('name=%s&pass=%s',
					urlencode($this->username), urlencode($this->password));
			};
		}; // if same status

		logme("URL: " . $url);
		logme("PostFields: " . $postfields);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);

		$body = curl_exec($ch);
		if (curl_errno($ch))
			displayErrorAndExit('CURL Fehler: ' . curl_error($ch));

		curl_close($ch);

		$result = json_decode ($body, TRUE);

		if (!$result['SUCCESS'])
			displayErrorAndExit('JSON Fehler: ' . $result['ERROR']);

		$this->import();
	} // function export
}; // class

# sub

function logme($aText) {
	error_log($aText . "\n", 3, "/tmp/osm.log");
};

function displayPleaseWait($aTitle = "", $aText = "Bitte warten...") {
	$disp = new AastraIPPhoneTextScreen();
	$disp->setDestroyOnExit();
	$disp->setEncodingUTF8();
	$disp->setTitle($aTitle);
	$disp->setText($aText);
	$disp->addSoftkey('6', 'Beenden', 'SoftKey:Exit');
	$disp->output();
};

function displayErrorAndExit($aText) {
	$disp = new AastraIPPhoneTextScreen();
	$disp->setDestroyOnExit();
	$disp->setEncodingUTF8();
	$disp->setTitle('Fehler');
	$disp->setText($aText);
	$disp->addSoftkey('6', 'Beenden', 'SoftKey:Exit');
	$disp->output();

	exit();
};

function displayMenu($aStatus, $aMessage, $aUnixTime) {
	global $xmlServer;

	$disp = new AastraIPPhoneTextScreen();
	$disp->setDestroyOnExit();
	$disp->setEncodingUTF8();

	$open_closed_text = $aStatus == OPEN ? 'Geöffnet' : 'Geschlossen';
	$disp->setTitle($open_closed_text . ' ' . strftime('%a %H:%M', $aUnixTime) . " Uhr");

	if ($aMessage) {
		$disp->setText("Statusnachricht: " . $aMessage);
	} else {
		$disp->setText("(keine Statusnachricht)");
	};

	if ($aStatus == CLOSED) {
		$disp->addSoftkey('1', 'Öffnen', 	$xmlServer . '?action=SELECT_MSG&new_status=OPEN');
		$disp->addSoftkey('2', 'Nachricht', 	$xmlServer . '?action=SELECT_MSG&new_status=CLOSED');
	} else {
		$disp->addSoftkey('2', 'Nachricht', 	$xmlServer . '?action=SELECT_MSG&new_status=OPEN');
		$disp->addSoftkey('3', 'Schließen', 	$xmlServer . '?action=SELECT_MSG&new_status=CLOSED');
	}
	$disp->addSoftkey('6', 'Beenden', 'SoftKey:Exit');

	$disp->output();
};

function displaySelectMsg($aStatus, $aNewStatus) {
	global $open_space_msg, $closed_space_msg, $xmlServer;

	$disp = new AastraIPPhoneTextMenu();
	$disp->setDestroyOnExit();
	$disp->setEncodingUTF8();

	if ($aStatus == $aNewStatus) {
		$title = 'Nachricht ändern';
	} else {
		$title = $aNewStatus == OPEN ? 'Hackerspace öffnen' : "Hackerspace schließen";
	};
	$disp->setTitle($title);

	foreach (($aNewStatus == OPEN ? $open_space_msg : $closed_space_msg) as $key => $val)
		$disp->addEntry($val[0], $xmlServer . '?action=SET_SELECTED_MSG&new_status=' . $aNewStatus . '&msg_idx=' . $key);

	$disp->addSoftkey('1', 'Auswahl', 	'SoftKey:Submit');
	$disp->addSoftkey('4', 'Freitext', 	$xmlServer . '?action=ENTER_MSG&new_status=' . $aNewStatus);
	$disp->addSoftkey('6', 'Beenden', 	'SoftKey:Exit');

	$disp->output();
};

function displayMsgQuery($aStatus, $aNewStatus) {
	global $xmlServer;

	$disp = new AastraIPPhoneInputScreen();
	$disp->setDestroyOnExit();
	$disp->setEncodingUTF8();
	
	if ($aStatus == $aNewStatus) {
		$title = 'Nachricht ändern';
	} else {
		$title = $aNewStatus == OPEN ? 'Hackerspace öffnen' : "Hackerspace schließen";
	};
	$disp->setTitle($title);
	$disp->setPrompt("Statusnachricht:");

	$disp->setType('string');
	$disp->setURL($xmlServer . '?action=SET_ENTERED_MSG&new_status=' . $aNewStatus);
	$disp->setParameter('msg_text');

	$disp->addSoftkey('1', 'Zurück', 	'SoftKey:BackSpace');
	$disp->addSoftkey('2', 'Punkt "."', 	'SoftKey:Dot');
	$disp->addSoftkey('3', '', 		'SoftKey:ChangeMode');
	$disp->addSoftkey('4', 'Leerz.', 	'SoftKey:NextSpace');
	$disp->addSoftkey('5', 'Fertig', 	'SoftKey:Submit');
	$disp->addSoftkey('6', 'Beenden', 	'SoftKey:Exit');

	$disp->output();
};

function updateLED($softkey, $aStatus) {
	global $xmlServer;

	$disp = new AastraIPPhoneExecute();
	$disp->setDestroyOnExit();
	$disp->setEncodingUTF8();
	
	$led_state = $aStatus == OPEN ? 'on' : 'off';
	$disp->addEntry("Led: $softkey=$led_state");

	$disp->output();
};

# main

# Security check: Host allowed?

if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_phone_ips))
	displayErrorAndExit('Zugriff verweigert: Die IP-Adresse ' . $_SERVER['REMOTE_ADDR'] . ' ist nicht zugelassen.');

# Locking
$lock = new cLock();

# Please wait...
if (!$do and (strtoupper($action) != 'UPDATE_LED')) {
	displayPleaseWait("Hackerspace Bremen", "Bitte warten..."); 
	header("Refresh: 0; url=" . $xmlServer . "?" . $_SERVER['QUERY_STRING'] . "&do=1");
	exit();
};

# Initialize backend object

$OSMBackend = new cOSMBackend($backendServer, $user, $pass);

# Perform $action

switch(strtoupper($action)) {

# MENU -> SELECT_MSG -> SET_SELECTED_MSG -> MENU
# MENU -> SELECT_MSG -> ENTER_MSG -> SET_ENTERED_MSG -> MENU

	case '':
	case 'MENU':
		$OSMBackend->import();
		displayMenu($OSMBackend->status, $OSMBackend->message, $OSMBackend->unixTime);
		break;
	case 'SELECT_MSG':
		$OSMBackend->import();
		displaySelectMsg($OSMBackend->status, $new_status);
		break;
	case 'SET_SELECTED_MSG':
		$OSMBackend->import();
		$OSMBackend->status = $new_status;
		$OSMBackend->message = ($new_status == OPEN ? $open_space_msg[$msg_idx][1] : $closed_space_msg[$msg_idx][1]);
		$OSMBackend->export();
		displayMenu($OSMBackend->status, $OSMBackend->message, $OSMBackend->unixTime);
		break;
	case 'ENTER_MSG':
		$OSMBackend->import();
		displayMsgQuery($OSMBackend->status, $new_status);
		break;
	case 'SET_ENTERED_MSG':
		$OSMBackend->import();
		$OSMBackend->status = $new_status;
		$OSMBackend->message = $msg_text;
		$OSMBackend->export();
		displayMenu($OSMBackend->status, $OSMBackend->message, $OSMBackend->unixTime);
		break;
	case 'UPDATE_LED':
		$OSMBackend->import();
		updateLED($softkey, $OSMBackend->status);
		break;
	default:
		displayErrorAndExit('Interner Fehler: action=' . $action . ' ist unbekannt!');
}

?>
