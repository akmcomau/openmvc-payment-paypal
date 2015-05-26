<?php

namespace modules\payment_paypal\controllers;

use Exception;
use ErrorException;
use core\classes\exceptions\RedirectException;
use core\classes\renderable\Controller;
use core\classes\Config;
use core\classes\Database;
use core\classes\Email;
use core\classes\Response;
use core\classes\Template;
use core\classes\Language;
use core\classes\Request;
use core\classes\Encryption;
use core\classes\Model;
use core\classes\Pagination;
use core\classes\FormValidator;
use modules\checkout\classes\Cart;
use modules\checkout\classes\Order;
use modules\payment_paypal\classes\PayPalRestAPI;
use PayPal\Exception\PayPalConnectionException;

class PaymentPayPal extends Controller {

	protected $permissions = [
	];

	public function getAllUrls($include_filter = NULL, $exclude_filter = NULL) {
		return [];
	}

	public function payment() {
		$cart = new Cart($this->config, $this->database, $this->request);

		if ($cart->getGrandTotal() == 0) {
			$this->logger->info('Cart is empty');
			throw new RedirectException($this->url->getUrl('Cart'));
		}

		$payment = null;
		try {
			$api = new PayPalRestAPI($this->config, $this->url);
			$payment = $api->set_express_checkout($cart);
		}
		catch (PayPalConnectionException $ex) {
			$this->logger->error('PayPal Error: '.$ex->getMessage().': '.$ex->getData());
			$this->display_error($ex->getData());
			return;
		}
		catch (Exception $ex) {
			$this->logger->error('PayPal Error: '.$ex->getMessage());
			$this->display_error();
			return;
		}

		$this->logger->info('Redirecting to PayPal');
		throw new RedirectException($payment->getApprovalLink());
	}

	public function confirm() {
		$this->language->loadLanguageFile('customer.php');
		$this->language->loadLanguageFile('checkout.php', 'modules'.DS.'checkout');
		$this->language->loadLanguageFile('administrator/orders.php', 'modules'.DS.'checkout');

		$cart = new Cart($this->config, $this->database, $this->request);
		$order = new Order($this->config, $this->database, $cart);

		$token      = $this->request->requestParam('token');
		$payer_id   = $this->request->requestParam('PayerID');
		$payment_id = $this->request->requestParam('paymentId');

		$payment = NULL;
		$payer = NULL;
		try {
			$api = new PayPalRestAPI($this->config, $this->url);
			$payment = $api->do_express_checkout_payment($payer_id, $payment_id, $token);
			$payer = $payment->getPayer();

			$this->logger->info('Transaction state: '.$payment->getState());
		    if ($payment->getState() != 'approved') {
				$this->display_error('Transaction was not approved.');
				return;
			}
		}
		catch (PayPalConnectionException $ex) {
			$this->logger->error('PayPal Error: '.$ex->getMessage().': '.$ex->getData());
			$this->display_error($ex->getData());
			return;
		}
		catch (Exception $ex) {
			$this->logger->error('PayPal Error: '.$ex->getMessage());
			$this->display_error();
			return;
		}

		$enc_checkout_id = NULL;
		try {
			throw new Exception("sdaf");
			// Get the fees
			$transactions = $payment->getTransactions();
			$sales = $transactions[0]->getRelatedResources();
			$sale = $sales[0]->getSale();
			$fee = $sale->getTransactionFee()->getValue();
			$fee = $fee ? $fee : 0;

			// create the customer
			// sometimees paypal does not reply with a shipping address
			// so a address cannot be created
			$customer = NULL;
			$address = NULL;
			if ($payer->getPayerInfo()->getEmail()) {
				$customer = $this->createCustomer($payer->getPayerInfo());
				$address = $this->createAddress($payer->getPayerInfo());
			}
			else {
				$this->logger->error("PayPal did not respond with PayerInfo");
			}

			// purchase the order
			$model = new Model($this->config, $this->database);
			$status = $model->getModel('\modules\checkout\classes\models\CheckoutStatus');
			$checkout = $order->purchase('paypal', $customer, $address, $address);
			$enc_checkout_id = Encryption::obfuscate($checkout->id, $this->config->siteConfig()->secret);
			if ($checkout->shipping_address_id) {
				$checkout->status_id = $status->getStatusId('Processing');
			}
			else {
				$checkout->status_id = $status->getStatusId('Complete');
			}
			$checkout->fees = $fee;
			$checkout->receipt_note = $customer ? NULL : 'paypal_no_payer_info';
			$checkout->update();
			$order->sendOrderEmails($checkout, $this->language);

			// create the paypal transaction record
			$paypal = $model->getModel('\modules\payment_paypal\classes\models\PayPal');
			$paypal->checkout_id             = $checkout->id;
			$paypal->paypal_reference        = $payment_id;
			$paypal->paypal_amount           = $sale->getAmount()->getTotal();
			$paypal->paypal_fee              = $fee;
			$paypal->paypal_payer_info       = $payer->toJSON();
			$paypal->paypal_transaction_info = $payment->toJSON();
			$paypal->insert();

			if ($checkout->anonymous) {
				$this->request->session->set('anonymous_checkout_purchase', TRUE);
			}
		}
		catch (Exception $ex) {
			$this->logger->error('Purchasing Error: '.$ex->getMessage());
			$this->request->session->delete('paypal-error-email-shown');
			throw new RedirectException($this->url->getUrl('PaymentPayPal', 'errorPaid'));
		}

		// goto the receipt
		$cart->clear();
		throw new RedirectException($this->url->getUrl('Checkout', 'receipt', [$enc_checkout_id]));
	}

	public function errorPaid() {
		$this->language->loadLanguageFile('payment_paypal.php', 'modules'.DS.'payment_paypal');
		$this->language->loadLanguageFile('checkout.php', 'modules'.DS.'checkout');

		$cart = new Cart($this->config, $this->database, $this->request);
		$data = [
			'contents' => $cart->getContents(),
			'total' => $cart->getCartSellTotal(),
		];

		if (!$this->request->session->get('paypal-error-email-shown')) {
			$body = $this->getEmailTemplate($this->language, 'emails/internal_error.txt.php', $data, 'modules'.DS.'payment_paypal');
			$html = $this->getEmailTemplate($this->language, 'emails/internal_error.html.php', $data, 'modules'.DS.'payment_paypal');
			$email = new Email($this->config);
			$email->setToEmail($this->config->siteConfig()->email_addresses->orders);
			$email->setSubject($this->config->siteConfig()->name.': '.$this->language->get('internal_error_subject'));
			$email->setBodyTemplate($body);
			$email->setHtmlTemplate($html);
			$email->send();

			$this->request->session->set('paypal-error-email-shown', TRUE);
		}

		$template = $this->getTemplate('pages/internal_error.php', $data, 'modules'.DS.'payment_paypal');
		$this->response->setContent($template->render());
	}

	protected function getEmailTemplate(Language $language, $filename, array $data = NULL, $path = NULL) {
		return new Template($this->config, $language, $filename, $data, $path);
	}

	protected function createCustomer($payer_info) {
		$model = new Model($this->config, $this->database);
		$customer = $model->getModel('\core\classes\models\Customer');
		$customer->password   = '';
		$customer->first_name = $payer_info->getFirstName() ? $payer_info->getFirstName() : '';
		$customer->last_name  = $payer_info->getLastName() ? $payer_info->getLastName() : '';
		$customer->email      = $payer_info->getEmail() ? $payer_info->getEmail() : '';
		$customer->login      = $customer->email;

		return $customer;
	}

	protected function createAddress($payer_info) {
		$model = new Model($this->config, $this->database);
		$paypal_address = $payer_info->getShippingAddress();

		# The country must exist
		$country = $model->getModel('\core\classes\models\Country')->get([
			'code' => $paypal_address->getCountryCode()
		]);
		if (!$country) {
			$country = $model->getModel('\core\classes\models\Country');
			$country->code = $paypal_address->getCountryCode();
			$country->name = $paypal_address->getCountryCode();
			$country->insert();
		}

		// get the state
		$state = NULL;
		if ($paypal_address->getState()) {
			$state = $model->getModel('\core\classes\models\State')->get([
				'country_id' => $country->id,
				'name' => $paypal_address->getState(),
			]);
			if (!$state) {
				$state = $model->getModel('\core\classes\models\State');
				$state->country_id = $country->id;
				$state->abbrev     = $paypal_address->getState();
				$state->name       = $paypal_address->getState();
				$state->insert();
			}
		}

		// get the city
		$city = $model->getModel('\core\classes\models\City')->get([
			'country_id' => $country->id,
			'state_id' => $state ? $state->id : NULL,
			'name' => $paypal_address->getCity(),
		]);
		if (!$city) {
			$city = $model->getModel('\core\classes\models\City');
			$city->country_id = $country->id;
			$city->state_id   = $state ? $state->id : NULL;
			$city->name       = $paypal_address->getCity();
			$city->insert();
		}

		// create the address
		$address = $model->getModel('\core\classes\models\Address');
		$address->first_name  = $payer_info->getFirstName() ? $payer_info->getFirstName() : '';
		$address->last_name   = $payer_info->getLastName() ? $payer_info->getLastName() : '';
		$address->line1       = $paypal_address->getLine1() ? $paypal_address->getLine1() : '';
		$address->line2       = $paypal_address->getLine2() ? $paypal_address->getLine2() : '';
		$address->postcode    = $paypal_address->getPostalCode() ? $paypal_address->getPostalCode() : '';
		$address->city_id     = $city->id;
		$address->state_id    = $state ? $state->id : NULL;
		$address->country_id  = $country->id;

		return $address;
	}

	protected function display_error($response = NULL) {
		$this->language->loadLanguageFile('payment_paypal.php', 'modules'.DS.'payment_paypal');
		try {
			if ($response) $response = json_decode($response);
		}
		catch (Exception $ex) {
			$response = NULL;
		}

		$error_message = '';
		$is_10486 = FALSE;
		if ($response && $response->name == 'INSTRUMENT_DECLINED') $is_10486 = TRUE;
		else if ($response) $error_message = $response->message;
		else $error_message = 'An error occurred';

		$data = [
			'errors' => $error_message,
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
}
