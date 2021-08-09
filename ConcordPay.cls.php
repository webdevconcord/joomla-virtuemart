<?php

/**
 * Class ConcordPay
 * @copyright
 * @license
 * @since
 */
class ConcordPay
{
    const ORDER_APPROVED      = 'Approved';
    const ORDER_SEPARATOR     = '#';
    const SIGNATURE_SEPARATOR = ';';
    const ALLOWED_CURRENCIES  = ['UAH'];

    const RESPONSE_TYPE_PAYMENT = 'payment';
    const RESPONSE_TYPE_REVERSE = 'reverse';

    protected $secret_key = '';

    const URL = 'https://pay.concord.ua/api/';

    /**
     * @var string[]
     * @since
     */
    protected $keysForResponseSignature = array(
        'merchantAccount',
        'orderReference',
        'amount',
        'currency'
    );

    /**
     * @var string[]
     * @since
     */
    protected $keysForSignature = array(
        'merchant_id',
        'order_id',
        'amount',
        'currency_iso',
        'description'
    );

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * @param $option
     * @param $keys
     * @return string
     * @since
     */
    public function getSignature($option, $keys)
    {
        $hash = array();
        foreach ($keys as $dataKey) {
            if (!isset($option[$dataKey])) {
                continue;
            }
            if (is_array($option[$dataKey])) {
                foreach ($option[$dataKey] as $v) {
                    $hash[] = $v;
                }
            } else {
                $hash[] = $option[$dataKey];
            }
        }

        $hash = implode(self::SIGNATURE_SEPARATOR, $hash);
        return hash_hmac('md5', $hash, $this->getSecretKey());
    }

    /**
     * @param array $options
     * @return string
     * @since
     */
    public function getRequestSignature(array $options)
    {
        return $this->getSignature($options, $this->keysForSignature);
    }

    /**
     * @param array $options
     * @return string
     * @since
     */
    public function getResponseSignature(array $options)
    {
        return $this->getSignature($options, $this->keysForResponseSignature);
    }

    /**
     * @param $response
     * @return bool|string
     * @since
     */
    public function isPaymentValid($response)
    {
        $sign = $this->getResponseSignature($response);

        if ($sign !== $response['merchantSignature']) {
            return 'An error has occurred during payment. Signature is not valid.';
        }

        if ($response['transactionStatus'] !== self::ORDER_APPROVED) {
            return false;
        }

        return true;
    }

    /**
     * @param string $key
     * @since
     */
    public function setSecretKey(string $key) :void
    {
        $this->secret_key = $key;
    }

    /**
     * @return string
     * @since
     */
    protected function getSecretKey() :string
    {
        return $this->secret_key;
    }
}
