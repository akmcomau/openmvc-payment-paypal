<?php

// Autoload PayPal classes
spl_autoload_register(function ($class) {
	$file = str_replace('\\', '/', $class);
	$root_path = __DIR__.'/../composer/vendor/paypal/PayPal-PHP-SDK/lib/';
	$filename = $root_path.$file.'.php';
	if (file_exists($filename)) {
		include($filename);
	}
});