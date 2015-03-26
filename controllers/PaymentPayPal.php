<?php

namespace modules\payment_paypal\controllers;

use Exception;
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
			$this->display_error();
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

		$payment = null;
		$payer = null;
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
			$this->display_error();
			return;
		}
		catch (Exception $ex) {
			$this->logger->error('PayPal Error: '.$ex->getMessage());
			$this->display_error();
			return;
		}

		// Get the fees
		$transactions = $payment->getTransactions();
		$sales = $transactions[0]->getRelatedResources();
		$sale = $sales[0]->getSale();
		$fee = $sale->getTransactionFee()->getValue();
		$fee = $fee ? $fee : 0;

		// create the customer
		$customer = $this->createCustomer($payer->getPayerInfo());
		$address = $this->createAddress($payer->getPayerInfo());
		if (!$address) {
			return;
		}

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
		$paypal->paypal_reference        = $payment_id;
		$paypal->paypal_amount           = $sale->getAmount()->getTotal();
		$paypal->paypal_fee              = $fee;
		$paypal->paypal_payer_info       = $payer->toJSON();
		$paypal->paypal_transaction_info = $payment->toJSON();
		$paypal->insert();

		if ($checkout->anonymous) {
			$this->request->session->set('anonymous_checkout_purchase', TRUE);
		}

		$enc_checkout_id = Encryption::obfuscate($checkout->id, $this->config->siteConfig()->secret);

		// goto the receipt
		throw new RedirectException($this->url->getUrl('Checkout', 'receipt', [$enc_checkout_id]));
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
			$this->invalidCountry($paypal_address->getCountryCode());
			return NULL;
		}

		// get the state
		$state = $model->getModel('\core\classes\models\State')->get([
			'country_id' => $country->id,
			'code' => $paypal_address->getState(),
		]);
		if (!$state) {
			$state = $model->getModel('\core\classes\models\State');
			$state->country_id = $country->id;
			$state->code       = $paypal_address->getState();
			$state->name       = $paypal_address->getState();
			$state->insert();
		}

		// get the city
		$city = $model->getModel('\core\classes\models\City')->get([
			'country_id' => $country->id,
			'state_id' => $state->id,
			'name' => $paypal_address->getCity(),
		]);
		if (!$city) {
			$city = $model->getModel('\core\classes\models\City');
			$city->country_id = $country->id;
			$city->state_id   = $state->id;
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
		$address->state_id    = $state->id;
		$address->country_id  = $country->id;

		return $address;
	}

	protected function display_error($response = NULL) {
		$this->language->loadLanguageFile('payment_paypal.php', 'modules'.DS.'payment_paypal');

		$error_message = '';
		$errors = $response ? $response->getErrors() : array();
		$errors = is_array($errors) ? $errors : [ $errors ];
		$is_10486 = FALSE;
		foreach ($errors as $error) {
			if ($error->getErrorCode() == 10486) {
				$is_10486 = TRUE;
			}
			$error_message .= $error->getErrorCode().': '.$error->getLongMessage().'<br />';
		}
		if ($error_message == '') $error_message = 'An error occurred';

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
