<?php
class ModelExtensionPaymentKreddyPaymentGateway extends Model {
	
    public function getMethod($address, $total) {
		$this->load->language('extension/payment/kreddy_payment_gateway');
		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

		$total = trim($this->currency->format($order_info['total'], $this->config->get('config_currency')), ',денари');
		
		if(preg_match("/^[0-9,]+$/", $total)) {
			$total = str_replace(',', '', $total);
		} 

		$status = false;

		if ($total >= 3000 && $total <= 120000) {
			$status = true;
		} 

		$method_data = array();
		
		if ($status) {
			$method_data = array(
				'code'       => 'kreddy_payment_gateway',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('payment_kreddy_payment_gateway_sort_order'),
				'total' => $total
			);
		}

		return $method_data;
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