<?php

namespace modules\payment_paypal\controllers;

use ErrorException;
use core\classes\exceptions\RedirectException;
use core\classes\renderable\Controller;
use core\classes\Config;
use core\classes\Database;
use core\classes\Response;
use core\classes\Request;
use core\classes\Encryption;
use core\classes\Model;
use core\classes\Pagination;
use core\classes\FormValidator;
use modules\checkout\classes\Cart as CartContents;
use modules\checkout\classes\Order;

use PayPal;
use ProfileHandler_Array;
use ProfileHandler;
use APIProfile;
use CallerServices;

class PaymentPayPal extends Controller {

	protected $permissions = [
	];

	public function getAllUrls($include_filter = NULL, $exclude_filter = NULL) {
		return [];
	}

	public function __construct(Config $config, Database $database = NULL, Request $request = NULL, Response $response = NULL) {
		parent::__construct($config, $database, $request, $response);
		$module_config = $this->config->moduleConfig('\modules\payment_paypal');

		$path = $module_config->sdk_path.DS.'lib';
		set_include_path($path . PATH_SEPARATOR . get_include_path());
		require_once 'PayPal.php';
		require_once 'PayPal/Profile/Handler/Array.php';
		require_once 'PayPal/Profile/API.php';
		require_once 'PayPal/Type/PayerInfoType.php';
		require_once 'PayPal/Type/AddressType.php';
		require_once 'PayPal/Type/AbstractResponseType.php';
		require_once 'PayPal/Type/ErrorType.php';
		require_once 'PayPal/Type/GetTransactionDetailsResponseType.php';
		require_once 'PayPal/Type/SetExpressCheckoutResponseType.php';
		require_once 'PayPal/Type/GetExpressCheckoutDetailsResponseDetailsType.php';
		require_once 'PayPal/Type/GetExpressCheckoutDetailsResponseType.php';
		require_once 'PayPal/Type/DoExpressCheckoutPaymentResponseType.php';
		require_once 'PayPal/Type/DoCaptureResponseDetailsType.php';
		require_once 'PayPal/Type/DoCaptureResponseType.php';
		require_once 'PayPal/Type/DoVoidResponseType.php';
	}

	public function payment() {
		$module_config = $this->config->moduleConfig('\modules\payment_paypal');
		$this->disablePayPalErrors();

		$cart = new CartContents($this->config, $this->database, $this->request);

		if ($module_config->mode != 'live' && $module_config->mode != 'sandbox' && $module_config->mode != 'beta-sandbox') {
			throw new ErrorException('PayPal "mode" setting must be either "live" or "sandbox" or "beta-sandbox"');
		}

		if ($cart->getGrandTotal() == 0) {
			$this->logger->info('Cart is empty');
			throw new RedirectException($this->url->getUrl('Cart'));
		}

		// Payment parameters
		$payment_type = 'Sale'; // ActionCodeType in ASP SDK
		$currency     = 'AUD';
		$amount       = $cart->getGrandTotal();
		$return_url   = $this->url->getUrl('PaymentPayPal', 'confirm');
		$cancel_url   = $this->url->getUrl('Cart');

		// Initalize the request
		$ec_request =& PayPal::getType('SetExpressCheckoutRequestType');
		$ec_details =& PayPal::getType('SetExpressCheckoutRequestDetailsType');
		$ec_details->setReturnURL($return_url);
		$ec_details->setCancelURL($cancel_url);
		$ec_details->setPaymentAction($payment_type);
		$amt_type =& PayPal::getType('BasicAmountType');
		$amt_type->setattr('currencyID', $currency);
		$amt_type->setval($amount, 'iso-8859-1');
		$ec_details->setOrderTotal($amt_type);
		$ec_request->setSetExpressCheckoutRequestDetails($ec_details);

		$profile = $this->getPaypalProfile($module_config);
		$caller =& PayPal::getCallerServices($profile);
		$response = $caller->SetExpressCheckout($ec_request);

		if (get_class($response) == 'SOAP_Fault') {
			$this->logger->info('PayPal Error='.$response);
			$ack = 'ERROR';
		}
		else {
			$ack = $response->getAck();
		}

		if ($this->checkPaypalResponse($response)) {
			// Extract the response details.
			// Redirect to paypal.com.
			$token = $response->getToken();
			$paypal_url = "https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=$token";
			if("sandbox" === $module_config->mode || "beta-sandbox" === $module_config->mode) {
				$paypal_url = "https://www.".$module_config->mode.".paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=$token";
			}
			throw new RedirectException($paypal_url);
		}
	}

	public function confirm() {
		$this->language->loadLanguageFile('customer.php');
		$this->language->loadLanguageFile('checkout.php', 'modules'.DS.'checkout');
		$this->language->loadLanguageFile('administrator/orders.php', 'modules'.DS.'checkout');

		$module_config = $this->config->moduleConfig('\modules\payment_paypal');
		$this->disablePayPalErrors();

		$cart = new CartContents($this->config, $this->database, $this->request);
		$order = new Order($this->config, $this->database, $cart);

		$token    = $this->request->requestParam('token');
		$payer_id = $this->request->requestParam('PayerID');

		$payment_type = 'Sale'; // ActionCodeType in ASP SDK
		$currency     = 'AUD';
		$amount       = $cart->getGrandTotal();

		// create the request
		$profile = $this->getPaypalProfile($module_config);
		$ec_request =& PayPal::getType('GetExpressCheckoutDetailsRequestType');
		$ec_request->setToken($token);
		$caller =& PayPal::getCallerServices($profile);

		// Execute SOAP request
		$response = $caller->GetExpressCheckoutDetails($ec_request);
		if (!$this->checkPaypalResponse($response)) {
			return;
		}

		// Get the payer details
		$resp_details = $response->getGetExpressCheckoutDetailsResponseDetails();
		$payer_info = $resp_details->getPayerInfo();

		$this->logger->info('PayPal Payer: '.json_encode($payer_info));

		$customer = $this->createCustomer($payer_info);
		$address = $this->createAddress($payer_info);
		if (!$address) {
			return;
		}

		// do the transaction
		$ec_details =& PayPal::getType('DoExpressCheckoutPaymentRequestDetailsType');
		$ec_details->setToken($token);
		$ec_details->setPayerID($payer_id);
		$ec_details->setPaymentAction($payment_type);

		$amt_type =& PayPal::getType('BasicAmountType');
		$amt_type->setattr('currencyID', $currency);
		$amt_type->setval($amount, 'iso-8859-1');

		$payment_details =& PayPal::getType('PaymentDetailsType');
		$payment_details->setOrderTotal($amt_type);

		$ec_details->setPaymentDetails($payment_details);

		$ec_request =& PayPal::getType('DoExpressCheckoutPaymentRequestType');
		$ec_request->setDoExpressCheckoutPaymentRequestDetails($ec_details);

		$this->logger->info('PayPal Request: '.json_encode($ec_request));

		$caller =& PayPal::getCallerServices($profile);
		$response = $caller->DoExpressCheckoutPayment($ec_request);
		if (!$this->checkPaypalResponse($response)) {
			return;
		}
		$this->logger->info('PayPal Response: '.json_encode($response));

		// Marshall data out of response
		$details = $response->getDoExpressCheckoutPaymentResponseDetails();
		$payment_info = $details->getPaymentInfo();
		$fee_obj = $payment_info->getFeeAmount();
		$fee = 0;
		if ($fee_obj) {
			$fee = $fee_obj->_value;
		}

		$amt_obj = $payment_info->getGrossAmount();
		$paypal_amount = $amt_obj->_value;
		$paypal_currency = $amt_obj->_attributeValues['currencyID'];

		// purchase the order
		$model = new Model($this->config, $this->database);
		$status = $model->getModel('\modules\checkout\classes\models\CheckoutStatus');
		$checkout = $order->purchase('paypal', $customer, $address, $address);
		if ($checkout->shipping_address_id) {
			$checkout->status_id = $status->getStatusId('Processing');
		}
		else {
			$checkout->status_id = $status->getStatusId('Complete');
		}
		$checkout->fees = $fee;
		$checkout->update();
		$order->sendOrderEmails($checkout, $this->language);
		$cart->clear();

		// create the paypal transaction record
		$paypal = $model->getModel('\modules\payment_paypal\classes\models\PayPal');
		$paypal->checkout_id             = $checkout->id;
		$paypal->paypal_reference        = $payment_info->getTransactionID();
		$paypal->paypal_amount           = $paypal_amount;
		$paypal->paypal_fee              = $fee;
		$paypal->paypal_payer_info       = json_encode($payer_info);
		$paypal->paypal_transaction_info = json_encode($payment_info);
		$paypal->insert();

		if ($checkout->anonymous) {
			$this->request->session->set('anonymous_checkout_purchase', TRUE);
		}

		$enc_checkout_id = Encryption::obfuscate($checkout->id, $this->config->siteConfig()->secret);

		// goto the receipt
		throw new RedirectException($this->url->getUrl('Checkout', 'receipt', [$enc_checkout_id]));
	}

	protected function createCustomer($payer_info) {
		$paypal_person  = $payer_info->getPayerName();
		$model = new Model($this->config, $this->database);
		$customer = $model->getModel('\core\classes\models\Customer');
		$customer->password   = '';
		$customer->first_name = $paypal_person->getFirstName();
		$customer->last_name  = $paypal_person->getLastName();
		$customer->email      = $payer_info->getPayer();
		$customer->login      = $customer->email;

		return $customer;
	}

	protected function createAddress($payer_info) {
		$model = new Model($this->config, $this->database);
		$paypal_address = $payer_info->getAddress();
		$paypal_person  = $payer_info->getPayerName();

		# The country must exist
		$country = $model->getModel('\core\classes\models\Country')->get([
			'code' => $paypal_address->getCountry()
		]);
		if (!$country) {
			$this->invalidCountry($paypal_address->getCountryName());
			return NULL;
		}

		// get the state
		$state = $model->getModel('\core\classes\models\State')->get([
			'country_id' => $country->id,
			'name' => $paypal_address->getStateOrProvince(),
		]);
		if (!$state) {
			$state = $model->getModel('\core\classes\models\State');
			$state->country_id = $country->id;
			$state->name       = $paypal_address->getStateOrProvince();
			$state->insert();
		}

		// get the city
		$city = $model->getModel('\core\classes\models\City')->get([
			'country_id' => $country->id,
			'state_id' => $state->id,
			'name' => $paypal_address->getCityName(),
		]);
		if (!$city) {
			$city = $model->getModel('\core\classes\models\City');
			$city->country_id = $country->id;
			$city->state_id   = $state->id;
			$city->name       = $paypal_address->getCityName();
			$city->insert();
		}

		// create the address
		$address = $model->getModel('\core\classes\models\Address');
		$address->first_name  = $paypal_person->getFirstName();
		$address->last_name   = $paypal_person->getLastName();
		$address->line1       = $paypal_address->getStreet1();
		$address->line2       = $paypal_address->getStreet2();
		$address->postcode    = $paypal_address->getPostalCode();
		$address->city_id     = $city->id;
		$address->state_id    = $state->id;
		$address->country_id  = $country->id;

		return $address;
	}

	protected function displayError($response = NULL) {
		$this->language->loadLanguageFile('payment_paypal.php', 'modules'.DS.'payment_paypal');

		$error_message = '';
		$errors = $response->getErrors();
		$errors = is_array($errors) ? $errors : [ $errors ];
		$is_10486 = FALSE;
		foreach ($errors as $error) {
			if ($error->getErrorCode() == 10486) {
				$is_10486 = TRUE;
			}
			$error_message .= $error->getErrorCode().': '.$error->getLongMessage().'<br />';
		}

		$data = [
			'ack'            => $response->getAck(),
			'correlation_id' => $response->getCorrelationID(),
			'version'        => $response->getVersion(),
			'errors'         => $error_message,
		];
		if ($is_10486) {
			$this->logger->info("PayPal Error: $error_message");
			$template = $this->getTemplate('pages/paypal_error_10486.php', $data, 'modules'.DS.'payment_paypal');
		}
		else {
			$this->logger->error("PayPal Error: $error_message");
			$template = $this->getTemplate('pages/paypal_error.php', $data, 'modules'.DS.'payment_paypal');
		}
		$this->response->setContent($template->render());
	}

	protected function invalidCountry($country) {
		$this->logger->info("Purchase from invalid country: $country");
		$this->language->loadLanguageFile('payment_paypal.php', 'modules'.DS.'payment_paypal');
		$data = ['message' => $this->language->get('invalid_country', [$country])];
		$template = $this->getTemplate('pages/invalid_country.php', $data, 'modules'.DS.'payment_paypal');
		$this->response->setContent($template->render());
	}

	protected function disablePayPalErrors() {
		// PayPal SDK throws a few notices and warning
		suppress_exceptions('/((PayPal|PEAR|Log|ProfileHandler_Array|ProfileHandler|SOAP_Transport)::.* should not be called statically|Only variables should be (passed|assigned) by reference|A session had already been started|(include_once\(|Failed opening \')PayPal\/Type\/)/');
	}

	protected function getPaypalProfile($module_config) {
		$handler = &ProfileHandler_Array::getInstance([
			'username'        => $module_config->username,
			'certificateFile' => null,
			'subject'         => null,
			'environment'     => $module_config->mode
		]);
		$pid = ProfileHandler::generateID();
		$profile_obj = new APIProfile($pid, $handler);
		$profile = &$profile_obj;
		$profile->setAPIUsername($module_config->username);
		$profile->setAPIPassword($module_config->password);
		$profile->setSignature($module_config->signature);
		$profile->setEnvironment($module_config->mode);
		return $profile;
	}

	protected function checkPaypalResponse($response) {
		if (get_class($response) == 'SOAP_Fault') {
			$this->logger->info('PayPal Error='.$response);
		}
		$ack = $response->getAck();
		switch($ack) {
			case 'Success':
			case 'SuccessWithWarning':
				return TRUE;

			default:
				$this->displayError($response);
				return FALSE;
		}
	}
}
