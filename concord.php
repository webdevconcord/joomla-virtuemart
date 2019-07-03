<?php

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS.DS.'vmpsplugin.php');
}

class plgVmPaymentCONCORD extends vmPSPlugin
{
    public static $_this = false;

    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey  = 'id'; //virtuemart_CONCORD_id';
        $this->_tableId    = 'id'; //'virtuemart_CONCORD_id';

        $varsToPush = $this->getVarsToPush();

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    function getTableSQLFields()
    {
        $SQLfields = array(
            'id'                          => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => ' char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3) ',
            'cost_per_transaction'        => 'decimal(10,2)',
            'cost_percent_total'          => 'decimal(10,2)',
            'tax_id'                      => 'smallint(1)',
            'user_session'                => 'varchar(255)',
        );

        return $SQLfields;
    }

    function getVarsToPush()
    {
        $varsToPush = array(
            'key'         => array('', 'char'),
            'password'    => array('', 'char'),
            'gateway_url' => array('', 'char'),
        );

        return $varsToPush;
    }

    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment CONCORD Table');
    }

    function plgVmOnPaymentNotification()
    {
        $callbackParams = json_decode(file_get_contents("php://input"), true);

//        die(var_dump($callbackParams));
        $log            = JPATH_SITE.'/logs/CONCORD-callback.log';
        file_put_contents($log, date('Y-m-d H:i:s').": Params: ".print_r($callbackParams, true)."\n\n", FILE_APPEND);


        if (!array_key_exists('orderReference', $callbackParams) || !isset($callbackParams['orderReference'])) {
            file_put_contents($log, date('Y-m-d H:i:s').": NO ORDER\n", FILE_APPEND);

            return null;
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php');
        }

        $modelOrder          = VmModel::getModel('orders');
        $virtuemart_order_id = $modelOrder->getOrderIdByOrderNumber($callbackParams['orderReference']);
        $order               = $modelOrder->getOrder($virtuemart_order_id);

        if (!$virtuemart_order_id || !$order) {
            file_put_contents($log, date('Y-m-d H:i:s').": WRONG ORDER\n", FILE_APPEND);
            return null;
        }

        $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);

        if (!$this->selectedThisElement($method->payment_element)) {
            file_put_contents($log, date('Y-m-d H:i:s').": WRONG PAYMENT METHOD\n", FILE_APPEND);
            return null; // Another method was selected, do nothing
        }
        // generate signature from callback params
        $sign =
            hash_hmac ("md5", $callbackParams['merchantAccount'].";".$callbackParams['orderReference'].";".$callbackParams['amount'].";".$callbackParams['currency'],$method->password);

        // verify signature
        if ($callbackParams['merchantSignature'] != $sign) {
            file_put_contents($log, date('Y-m-d H:i:s').": WRONG SIGNATURE\n", FILE_APPEND);
            // answer with fail response
            die("ERROR: Invalid signature.");
        }
        // do processing stuff
        switch ($callbackParams['transactionStatus']) {
            case 'Approved':
                $data                        = array();
                $data['order_status']        = 'C';
                $data['virtuemart_order_id'] = $virtuemart_order_id;
                $data['customer_notified']   = 1;
                $data['comments']            = JText::_('VMPAYMENT_'.$this->_name.'_PAYMENT_STATUS_CONFIRMED');

                $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $data, true);
                file_put_contents($log, date('Y-m-d H:i:s').": Success sale\n", FILE_APPEND);

                break;

            case 'Decline':
                break;

            default:
                die("ERROR: Invalid callback data");
        }
        // answer with success response
        exit('{"code":"0","message":"Accepted"}');
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
        } // Another method was selected, do nothing

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id)
    {
        if (!$this->selectedThisByMethodId($payment_method_id)) {
            return null;
        } // Another method was selected, do nothing

        if (!($paymentTable = $this->_getInternalData($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        $this->getPaymentCurrency($paymentTable);
        $q  = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'.
              $paymentTable->payment_currency.'" ';
        $db = JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();
        $html            = '<table class="adminlist table">'."\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('PAYMENT_NAME', $paymentTable->payment_name);

        $code = "mb_";
        foreach ($paymentTable as $key => $value) {
            if (substr($key, 0, strlen($code)) == $code) {
                $html .= $this->getHtmlRowBE($key, $value);
            }
        }
        $html .= '</table>'."\n";

        return $html;
    }


    /**
     * Check if the payment conditions are fulfilled for this payment method
     * @author: Joe Harwell
     *
     * @param $cart_prices : cart prices
     * @param $payment
     *
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    /**
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
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
     *
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *

     */

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);

    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object  $cart     Cart object
     * @param integer $selected ID of the method selected
     *
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        $html = array();
        foreach ($this->methods as $method) {
            $methodSalesPrice    = $this->calculateSalesPrice($cart, $method, $cart->pricesUnformatted);
            $method->method_name = $this->renderPluginName($method);
            $html                = $this->getPluginHtml($method, $selected, $methodSalesPrice);
        }
        $htmlIn[][0] = $html;

        return $this->displayListFE($cart, $selected, $htmlIn);
    }


    /**
     * Prepare data and redirect to CONCORD payment platform
     *
     * @param string $order_number
     * @param object $orderData
     * @param string $return_context the session id
     * @param string $html           the form to display
     * @param bool   $new_status     false if it should not be changed, otherwise new staus
     *
     * @return NULL
     */

    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $fields          = array();
        $fields['operation'] = 'Purchase';
        $fields['merchant_id']   = $method->key;
        $fields['order_id'] = $order['details']['BT']->order_number;
        $url_success     = JROUTE::_(
            JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived'
        );

        $paymentMethodID = $order['details']['BT']->virtuemart_paymentmethod_id;
        $callbackUrl = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm=' . $paymentMethodID);

        $uri           = JURI::getInstance($url_success);
        $fields['approve_url'] = $uri->toString();
        $url_cancel    = JROUTE::_(
            JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel'
        );
        $fields['callback_url'] = $callbackUrl;

        $uri2                = JURI::getInstance($url_cancel);
        $fields['decline_url'] = $uri2->toString();
        $fields['cancel_url'] = $uri2->toString();

        // Set currency
        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'currency.php');
        }

        $currencyModel = new VirtueMartModelCurrency();
        $currencyObj   = $currencyModel->getCurrency($order['details']['BT']->order_currency);
        $fields['currency_iso'] = $currencyObj->currency_code_3;
        $fields['amount'] = sprintf("%01.2f", $order['details']['BT']->order_total);
        $fields['description'] = JText::_(
                'VMPAYMENT_'.$this->_name.'_PAYMENT_FOR_ORDER'
            ).' ['.$order['details']['BT']->order_number.']';



        /* Calculation of signature */

        $sign =
            hash_hmac ("md5", $fields['merchant_id'].";".$fields['order_id'].";".$fields['amount'].";".$fields['currency_iso'].";".$fields['description'] ,$method->password);


        $fields['signature'] = $sign;

        // echo the redirect form
        $form = '<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8" /><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
        $form .= '<p>'.JText::_('VMPAYMENT_'.$this->_name.'_PLEASE_WAIT').'</p>';
        $form .= '<p>'.JText::_('VMPAYMENT_'.$this->_name.'_CLICK_BUTTON_IF_NOT_REDIRECTED').'</p>';
        $form .= '<form action="'.$method->gateway_url.'" method="POST" name="vm_'.$this->_name.'_form" id="vm_'.$this->_name.'_form">';
        $form .= '<input type="submit" name="sbmt" title="'.JText::_(
                'VMPAYMENT_'.$this->_name.'_BTN_TITLE'
            ).'" value="'.JText::_('VMPAYMENT_'.$this->_name.'_BTN_TITLE').'"/>';
        $form .= $this->getRequestFieldsHtml($fields);
        $form .= '</form></div>';
        $form .= '<script type="text/javascript">document.getElementById("vm_'.$this->_name.'_form").submit();</script></body></html>';

        echo $form;

        $cart->_confirmDone   = false;
        $cart->_dataValidated = false;
        $cart->setCartIntoSession();
        die();
    }


    function getRequestFieldsHtml($fields)
    {
        $html = '';
        foreach ($fields as $key => $value) {
            $html .= '<input type="hidden" name="'.$key.'" value="'.$value.'" />';
        }

        return $html;
    }


    function plgVmOnPaymentResponseReceived(&$html)
    {
        $order_number = JRequest::getString('order', 0);

        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE.DS.'helpers'.DS.'cart.php');
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php');
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return null;
        }

        $modelOrder = VmModel::getModel('orders');
        $order      = $modelOrder->getOrder($virtuemart_order_id);

        if (!$order) {
            return null;
        }

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();

        return true;
    }


    function plgVmOnUserPaymentCancel()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php');
        }

        $order_number = JRequest::getVar('on');

        if (!$order_number) {
            return false;
        }

        $db    = JFactory::getDBO();
        $query = 'SELECT '.$this->_tablename.'.`virtuemart_order_id` FROM '.$this->_tablename." WHERE  `order_number`= '".$order_number."'";
        $db->setQuery($query);
        $virtuemart_order_id = $db->loadResult();

        if (!$virtuemart_order_id) {
            return null;
        }

        return true;
    }


    /*
     * @param $plugin plugin
     */
    protected function renderPluginName($plugin)
    {
        $return      = '';
        $plugin_name = $this->_psType.'_name';
        $plugin_desc = $this->_psType.'_desc';
        $description = '';

        if (!empty($plugin->$plugin_desc)) {
            $description = '<span class="'.$this->_type.'_description">'.$plugin->$plugin_desc.'</span>';
        }

        $pluginName = $return.'<span class="'.$this->_type.'_name">'.$plugin->$plugin_name.'</span>'.$description;

        return $pluginName;
    }


    /*
     * plgVmonSelectedCalculatePricePayment
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @author Valerie Isaksen
     *
     * @param VirtueMartCart cart: the cart object
     *
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin
     *              is found
     *
     */

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        vmdebug('CONCORD plgVmOnCheckAutomaticSelectedPayment');

        return parent::onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     *
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id            method used for this order
     *
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     *
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     *
     *
     *
     * public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
     *
     * return null;
     *
     * }
     */
    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    function _getInternalData($virtuemart_order_id, $order_number = '')
    {
        $db = JFactory::getDBO();
        $q  = 'SELECT * FROM `'.$this->_tablename.'` WHERE ';

        if ($order_number) {
            $q .= " `order_number` = '".$order_number."'";
        } else {
            $q .= ' `virtuemart_order_id` = '.$virtuemart_order_id;
        }

        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        return $paymentTable;
    }
}
