<?php
class ModelExtensionPaymentKreddyPaymentGateway extends Model 
{
    public function getMethod($address, $total) {
		$this->load->language('extension/payment/kreddy_payment_gateway');
		$this->load->model('checkout/order');
		$status = false;
	
		if (isset($total) && $total) {
			$cartPrice = trim($this->currency->format($total, $this->config->get('config_currency')), ',денари');
		
			$cartPrice = str_replace(',', '', $cartPrice);
		}

		if ($cartPrice >= 3000 && $cartPrice <= 180000) {
			$status = true;
		} 

		$data = array();
		
		if ($status) {
			$data = array(
				'code'       => 'kreddy_payment_gateway',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('payment_kreddy_payment_gateway_sort_order'),
				'total' => $cartPrice
			);
		}

		return $data;
	}

	public function mapCurrency($code) {
		$currency = array(
			'MKD' => 807,
		);

		if (array_key_exists($code, $currency)) {
			return $currency[$code];
		} else {
			return false;
		}
	}
}
?>