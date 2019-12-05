<?php
class AliKassa
{
    const SUCCESS = 'true';
    const ERROR = 'error';
    public $url = 'https://api.alikassa.com';
    public $version = 'v1';
    private $uuid;
    private $secret;
    private $algo;
    public function __construct($uuid, $secret, $algo='sha256')
    {
        $this->uuid = $uuid;
        $this->secret = $secret;
        $this->algo = $algo;
    }
    /**
     * Get currencies
     * @return array
     * @throws \Exception
     */
    public function currency()
    {
        return $this->query('currency', 'get', false);
    }
    /**
     * Deposit
     * @url https://alikassa.com/site/api-doc#section/2.-Deposit-API
     * @param $orderId
     * @param $amount
     * @param $paySystem
     * @param $currency
     * @param $desc
     * @param $ip
     * @param $userAgent
     * @param array $data
     * @param string $commissionType
     * @param string $lifeTime
     * @return array
     * @throws \Exception
     */
    public function deposit(
        $orderId, $amount, $paySystem, $currency, $desc, $ip, $userAgent, array $data=[],
        $commissionType='', $lifeTime=''
    ) {
        return $this->query('site/deposit', 'post', true, array_merge([
            'orderId' => $orderId,
            'amount' => $amount,
            'paySystem' => $paySystem,
            'currency' => $currency,
            'desc' => $desc,
            'ip' => $ip,
            'userAgent' => $userAgent,
            'commissionType' => $commissionType,
            'lifetime' => $lifeTime,
        ], $data));
    }
    /**
     * Withdrawal
     * @url https://alikassa.com/site/api-doc#section/Withdrawal-API
     * @param $orderId
     * @param $amount
     * @param $paySystem
     * @param $currency
     * @param $number
     * @return array
     * @throws \Exception
     */
    public function withdrawal($orderId, $amount, $paySystem, $currency, $number)
    {
        return $this->query('site/withdrawal', 'post', true, [
            'orderId' => $orderId,
            'amount' => $amount,
            'paySystem' => $paySystem,
            'currency' => $currency,
            'number' => $number,
        ]);
    }
    /**
     * Transaction history
     * @url https://alikassa.com/site/api-doc#section/5.-Transaction
     * @param $id
     * @return array
     * @throws \Exception
     */
    public function history($id)
    {
        return $this->query('site/history/' . $id, 'post', true);
    }
    /**
     * Deposit history
     * @url https://alikassa.com/site/api-doc#section/5.-Transaction
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function depositHistory(array $data=[])
    {
        return $this->query('site/history/deposit', 'post', true, $data);
    }
    /**
     * Withdrawal history
     * @url https://alikassa.com/site/api-doc#section/5.-Transaction
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function withdrawalHistory(array $data=[])
    {
        return $this->query('site/history/withdrawal', 'post', true, $data);
    }
    /**
     * Get payment systems
     * @url https://alikassa.com/site/api-doc#section/2.-Paysystem
     * @return array
     * @throws \Exception
     */
    public function paySystem()
    {
        return $this->query('paysystem', 'get', false);
    }
    /**
     * Get payment systems attributes
     * @url https://alikassa.com/site/api-doc#section/2.-Paysystem-attributes
     * @return array
     * @throws \Exception
     */
    public function paySystemAttributes()
    {
        return $this->query('paysystem-attributes', 'get', false);
    }
    /**
     * Site Information
     * @url https://alikassa.com/site/api-doc#section/1.-Site
     * @return array
     * @throws \Exception
     */
    public function site()
    {
        return $this->query('site', 'post', true);
    }
    /**
     * Ip Notification
     * @url https://alikassa.com/site/api-doc#section/6.-IP-Notification
     * @return array
     * @throws \Exception
     */
    public function ipNotification()
    {
        return $this->query('site/ip-notification', 'post', true);
    }
    /**
     * @param string $method
     * @param string $httpMethod
     * @param bool $isPrivate
     * @param array $data
     * @return array
     * @throws \Exception
     */
    private function query($method, $httpMethod, $isPrivate, array $data=[])
    {
        $url = $this->url . '/' . $this->version . '/' . $method;
        // normalization data
        $data = array_map(function($value) {
            return is_null($value) ? '' : $value;
        }, $data);
        $ch = curl_init();
        if ($httpMethod==='post') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else {
            $url .= '?' . http_build_query($data);
        }
        if ($isPrivate) {
            if (empty($this->secret) || empty($this->uuid)) {
                throw new \Exception('Not set merchant site.');
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Basic ' . base64_encode(
                    $this->uuid . ':' . self::sign($data, $this->secret, $this->algo)
                ),
            ));
        }
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch,CURLOPT_USERAGENT, 'AliKassa API');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        return json_decode(curl_exec($ch), true);
    }
    public static function sign(array $dataSet, string $key, string $algo) :string
    {
        if (isset($dataSet['sign'])) {
            unset($dataSet['sign']);
        }
        ksort($dataSet, SORT_STRING); // Sort elements in array by var names in alphabet queue
        array_push($dataSet, $key); // Adding secret key at the end of the string
        $signString = implode(':', $dataSet); // Concatenation calues using symbol ":"
        $signString = hash($algo, $signString, true);
        return base64_encode($signString); // Return the result
    }
}