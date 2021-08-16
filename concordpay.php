<?php

/**
 * @package     VirtueMart
 * @subpackage  Plugins - ConcordPay
 * @package     VirtueMart
 * @subpackage  Payment
 * @author      ConcordPay
 * @link        https://concordpay.concord.ua
 * @copyright   2021 ConcordPay
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 * @since       3.0
 */

defined('_JEXEC') or die('Direct Access to ' . basename(__FILE__) . 'is not allowed.');

defined('_VALID_MOS') or die('Direct Access to ' . basename(__FILE__) . 'is not allowed.');

//ini_set("display_errors", true);
//error_reporting(E_ALL);

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}


class plgVmPaymentConcordpay extends vmPSPlugin
{
    // instance of class
    public static $_this = false;

    /**
     * plgVmPaymentConcordpay constructor.
     * @param $subject
     * @param $config
     * @since
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey  = 'id'; //virtuemart_concordpay_id';
        $this->_tableId    = 'id'; //'virtuemart_concordpay_id';
        $varsToPush        = $this->getVarsToPush();

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * @param $method_id
     * @return mixed|null
     * @since
     */
    public function __getVmPluginMethod($method_id)
    {
        $method = $this->getVmPluginMethod($method_id);
        if (!$method) {
            return null;
        }
        return $method;
    }

    /**
     * This function is triggered when the user click on the Confirm Purchase button on cart view.
     * You can store the transaction/order related details using this function.
     * You can set your html with a variable name html at the end of this function, to be shown be thank you message.
     *
     * @param $cart
     * @param $order
     * @return null|bool|void
     * @since
     */
    public function plgVmConfirmedOrder($cart, $order)
    {
        $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
        if (!$method) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        include_once(__DIR__ . '/ConcordPay.cls.php');
        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . '/models/currency.php');
        }

        JFactory::getLanguage()->load($filename = 'com_virtuemart', JPATH_ADMINISTRATOR);
        $vendorId = 0;
        $html = "";

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . '/models/orders.php');
        }

        self::getPaymentCurrency($method);

        $currencyModel = new VirtueMartModelCurrency();
        $currencyObj   = $currencyModel->getCurrency($order['details']['BT']->order_currency);
        $currency      = $currencyObj->currency_code_3;

        $concordpay = new ConcordPay();
        $concordpay->setSecretKey($method->concordpay_secret_key);

        list($lang, ) = explode('-', JFactory::getLanguage()->getTag());

        $paymentMethodID = $order['details']['BT']->virtuemart_paymentmethod_id;
        $return_url = JROUTE::_(
            JURI::root() . 'index.php?option=com_virtuemart&view=orders&layout=details&order_number='
            . $order['details']['BT']->order_number . '&order_pass=' . $order['details']['BT']->order_pass
        );
        $callback_url = JROUTE::_(
            JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='
            . $paymentMethodID
        );

        $user = &$cart->BT;
        $orderDetails = $order['details']['BT'];

        $formFields = array(
            'operation'    => 'Purchase',
            'merchant_id'  => $method->concordpay_merchant_account,
            'amount'       => round($orderDetails->order_total, 2),
            'order_id'     => $cart->order_number . ConcordPay::ORDER_SEPARATOR . time(),
            'currency_iso' => $method->concordpay_currency,
            'description'  => $this->getOrderDescription($orderDetails),
            'add_params'   => [],
            'approve_url'  => $return_url,
            'decline_url'  => $return_url,
            'cancel_url'   => $return_url,
            'callback_url' => $callback_url
        );

        //Adds js script with currency flag
        $this->isAllowedCurrency($currency);

        $formFields['signature'] = $concordpay->getRequestSignature($formFields);

        $concordpayArgsArray = array();
        foreach ($formFields as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $concordpayArgsArray[] = "<input type='hidden' name='{$key}[]' value='$v'/>";
                }
            } else {
                $concordpayArgsArray[] = "<input type='hidden' name='$key' value='$value'/>";
            }
        }

        $html = '<form action="' . Concordpay::URL . '" method="post" id="concordpay_payment_form">' .
            implode('', $concordpayArgsArray) . '</form>' .
            '<div><img src="/plugins/vmpayment/concordpay/assets/images/loader.gif"
  				 width="50px" style="margin:20px 20px;"></div>' .
            "<script> setTimeout(function() {
                 document.getElementById('concordpay_payment_form').submit();
             }, 500);
            </script>";

        //  2 = don't delete the cart, don't send email and don't redirect
        $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, '');
        //We delete the old stuff
        $cart->emptyCart();

        /**
         * in v3.8.8 virtuemart changed the logic of the VirtueMartCart::emptyCart() method
         * and $html not not showing in orderdone page.
         * this is simplest way to show it now
         */
        vRequest::setVar('html', $html);
    }

    /**
     * This function is used If user redirection to the Payment gateway is required.
     * You can use this function as redirect URL for the payment gateway and receive response from payment gateway here.
     * YOUR_SITE/.'index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived'
     *
     * @param $html
     * @return bool|null
     * @since
     */
    public function plgVmOnPaymentResponseReceived(&$html)
    {
        $method = $this->getVmPluginMethod(vRequest::getInt('pm', 0));
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . '/helpers/cart.php');
        }

        $data = vRequest::getPost();
        if (!isset($data['orderReference'])) {
            $data = json_decode(file_get_contents("php://input"), true);
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . '/models/orders.php');
        }

        if (!class_exists('ConcordPay')) {
            require(__DIR__ . '/ConcordPay.cls.php');
        }

        list($order_id, ) = explode(ConcordPay::ORDER_SEPARATOR, $data['orderReference']);

        $order      = new VirtueMartModelOrders();
        $order_s_id = $order::getOrderIdByOrderNumber($order_id);
        $orderitems = $order->getOrder($order_s_id);

        $method = $this->getVmPluginMethod($orderitems['details']['BT']->virtuemart_paymentmethod_id);
        if (!$method) {
            return null; // Another method was selected, do nothing
        }

        $concordpay = new ConcordPay();
        $concordpay->setSecretKey($method->concordpay_secret_key);
        $response = $concordpay->isPaymentValid($data);

        if ($response === true && isset($data['type'])) {
            // VirtueMart pre-configured order statuses:
            // C - Confirmed
            // D - Denied
            // F - Completed
            // P - Pending - LOCKED
            // R - Refunded
            // S - Shipped - LOCKED
            // U - Confirmed by Shopper
            // X - Cancelled - LOCKED
            $orderStatus = null;
            if ($data['type'] === ConcordPay::RESPONSE_TYPE_PAYMENT) {
                $orderStatus = $method->concordpay_status_success;
            } elseif ($data['type'] === ConcordPay::RESPONSE_TYPE_REVERSE) {
                $orderStatus = $method->concordpay_status_refunded;
            }

            if ($orderStatus) {
                $orderitems['order_status']        = $orderStatus;
                $orderitems['customer_notified']   = 0;
                $orderitems['virtuemart_order_id'] = $order_s_id;

                $orderitems['comments'] = 'ConcordPay ID: ' . $order_id . ' Ref ID: ' . $data['transactionId'];
                $order->updateStatusForOneOrder($order_s_id, $orderitems, true);

                // get the correct cart / session
                $cart = VirtueMartCart::getCart();
                $cart->emptyCart();

                return true;
            }
        }

        return false;
    }

    /**
     * Process User Payment Cancel
     *
     * @return bool
     * @since
     */
    public function plgVmOnUserPaymentCancel()
    {
        $data = JFactory::getApplication()->input;

        require(__DIR__ . '/ConcordPay.cls.php');
        list($order_id, ) = explode(ConcordPay::ORDER_SEPARATOR, $data['order_id']);
        $order = new VirtueMartModelOrders();

        $order_s_id = $order::getOrderIdByOrderNumber($order_id);
        $orderitems = $order->getOrder($order_s_id);

        $method = $this->getVmPluginMethod($orderitems['details']['BT']->virtuemart_paymentmethod_id);
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . '/models/orders.php');
        }

        $this->handlePaymentUserCancel($data['oid']);

        return true;
    }

    /**
     * This event is fired by Offline Payment. It can be used to validate the payment data as entered by the user.
     *
     * @return null
     * @since
     */
    public function plgVmOnPaymentNotification()
    {
        $isCb = false;
        $data = vRequest::getPost();
        if (!isset($data['orderReference'])) {
            $data = json_decode(file_get_contents("php://input"), true);
            $isCb = true;
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . '/models/orders.php');
        }

        require(__DIR__ . '/ConcordPay.cls.php');

        list($order_id, ) = explode(ConcordPay::ORDER_SEPARATOR, $data['orderReference']);

        $order = new VirtueMartModelOrders();
        $order_s_id = $order::getOrderIdByOrderNumber($order_id);
        $orderitems = $order->getOrder($order_s_id);

        $method = $this->getVmPluginMethod($orderitems['details']['BT']->virtuemart_paymentmethod_id);
        if (!$method) {
            return null; // Another method was selected, do nothing
        }

        $concordpay = new ConcordPay();
        $concordpay->setSecretKey($method->concordpay_secret_key);
        $response = $concordpay->isPaymentValid($data);

        if ($response === true && isset($data['type'])) {
            $orderStatus = null;
            if ($data['type'] === ConcordPay::RESPONSE_TYPE_PAYMENT) {
                $orderStatus = $method->concordpay_status_success;
            } elseif ($data['type'] === ConcordPay::RESPONSE_TYPE_REVERSE) {
                $orderStatus = $method->concordpay_status_refunded;
            }

            if ($orderStatus) {
                $orderitems['order_status']        = $orderStatus;
                $orderitems['customer_notified']   = 0;
                $orderitems['virtuemart_order_id'] = $order_s_id;

                $orderitems['comments'] = 'ConcordPay ID: ' . $order_id . ' Ref ID: ' . $data['transactionId'];
                $order->updateStatusForOneOrder($order_s_id, $orderitems, true);

                // get the correct cart / session
                $cart = VirtueMartCart::getCart();
                $cart->emptyCart();
            }
        }
        exit();
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param $cart
     * @param $method
     * @param $cart_prices : cart prices
     * @return true: if the conditions are fulfilled, false otherwise
     * @author: Valerie Isaksen
     * @since
     */
    protected function checkConditions($cart, $method, $cart_prices) :bool
    {
        return true;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @author Valérie Isaksen
     * @since
     */
    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart : the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not valid
     * @since
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * This event is fired to display the plugin methods in the cart (edit shipment/payment) for example
     * This is a required function for your payment plugin and called after plgVmonSelectedCalculatePricePayment.
     * This function is used to display the payment plugin name on cart view payment option list.
     * This function has a parameter $html by the help of which you can add additional view,
     * if required to be shown with payment name.
     *
     * @param VirtueMartCart $cart Cart object
     * @param integer $selected ID of the method selected
     * @param $htmlIn
     * @return boolean True on success, false on failures, null when this plugin was not selected.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     * @since
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /**
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented,
     * then the default values from this function are taken.
     * This function is used to calculate the price of the payment method.
     * Price calculation is done on checkbox selection of payment method at cart view.
     * If you forget to add this function in your plugin code,
     * your payment plugin will not be selectable at all on the cart view.
     *
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param $cart_prices_name
     * @return null if the method was not selected, false if the shipping rate is not valid any more, true otherwise
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @since
     */
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return false|null
     * @since
     */
    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        self::getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * This function is first called when you finally setup the configuration of payment plugin
     * and redirect to the cart view on store.
     * In case you have set your payment plugin as the default payment method by VirtueMart’s Configuration,
     * this function is used.
     *
     * @param VirtueMartCart cart: the cart object
     * @param array $cart_prices
     * @return null if no plugin was found, 0 if more then one plugin was found,
     * virtuemart_xxx_id if only one plugin is found
     * @author Valerie Isaksen
     * @since
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     * This function is triggered after plgVmConfirmedOrder, to display the payment related information in order.
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     * @since
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }


    /**
     * This method is fired when showing when printing an Order
     * It displays the the payment method-specific data.
     *
     * @param $order_number
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     * @since
     */
    public function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * You can get the saved plugin configuration values by the help of this function’s parameter.
     * This function is called whenever you try to update the configuration of the payment plugin.
     *
     * @param $data
     * @return bool
     * @since
     */
    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    /**
     * After plgVmOnStoreInstallPaymentPluginTable function the next required function for the plugin.
     * Used for storing values of payment plugins configuration in database table.
     *
     * @param $name
     * @param $id
     * @param $table
     * @return bool
     * @since
     */
    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * This function is used to define the column names to be added in the payment table creation.
     * One can add columns after installation of plugin as well using this function.
     *
     * @return array|string[]
     * @since
     */
    public function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL',
            'payment_currency' => 'smallint(1)',
            'email_currency' => 'smallint(1)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(1)',
            'concordpay_custom' => 'varchar(255)',
            'concordpay_method' => 'varchar(200)',

            'concordpay_response_mc_gross' => 'decimal(10,2)',
            'concordpay_response_mc_currency' => 'char(10)',
            'concordpay_response_invoice' => 'char(32)',
            'concordpay_response_protection_eligibility' => 'char(128)',
            'concordpay_response_payer_id' => 'char(13)',
            'concordpay_response_tax' => 'decimal(10,2)',
            'concordpay_response_payment_date' => 'char(28)',
            'concordpay_response_payment_status' => 'char(50)',
            'concordpay_response_pending_reason' => 'char(50)',
            'concordpay_response_mc_fee' => 'decimal(10,2)',
            'concordpay_response_payer_email' => 'char(128)',
            'concordpay_response_last_name' => 'char(64)',
            'concordpay_response_first_name' => 'char(64)',
            'concordpay_response_business' => 'char(128)',
            'concordpay_response_receiver_email' => 'char(128)',
            'concordpay_response_transaction_subject' => 'char(128)',
            'concordpay_response_residence_country' => 'char(2)',
            'concordpay_response_txn_id' => 'char(32)',
            'concordpay_response_txn_type' => 'char(32)', //The kind of transaction for which the IPN message was sent
            'concordpay_response_parent_txn_id' => 'char(32)',
            'concordpay_response_case_creation_date' => 'char(32)',
            'concordpay_response_case_id' => 'char(32)',
            'concordpay_response_case_type' => 'char(32)',
            'concordpay_response_reason_code' => 'char(32)',
            'concordpayresponse_raw' => 'varchar(512)',
            'concordpay_fullresponse' => 'text',
        );
        return $SQLfields;
    }

    /**
     * @param $order
     * @return string
     * @since
     */
    protected function getOrderDescription($order) :string
    {
        return JFactory::getLanguage()->_('VMPAYMENT_CONCORDPAY_PAYMENT_DESCRIPTION') . ' ' .
            JUri::getInstance()->getHost() . ', ' . $order->first_name  . ' ' . $order->last_name . ', ' . $order->phone_1;
    }

    /**
     * Check for allowed currency
     *
     * @param string $currency
     * @since
     */
    protected function isAllowedCurrency(string $currency)
    {
        if (in_array($currency, ConcordPay::ALLOWED_CURRENCIES, true)) {
            echo '<script> const isAllowedCurrency = true; </script>';
        } else {
            echo '<script> const isAllowedCurrency = false; </script>';
        }
    }

    /**
     * @param $order
     * @return string
     * @since
     */
    protected function getPhone($order) :string
    {
        if (!empty($order->phone_1)) {
            $phone = $order->phone_1;
        } else {
            $phone = $order->phone_2;
        }
        $phone = str_replace(array('+', ' ', '(', ')'), array('', '', '', ''), $phone);
        if (strlen($phone) === 10) {
            $phone = '38' . $phone;
        } elseif (strlen($phone) === 11) {
            $phone = '3' . $phone;
        }

        return $phone;
    }

    /**
     * Displays payment service logo on checkout page.
     *
     * @param $logo_list
     *
     * @return string
     *
     * @since version
     */
    protected function displayLogos($logo_list): string
    {
        $img = '';

        if (! empty($logo_list)) {
            $url = JURI::root() . 'plugins/vmpayment/concordpay/';
            if (! is_array($logo_list)) {
                $logo_list = (array)$logo_list;
            }
            foreach ($logo_list as $logo) {
                $alt_text = substr($logo, 0, strpos($logo, '.'));
                $img .= '<img src="' . $url . $logo . '"  alt="' . $alt_text . '" style="vertical-align:middle" /> ';
            }
        }
        return $img;
    }
}

// No closing tag
