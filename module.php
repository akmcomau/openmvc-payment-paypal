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
		"sdk_path" => "/path/to/sdk",
		"mode" => "sandbox",
		"username" => "",
		"password" => "",
		"signature" => ""
	]
];
