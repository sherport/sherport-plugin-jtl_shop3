<?php
include_once (PFAD_ROOT . PFAD_INCLUDES_MODULES . 'PaymentMethod.class.php');
require_once dir(__FILE__) . 'classSherport.php';

class Sherport extends PaymentMethod {
	private $bDebug = FALSE;
	private $settings;
	private $translations;
	private $data;	// Zahlungsdaten vom Sherport-Server
	var $oPosition_arr = array ();

	function init($nAgainCheckout = 0) {
		global $Einstellungen;
		
		parent::init ( $nAgainCheckout );
		$oPlugin = Plugin::getPluginById('sherport');
		$this->settings = $oPlugin->oPluginEinstellungAssoc_arr;
		$this->translations = $oPlugin->oPluginSprachvariableAssoc_arr;

		$this->name = 'Sherport';
		$this->caption = 'Sherport';
		//Jtllog::writeLog(print_r($oPlugin, true ), JTLLOG_LEVEL_DEBUG);
		// Mode
		$bDebug = isset($this->settings['modus']) && $this->settings['modus'] != 'live';
	}

	function preparePaymentProcess($order) {
		global $DB, $smarty;

		$sherport = new classSherport();
		$hash = $this->generateHash($order);
		//Jtllog::writeLog(print_r($order, true), JTLLOG_LEVEL_DEBUG);

		// Was ist die Währung?
		// Im Waehrung-Objekt die Metadaten
		$sherport->setCurrency($order->Waehrung->cISO);
		// Loope die Positionen des Warenkorbes und füge die Daten in den Sherport-Warenkorb
		foreach ($order->Positionen as $oPosition) {
			// fPreis ist immer der Netto-Einzelpreis???
			$price = (int) round($oPosition->fPreis * (100 + $oPosition->fMwSt));
			$sum = $price * $oPosition->nAnzahl;
			$tax = $oPosition->fMwSt > 0? (int)($oPosition->fMwSt * 100): FALSE;
			if ($oPosition->nPosTyp == C_WARENKORBPOS_TYP_ARTIKEL) {
				$sherport->addArticle($oPosition -> nAnzahl, utf8_encode($oPosition -> cName), $price, $sum, $tax);
			} else {
				// Es ist kein Arkikel
				// Nehme jetzt erst einmal an, das es immer Versand ist
				// C_WARENKORBPOS_TYP_VERSANDPOS
				$sherport->addOther('shipping', $price, $tax, utf8_encode($oPosition->cName));
				// Vorläufig auch als Artikel adden, die App handelt das zur Zeit noch nicht
				//$sherport->addArticle(1, utf8_encode($oPosition -> cName), $price, $sum, $tax);
			}
		}
		// Gibt es Steuerpositionen?
		if (isset($order->Steuerpositionen)) {
			// Ja, dann füge Sie direkt hinzu, damit keine Abweichungen entstehen können
			foreach ($order->Steuerpositionen as $oSteuer) {
				// Es gibt hier keine Nettosumme pro Position. Setze erst einmal auf 0, wird vielleicht nicht weiter verwendet
				$sherport->addTax(0, (int) round($oSteuer -> fBetrag * 100), (int) ($oSteuer -> fUst * 100));
			}
		}

		// Wenn die Zahlung erst am Abschluss passiert, brauche ich eigentlich keine Daten vom Nutzer
		// Daten Explizit leeren
		$fetchData = array('none' => 0);
		$options = array(
				'url' => $this->getReturnURL($order),
				'notify' => $this->getNotificationURL($hash),
		);
		if ($sherport->paymentInit($this->settings['consumer_id'], $fetchData, NULL, $options)) {
			$_SESSION['sherport']['token'] = $sherport->exportTokenArray();
			// Speichere token auch in der Datenbank, da die Session nicht in notify.php verfügbar ist
			$GLOBALS['DB']->executeQuery('UPDATE tzahlungsid SET sherport_token="' . $GLOBALS['DB'] -> escape($sherport -> exportTokenString()). '" WHERE cId="' . $hash . '"', 3);
		}
		else {
			Jtllog::writeLog('Sherport paymentInit error='.$sherport->getError(), JTLLOG_LEVEL_ERROR);
		}

		$code = $sherport->paymentGetSnippet(gibShopURL() . '/bestellabschluss.php?i=' . $hash, $this->translations['sherport_scancode']);
		$smarty -> assign('sherport_code', $code);
		// Bestellab_again funktioniert irgendwie überhaupt nicht
		//if (isset($_SESSION['Kunde'])) {
		//	$smarty -> assign('url_again', gibShopURL() . '/bestellab_again.php?kBestellung=' . $order -> kBestellung);
		//}

		$smarty->assign('title', $this->translations['sherport_title']);
		$smarty->assign('content', $this->translations['sherport_content']);
	}

	function handleNotification($order, $paymentHash, $args) {
		if ($this->verifyNotification($order, $paymentHash, $args)) {
			//Jtllog::writeLog('data= '.print_r($this->data, true), JTLLOG_LEVEL_DEBUG);
			$zahlungsid = $GLOBALS['DB']->executeQuery('SELECT * FROM tzahlungsid WHERE cId="' . $paymentHash . '"', 1 );
			
			if ($this->bDebug) {
				Jtllog::writeLog('zahlungsid='.var_export($zahlungsid, true ), JTLLOG_LEVEL_DEBUG);
			}

			// Zahlungseingang darf nur einmal gesetzt werden.
			// Falls jedoch mehrere Notifications ankommen, darf nur einmal
			// der Zahlungseingang gesetzt werden
			if (isset($zahlungsid->kBestellung) && intval($zahlungsid->kBestellung) > 0) {
				$oZahlungseingang = $GLOBALS ['DB'] -> executeQuery('SELECT kZahlungseingang FROM tzahlungseingang WHERE kBestellung=' . $zahlungsid -> kBestellung, 1);
				
				if (is_object($oZahlungseingang) && isset($oZahlungseingang -> kZahlungseingang) && intval($oZahlungseingang->kZahlungseingang) > 0) {
					// Ist das die offizielle Art zum beenden hier???
					die('0');
				}
			}

			if ($order -> Waehrung -> cISO != $this -> data['payment']['currency']) {
				Jtllog::writeLog('Falsche Waehrung: ' . $order -> Waehrung -> cISO . ' != ' . $this -> data['payment']['currency'], JTLLOG_LEVEL_ERROR);
				die('0');
			}

			// zahlung setzen
			$this -> setOrderStatusToPaid($order);

			// process payment
			$zahlungseingang = new StdClass;
			$zahlungseingang -> fBetrag = $this -> data['payment']['total'] / 100;
			$zahlungseingang -> fZahlungsgebuehr = $this -> data['payment']['fee'] / 100;
			$zahlungseingang -> cISO = $this -> data['payment']['currency'];
			$zahlungseingang -> cZahler = $this -> data['id.anonym'];
			$zahlungseingang -> cHinweis = $this -> data['payment']['txId'];
			// dZeit wird in addIncomingPayment überschrieben
			//$zahlungseingang -> dZeit = strftime('%Y-%m-%d %H:%M:%S', $this -> data['payment']['txDate']);

			$this -> addIncomingPayment($order, $zahlungseingang);

			$this -> sendConfirmationMail($order);

			// Die Umleitung funktioniert nicht, wir sind im Server-Call
			//header('Location: ' . $this->getReturnURL($order));
		}
	}

	/**
	 *
	 * @return boolean
	 * @param Bestellung $order
	 * @param array $args
	 */
	function verifyNotification($order, $paymentHash, $args) {

		// PaymentHash ist hoffentlich schon validiert (kein escaping notwendig)
		$zahlungsid = $GLOBALS['DB']->executeQuery('SELECT * FROM tzahlungsid WHERE cId="' . $paymentHash . '"', 1 );
		if ($zahlungsid -> kBestellung == '0') {
			if ($this->bDebug) {
				Jtllog::writeLog('ZahlungsID ist unbekannt: ' . $args['ph'], JTLLOG_LEVEL_DEBUG);
			}
			return FALSE;
		}
		// Hole hier die Zahlungsdaten ab. Notify ist bei Sherport nur, das der Server die Daten abholen kann
		$sherport = new classSherport();
		$sherport -> importTokenString($zahlungsid -> sherport_token);
		if ($this -> data = $sherport -> paymentGetData()) {
			// check that txn_id has not been previously processed
			// Geht aktuell nicht, da txn_id noch nicht gespeichert wird
			/*$txn_id_obj = $GLOBALS ['DB']->executeQuery ( "select * from tzahlungsid where txn_id=\"" . $args ['txn_id'] . "\"", 1 );
			if ($txn_id_obj->kBestellung > 0) {
				if (D_MODE == 1)
					writeLog ( D_PFAD, "ZahlungsID " . $args ['txn_id'] . " bereits gehabt.", 1 );
			
				return false;
			}*/

			// check that payment_amount/payment_currency are correct
			if ($this -> data['payment']['currency'] != $order->Waehrung->cISO) {
				Jtllog::writeLog('Sherport: Währung stimmt nicht überein! Erwartet: ' . $order -> Waehrung -> cISO . ', Erhalten: ' . $this -> data['payment']['currency'], JTLLOG_LEVEL_ERROR);
				return FALSE;
			}
			$summe = (int) ($order -> fGesamtsumme * 100);
			if ($this -> data['payment']['total'] != $summe) {
				Jtllog::writeLog('Sherport: Über Sherport wurde eine andere Summe gezahlt! Erwartet: ' . $summe . ', Erhalten: ' . $this -> data['payment']['total'], JTLLOG_LEVEL_ERROR);
				return FALSE;
			}
		}
		else {
			Jtllog::writeLog('Sherport getData failed! Error: ' . $sherport -> getError());
			return FALSE;
		}
		// Was bei korrekter Überprüfung zurücksenden?
		// Hash aus Summe/Währung und Zahlungs-Id
		$hash = "$paymentHash|{$this -> data['payment']['total']}|{$this -> data['payment']['currency']}";
		echo hash('sha256', $hash);
		return TRUE;
	}

	function finalizeOrder($order, $hash, $args) {
		Jtllog::writeLog('finalizeOrder. '.print_r($order, true), JTLLOG_LEVEL_DEBUG);
		return $this->verifyNotification ( $order, $hash, $args );
	}

	public function isValidIntern($args_arr = array()) {
		if ($this->settings['consumer_id'] == 0) {
			ZahlungsLog::add($this->moduleID, "Pflichtparameter 'Betreiber ID' ist nicht gesetzt!", null, LOGLEVEL_ERROR );
			return false;
		}
		return true;
	}

	public function canPayAgain() {
		return true;
	}
}
?>
