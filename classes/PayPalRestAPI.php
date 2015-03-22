<?php

namespace modules\payment_paypal\classes;

use modules\checkout\classes\Cart;
use core\classes\Encryption;

use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Amount;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Transaction;
use PayPal\Api\RedirectUrls;

class PayPalRestAPI {
	protected $config;
	protected $module_config;
	protected $url;

	protected $mode;
	protected $client_id;
	protected $secret;

	protected $credentials = null;

	public function __construct($config, $url) {
		$this->module_config = $config->moduleConfig('\modules\payment_paypal');
		$this->config        = $config;
		$this->url           = $url;

		$this->mode      = ($this->module_config->mode == 'live') ? 'live' : 'sandbox';
		$this->client_id = $this->module_config->client_id;
		$this->secret    = $this->module_config->secret;
	}

	public function set_express_checkout(Cart $cart) {
		if ($this->credentials == null) $this->get_oauth_token();

		$apiContext = new ApiContext($this->credentials, 'Request-'.rand().'-'.time());
		$apiContext->setConfig($this->sdk_config());

		$payer = new Payer();
		$payer->setPaymentMethod("paypal");

		$amount = new Amount();
		$amount->setCurrency($this->module_config->currency);
		$amount->setTotal($cart->getGrandTotal());

		// Create the items
		$items = new ItemList();
		/*
		foreach ($cart->getContents() as $cart_item) {
			$item = new Item();
			$item->setQuantity($cart_item->getQuantity());
			$item->setName($cart_item->getName());
			$item->setPrice($cart_item->getPrice());
			$item->setCurrency($this->module_config->currency);
			$item->setSku('asdfad'.rand());
			$items->addItem(array($item));
		}
		*/

		$transaction = new Transaction();
		$transaction->setDescription($this->config->siteConfig()->name." Checkout");
		$transaction->setAmount($amount);
		$transaction->setItemList($items);

		$redirectUrls = new RedirectUrls();
		$redirectUrls->setReturnUrl($this->url->getUrl('PaymentPayPal', 'confirm'));
		$redirectUrls->setCancelUrl($this->url->getUrl('Cart'));

		$payment = new Payment();
		$payment->setIntent("sale");
		$payment->setPayer($payer);
		$payment->setRedirectUrls($redirectUrls);
		$payment->setTransactions(array($transaction));

		$payment->create($apiContext);

		return $payment;
	}

	public function do_express_checkout_payment($payer_id, $payment_id) {
		if ($this->credentials == null) $this->get_oauth_token();

		$apiContext = new ApiContext($this->credentials, 'Request-'.rand().'-'.time());
		$apiContext->setConfig($this->sdk_config());

		$payment = new Payment();
		$payment->setId($payment_id);
		$execution = new PaymentExecution();
		$execution->setPayerId($payer_id);
		$payment->execute($execution, $apiContext);

		return $payment;
	}

	private function sdk_config() {
		return array(
			'mode' => $this->mode,
		);
	}

	private function get_oauth_token() {
		$this->credentials = new OAuthTokenCredential($this->client_id, $this->secret, $this->sdk_config());
	}
}
