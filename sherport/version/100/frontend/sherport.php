<?php
global $smarty, $step;
$bIsPage = FALSE;
if (gibSeitenTyp() == PAGE_BESTELLVORGANG && $step == 'accountwahl') {
	if ($oPlugin->oPluginEinstellungAssoc_arr['consumer_id'] == 0 || $oPlugin->oPluginEinstellungAssoc_arr['modus'] != 'live') {
		Jtllog::writeLog("Sherport: Konfiguration fehlerhaft.", JTLLOG_LEVEL_ERROR);
	}
	else {
		try {
			require_once PFAD_ROOT . PFAD_PLUGIN . 'sherport/version/' . $oPlugin->nVersion . '/paymentmethod/sherport/classSherport.php';
			$cShopURL = gibShopURL();
			$sherport = new classSherport();
			// Ist der Sherport-Parameter gesetzt?
			if (isset($_GET['t'], $_SESSION['sherport']['token'])) {
				// Ist es ein Sherport-Token, dann versuche die freigegebenen Daten von Sherport zu holen
				$sherport ->importTokenArray($_SESSION['sherport']['token']);
				$userData = $sherport -> loginGetData($_GET['t']);
				$temp = $_SESSION;
				$temp['Warenkorb'] = 'deleted';
				$temp['Zahlungsarten'] = 'deleted';
				$temp['Lieferlaender'] = 'deleted';
				$temp['Linkgruppen'] = 'deleted';
				$temp['Sprache'] = 'deleted';
				$temp['ZuletztBesuchteArtikel'] = 'deleted';
				$temp['oBoxenMaster_arr'] = 'deleted';
				Jtllog::writeLog("Sherport: Got data:" . print_r($userData, true), JTLLOG_LEVEL_DEBUG);
				Jtllog::writeLog("Sherport: SESSION:" . print_r($temp, true), JTLLOG_LEVEL_DEBUG);
				// Sherport schickt auf jeden Fall eine Lieferadresse, die Rechnungsadresse fehlt eventuell, wenn gleich mit Versand
				$oLieferadresse = transformAddress(new Lieferadresse(), $userData, 'address.shipping.');
				if (isset($userData['address.billing.sameAsShipping'])) {
					$oRechnungsadresse = $oLieferadresse;
				}
				else {
					$oRechnungsadresse = transformAddress(new stdClass, $userData, 'address.billing.');
					$oBestellung = new stdClass;
					$oBestellung->kLieferadresse = -1;
					$_SESSION['Bestellung'] = $oBestellung;
					$_SESSION['Lieferadresse'] = $oLieferadresse;
				}
				$oRechnungsadresse -> kKundengruppe = gibAktuelleKundengruppe();
				if (isset($userData['contact.email'], $userData['contact.email.verified'])) {
					$oRechnungsadresse->cMail = utf8_decode($userData['contact.email']);
				}

				setzeKundeInSession($oRechnungsadresse);
				// Redirekt zum Warenkorb?
				header('Location: ' . $cShopURL . '/bestellvorgang.php?editVersandart=1');
			}
			else {
				// Bedingungen erfüllt um Quick-Checkout zu aktivieren?
				// - Lieferland muss DE sein (Default vom Shop)
				// TODO: Versandkosten
				$temp = $_SESSION;
				$temp['Warenkorb'] = 'deleted';
				$temp['Zahlungsarten'] = 'deleted';
				//$temp['Lieferlaender'] = 'deleted';
				$temp['Linkgruppen'] = 'deleted';
				$temp['Sprache'] = 'deleted';
				$temp['ZuletztBesuchteArtikel'] = 'deleted';
				$temp['oBoxenMaster_arr'] = 'deleted';
				//Jtllog::writeLog('session='.print_r($Einstellungen, true), JTLLOG_LEVEL_DEBUG);
				//Jtllog::writeLog('session='.print_r(array_keys($_SESSION), true), JTLLOG_LEVEL_DEBUG);

				// sameAsShipping funktioniert aktuell nicht richtig., deshalb nur address.billing
				$fetchData = array(
					'req' => array('address.shipping', 'address.billing'),
					'opt' => array('contact.email'),
					'url' => $cShopURL . '/bestellvorgang.php?wk=1');
				// Erstelle eine Liste mit Lieferländern
				if (isset($_SESSION['LieferLaender'])) {
					$shippingCountries = '';
					foreach ($_SESSION['LieferLaender'] as $oLand) {
						$shippingCountries.= $oLand->cISO;
					}
					$fetchData['ctr'] = strtolower($shippingCountries);
				}
				
				if ($sherport->loginInit($oPlugin->oPluginEinstellungAssoc_arr['consumer_id'], $fetchData)) {
					$_SESSION['sherport']['token'] = $sherport->exportTokenArray();
				}
				else {
					Jtllog::writeLog('Sherport loginInit error='.$sherport->getError(), JTLLOG_LEVEL_ERROR);
				}

				$code = $sherport->loginGetSnippet($cShopURL . '/bestellvorgang.php?wk=1&t=' . $sherport->getToken(), $oPlugin->oPluginSprachvariableAssoc_arr['sherport_scancode']);

				$cHtml = '<fieldset id="sherport_login"><legend>' . $oPlugin->oPluginSprachvariableAssoc_arr['sherport_login_title'] . '</legend>' . $code .
						 '<p class="box_plain">' . $oPlugin->oPluginSprachvariableAssoc_arr['sherport_login_content'] . '</p></fieldset>';
				$cViewport = $oPlugin->oPluginEinstellungAssoc_arr['viewport_login'];

				$fx = 'before';
				switch ($cViewport) {
				default:
				case 'bottom':
					$fx = 'after';
					$cSelector = '#order_customer_login';
					break;
				case 'top':
					$cSelector = '#order_choose_order_type';
					break;
				case 'custom':
					$cSelector = $oPlugin->oPluginEinstellungAssoc_arr['viewport_custom'];
					break;
				}
				
				pq($cSelector)->{$fx}($cHtml);
			}
		}
		catch (Exception $ex)
		{
			Jtllog::writeLog("Sherport: {$ex->getMessage()}", JTLLOG_LEVEL_ERROR);
		}
	}
}
/*else {
	$temp = $_SESSION;
	//$temp['Warenkorb'] = 'deleted';
	$temp['Zahlungsarten'] = 'deleted';
	$temp['Lieferlaender'] = 'deleted';
	$temp['Linkgruppen'] = 'deleted';
	$temp['Sprache'] = 'deleted';
	$temp['ZuletztBesuchteArtikel'] = 'deleted';
	$temp['oBoxenMaster_arr'] = 'deleted';
	Jtllog::writeLog("step=$step: SESSION=" . print_r($temp, true), JTLLOG_LEVEL_DEBUG);
}*/

/**
 * Wandle die Sherport-Adressdate in ein JTL-Shop Addressobjekt
 * @param object $obj - Das Adressobjekt (stdClass oder Lieferadresse)
 * @param array $data - Array mit den Adressdaten von Sherport
 * @param string $prefix - Der Prefix-String mit dem letzten Punkt
 */
function transformAddress($obj, $data, $prefix) {
	if (isset($data[$prefix . 'company'])) {
		$obj->cFirma = utf8_decode($data[$prefix . 'company']);
	}
	if (isset($data[$prefix . 'postalCode'])) {
		$obj->cPLZ = utf8_decode($data[$prefix . 'postalCode']);
	}
	if (isset($data[$prefix . 'city'])) {
		$obj->cOrt = utf8_decode($data[$prefix . 'city']);
	}
	if (isset($data[$prefix . 'country'])) {
		$obj->cLand = strtoupper($data[$prefix . 'country']);
	}
	// Trenne Vor und Nachname
	// TODO: Muß dringend in Sherport getrennt erfasst werden
	if (isset($data[$prefix . 'name'])) {
		$name = utf8_decode($data[$prefix . 'name']);
		// Trenne am letzten Leerzeichen (Doppel-Nachnamen sind unwahrscheinlicher als Doppel-vornamen)
		if ($trenner = strrpos($name, ' ')) {
			$obj->cVorname = substr($name, 0, $trenner);
			$obj->cNachname = substr($name, $trenner + 1);
		}
	}
	// Trenne Straße und Hausnummer
	// Trennen an der ersten Ziffer?
	if (isset($data[$prefix . 'street'])) {
		$street = utf8_decode($data[$prefix . 'street']);
		$firstNumber = strcspn($street, '0123456789');
		$obj->cStrasse = substr($street, 0, $firstNumber);
		$obj->cHausnummer = substr($street, $firstNumber);
	}
	return $obj;
}
