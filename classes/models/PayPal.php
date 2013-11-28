<?php

namespace modules\payment_paypal\classes\models;

use core\classes\Model;
use core\classes\Encryption;

class PayPal extends Model {
	protected $table       = 'paypal';
	protected $primary_key = 'paypal_id';
	protected $columns     = [
		'paypal_id' => [
			'data_type'      => 'bigint',
			'auto_increment' => TRUE,
			'null_allowed'   => FALSE,
		],
		'paypal_created' => [
			'data_type'      => 'datetime',
			'null_allowed'   => FALSE,
		],
		'checkout_id' => [
			'data_type'      => 'bigint',
			'null_allowed'   => FALSE,
		],
		'paypal_reference' => [
			'data_type'      => 'text',
			'data_length'    => 32,
			'null_allowed'   => TRUE,
		],
		'paypal_amount' => [
			'data_type'      => 'numeric',
			'data_length'    => [6, 4],
			'null_allowed'   => FALSE,
		],
		'paypal_fee' => [
			'data_type'      => 'numeric',
			'data_length'    => [6, 4],
			'null_allowed'   => FALSE,
		],
		'paypal_payer_info' => [
			'data_type'      => 'text',
			'null_allowed'   => FALSE,
		],
		'paypal_transaction_info' => [
			'data_type'      => 'text',
			'null_allowed'   => FALSE,
		],
	];

	protected $indexes = [
		'paypal_created',
		'checkout_id',
		'paypal_reference',
	];

	protected $foreign_keys = [
		'checkout_id'     => ['checkout',     'checkout_id'],
	];
}
