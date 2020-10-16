<?php
class ControllerExtensionPaymentKreddyPaymentGateway extends Controller
{
    /**
     * Accountable for calculating and parsing the necessary data using the helper methods for installment calculation using the Kreddy API
     */
    public function index() 
    {
        $order = $this->getOrder($this->session->data['order_id']);
        
		$total = trim($this->currency->format($order['total'], $this->config->get('config_currency')), ',денари');
        
        $data['total'] = $total;

        //get total price in usable format
        if ($total) {
            $total = str_replace(',', '', $total);
            $total = intval($total);
        }

        //authorize current session
        $authorize = $this->authorize();

        $cookieString = $this->parseCookies($authorize);
       
        $this->setAuthCookie($this->parseCookies($authorize, true));
        
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
    
    /**
     * Gets fired after payment form confirmation
     */
    public function confirm() 
    {
        $formData = $_POST ? $_POST : null;
        $parsedData = '';
        $clientId = '';

        // parse necessary data into array
        if ($formData) {
            $parsedData = $this->parseFormData($formData);
        }
        
        //check if client exists
        if ($parsedData) {
            if ($parsedData['agreement_checked'] == 1 && $parsedData['kreddy_form']['client_embg'] != '') {
                $userCheck = $this->findKreddyUserByDocNum($parsedData['kreddy_form']['doc_type'], $parsedData['kreddy_form']['doc_number']);
            }
        }
        
        //create client if it doesnt exist
        if (!$userCheck) {
            $clientId = $this->createKreddyClient($parsedData['client'], $parsedData['kreddy_form']);
        } else {
            $clientId = $userCheck['id'];
        }

        $app = [];
        //create application
        if ($clientId) {
            $app = $this->createLoanApplication($clientId, $parsedData['client']['ip_address'], $parsedData['loan_info']);
        }

        //failed order status code
        $orderStatusCode = 10;

        //loan application is good
        if (isset($app['code']) && $app['code'] == 'OK') {
            $orderStatusCode = 1;
            
            //send email here
        }

		if ($this->session->data['payment_method']['code'] == 'kreddy_payment_gateway') {
			$this->load->model('checkout/order');

			$this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $orderStatusCode);
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
     * Parse cookies into string or array
     */
    public function parseCookies($authorize, $array = false)
    {
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

        //return cookies in array form
        if ($array && $array != false) {
            return $cookies;
        }

        $cookieString = '';

        if ($cookiesObj->Code == 0) {
            foreach ($cookies as $cookie) {
                $cookieString .= array_keys($cookie)[0] . "=" . array_values($cookie)[0] . '; ';
            }
        }

        $cookieString = str_replace('_ASPXAUTH', '.ASPXAUTH', $cookieString);

        return $cookieString;
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

    /**
     * Parses the data into implementable array
     */
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

    /**
     * Parses the received form data into an array
     */
    public function parseFormData($formData) : array 
    {
        //client billing data
        $clientFirstName = isset($formData['clientFirstName']) ? $formData['clientFirstName'] : '';
        $clientLastName = isset($formData['clientLastName']) ? $formData['clientLastName'] : '';
        $clientTelephone = isset($formData['clientTelephone']) ? $formData['clientTelephone'] : '';
        $clientEmail = isset($formData['clientEmail']) ? $formData['clientEmail'] : '';
        $clientEMBG = isset($formData['clientEmbg']) ? $formData['clientEmbg'] : '';

        //client passport or ID card info (0 = ID card, 1 = Passport)
        $clientDocType = isset($formData['clientDocType']) ? $formData['clientDocType'] : 0;
        $clientDocNumber = isset($formData['clientDocNumber']) ? $formData['clientDocNumber'] : '';

        //kreddy specific for client
        $clientCheckedAgreement = isset($formData['kreddy-agreement']) ? true : false;

        //client loan info
        $loanAPR = isset($formData['loanAPR']) ? $formData['loanAPR'] : 0;
        $loanAPRamount = isset($formData['loanAPRamount']) ? trim(str_replace('MKD', '', $formData['loanAPRamount'])) : 0;
        $loanAMTP = isset($formData['loanAMTP']) ? trim(str_replace('MKD', '', $formData['loanAMTP'])) : 0;
        $loanFeeAmount = isset($formData['loanFeeAmount']) ? trim(str_replace('MKD', '', $formData['loanFeeAmount'])) : 0;
        $loanDueDate = isset($formData['loanDueDate']) ? $formData['loanDueDate'] : 0;
        $nOfInstallments = isset($formData['installments']) ? $formData['installments'] : 3;

        //order info
        $orderNum = isset($formData['kreddyOrderNumber']) ? $formData['kreddyOrderNumber'] : 0;
        $order = $this->getOrder($orderNum);
        $orderTotal = trim($this->currency->format($order['total'], $this->config->get('config_currency')), ',денари');
        $clientIP = $order['ip'];
        
        $parsedInfo = [
            'agreement_checked' => $clientCheckedAgreement,
            'client' => [
                'first_name' => $clientFirstName,
                'last_name' => $clientLastName,
                'phone' => $clientTelephone,
                'email' => $clientEmail,
                'ip_address' => $clientIP
            ],
            'kreddy_form' => [
                'client_embg' => $clientEMBG,
                'doc_type' => $clientDocType,
                'doc_number' => $clientDocNumber,
            ],
            'loan_info' => [
                'asked_amount' => $orderTotal,
                'amount_to_pay' => $loanAMTP,
                'apr' => $loanAPR,
                'apr_amount' => $loanAPRamount,
                'fee' => $loanFeeAmount,
                'installments' => $nOfInstallments,
                'due_date' => $loanDueDate,
            ]
        ];

        return $parsedInfo;
    }

    /**
     * Retrieve order by id
     */
    public function getOrder($orderNum) 
    {
        $this->load->model('checkout/order');

        $order = $this->model_checkout_order->getOrder($orderNum);
        
        return $order;
    }

    /**
     * Searches for possible existing user in the kreddy records
     */
    public function findKreddyUserByDocNum($docType, $docNumber) 
    {
        $docTypeID = '';

        if ($docType == 0){
            // ID card
            $docTypeID = '1188b317-4229-4c67-8d20-19048e279aac';
        } else {
            // Passport
            $docTypeID = 'BDC463D4-FE42-406A-A3D8-C87320E3536C';
        }

        //get cookies in string format
        $cookies = $this->parseCookies($this->authorize());
        
        //API call
        $url = 'https://88.85.110.253/0/rest/FinstarClientAPI/clients?DocTypeID='.$docTypeID.'&DocNumber='.$docNumber;
            
        $curl = curl_init($url);
        
        curl_setopt($curl, CURLOPT_COOKIE, $cookies);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_VERBOSE, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($curl);

        curl_close($curl);

        $response = json_decode($result, true);
        
        if (!empty($response['ClientListResult']['ResponseObject'])) {
            return [
                'id' => $response['ClientListResult']['ResponseObject'][0]['ClientID'],
                'activeLoan' => $response['ClientListResult']['ResponseObject'][0]['ActiveLoan'] == '' ? 0 : 1
            ];
        }

        return false;
    }

    /**
     * Creates new Kreddy client 
     */
    public function createKreddyClient($clientData, $formData) 
    {
        // construct body of request
        $body = [
            'clientCreateRequest' => [
                'RequestObject' => [
                    'ClientStatusID' => '6ec0f766-d7f5-49b9-b36b-e63ea94de72a',
                    'CommercialBrandID' => '02775ed0-ca6e-4284-a391-8c1b22eef008',
                    'Firstname' => $clientData['first_name'],
                    'Lastname' => $clientData['last_name'],
                    'RegDocuments' => [
                        0 => [
                            'DocNumber' => $formData['client_embg'],
                            'DocTypeID' => 'BDC463D4-FE42-406A-A3D8-C87320E3536C',
                            'DocTypeName' => 'Jmbg',
                        ],
                        1 => [
                            'DocNumber' => $formData['doc_number'],
                            'DocTypeID' => '1188b317-4229-4c67-8d20-19048e279aac',
                            'DocTypeName' => $formData['doc_type'] == 0 ? 'ID card' : 'Passport',
                        ],
                    ],
                    'Communications' => [
                        0 => [
                            'CommTypeID' => 'd4a2dc80-30ca-df11-9b2a-001d60e938c6',
                            'CommTypeName' => 'Phone',
                            'CommValue' => $clientData['phone'],
                            'Confirmed' => null,
                        ],
                        1 => [
                            'CommTypeID' => 'ee1c85c3-cfcb-df11-9b2a-001d60e938c6',
                            'CommTypeName' => 'Email',
                            'CommValue' => $clientData['email'],
                            'Confirmed' => null,
                        ],
                    ],
                ]
            ]
        ];

        //api call headers
        $headers = [
            'Content-Type: application/json'
        ];

        //get cookies in string format
        $cookies = $this->parseCookies($this->authorize());

        //api call url
        $url = 'https://88.85.110.253/0/rest/FinstarClientAPI/clients';

        $curl = curl_init($url);
        
        //api call options
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_TIMEOUT, 10000);
        curl_setopt($curl, CURLOPT_COOKIE, $cookies);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_VERBOSE, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        //send call
        $result = curl_exec($curl);

        curl_close($curl);

        //decode response
        $response = json_decode($result, true);

        //if client was created return his id : return false
        if ($response['ClientCreateResult']['Code'] == 'OK') {
            return $response['ClientCreateResult']['Id'];
        } 

        return false;
    }

    /**
     * Creates loan application via the API
     *  */
    public function createLoanApplication($clientId, $clientIp, $loanData) 
    {
        $cookies = $this->parseCookies($this->authorize());

        $offerInfo = $this->offerInfo($cookies);
        
        $offer = $this->parseOffer($offerInfo['GetRelatedOffersListResult']['ResponseObject']['offers']);
        
        $productId = $offer['productId'];

        $cartTotal = rtrim($loanData['amount_to_pay'], ' мкд') - rtrim($loanData['apr_amount'], ' мкд');
        
        //body of API request
        $body = [
            'LoanApplicationCreateRequest' => [
                'RequestObject' => [
                    'ClientID' => $clientId,
                    'DisbursmentMethodID' => '25ab9503-6c8c-4ae0-802f-f76c0be9b610',
                    'LoanApplicationStatusID' => '1E8C32B6-1CC9-43E6-B45A-17AC51945208',
                    'Parameters' => [
                        0 => [
                            'LoanApplicationParameterID' => 'E477D926-A5B9-43A5-97FF-62B9ADC711D5',
                            'LoanApplicationParameterValue' => $cartTotal,
                        ],
                        1 => [
                            'LoanApplicationParameterID' => 'E9FC717B-AF4C-4C4C-85CD-C4FA09A944D8',
                            'LoanApplicationParameterValue' => $loanData['due_date'],
                        ],
                        2 => [
                            'LoanApplicationParameterID' => '223CB1CB-6BAA-4854-AB0D-03FBD620BA25',
                            'LoanApplicationParameterValue' => '0.00',
                        ],
                        3 => [
                            'LoanApplicationParameterID' => '42E7A51C-F936-4078-BA7F-6E24552E3280',
                            'LoanApplicationParameterValue' =>  rtrim($loanData['apr_amount'], ' мкд'),
                        ],
                        4 => [
                            'LoanApplicationParameterID' => '5E88395F-AA88-43F8-957E-A8A3A30F2EAE',
                            'LoanApplicationParameterValue' => rtrim($loanData['amount_to_pay'], ' мкд'),
                        ],
                        5 => [
                            'LoanApplicationParameterID' => 'F31DD9A9-BD38-4D01-BACD-5FEC11E5FB2A',
                            'LoanApplicationParameterValue' => rtrim($loanData['apr'], ' %'),
                        ],
                        6 => [
                            'LoanApplicationParameterID' => '510BA101-5A11-4DA8-8846-150F9AD66F7D',
                            'LoanApplicationParameterValue' => '0.00',
                        ],
                        7 => [
                            'LoanApplicationParameterID' => 'C644C489-D8C3-47DA-86EF-A80DA89DD747',
                            'LoanApplicationParameterValue' => $loanData['installments'],
                        ],
                        8 => [
                            'LoanApplicationParameterID' => 'C1B7C74E-19C2-4AFA-BA3B-009B9A3103BA',
                            'LoanApplicationParameterValue' => rtrim($loanData['fee'], ' мкд'),
                        ],
                    ],
                    'ProductID' => $productId,
                    'SigningMethodID' => '8801dbed-0af8-4141-9247-77d07ef668dc',
                    'CreationIP' => $clientIp,
                    'MarkAgree' => true,
                    'UserAgree' => true,
                ]
            ]
        ];

        //API call headers
        $headers = [
            'Content-Type: application/json'
        ];
        
        //url of API call
        $url = 'https://88.85.110.253/0/rest/FinstarLoanApplicationAPI/loan-applications/';

        $curl = curl_init($url);
        
        //api call options
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_TIMEOUT, 50000);
        curl_setopt($curl, CURLOPT_COOKIE, $cookies);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_VERBOSE, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); 

        //send call
        $result = curl_exec($curl);
        curl_close($curl);
        
        //decode response
        $response = json_decode($result, true);
        
        $applicationResult = [];

        //set application result data if response is ok
        if (isset($response['LoanApplicationCreateResult']) && $response['LoanApplicationCreateResult']['Code'] == "OK") {
            $applicationResult['code'] = $response['LoanApplicationCreateResult']['Code'];
            $applicationResult['application_id'] = $response['LoanApplicationCreateResult']['Id'];
        }

        return $applicationResult;
    }
}