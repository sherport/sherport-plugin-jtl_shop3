<?php
/**
 * classSherport
 * Die Sherport-Klasse für die Anwendung auf der Seite mit dem Login oder dem Bezahlvorgang
 * @author Falk Berger
 * @copyright 2011,2014 Sherport
 * @version 1.1.0
 */
class classSherport {
	private $sherportUrl = 'sherport.com';
	private $sherportPort = 443;
	private $apiUrl = 'https://sherport.com/api/';
	private $version = '1.1.0';
	private $error;
	private $token;
	private $url;
	private $params = array();
	private $invoice;
	private $invoiceValidated;

	/**
	 * Constructor
	 * @param string $url - Die Url der eigenen Webseite inkl. Schema. Sie wird als Referer bei jedem Request an Sherport übertragen.
	 *                       Wenn weggelassen, so wird der Servername aus dem $_SERVER-Array benutzt
	 */
	function __construct($url = null) {
		$this->error = false;
		if ($url === null) {
			$this->url = isset($_SERVER['HTTPS'])? 'https': 'http';
			$this->url.= '://'.$_SERVER['SERVER_NAME'];
		}
		else {
			$this->url = $url;
		}
		$this->invoiceValidated = false;
	}

	public function getError() {
		return $this->error;
	}

	/**
	 * Token-Storage
	 * Der von den init-Funktionen generierte Token mu� von der Webseite so lange gespeichert werden,
	 * bis der Vorgang abgeschlossen ist. Dazu bietet diese Klasse Funktionen zum export und import der
	 * Tokeninformationen in verschiedenen Formaten an. Die Daten sollten in einer $_SESSION-Variablen oder
	 * einer Datenbank zwischengespeichert werden.
	 */
	public function exportTokenArray() {
		return $this->token;
	}

	public function exportTokenString() {
		return json_encode($this->token);
	}

	public function importTokenArray($token) {
		$this->token = $token;
	}

	public function importTokenString($token) {
		$this->token = json_decode($token, true);
	}

	/**
	 * Liefere den Token String, wie er im QR-Code angegeben ist
	 * Es muss vorher erfolgreich eine *Init-Funktion aufgerufen worden sein.
	 * @return string - Der Sherport-token des aktuellen Vorgangs
	 */
	public function getToken() {
		return $this->token['token'];
	}

	/**
	 * Setze die gewünschte Größe des QR-Codes
	 * Unterstützte Größen: 4 - 164x164px (default)
	 *                      6 - 246x246px
	 * @param integer $size - gewünschte Größe
	 */
	public function setQrSize($size) {
		if ($size >= 6) {
			$this->params['size'] = 6;
		}
		else {
			unset($this->params['size']);
		}
	}

	/**
	 * gets the html code of a script-tag to embed the sherport javascript
	 * @return string - the html-code
	 */
	public function getJsLibHtml() {
		return '<script type="text/javascript" src="'.$this->apiUrl.'js/sherport-'.$this->version.'.js"></script>';
	}

	/**
	 * Initialisiere eine Sherport-Login-Abfrage
	 * @param integer $consumerId - Die von Sherport vergebene consumerId
	 * @param array $fetchData - data to fetch from user (see manual)
	 * @return boolean - Funktion erfolgreich ausgeführt
	 */
	public function loginInit($consumerId, $fetchData = null) {
		$this->token = $this->createToken((int) $consumerId);
		if ($fetchData) {
			$postData = array('fetchData' => $fetchData);
			// JSON_ESCAPE_SLASHES in json_encode wäre eigentlich möglich, ist aber erst seit php 5.4 vorhanden
			if (($data = $this->sendHTTP('a=loginInit', 't='.$this->token['token'].'&c='.$consumerId.'&data='.urlencode(json_encode($postData)), 'POST', 'login')) !== false) {
				// So lange kein Fehler geliefert wurde, kehre hier mit den Daten zurück
				if (!isset($data['status']) || $data['status'] != 'error') {
					return true;
				}
				var_dump($data);
				$error = reset($data['error']);
				$this->error = 'ERR_'.$error[0];
			}
		}
		return true;
	}

	/**
	 * Liefere das HTML-Codeschnipsel für die einbindung auf der Webseite
	 * @param string $urlSuccess - die Url, welche bei einem Klick auf den QR-Code aufgerufen werden soll.
	 *                            Dies ist der gleiche Wert wie im Javascript-Config-Ojekt definiert
	 * @param string $text - Text der unterhalb des QR-Codes angezeigt wird ("bitte scannen")
	 * @param string $outerClass - Klassendefinition, welche dem äußeren Div zugewiesen wird
	 * @return string - Html-Code
	 */
	public function loginGetSnippet($urlSuccess, $text = null, $outerClass = null) {
		$text = ($text)? htmlspecialchars($text): 'Bitte scannen';
		return '<div id="sherport"'.(empty($outerClass)? '': ' class="'.$outerClass.'"').'><a href="'.$urlSuccess.'" id="sherport-code"><img id="qr-code" width="164" height="164" src="'.$this->apiUrl.'code?lt='.$this->token['token'].$this->buildCodeParams().'" alt="Sherport-Login-Code" /></a>
	<div id="sherport-status"><span class="js-disabled">Kein Javascript</span><span class="js-enabled">'.$text.' <img width="16" height="16" src="'.$this->apiUrl.'img/sherport-loader.gif" id="sherport-spinner" alt="" /></span></div>
	'.$this->buildJS($urlSuccess).'<script type="text/javascript" src="'.$this->apiUrl.'js/sherport-'.$this->version.'.js"></script>
</div>';
	}

	/**
	 * First checks if the given token matched the stored consumer-token
	 * If successful, ask the sherport server for the data
	 * @param string $token	- the token as given in the qr-code
	 * @return bool/array	- False or the data in an array
	 */
	public function loginGetData($token) {
		if ($token == $this->token['token']) {
			if (($data = $this->sendHTTP('a=loginGetData', 's='.$this->token['secret'].'&c='.$this->token['consumerId'], 'POST', 'login')) !== FALSE) {
				// So lange kein Fehler geliefert wurde, kehre hier mit den Daten zurück
				if (!isset($data['status']) || $data['status'] !== 'error') {
					return $data;
				}
				$error = reset($data['error']);
				$this->error = 'ERR_'.$error[0];
			}
		}
		else {
			// Übergebenes Token passt nicht zum gespeicherten Token
			$this->error = 'ERR_WRONG_TOKEN';
		}
		return false;
	}

	/**
	 * Initialize a payment
	 * @param integer $consumerId
	 * @param array $fetchData - data to fetch from user (see manual)
	 * @param array $invoice - invoice data. If null, data is taken from previous calls to invoice functions (addArticle)
	 * @param array $options - various options for payment
	 *    - doubleConfirm: if true, then sherport needs a second confirmation of payment order (default: false)
	 *    - url: Auf diese Url wird der Shop (per Javascript) nach erfolgreicher Zahlung weitergeleitet (mit token angehängt)
	 *    - notify: Url auf Server, welche von Sherport über Zahlungsstatus informiert wird
	 * @return boolean - If successful, then return true
	 */
	public function paymentInit($consumerId, $fetchData = null, $invoice = null, $options = null) {
		if ($invoice === null) $invoice = $this->getInvoice();
		if ($invoice !== null) {
			$token = $this->createToken((int) $consumerId);
			$postData = array('invoice' => $invoice);
			if ($fetchData !== null) {
				$postData['fetchData'] = $fetchData;
			}
			if ($options !== null) {
				$postData['options'] = $options;
			}
			if (($data = $this->sendHTTP('a=paymentInit', 't='.$token['token'].'&c='.$consumerId.'&data='.urlencode(json_encode($postData)), 'POST', 'payment')) !== false) {
				// So lange kein Fehler geliefert wurde, kehre hier mit den Daten zur�ck
				if (!isset($data['status']) || $data['status'] !== 'error') {
					$this->token = $data;
					$this->token['consumerId'] = (int) $consumerId;
					$this->token['authToken'] = $token['secret'];
					return true;
				}
				$error = reset($data['error']);
				$this->error = 'ERR_'.$error[0];
			}
		}
		else {
			$this->error = 'ERR_NO_INVOICE_DATA';
		}
		return false;
	}

	/**
	 * Liefere das HTML-Codeschnipsel für die einbindung auf der Webseite
	 * @param string $loginURL - die Url, welche bei einem Klick auf den QR-Code aufgerufen werden soll.
	 *                            Dies ist der gleiche Wert wie im Javascript-Config-Ojekt definiert
	 * @param string $text - Text der unterhalb des QR-Codes angezeigt wird ("bitte scannen")
	 * @param string $outerClass - Klassendefinition, welche dem au�eren Div zugewiesen wird
	 * @return string - Html-Code
	 */
	public function paymentGetSnippet($urlSuccess, $text = null, $outerClass = null) {
		$text = ($text)? htmlspecialchars($text): 'Bitte scannen';
		return '<div id="sherport"'.(empty($outerClass)? '': ' class="'.$outerClass.'"').'><a href="'.$urlSuccess.'" id="sherport-code"><img id="qr-code" width="164" height="164" src="'.$this->apiUrl.'code?pt='.$this->token['token'].$this->buildCodeParams().'" alt="Sherport-Login-Code" /></a>
	<div id="sherport-status"><span class="js-disabled">Kein Javascript</span><span class="js-enabled">'.$text.' <img width="16" height="16" src="'.$this->apiUrl.'img/sherport-loader.gif" id="sherport-spinner" alt="" /></span></div>
	'.$this->buildJS($urlSuccess).'<script type="text/javascript" src="'.$this->apiUrl.'js/sherport-'.$this->version.'.js"></script>
</div>';
	}

	/**
	 * First checks if the given token matched the stored consumer-token
	 * If successful, ask the sherport server for the data
	 * @param string $token	- the token as given in the qr-code
	 * @return bool/array	- False or the data in an array
	 */
	public function paymentGetData($token = '') {
		if ($token == '' || $token == $this->token['token']) {
			if (($data = $this->sendHTTP('a=paymentGetData', 's='.$this->token['secret'].'&c='.$this->token['consumerId'].'&auth='.$this->token['authToken'], 'POST', 'payment')) !== FALSE) {
				// So lange kein Fehler geliefert wurde, kehre hier mit den Daten zurück
				if (!isset($data['status']) || $data['status'] != 'error') {
					return $data;
				}
				$error = reset($data['error']);
				$this->error = 'ERR_'.$error[0];
			}
		}
		else {
			// Übergebenes Token passt nicht zum gespeicherten Token
			$this->error = 'ERR_WRONG_TOKEN';
		}
		return false;
	}

	/**
	 * Schliesse eine Zahlung ab.
	 * Der Aufruf von dieser Funktion oder paymentCancel ist bei einer doppelt-bestätigten Zahlung dringend erforderlich.
	 * @return Ambigous <boolean, string>
	 */
	public function paymentConfirm() {
		if (($data = $this->sendHTTP('a=paymentConfirm', 's='.$this->token['secret'].'&c='.$this->token['consumerId'].'&auth='.$this->token['authToken'], 'POST', 'payment')) !== FALSE) {
			// So lange kein Fehler geliefert wurde, kehre hier mit den Daten zurück
			if (!isset($data['status']) || $data['status'] != 'error') {
				return true;
			}
			$error = reset($data['error']);
			$this->error = 'ERR_'.$error[0];
		}
		return false;
	}

	/**
	 * Schliesse eine Zahlung ab.
	 * Der Aufruf von dieser Funktion oder paymentConfirm ist bei einer doppelt-best�tigten Zahlung dringend erforderlich.
	 * @return Ambigous <boolean, string>
	 */
	public function paymentCancel() {
		if (($data = $this->sendHTTP('a=paymentCancel', 's='.$this->token['secret'].'&c='.$this->token['consumerId'].'&auth='.$this->token['authToken'], 'POST', 'payment')) !== FALSE) {
			// So lange kein Fehler geliefert wurde, kehre hier mit den Daten zurück
			if (!isset($data['status']) || $data['status'] != 'error') {
				return true;
			}
			$error = reset($data['error']);
			$this->error = 'ERR_'.$error[0];
		}
		return false;
	}

	/**
	 * Füge einen Artikel der Rechnung hinzu
	 * @param integer $amount	- Anzahl der Artikel
	 * @param string $description	- Beschreibung
	 * @param integer $price	- Preis des Einzelartikels
	 * @param integer $sum		- Gesamtpreis für den/die Artikel (inkl. Steuern)
	 * @param integer $tax		- Die Steuern (in hundertstel Prozent)
	 * @return integer - die Id des hinzugefügten Artikels
	 */
	public function addArticle($amount, $description, $price, $sum = false, $tax = false) {
		$amount = (int) $amount;
		$price = (int) $price;
		$sum = ($sum === false)? $amount * $price: (int) $sum;
		$posten = array('amount' => $amount, 'desc' => substr($description, 0, 100), 'price' => $price, 'sum' => $sum);
		if ($tax !== false) {
			$posten['taxP'] = (int) $tax;
		}
		$this->invoice['articles'][] = $posten;
		$this->invoiceValidated = false;
		return count($this->invoice) - 1;
	}

	/**
	 * Füge Informationen der Rechnung hinzu, die nicht als Artikel zählen (Versand, Verpackung, Gutscheine...)
	 * @param string $type - Art der Information (packaging, shipping, packnship, coupon)
	 * @param integer $sum - Preis für Versand in Cent
	 * @param string $tax - Steuersatz
	 * @param string $extra - Extratext, abhängig von type. Bei shipping der Name des Versandunternehmen
	 */
	public function addOther($type, $sum, $tax = false, $extra = false) {
		$posten = array('type' => $type, 'sum' => (int) $sum);
		if ($tax !== false) {
			$posten['taxP'] = (int) $tax;
		}
		if ($extra !== false) {
			$posten['extra'] = $extra;
		}
		$this->invoice['other'][] = $posten;
	}

	/**
	 * Füge einen Eintrag zu den Steuern der Rechnung hinzu.
	 * Wenn einmal aufgerufen, dann wird die Stuer nicht bei validateInvoice berechnet
	 * @param integer $netto - Nettobetrag in Cent (bei EUR)
	 * @param integer $tax - Betrag der MwSt in Cent (bei EUR)
	 * @param integer $percent - Prozentsatz in hunderstel
	 */
	public function addTax($netto, $tax, $percent) {
		$this->invoice['tax'][$percent] = array('netto' => $netto, 'tax' => $tax);
	}

	/**
	 * Setze die Währung der Zahlungsinformationen.
	 * Hinweis: Wird die Funktion nicht aufgerufen, setzt validateInvoice die Währung automatisch auf EUR
	 * @param string $currency - ISO-Code der Währung
	 */
	public function setCurrency($currency) {
		$this->invoice['currency'] = $currency;
	}

	/**
	 * Request a connect token from sherport
	 * @param int $consumerId
	 * @param array $connectData - Array of user data for sherport
	 */
	public function initConnectToken($consumerId, $connectData) {
		$token = $this->getToken($consumerId);
		$postData = array('consumerId' => (int) $consumerId, 't' => $token['token'], 'connect' => $connectData);
		$data = $this->sendHTTP('', 'data='.urlencode(json_encode($postData)), 'POST', 'cn_start');
		$data = json_decode($data, true);
		$data['authToken'] = $token['secret'];
		return $data;
	}

	private function getInvoice() {
		if (isset($this->invoice)) {
			$this->validateInvoice();
		}
		return $this->invoice;
	}

	private function validateInvoice() {
		if (!$this->invoiceValidated) {
			if (!isset($this->invoice['currency'])) {
				$this->invoice['currency'] = 'EUR';	// Default-Währung
			}
			// Rechne Einzelartikel zusammen
			$total = 0;
			$bWithTax = false;
			$taxSum = array();
			if (is_array($this->invoice['articles'])) {
				foreach ($this->invoice['articles'] as $id => $data) {
					$total+= $data['sum'];
					if (isset($data['taxP'])) $bWithTax = true;
				}
			}
			if (isset($this->invoice['other'])) {
				foreach ($this->invoice['other'] as $id => $data) {
					$total+= $data['sum'];
					if (isset($data['taxP'])) $bWithTax = true;
				}
			}
			// Sobald ein Artikel mit Steuer ist, werden alle Artikel mit Steuer gerechnet
			if ($bWithTax && !isset($this->invoice['tax'])) {
				foreach ($this->invoice['articles'] as $id => $data) {
					if (isset($data['taxP'])) {
						$tax = $data['taxP'];
					}
					else {
						$tax = 0;
						$this->invoice['articles'][$id]['taxP'] = 0;
					}
					if (isset($taxSum[$tax])) {
						$taxSum[$tax]+= $data['sum'];
					}
					else {
						$taxSum[$tax] = $data['sum'];
					}
				}
				if (isset($this->invoice['other'])) {
					foreach ($this->invoice['other'] as $id => $data) {
						if (isset($data['taxP'])) {
							$tax = $data['taxP'];
						}
						else {
							$tax = 0;
							$this->invoice['other'][$id]['taxP'] = 0;
						}
						if (isset($taxSum[$tax])) {
							$taxSum[$tax]+= $data['sum'];
						}
						else {
							$taxSum[$tax] = $data['sum'];
						}
					}
				}
				// In $taxSum haben wir eine Liste mit den Beträgen Pro Steuersatz aufsummiert
				foreach ($taxSum as $tax => $sum) {
					if ($tax !== 0) {
						$amount = floor($sum * $tax / (10000 + $tax) + 0.5);
						$this->invoice['tax'][$tax] = array('netto' => $sum - $amount, 'tax' =>$amount);
					}
				}
			}
			$this->invoice['total'] = $total;	// Hier noch eventuelle Steuern einrechnen
			$this->invoiceValidated = true;
		}
	}

	private function buildCodeParams() {
		$out = '';
		if (isset($this->params['size'])) {
			$out.= '&s='.$this->params['size'];
		}
		return $out;
	}

	/**
	 * Erstelle das HTML-Code für den Sherport-Code.
	 */
	private function buildJS($urlSuccess = '') {
		$out = '<script type="application/javascript">var sherportConfig={token:"'.$this->token['token'].'"';
		if ($urlSuccess != '') {
			$out.= ',urlSuccess:"'.$urlSuccess.'"';
		}
		if (isset($this->params['size'])) {
			$out.= ',qrSize:'.$this->params['size'];
		}
		// TODO: noCSS-Option
		return $out."};</script>\n	";
	}

	private function sendHTTP($getData, $postData = '', $methode = 'GET', $script = 'fetch') {
		$out = '';
		$httpStatus = 0;
		$chunked = false;

		// HTTP Header generieren
		$header = $methode.' /api/'.$script.(($getData)? '?'.$getData: '')." HTTP/1.1\r\n";
		$header.= "Host: {$this->sherportUrl}\r\n";
		$header.= "User-Agent: Sherport-Consumer/{$this->version}\r\n";
		$header.= 'Referer: '.$this->url."\r\n";
		if ($methode == 'POST' && !empty($postData)) {
			$header.= "Content-Type: application/x-www-form-urlencoded\r\n";
			$header.= 'Content-Length: '.strlen($postData)."\r\n";
		}
		$header.= "Connection: close\r\n\r\n";
		if ($methode == 'POST' && !empty($postData)) {
			$header.= $postData."\r\n";
		}

		// Connection öffnen
		if ($socket = @fsockopen('ssl://'.$this->sherportUrl, $this->sherportPort, $errno, $errstr)) {
			fputs($socket, $header); // Header senden
			while (!feof($socket)) {
				$line = strtolower(fgets($socket));
				if ($line == "\r\n") break;	// Ende Header
				if (substr($line, 0, 8) == 'http/1.1') {
					$httpStatus = (int) substr($line, 9, 3);
				}
				if (substr($line, 0, 18) == 'transfer-encoding:') {
					if (trim(substr($line, 18)) == 'chunked') {
						$chunked = true;
					}
				}
			}
			if ($chunked) {
				while (!feof($socket)) {
					$count = hexdec(trim(fgets($socket))) + 2;
					$block = '';
					while (!feof($socket)) {
						$block.= fgets($socket);
						if (strlen($block) >= $count) break;
					}
					$out.= $block;
				}
			}
			fclose($socket);
		}
		else {
			$this->error = 'ERR_NO_SERVER_CONNECTION';
		}
		if ($httpStatus == 200) {
			// Funktion liefert bisher immer json, dekodiere deshalb dann gleich hier
			if (($out = json_decode($out, true)) !== NULL) {
				return $out;
			}
			$this->error = 'ERR_MALFORMED_JSON';
		}
		else {
			$this->error = 'ERR_CONNECTION_ERROR';
		}
		return false;
	}

	private function createToken($consumerId) {
		if (function_exists('openssl_random_pseudo_bytes')) {
			$random = openssl_random_pseudo_bytes(20);
		}
		else {
			$random = sha1('QRL'.microtime(true).mt_rand().rand(), true);
		}
		$token = sha1($random, true).pack('N', $consumerId);
		return array('consumerId' => $consumerId, 'token' => strtr(base64_encode($token), '+/', '-_'), 'secret' => substr(strtr(base64_encode($random), '+/', '-_'), 0, -1));
	}
}
