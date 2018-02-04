<div class="<?php echo $page_class; ?>">
	<h1 style="color: darkred;"><?php echo nl2br($text_paypal_error_msg); ?></h1>
	<br />
	<form action="<?php echo $this->url->getUrl('Cart'); ?>" method="post">
		<div class="row public-form"><div class="col-md-12">
			<table class="table">
				<tr>
					<th class="hidden-xs"><?php echo $text_sku; ?></th>
					<th><?php echo $text_name; ?></th>
					<th class="hidden-xs"><?php echo $text_price; ?></th>
					<th><?php echo $text_quantity; ?></th>
					<th><?php echo $text_total; ?></th>
				</tr>
				<?php foreach ($contents as $item) { ?>
					<tr>
						<td class="hidden-xs"><?php echo $item->getSKU(); ?></td>
						<td><?php echo $item->getName(); ?></td>
						<td class="hidden-xs"><?php echo money_format('%n', $item->getSellPrice()); ?></td>
						<td>
							<?php echo $item->getQuantity(); ?>
						</td>
						<td><?php echo money_format('%n', $item->getSellTotal()); ?></td>
					</tr>
				<?php } ?>
				<tr>
					<th class="visible-xs"></th>
					<th class="hidden-xs" colspan="3"></th>
					<th><?php echo $text_total; ?></th>
					<th><?php echo money_format('%n', $total); ?></th>
				</tr>
			</table>
		</div></div>
	</form>
</div>
