<?php
class ControllerExtensionPaymentKreddyPaymentGateway extends Controller
{
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

        $cookieString = '';

        if ($cookiesObj->Code == 0) {
            foreach ($cookies as $cookie) {
                $this->setAuthCookie($cookie);
                $cookieString .= array_keys($cookie)[0] . "=" . array_values($cookie)[0] . '; ';
            }
        }

        $cookieString = str_replace('_ASPXAUTH', '.ASPXAUTH', $cookieString);
        
        $offers = $this->offerInfo($cookieString);
        
        $parsedOffer = $this->parseOffer($offers['GetRelatedOffersListResult']['ResponseObject']['offers']);
        
        $bulkFile = $this->retrieveData($cookieString, $parsedOffer['productId']);

        var_dump($bulkFile);
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
            'UserName' => 'WebsiteUserRiversoft',
            'UserPassword' => 'Dssd\'tVtd#5g6'
        ];

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_VERBOSE, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($curl);
        
        curl_close($curl);
        
        return $result; 
    }

    public function setAuthCookie($cookie)
    {
        $name = array_keys($cookie)[0];

        if (!strpos($name, '_ASPXAUTH')) {
            $name = str_replace('_ASPXAUTH', '.ASPXAUTH', $name);
        }
        
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
                    'ClientId' => null,
                    'URL' => null
                ]
            ]
        ];

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_COOKIE, $cookies);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_VERBOSE, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($curl);

        curl_close($curl);
        
        return json_decode($result, true);
    }

    public function parseOffer($offers)
    {
        $parsedOffer = null;
        $offerArray = [];
       
        foreach ($offers as $offer) {
            if ($offer['Rank'] == 100)  {
                $parsedOffer = $offer;
                break;
            }
        }

        if ($parsedOffer) {
            $offerArray = [
                'bulkFileId' => $parsedOffer['BulkFileID'],
                'createdOn' => $parsedOffer['CreatedOn'],
                'type' => $parsedOffer['OfferType'],
                'productId' => $parsedOffer['ProductId'],
                'rank' => $parsedOffer['Rank']
            ];
        }

        return $offerArray;
    }

    public function retrieveData($cookies, $productId)
    {
        $redis = new Cache('redis', 432000);

        $cachedBulkFile = $redis->get('bulkFile');
        
        if (!$cachedBulkFile) {
            $maxTime = ini_get('max_execution_time');
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '512M');

            $url = 'https://88.85.110.253/0/rest/FinstarProductAPI/products/' . $productId . '/recalculatedOffer';
            
            $curl = curl_init($url);

            curl_setopt($curl, CURLOPT_TIMEOUT, 10000);
            curl_setopt($curl, CURLOPT_COOKIE, $cookies);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_VERBOSE, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            
            $result = curl_exec($curl);

            curl_close($curl);
    
            $jsonDecodedResult = json_decode($result, true);

            $base64DecodedResult = base64_decode($jsonDecodedResult['RecalculatedOfferResult']['ResponseObject']);

            $bulkFile = json_decode($base64DecodedResult, true);

            $redis->set('bulkFile', $bulkFile['PrecalculatedValues']);

            ini_set('max_execution_time', $maxTime);
            
            return $bulkFile['PrecalculatedValues'];
        }

        return $cachedBulkFile;
    }
}