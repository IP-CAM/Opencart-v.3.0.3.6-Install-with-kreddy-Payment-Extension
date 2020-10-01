<?php
class ControllerExtensionPaymentKreddyPaymentGateway extends Controller
{
    public function index() {
        $this->load->model('checkout/order');

        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        
		$total = trim($this->currency->format($order['total'], $this->config->get('config_currency')), ',денари');
        
        $data['total'] = $total;

        //get total price in usable format
        if ($total) {
            $total = str_replace(',', '', $total);
            $total = intval($total);
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
    
        $valuesForOrder = [];

        $minAmount = 3000;
        $maxAmount = 180000;

        if (($total % 100) != 0) {
            $valuesForOrder = $this->getValuesForOrderTotal($total, $bulkFile, $maxAmount, $minAmount);
        } else {
            $valuesForOrder = $this->getValuesForOrderTotalMultOfHundred($total, $bulkFile, $maxAmount, $minAmount);
        }
        
        $installmentsArray = $this->getInstallments($valuesForOrder);

        
        $data['installments'] = $installmentsArray;
        $data['order'] = $order;
        $data['continue'] = $this->url->link('checkout/success');

		return $this->load->view('extension/payment/kreddy_payment_gateway', $data);
    }
    
    public function confirm() {
		if ($this->session->data['payment_method']['code'] == 'kreddy_payment_gateway') {
			$this->load->model('checkout/order');

			$this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('payment_kreddy_payment_gateway_order_status_id'));
		}
    }
    
    /**
     * Authorizes via API and retrieves necessary headers (i.e. cookies)
     */
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

    /**
     * Sets necessary auth cookies for further requests
     */
    public function setAuthCookie(array $cookie)
    {
        $name = array_keys($cookie)[0];

        if (!strpos($name, '_ASPXAUTH')) {
            $name = str_replace('_ASPXAUTH', '.ASPXAUTH', $name);
        }
        
        $value = array_values($cookie)[0];

        setcookie($name, $value, strtotime('+72 hours'));

        return;
    }

    /**
     * Retrieves metadata about offers from the API
     */
    public function offerInfo(string $cookies) : array
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

    /**
     * Parses the API response for offer metadata into a usable array
     */ 
    public function parseOffer(array $offers) : array
    {
        $parsedOffer = null;
        $bulkFileID = "8b14e4f7-c181-4ea5-9e1d-09b314c054a8";
        $offerArray = [];

        foreach ($offers as $offer) {
            if ($offer['BulkFileID'] == $bulkFileID)  {
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

    /**
     * Retrieves installment data, from the cache or from the API
     */
    public function retrieveData(string $cookies, string $productId) : array
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

    /**
     * Calculates installment details based on order parameters using API metadata (if total is multiple of 100)
     */
    public function getValuesForOrderTotalMultOfHundred(int $total, array $bulkFile, int $maxAmount, int $minAmount) : array
    {
        $stepForAmount = (($maxAmount - $minAmount) / 100) + 1;
        $nthArray = ($total - $minAmount) / 100;

        $installments = [
            $bulkFile[$nthArray]
        ];

        for ($i=4; $i <= 36; $i++) { 
            $currentArrOrder = $nthArray + ($stepForAmount * ($i - 3));
            $installments[] = $bulkFile[$currentArrOrder];
        }
        
        return $installments;
    }

    /**
     * Calculates installment details based on order parameters using API metadata
     */
    public function getValuesForOrderTotal(int $total, array $bulkFile, int $maxAmount, int $minAmount) : array
    {
        $stepForAmount = (($maxAmount - $minAmount) / 100) + 1;
        $lowerAmtTo100 = $total - ($total % 100);
        $higherAmtTo100 = $lowerAmtTo100 + 100;
        $arrayForLowerAmtTo100 = ($lowerAmtTo100 - $minAmount) / 100;
        $arrayForHigherAmtTo100 = ($higherAmtTo100 - $minAmount) / 100;

        $lowerAmount = [
            $bulkFile[$arrayForLowerAmtTo100],
        ];

        $higherAmount = [
            $bulkFile[$arrayForHigherAmtTo100],
        ];
        
        for ($i=4; $i <= 36; $i++) { 
            $currentArrOrder = $arrayForLowerAmtTo100 + ($stepForAmount * ($i - 3));
            $currentArrOrderHigher = $arrayForHigherAmtTo100 + ($stepForAmount * ($i - 3));
            
            $lowerAmount[] = $bulkFile[$currentArrOrder];
            $higherAmount[] = $bulkFile[$currentArrOrderHigher];
        }

        $atpRecalculated = [];
    
        foreach ($lowerAmount as $index => $arr) {
            $recalculatedATP = round(((($higherAmount[$index]['totalDue'] - $arr['totalDue'] ) / 100 ) * ( $total - $lowerAmtTo100 )) + $higherAmount[$index]['totalDue']);
            $noOfInstallments = $arr['term'];
            $aprValue = $recalculatedATP - $total;
            $singleInstallment = $recalculatedATP / $noOfInstallments;
            
            $atpRecalculated[] = [
                'term' => $arr['term'],
                'amount' => $arr['amount'],
                'totalDue' => $recalculatedATP,
                'dueDate' => $arr['dueDate'],
                'apr' => $arr['apr'],
                'totalFeeDue' => $aprValue,
                'totalInterestDue' => $arr['totalInterestDue'],
                'monthlyPayment' => $singleInstallment,
                'deductedFee' => $arr['deductedFee'],
                'guarantorPenaltyFee' => $arr['guarantorPenaltyFee'],
                'totalFees' => $arr['totalFees'],
                'monthlyPaymentPlusGuarantorPenaltyFee' => $arr['monthlyPaymentPlusGuarantorPenaltyFee'],
            ];
        }
        
        return $atpRecalculated;
    }   

    public function getInstallments(array $values) : array
    {
        $counter = 3;

        $installments = [];

        for ($i = 0; $i <= 33; $i++) {
            $installments[$counter] = [
                'amount_to_pay' 			=> ceil($values[$i]['totalDue']),
                'due_date'					=> $values[$i]['dueDate'],
                'apr'						=> $values[$i]['apr'],
                'fee_amount'				=> ceil($values[$i]['totalFeeDue']),
                'interest_amount'			=> $values[$i]['totalInterestDue'],
                'installment_payment'		=> ceil($values[$i]['monthlyPayment']),
                'no_of_installments'		=> $values[$i]['term'],
                'deducted_fee'				=> $values[$i]['deductedFee'],
                'deducted_plus_interest'	=> ceil($values[$i]['guarantorPenaltyFee']),
                'deposit_amount'			=> ceil($values[$i]['monthlyPaymentPlusGuarantorPenaltyFee']),
            ];

            $counter++;
        }

        return $installments;
    }
}