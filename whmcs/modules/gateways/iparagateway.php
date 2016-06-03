<?php

/**
 * WHMCS Ipara Gateway Module
 * **
 * LÜTFEN ÖNCELİKLE README DOSYASINI OKUYUNUZ
 * **
 * Bu eklenti WHMCS sistemleri üzerinden Kredi Kartı ödemeleri almanızı sağlar.
 * Tamamen açık kaynaklı ve ücretsizdir. Satılamaz. 
 * @copyright Copyright (c) Aypara Ödeme Kuruluşu A.Ş
 * @license GPL http://www.gnu.org/licenses/gpl-3.0.en.html
 * @see http://ipara.com
 * @see http://docs.whmcs.com/Gateway_Module_Developer_Docs
 * Teknik destek için http://ipara.com 
 *
 */
if (!defined("WHMCS")) {
	die("This file cannot be accessed directly");
}


function iparagateway_MetaData()
{
	return array(
		'DisplayName' => 'iPara Credit Card Gateway Module',
		'APIVersion' => '1.1', // Use API Version 1.1
		'DisableLocalCredtCardInput' => false,
		'TokenisedStorage' => false,
	);
}

function iparagateway_getParams()
{
	$table = "tblpaymentgateways";
	$fields = "setting,value";
	$where = array("gateway" => 'iparagateway');
	$query = select_query($table, $fields, $where);
	$params = array();
	while ($param = mysql_fetch_array($query)) {
		$params[$param['setting']] = $param['value'];
	}
	return $params;
}

function iparagateway_config()
{
	$config_options = array(
		// the friendly display name for a payment gateway should be
		// defined here for backwards compatibility
		'FriendlyName' => array(
			'Type' => 'System',
			'Value' => 'iPara Kredi Kartı İle Ödeme Modülü',
		),
		// a text field type allows for single line text input
		'ipara_publickey' => array(
			'FriendlyName' => 'Mağaza açık anahtarı (PublicKey)',
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'Açık anahtar (publicKey) değerini iPara hesabınızdan (ipara.com) edinebilirsiniz.',
		),
		// a password field type allows for masked text input
		'ipara_privatekey' => array(
			'FriendlyName' => 'Kapalı Anahtar (PrivateKey)',
			'Type' => 'text',
			'Size' => '60',
			'Default' => '',
			'Description' => 'Kapalı anahtar (privateKey) değerini iPara hesabınızdan (ipara.com) edinebilirsiniz.',
		),
		'ipara_3dmode' => array(
			'FriendlyName' => '3D Secure Yönlendirme Modu',
			'Type' => 'dropdown',
			'Options' => array(
				'auto' => 'Otomatik (ÖNERİLİR) (iPara webservis ile seç)',
				'on' => 'Tüm ödemeleri 3D Secure ile yaptır. (Daha güvenli)',
				'off' => 'Tüm ödemeleri API ile yaptır. (Daha kolay daha hızlı)',
			),
			'Description' => '3D-Secure yöntemini seçiniz',
		),
	);

	for ($i = 1; $i < 10; $i++) {
		$config_options[$i . '_installment'] = array(
			'FriendlyName' => ($i == 1 ? 'Tek çekim' : $i . ' taksit') . ' komisyonu',
			'Type' => 'text',
			'Size' => '4',
			'Default' => $i + (0.4) + ($i / 2),
			'Description' => '',
		);
	}

	return $config_options;
}


function iparagateway_validate3d($params)
{

	require_once(dirname(__FILE__) . '/ipara/ipara_payment.php');
	
	$params = iparagateway_getParams();
	$public_key = $params['ipara_publickey'];
	$private_key = $params['ipara_privatekey'];
		
	$error_message = false;

	$response = $_POST;
	
	$record = array(
		'id_cart' => $params['invoice']['id'],
		'id_customer' => $params['invoice']['clientdetails']['userid'],
		'amount' => $response['amount']/100,
		'amount_paid' => 0,
		'id_ipara' => $response['orderId'],
		'result_code' => $response['errorCode'],
		'result_message' => $response['errorMessage'],
		'result' => false
	);

	$record['result_code'] = $_POST['errorCode'];
	$record['result_message'] = $_POST['errorMessage'];
	$record['id_ipara'] = $_POST['orderId'];
	$record['result'] = false;

	$hash_text = $response['orderId']
			. $response['result']
			. $response['amount']
			. $response['mode']
			. $response['errorCode']
			. $response['errorMessage']
			. $response['transactionDate']
			. $response['publicKey']
			. $private_key;
	$hash = base64_encode(sha1($hash_text, true));

	if ($hash != $response['hash']) { // has yanlışsa
		$record['result_message'] = "Hash uyumlu değil";
		return $record;
	}
	if ($response['result'] == 1) { // 3D doğrulama başarılı çekim yap

		$amount = $response['amount'];
		$orderid = $response['orderId'];
		$ipara_products = array();  // aşağıda düzenlenecek;
		$ipara_address = array();  //aşağıda düzenlenecek
		$ipara_purchaser = array();  // aşağıda düzenlenecek
		// Müşteri
		$ipara_purchaser['name'] = $params['invoice']['clientdetails']['firstname'];
		$ipara_purchaser['surname'] = $params['invoice']['clientdetails']['lastname'];
		$ipara_purchaser['email'] = $params['invoice']['clientdetails']['email'];
		$ipara_purchaser['birthdate'] = NULL;
		$ipara_purchaser['gsm_number'] = NULL;
		$ipara_purchaser['tc_certificate_number'] = NULL;

		$ipara_address['name'] = $params['invoice']['clientdetails']['firstname'];
		$ipara_address['surname'] = $params['invoice']['clientdetails']['lastname'];
		$ipara_address['address'] = $params['invoice']['clientdetails']['address1'] . ' ' . $params['clientdetails']['address2'];
		$ipara_address['zipcode'] = $params['invoice']['clientdetails']['postcode'];
		$ipara_address['city_code'] = 34;
		$ipara_address['city_text'] = $params['invoice']['clientdetails']['city'];
		$ipara_address['country_code'] = $params['invoice']['clientdetails']['countrycode'];
		$ipara_address['country_text'] = $params['invoice']['clientdetails']['state'];
		$ipara_address['phone_number'] = $params['invoice']['clientdetails']['postcode'];
		$ipara_address['tax_number'] = NULL;
		$ipara_address['tax_office'] = NULL;
		$ipara_address['tc_certificate_number'] = NULL;
		$ipara_address['company_name'] = $params['invoice']['clientdetails']['companyname'];

		// ÜRÜNLER

		$ipara_products[0]['title'] = $params['invoice']['description'];
		$ipara_products[0]['code'] = $params['invoice']['invoiceid'];
		$ipara_products[0]['quantity'] = 1;
		$ipara_products[0]['price'] = $params['invoice']['amount'];
		
		$obj = new iParaPayment();
		$obj->public_key = $public_key;
		$obj->private_key = $private_key;
		$obj->mode = "P";
		$obj->three_d_secure_code = $response['threeDSecureCode'];
		$obj->order_id = $response['orderId'];
		$obj->amount = $response['amount'] / 100;
		$obj->echo = "EticSoft";
		$obj->vendor_id = 4;
		$obj->products = $ipara_products;
		$obj->shipping_address = $ipara_address;
		$obj->invoice_address = $ipara_address;
		$obj->purchaser = $ipara_purchaser;

		try {
			$xml_response = $obj->pay();
		} catch (Exception $e) {
			$record['result_message'].= "Post error after 3DS";
			$record['result_code'] = "8888";
			$record;
			return $record;
		}
			
		$record['result'] = (string)$xml_response['result'] == '1' ? true : false;
		$record['result_message'] = (string)$xml_response['error_message'];
		$record['result_code'] = (string)$xml_response['error_code'];
	}
	return $record;

}

function iparagateway_capture($params)
{
	global $smarty;
	
	
	$_POST['ipara_installment'] = isset($_POST['ipara_installment']) ? (int)$_POST['ipara_installment'] : 
	(isset($_SESSION['ipara_installment']) ? (int)$_SESSION['ipara_installment'] : 1);
	
	$response = iparagateway_post2iPara($params);
	$smarty->assign('errormessage_ipara', $response['result_code'] . ':' . $response['result_message']);

	return array(
		'status' => $response['result'] ? 'success' : 'declined',
		'rawdata' => $response,
		'transid' => $response['id_ipara'],
		'fees' => $response['fee'],
		'errormessage' => $response['result_code'] . ':' . $response['result_message'],
		'error' => $response['result_code'] . ':' . $response['result_message'],
	);
}

function iparagateway_getiParaOptions($cc)
{
	$params = iparagateway_getParams();
	$publicKey = $params['ipara_publickey'];
	$privateKey = $params['ipara_privatekey'];
	$binNumber = substr($cc, 0, 6);
	$transactionDate = date("Y-m-d H:i:s");
	$token = $publicKey . ":" . base64_encode(sha1($privateKey . $binNumber . $transactionDate, true));
	$data = array("binNumber" => $binNumber);
	$data_string = json_encode($data);

	$ch = curl_init('https://api.ipara.com/rest/payment/bin/lookup');
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Content-Type: application/json',
		'Content-Length:' . strlen($data_string),
		'token:' . $token,
		'transactionDate:' . $transactionDate,
		'version:' . '1.0',
	));

	$response = curl_exec($ch);
	return json_decode($response);
}

function iparagateway_post2iPara($params)
{
	require_once(dirname(__FILE__) . '/ipara/ipara_payment.php');

	$amount = (float) ($params['amount']);
	$ins = (int) $_POST['ipara_installment'];
	$ins_rate = (100 + $params[$ins . '_installment']) / 100;
	$amount_pay = $amount * $ins_rate;
	$fee = number_format($amount_pay - $amount, 2);

	$orderid = 'WHMCS' . $params['invoiceid'] . '-' . time();
	$installment = $ins;
	$public_key = $params['ipara_publickey'];
	$private_key = $params['ipara_privatekey'];
	$ipara_3d_mode = $params['ipara_3dmode'];
	$ipara_products = array();  // aşağıda düzenlenecek;
	$ipara_address = array();  //aşağıda düzenlenecek
	$ipara_purchaser = array();  // aşağıda düzenlenecek


	$ipara_card = array(// Kredi kartı bilgileri
		'owner_name' => $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
		'number' => $params['cardnum'],
		'expire_month' => substr($params['cardexp'], 0, 2),
		'expire_year' => substr($params['cardexp'], 2, 4),
		'cvc' => $params['cccvv']
	);


	$record = array(
		'id_cart' => $params['invoiceid'],
		'id_customer' => $params['clientdetails']['userid'],
		'amount' => $amount,
		'amount_paid' => 0,
		'installment' => $ins,
		'fee' => $fee,
		'cc_name' => $ipara_card['owner_name'],
		'cc_expiry' => $ipara_card['expire_month'] . $ipara_card['expire_year'],
		'cc_number' => substr($ipara_card['number'], 0, 6) . 'XXXXXXXX' . substr($ipara_card['number'], -2),
		'id_ipara' => $orderid,
		'result_code' => '0',
		'result_message' => '',
		'result' => false
	);

	// Müşteri
	$ipara_purchaser['name'] = $params['clientdetails']['firstname'];
	$ipara_purchaser['surname'] = $params['clientdetails']['lastname'];
	$ipara_purchaser['email'] = $params['clientdetails']['email'];
	$ipara_purchaser['birthdate'] = NULL;
	$ipara_purchaser['gsm_number'] = NULL;
	$ipara_purchaser['tc_certificate_number'] = NULL;


	// ADRES
	$ipara_address['name'] = $params['clientdetails']['firstname'];
	$ipara_address['surname'] = $params['clientdetails']['lastname'];
	$ipara_address['address'] = $params['clientdetails']['address1'] . ' ' . $params['clientdetails']['address2'];
	$ipara_address['zipcode'] = $params['clientdetails']['postcode'];
	$ipara_address['city_code'] = 34;
	$ipara_address['city_text'] = $params['clientdetails']['city'];
	$ipara_address['country_code'] = $params['clientdetails']['countrycode'];
	$ipara_address['country_text'] = $params['clientdetails']['state'];
	$ipara_address['phone_number'] = $params['clientdetails']['postcode'];
	$ipara_address['tax_number'] = NULL;
	$ipara_address['tax_office'] = NULL;
	$ipara_address['tc_certificate_number'] = NULL;
	$ipara_address['company_name'] = $params['clientdetails']['companyname'];


	// ÜRÜNLER


	$ipara_products[0]['title'] = $params['description'];
	$ipara_products[0]['code'] = $params['invoiceid'];
	$ipara_products[0]['quantity'] = 1;
	$ipara_products[0]['price'] = $params['amount'];

	// Gerekli değil gatewaylog için özel olarak yarattık
	$debug_array = array(
		'ipara_products' => $ipara_products,
		'ipara_purchaser' => $ipara_purchaser,
		'ipara_address' => $ipara_address,
		'installment' => $installment,
		'orderid' => $orderid,
		'amount' => $amount_pay,
		'public_key' => $public_key,
		'private_key' => $private_key
	);
	$record['debug'] = serialize($debug_array);

	$obj = new iParaPayment();
	$obj->public_key = $public_key;
	$obj->private_key = $private_key;
	$obj->mode = "P";
	$obj->order_id = $orderid;
	$obj->installment = $installment;
	$obj->amount = $amount;
	$obj->vendor_id = 4;
	$obj->echo = "echo message";
	$obj->products = $ipara_products;
	$obj->shipping_address = $ipara_address;
	$obj->invoice_address = $ipara_address;
	$obj->card = $ipara_card;
	$obj->purchaser = $ipara_purchaser;
	$obj->success_url = $params['systemurl'] . '/creditcard.php?invoiceid=' . $params['invoiceid'] . '&tdvalidate=1';
	$obj->failure_url = $params['systemurl'] . '/creditcard.php?invoiceid=' . $params['invoiceid'] . '&tdvalidate=1';


	$check_ipara = iparagateway_getiParaOptions($ipara_card['number']);

	if (!$check_ipara OR $check_ipara == NULL) {
		$check_ipara = (object) array(
					'result_code' => "Webservis çalışmıyor",
					'supportsInstallment' => "1",
					'cardThreeDSecureMandatory' => "1",
					'merchantThreeDSecureMandatory' => "1",
					'result' => "1",
		);
	}


	if ($check_ipara->result == '0') {
		$record['result_code'] = 'REST-' . $check_ipara->errorCode;
		$record['result_message'] = 'WebServis Hatası ' . $check_ipara->errorMessage;
		$record['result'] = false;
		return $record;
	}
	if ($check_ipara->supportsInstallment != '1' AND (string) $installment != "1") {
		$record['result_code'] = 'REST-3D-1';
		$record['result_message'] = 'Kartınız taksitli alışverişi desteklemiyor. Lütfen tek çekim olarak deneyiniz';
		$record['result'] = false;
		return $record;
	}

	$td_mode = true;

	if ($check_ipara->cardThreeDSecureMandatory == '0'
			AND $check_ipara->merchantThreeDSecureMandatory == '0')
		$td_mode = false;

	if ($ipara_3d_mode == 'on')
		$td_mode = true;
	if ($ipara_3d_mode == 'off')
		$td_mode = false;



	if ($td_mode) {
		try {
			$record['result_code'] = '3D-R';
			$record['result_message'] = '3D yönlendimesi yapıldı. Dönüş bekleniyor';
			$record['result'] = false;
			$response = $obj->payThreeD();
			exit;
		} catch (Exception $e) {
			$record['result_code'] = 'IPARA-LIB-ERROR';
			$record['result_message'] = $e->getMessage();
			$record['result'] = false;
			return $record;
		}
	}

	$response = $obj->pay();
	$record['result_code'] = $response['error_code'];
	$record['id_ipara'] = $response['order_id'];
	$record['result_message'] = $response['error_message'];
	$record['result'] = (string) $response['result'] == "1" ? true : false;
	$record['amount_paid'] = $amount;
	return $record;
}

function iparagateway_getAvailablePrograms()
{
	return array(
		'axess' => array('name' => 'Axess', 'bank' => 'Akbank A.Ş.'),
		'word' => array('name' => 'WordCard', 'bank' => 'Yapı Kredi Bankası'),
		'bonus' => array('name' => 'BonusCard', 'bank' => 'Garanti Bankası A.Ş.'),
		'cardfinans' => array('name' => 'CardFinans', 'bank' => 'FinansBank A.Ş.'),
		'asyacard' => array('name' => 'AysaCard', 'bank' => 'BankAsya A.Ş.'),
		'maximum' => array('name' => 'Maximum', 'bank' => 'T.C. İş Bankası'),
		'paraf' => array('name' => 'Paraf', 'bank' => 'T Halk Bankası A.Ş.'),
	);
}

function iparagateway_calculatePrices($price, $rates)
{
	$banks = iparagateway_getAvailablePrograms();
	for ($i = 1; $i <= 9; $i++) {
		$return[$i] = array(
			'total' => number_format((((100 + $rates[$i . '_installment']) * $price) / 100), 2, '.', ''),
			'monthly' => number_format((((100 + $rates[$i . '_installment']) * $price) / 100) / $i, 2, '.', ''),
		);
	}
	return $return;
}

function iparagateway_installments_form($params, $amount)
{

	$amount = iparagateway_getAmount($params['invoice']['balance']);
	$ipara_params = iparagateway_getParams();
	$rates = iparagateway_calculatePrices($amount, $ipara_params);

	$txt = 'Taksit Seçimi: <select name="ipara_installment" class="select">';
	for ($ins = 1; $ins < 9; $ins++)
		if ($ins == 1)
			$txt .= '<option value="' . $ins . '">Tek Çekim'
					. ' ' . $rates[key($rates)]['installments'][$ins]['total'] . '</option>';
		else
			$txt .= '<option value="' . $ins . '">' . $ins . ' taksit X ' . $rates[key($rates)]['installments'][$ins]['monthly']
					. ' = ' . $rates[key($rates)]['installments'][$ins]['total'] . '</option>';
	$txt .= '</select>';

	return $txt;
}

add_hook('ClientAreaPageCreditCardCheckout', 1, function($vars) {
	$errormessage_ipara = false;
	if (isset($_GET['tdvalidate']) AND $_GET['tdvalidate']) {
		$record = iparagateway_validate3d($vars);
		$errormessage_ipara = 'Hata: ('.$record['result_code'].') '.$record['result_message'];
			
			$result = $record['result'] ? 'success' : 'declined';
			
			logTransaction( 
				'iparagateway', 
				$record, 
				$result 
			); 
		if($record['result']) {
			$fee = (float)($record['amount']-(iparagateway_getAmount($vars['invoice']['balance'])));
			addInvoicePayment( 
				$vars['invoice']['id'], 
				$record['orderId'], 
				iparagateway_getAmount($vars['invoice']['balance']), 
				$fee, 
				'iparagateway' 
			);
			redir("id=" . $vars['invoice']['id'], "viewinvoice.php");

		}
	}
	$amount = iparagateway_getAmount($vars['invoice']['balance']);
	$ipara_params = iparagateway_getParams();
	return array(
		"pagetitle" => $vars['filename'],
		"installments" => iparagateway_calculatePrices($amount, $ipara_params),
		"errormessage_ipara" => $errormessage_ipara
	);
	
}
);

add_hook('ClientAreaPageCart', 1, function($vars) {
	if ($_GET['a'] != "checkout")
		return;
	$amount = iparagateway_getAmount($vars['total']);
	$ipara_params = iparagateway_getParams();
	return array(
		"installments" => iparagateway_calculatePrices($amount, $ipara_params),
		"ipara_currency" => preg_replace("/[^a-zA-Z]+/", "", $vars['total'])
	);
}
);

add_hook('ShoppingCartValidateCheckout', 1, function($vars) {
		if(isset($_POST['ipara_installment'])) {
			$_SESSION['ipara_installment'] = (int)$_POST['ipara_installment'];
		}
	}
);


function iparagateway_getAmount($money)
{
	$cleanString = preg_replace('/([^0-9\.,])/i', '', $money);
	$onlyNumbersString = preg_replace('/([^0-9])/i', '', $money);

	$separatorsCountToBeErased = strlen($cleanString) - strlen($onlyNumbersString) - 1;

	$stringWithCommaOrDot = preg_replace('/([,\.])/', '', $cleanString, $separatorsCountToBeErased);
	$removedThousendSeparator = preg_replace('/(\.|,)(?=[0-9]{3,}$)/', '', $stringWithCommaOrDot);

	return (float) str_replace(',', '.', $removedThousendSeparator);
}
