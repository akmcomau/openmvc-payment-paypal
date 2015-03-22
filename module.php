<?php
$_MODULE = [
	"name" => "Payment - PayPal",
	"description" => "Support for PayPal payments within the checkout",
	"namespace" => "\\modules\\payment_paypal",
	"config_controller" => "administrator\\PaymentPayPal",
	"controllers" => [
		"administrator\\PaymentPayPal",
		"PaymentPayPal"
	],
	"default_config" => [
		"currency" => "AUD",
		"mode" => "sandbox",
		"client_id" => "",
		"secret" => ""
	]
];
