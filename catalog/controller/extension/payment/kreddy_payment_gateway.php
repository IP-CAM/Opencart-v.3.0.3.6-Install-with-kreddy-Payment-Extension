<?php
class ControllerExtensionPaymentKreddyPaymentGateway extends Controller
{
    private $authUsername = 'WebsiteUserRiversoft';
    private $authPassword = 'Dssd\'tVtd#5g6';

    public function index() {
        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        
		$total = trim($this->currency->format($order_info['total'], $this->config->get('config_currency')), ',денари');
        
        //get total price in correct format
        if(preg_match("/^[0-9,]+$/", $total)) {
			$total = str_replace(',', '', $total);
        } 
        
        //authorize current session
        $authorize = $this->authorize();

        $authInfo = explode('GMT', $authorize);
        
        $cookiesObj = json_decode(end($authInfo));
        $cookies = [];
        
        preg_match_all('/^Set-Cookie:\s*([^;\r\n]*)/mi', $authorize, $matches);

        //set auth cookies
        foreach($matches[1] as $item) {
            parse_str($item, $cookie);
            array_push($cookies, $cookie);
        }

        $cookies = array_unique($cookies, SORT_REGULAR);

        if ($cookiesObj->Code == 0) {
            foreach ($cookies as $cookie) {
                $this->setAuthCookie($cookie);
            }
        }

        $offer = $this->offerInfo($cookies);
        print_r($cookies);
        die();
        $data['total'] = $total;
		$data['continue'] = $this->url->link('checkout/success');

		return $this->load->view('extension/payment/kreddy_payment_gateway', $data);
    }
    
    public function confirm() {
		if ($this->session->data['payment_method']['code'] == 'kreddy_payment_gateway') {
			$this->load->model('checkout/order');

			$this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('payment_kreddy_payment_gateway_order_status_id'));
		}
    }
    
    public function authorize()
    {
        $url = 'https://88.85.110.253/ServiceModel/AuthService.svc/Login';
        $headers = [
            'Content-Type: application/json'
        ];
        $body = [
            'UserName' => $this->authUsername,
            'UserPassword' => $this->authPassword
        ];

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($curl);
        
        return $result;
    }

    public function setAuthCookie($cookie)
    {
        $name = array_keys($cookie)[0];
        $value = array_values($cookie)[0];

        setcookie($name, $value, strtotime('+72 hours'));

        return;
    }

    public function offerInfo($cookies)
    {
        $url = 'https://88.85.110.253/0/rest/FinstarOffersAPI/offers';
        $headers = [
            'Content-Type: application/json'
        ];
        $body = [
            'getRelatedOffersListRequest' => [
                'APIKey' => null,
                'RequestObject' => [
                    'ClientId'=> null,
                    'URL' => null
                ]
            ]
        ];

        //get cookies in string format, to be set with CURLOPT_COOKIE
        $cookieString = '';

        foreach ($cookies as $cookie) {
            $cookieString .= array_keys($cookie)[0] . "=" . rawurlencode(array_values($cookie)[0]) . '; ';
        }
        print_r($cookieString);
        die();
        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_COOKIE, $cookieString);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($curl);

        print_r($result);
        die();
    }
}