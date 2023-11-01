<?php


enum paypalQuery:string {
    case OrderUrl = '/v2/checkout/orders';
    case getOrderInfo = 'getOrderInfo:["order_id" => string]';
    case createOrder = 'createOrder:["order_id" => string]';
    case capturePayment = 'capturePayment:["order_id" => string]';
}

enum PaypalCurrency:string {
    case CAD = 'CAD';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case JPY = 'JPY';
    case CHF = 'CHF';
}
enum PaypalCurrencyFees {
    case CAD;
    case USD;
    case EUR;
    case GBP;
    case JPY;
    case CHF;
}

class PaypalApi
{

    private $client_id;
    private $client_secret;
    private $access_token;
    private $expires_in;
    private $refresh_token;
    private $nonce;
    private $scope;
    private $token_type;
    private $currency = 'CAD';
    private $api_host;
    public array $error = array();
    private $requests;
    private $client_Token = null;

    /**
     * in Percent %
     */
    private static float $fees = 1.029;



    public function __construct(string $client_id, string $client_secret, string $baseURL) {
        include_once CLASS_PATH . '/request.class.php';

        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->api_host = $baseURL;
        $this->requests = new Requests();
    }
    private function initPOSTRequests(paypalQuery $query, array $data = [], array $postData = []):CurlHandle|bool {
        $url = '';
        $postEnable = false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $this->GetAccessToken()));

        switch ($query) {
            case paypalQuery::createOrder:
                $url = paypalQuery::OrderUrl->value;
                $postEnable = true;
                break;
            case paypalQuery::getOrderInfo:
                $url = paypalQuery::OrderUrl->value . '/' . $data['order_id'];
                break;
            case paypalQuery::capturePayment:
                $url = paypalQuery::OrderUrl->value. '/'. $data['order_id']. '/capture';
                $postEnable = true;
                break;
            default:
                return false;
        }
        if($postEnable) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        }
        
        curl_setopt($ch, CURLOPT_URL, $this->api_host . $url);
        return $ch;
    }
    private function makeRequests(CurlHandle $ch):array {
        $output = json_decode(curl_exec($ch), true);
        $httpcode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        if($httpcode != 201 && $httpcode != 200) {
            $this->error[] = $output;
            throw new Exception(
                "Paypal:\nHttp Code: {$httpcode}\nData: " . json_encode($output, JSON_PRETTY_PRINT)
            );
        }
        curl_close($ch);
        return $output;
    }
    public static function getFees(float $amount, PaypalCurrencyFees $currency):float {
        $value = 0.00;
        switch ($currency) {
            case PaypalCurrencyFees::CAD:
                $value = 0.30;
                break;
            case PaypalCurrencyFees::USD:
                $value = 0.30;
                break;
            case PaypalCurrencyFees::EUR:
                $value = 0.35;
                break;
            case PaypalCurrencyFees::GBP:
                $value = 0.20;
                break;
            case PaypalCurrencyFees::JPY:
                $value = 40.00;
                break;
            case PaypalCurrencyFees::CHF:
                $value = 0.55;
                break;
        }
        return round(((($amount * self::$fees) + $value) - $amount), 2);
    }
    public function CreateOrder(String $amount = '1.00', PaypalCurrency $currency = PaypalCurrency::CAD):PaypalOrder {
        $this->currency = $currency;
        $ch = $this->initPOSTRequests(paypalQuery::createOrder, [], array(
            'intent' => 'CAPTURE', 
            'purchase_units' => array(
                array(
                    'amount' => array(
                        'currency_code' => $currency->value,
                        'value' => $amount
                    ))))
        );
        $output = $this->makeRequests($ch);

        $orderObj = new PaypalOrder($output['id'], paypalOrderStatus::from($output['status']), $output['links'], date('Y-m-d H:i:s'));
        $orderObj->setPrice(floatval($amount), $currency->value);
        $orderObj->setRawData(json_encode($output));
        return $orderObj;
    }
    public function getOrderInfo(string $order_id):paypalOrderInfo {
        $ch = $this->initPOSTRequests(paypalQuery::getOrderInfo, array('order_id' => $order_id));
        $data = $this->makeRequests($ch);
        return new paypalOrderInfo(
            $data['id'], 
            paypalOrderStatus::from($data['status']),
            $data['intent'],
            $data['payment_source']['paypal']['name']['given_name'] ?? '',
            $data['payment_source']['paypal']['name']['surname'] ?? '',
            $data['payment_source']['paypal']['email_address'] ?? '',
            $data['purchase_units'],
            $data['create_time'],
            $data['payment_source']['paypal']['account_id']?? '',
        );
    }
    public function CapturePayment(string $orderId):paypalOrderInfo {
        $ch = $this->initPOSTRequests(paypalQuery::capturePayment, array('order_id' => $orderId), array('intent' => 'CAPTURE'));
        $data = $this->makeRequests($ch);
        $order = new paypalOrderInfo(
            $data['id'], 
            paypalOrderStatus::from($data['status']),
            $data['intent'] ?? '',
            $data['payment_source']['paypal']['name']['given_name'],
            $data['payment_source']['paypal']['name']['surname'],
            $data['payment_source']['paypal']['email_address'],
            $data['purchase_units'],
            $data['create_time'] ?? '',
            $data['payment_source']['paypal']['account_id'],
        );
        return $order->_setRawData($data);
    }
    public function Connect($code, $schema) {
        if(!$this->GetConnctionCode($code)) return false;
        if(!$this->Exchange_RefreshToken_For_AcessToken()) return false;
        return $this->GetUserInfo($schema);
    }
    private function GetConnctionCode($code) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_host. '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Basic " . base64_encode($this->client_id . ":" . $this->client_secret))
        );
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'grant_type' => 'authorization_code', 'code' => $code)
        ));
        $output = json_decode(curl_exec($ch), true);
        if($httpcode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)) != 200) throw new Exception(
            "Paypal:\nHttp Code: {$httpcode}\nData: ". json_encode($output, JSON_PRETTY_PRINT)
        );
        curl_close($ch);
        $this->access_token = $output['access_token'];
        $this->expires_in = $output['expires_in'];
        $this->nonce = $output['nonce'];
        $this->scope = $output['scope'];
        $this->token_type = $output['token_type'];
        $this->refresh_token = $output['refresh_token'];
        return true;
    }
    private function Exchange_RefreshToken_For_AcessToken() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_host. '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Basic ". base64_encode($this->client_id. ":". $this->client_secret))
        );
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'grant_type' =>'refresh_token','refresh_token' => $this->refresh_token)
        ));
        $output = json_decode(curl_exec($ch), true);
        if($httpcode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)) != 200) throw new Exception("Exchange_RefreshToken_For_AcessToken");
        curl_close($ch);
        $this->access_token = $output['access_token'];
        return true;
    }
    private function GetUserInfo($schema) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 
            "{$this->api_host}/v1/identity/openidconnect/userinfo?schema=" . urlencode($schema)
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer {$this->access_token}",
            "Content-Type: application/x-www-form-urlencoded"
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = json_decode(curl_exec($ch), true);
        if($httpcode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)) != 200) {
            $this->error = $output;
            return false;
        }
        curl_close($ch);
        return $output;
    }
    public function GetClientToken() {
        if($this->client_Token != null) return $this->client_Token['client_token'];
        $this->requests->Post(
            "{$this->api_host}/v1/identity/generate-token",
            array(),
            array(
                'Content-Type: application/json',
                'Authorization: Bearer '. $this->access_token,
                'Accept-Language: en_US'
            ),
            true
        );
        $this->client_Token = json_decode($this->requests->GetData(), true);
        return $this->client_Token['client_token'];
    }
    private function GetAccessToken():string {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_host. '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('grant_type' => 'client_credentials')));
        curl_setopt($ch, CURLOPT_USERPWD, $this->client_id . ':' . $this->client_secret);
        $output = json_decode(curl_exec($ch), true);
        if($httpcode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)) != 200) {
            throw new Exception('Paypal: Http Code: ' . $httpcode);
        }
        curl_close($ch);
        $this->access_token = $output['access_token'];
        $this->expires_in = $output['expires_in'];
        $this->nonce = $output['nonce'];
        $this->scope = $output['scope'];
        $this->token_type = $output['token_type'];
        return $this->access_token;
    }
    public function GetError() {
        return $this->error;
    }
}

enum paypalOrderStatus:string {
    /**
     * The order was created with the specified context.
     */
    case CREATED = 'CREATED';
    /**
     * The order was saved and persisted. The order status continues to be in progress until a capture is made with final_capture = true for all purchase units within the order.
     */
    case SAVED = 'SAVED';
    /**
     * The customer approved the payment through the PayPal wallet or another form of guest or unbranded payment. For example, a card, bank account, or so on.
     */
    case APPROVED = 'APPROVED';
    /**
     * The payment was authorized or the authorized payment was captured for the order.
     */
    case COMPLETED = 'COMPLETED';
    /** 
     * All purchase units in the order are voided.
     */
    case VOIDED = 'VOIDED';
    /**
     * The order requires an action from the payer (e.g. 3DS authentication). Redirect the payer to the "rel":"payer-action" HATEOAS link returned as part of the response prior to authorizing or capturing the order.
     */
    case PAYER_ACTION_REQUIRED	= 'PAYER_ACTION_REQUIRED';
}

class paypalOrderInfo {
    public string $id;
    public paypalOrderStatus $status;
    public string $intent;

    public string $user_name;
    public string $user_surname;
    public string $user_email;


    public string $account_id;
    public array $items;

    public string $time;

    public array $rawData = [];

    public function __construct( string $id, paypalOrderStatus $status, string $intent, string $user_name, string $user_surname, string $user_email, array $items, string $time, string $account_id) {
        $this->id = $id;
        $this->status = $status;
        $this->intent = $intent;
        $this->user_name = $user_name;
        $this->user_surname = $user_surname;
        $this->user_email = $user_email;
        $this->time = $time;
        $this->account_id = $account_id;
        $this->items = $items;
    }
    public function _setRawData(array $rawData): paypalOrderInfo {
        $this->rawData = $rawData;
        return $this;
    }
    public function getOrderItem(int $index = 0): PaypalOrderInfoItem {
        if($index >= count($this->items) || $index == -1) $index = count($this->items) - 1;
        return new PaypalOrderInfoItem($this->items[$index]);
    }
}
class PaypalOrderInfoItem {
    public string $reference_id;
    public PaypalCurrency $currency;
    public float $amount;
    public function __construct(array $item_data) {
        $this->reference_id = $item_data['reference_id'];
        $this->currency = PaypalCurrency::from($item_data['amount']['currency_code']);
        $this->amount = floatval($item_data['amount']['value']);
    }
}

class PaypalOrder {
    public string $id;
    public paypalOrderStatus $status;
    public array $links = array();
    public string $createdTimestamp;
    public string $currency = 'CAD';
    public float $amount = 0.00;

    public string $rawData;

    private paypalUSer|null $customer = null;

    public function __construct(String $id, paypalOrderStatus $status, array $links, string $createdTimestamp) {
        $this->id = $id;
        $this->status = $status;
        $this->links = $links;
        $this->createdTimestamp = $createdTimestamp;
    }
    public function setRawData(string $rawData):PaypalOrder {
        $this->rawData = $rawData;
        return $this;
    }
    public function setPrice(float $amount, string $currency):PaypalOrder {
        $this->amount = $amount;
        $this->currency = $currency;
        return $this;
    }
    public function setCustomer(paypalUSer $customer):PaypalOrder {
        $this->customer = $customer;
        return $this;
    }
    public static function load(array $orderData):PaypalOrder {
        $order = $orderData;
        $orderObj = new PaypalOrder($order['id'], paypalOrderStatus::from($order['status']), $order['links'], $order['created_timestamp']);
        $orderObj->setPrice($order['amount'], $order['currency']);
        if($order['customer'] !== null) {
            $orderObj->customer = new paypalUSer($order['customer']);
        }
        return $orderObj;
    }
    public function makeSave():array {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'links' => $this->links,
            'created_timestamp' => $this->createdTimestamp,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'customer' => $this->customer?->makeSave()
        ];
    }

    public function getCustomer() {
        if($this->customer!= null) return $this->customer;
        else {

        }
    }
}
class paypalUSer {
    public string $name;
    public string $email;
    public string $payer_id;

    public function __construct(array $data) {
        $this->name = $data['name'];
        $this->email = $data['email'];
        $this->payer_id = $data['payer_id'];
    }
    public function makeSave():array {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'payer_id' => $this->payer_id
        ];
    }

}