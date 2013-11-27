<?php

namespace modules\payment_paypal;

use ErrorException;
use core\classes\Config;
use core\classes\Database;
use core\classes\Language;
use core\classes\Model;
use core\classes\Menu;

class Installer {
	protected $config;
	protected $database;

	public function __construct(Config $config, Database $database) {
		$this->config = $config;
		$this->database = $database;
	}

	public function install() {
		$model = new Model($this->config, $this->database);
		$table = $model->getModel('\\modules\\payment_paypal\\classes\\models\\PayPal');
		$table->createTable();
		$table->createIndexes();
		$table->createForeignKeys();
	}

	public function uninstall() {
		$model = new Model($this->config, $this->database);
		$table = $model->getModel('\\modules\\payment_paypal\\classes\\models\\PayPal');
		$table->dropTable();
	}

	public function enable() {
		$config = $this->config->getSiteConfig();
		$config['sites'][$this->config->getSiteDomain()]['checkout']['payment_methods']['paypal'] = [
			'name' => 'PayPal',
			'public' => '\modules\payment_paypal\controllers\PaymentPayPal',
			'administrator' => '\modules\payment_paypal\controllers\administrator\PaymentPayPal',
		];
		$this->config->setSiteConfig($config);
	}

	public function disable() {
		$config = $this->config->getSiteConfig();
		unset($config['sites'][$this->config->getSiteDomain()]['checkout']['payment_methods']['paypal']);
		$this->config->setSiteConfig($config);
	}
}