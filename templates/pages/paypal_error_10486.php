<div class="<?php echo $page_class; ?>">
	 <h2><?php echo $text_bad_funding_method; ?></h2>
	 <p>
		 <?php echo $text_bad_funding_method_cause; ?>
	 </p>
	 <p>
		 <?php echo $text_redirect_to_paypal; ?>
	 </p>
	 <div>
		 <a href="<?php echo $this->url->getUrl('Checkout'); ?>" class="btn btn-primary" id="payment-button"><?php echo $text_goto_checkout; ?></a>
	 </div>
</div>
