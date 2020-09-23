<?php
class ControllerExtensionPaymentKreddyPaymentGateway extends Controller
{
    private $error = array();

    public function index() {
        $this->language->load('extension/payment/kreddy_payment_gateway');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        $this->load->model('localisation/order_status');
        
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_kreddy_payment_gateway', $this->request->post);
														
			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }
        
        $data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extensions'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/kreddy_payment_gateway', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/payment/kreddy_payment_gateway', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        
        if (isset($this->request->post['payment_kreddy_payment_gateway_status'])) {
            $data['payment_kreddy_payment_gateway_status'] = $this->request->post['payment_kreddy_payment_gateway_status'];
        } else {
            $data['payment_kreddy_payment_gateway_status'] = $this->config->get('payment_kreddy_payment_gateway_status');
        }
            
        if (isset($this->request->post['payment_kreddy_payment_gateway_status_id'])) {
            $data['payment_kreddy_payment_gateway_status_id'] = $this->request->post['payment_kreddy_payment_gateway_status_id'];
        } else {
            $data['payment_kreddy_payment_gateway_status_id'] = $this->config->get('payment_kreddy_payment_gateway_status_id');
        }

        if (isset($this->request->post['payment_kreddy_payment_gateway_sort_order'])) {
			$data['payment_kreddy_payment_gateway_sort_order'] = $this->request->post['payment_kreddy_payment_gateway_sort_order'];
		} else {
			$data['payment_kreddy_payment_gateway_sort_order'] = $this->config->get('payment_kreddy_payment_gateway_sort_order');
		}
        
        if (isset($this->request->server['HTTPS']) && (($this->request->server['HTTPS'] == 'on') || ($this->request->server['HTTPS'] == '1'))) {
			$data['server'] = HTTPS_SERVER;
			$data['catalog'] = HTTPS_CATALOG;
		} else {
			$data['server'] = HTTP_SERVER;
			$data['catalog'] = HTTP_CATALOG;
        }

        if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}
					
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/kreddy_payment_gateway', $data));
    }

    protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/kreddy_payment_gateway')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}
}