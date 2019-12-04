<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC')){
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
}

if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentAlikassa extends vmPSPlugin
{   
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);  
		
        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; 
		$this->_tableId = 'id'; 
		$varsToPush = $this->getVarsToPush();
	
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

    }    
    
    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Alikassa Table');
    }
    
    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' 							=> 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' 			=> 'int(11) UNSIGNED',
            'order_number' 					=> 'char(32)',
            'virtuemart_paymentmethod_id' 	=> 'mediumint(1) UNSIGNED',
            'payment_name' 					=> 'varchar(5000)',
            'payment_order_total' 			=> 'decimal(15,2) NOT NULL DEFAULT \'0.00\' ',
            'payment_currency' 				=> 'char(3) '	
        );
        
        return $SQLfields;
    }

    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        
        $lang     = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        $vendorId = 0;
        
        $session        = JFactory::getSession();
        $return_context = $session->getId();
        $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
        
        $html = "";
        
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        if (!$method->payment_currency)
            $this->getPaymentCurrency($method);

        // получение кода валюты вида "RUB"
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db =& JFactory::getDBO();
        $db->setQuery($q);

        $currency = $db->loadResult();

        $dateexp = date("Y-m-d H:i:s", time() + 24 * 3600);
        $amount = ceil($order['details']['BT']->order_total*100)/100;
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order['details']['BT']->order_number);
        
        $desc = 'Оплата заказа №'.$order['details']['BT']->order_number;

        $action_url = "https://sci.alikassa.com/payment"; 
        $this->_virtuemart_paymentmethod_id      = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['payment_name']                = $this->renderPluginName($method);
        $dbValues['order_number']                = $order['details']['BT']->order_number;
        $dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
        $dbValues['payment_currency']            = $currency;
        $dbValues['payment_order_total']         = $amount;
        $this->storePSPluginInternalData($dbValues);
        $success_url = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order['details']['BT']->order_number . '&order_pass=' . $order['details']['BT']->order_pass);
        $fail_url = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
        $interaction_url = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pro=1&tmpl=component');

        $params = array(
			'merchantUuid' => $method->merchant_uuid,
			'orderId' => $virtuemart_order_id,
			'currency' => $currency,
			'amount' => $amount,
			'desc' => 'Order # '.$virtuemart_order_id,
			'commissionType' => $method->commissionType,
			'urlSuccess' => $success_url,
			'urlFail' => $fail_url
        );

        ksort($params, SORT_STRING);
        array_push($params, $method->secret_key);
        $signString = implode(':', $params);

        $signature = base64_encode(hash($method->hash_alg, $signString, true));

		$html = '<form action='.$action_url.' method="POST"  name="vm_alikassa_form" id="akform">
		            <input type="hidden" value="'.$amount.'" name="amount">
					<input type="hidden" value="'.$method->merchant_uuid.'" name="merchantUuid">					
					<input type="hidden" value="'.$virtuemart_order_id.'" name="orderId">
					<input type="hidden" value="'.$params['desc'].'" name="desc">
					<input type="hidden" value="'.$currency.'" name="currency">	
					<input type="hidden" value="'.$params['commissionType'].'" name="commissionType">	
					<input type="hidden" value="'.$signature.'" name="sign">	  
					<input type="hidden" value="'.$params['urlSuccess'].'" name="urlSuccess">	  
					<input type="hidden" value="'.$params['urlFail'].'" name="urlFail">	  					
				</form>
				';
				
		$currentValueTotalBalance = $method->totalBalance;
		
		include 'AliKassa.class.php';
		
		$q = 'SELECT `payment_params` FROM `#__virtuemart_paymentmethods` WHERE `virtuemart_paymentmethod_id`="' . $order['details']['BT']->virtuemart_paymentmethod_id . '" ';
        $db =& JFactory::getDBO();
        $db->setQuery($q);
		
		$aliAPI = new \AliKassa($method->merchant_uuid, $method->secret_key, $method->hash_alg);
		
		$balance = $aliAPI->site()['return']['totalBalance'];
		
		$site = (@$balance['RUB']) ? $balance['RUB']:'0.00';
		
		$newTotal = 'RUB: '.$site;

        $payment_params = $db->loadResult();
		$payment_params = str_replace('totalBalance="'.$method->totalBalance.'"', 'totalBalance="'.$newTotal.'"', $payment_params);
		
		$query = $db->getQuery(true)
			->update('#__virtuemart_paymentmethods')
			->set('payment_params = "'.addslashes($payment_params).'"')
			->where(['virtuemart_paymentmethod_id = '.$order['details']['BT']->virtuemart_paymentmethod_id.'']); 

		$db->setQuery($query);
		$db->execute();

		
        $_SESSION['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$img_path = JURI::base(). "plugins/vmpayment/alikassa/paysystems/";
        $payment_systems = [
			'Card' => [
				'title' => 'Visa/MasterCard'
			],
			'YandexMoney' => [
				'title' => 'Яндекс.Деньги'
			],
			'Qiwi' => [
				'title' => 'Qiwi Wallet'
			]
		];
        $html .=  require 'api.tpl.php';

        
        return $this->processConfirmedOrderPaymentResponse(true, $cart, $order, $html, $this->renderPluginName($method, $order), 'P');
        }
         function plgVmOnSelfCallFE ($type, $name, &$render){
             $method = $this->getVmPluginMethod($_SESSION['virtuemart_paymentmethod_id']);
            if ($name != $this->_name || $type != 'vmpayment') return false;
            
            $params = array();
            parse_str($_POST['form'], $params);
			
             $render->sign = $this->AkSignFormation($params, $method->hash_alg, $method->secret_key);
        }

        public function AkSignFormation($data, $hash_alg, $secret_key){
            if (!empty($data['sign'])) unset($data['sign']);

            $dataSet = array();
            foreach ($data as $key => $value) {
                $dataSet[$key] = $value;
            }

            ksort($dataSet, SORT_STRING);
            array_push($dataSet, $secret_key);
            $arg = implode(':', $dataSet);
            $ak_sign = base64_encode(hash($hash_alg, $arg, true));

            return $ak_sign;
        }
    
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null; // Another method was selected, do nothing
        }
        
        $db = JFactory::getDBO();
        $q  = 'SELECT * FROM `' . $this->_tablename . '` ' . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());
            return '';
        }
        $this->getPaymentCurrency($paymentTable);
        
        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";
        return $html;
    }
    
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        return 0;
    }
    
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }
    
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }
    
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }
    
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
    
    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);
        
        $paymentCurrencyId = $method->payment_currency;
    }
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
    
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }    
    
    function plgVmDeclarePluginParamsPaymentVM3( &$data) 
	{
		return $this->declarePluginParams('payment', $data);
	}
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
    
    
    public function plgVmOnPaymentNotification()
    {	
       // $this->wrlog('Hello');
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

        $orderid = $_POST['orderId'];
        $payment = $this->getDataByOrderId($orderid);
        $method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);        
        $amount = ceil($payment->payment_order_total*100)/100;
		

        if ($method){      

            if (count($_POST) && isset($_POST['sign'])) {
               
	            if ($_POST['payStatus'] == 'success' && $method->merchant_uuid == $_POST['merchantUuid'] ) {  
				
	                $secret_key = $method->secret_key;
	                $request = $_POST;
	                $request_sign = $request['sign'];
	                unset($request['sign']);

	                foreach ($request as $key => $value) {
	                    $request[$key] = $value;
	                }
					
	                ksort($request, SORT_STRING);
	                array_push($request, $secret_key);
	                $str = implode(':', $request);
	                $sign = base64_encode(hash($method->hash_alg, $str, true));
					
	                if ($request_sign == $sign) {
	                    $order['order_status'] = $method->status_success;
	                    $order['virtuemart_order_id'] = $orderid;
	                    $order['customer_notified'] = 1;
	                    $order['comments'] = JTExt::sprintf('ALIKASSA_PAYMENT_CONFIRMED', $payment->order_number);
	                    $modelOrder = VmModel::getModel('orders');
	                    $modelOrder->updateStatusForOneOrder($orderid, $order, true);
	                } else {
		                $order['order_status']        = $method->status_pending;
		                $order['virtuemart_order_id'] = $orderid;
		                $order['customer_notified']   = 0;
		                $order['comments']            = JTExt::sprintf('ALIKASSA_STATUS_FAILED', $payment->order_number);
		                $modelOrder = VmModel::getModel ('orders');
		                $modelOrder->updateStatusForOneOrder($orderid, $order, true);
	                } 
	            }           
    		} else {
	    		exit;
	        	return null;
    		}
		} else {
            exit;
            return null;
        }
    } 
    
    function plgVmOnUserPaymentCancel()
    {
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        
        $order_number = JRequest::getVar('on');
        if (!$order_number)
            return false;
        $db    = JFactory::getDBO();
        $query = 'SELECT ' . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->_tablename . " WHERE  `order_number`= '" . $order_number . "'";
        
        $db->setQuery($query);
        $virtuemart_order_id = $db->loadResult();
        
        if (!$virtuemart_order_id) {
            return null;
        }
        $this->handlePaymentUserCancel($virtuemart_order_id);
        
        return true;
    }

    function wrlog($content){
        $file = $_SERVER['DOCUMENT_ROOT'].'/logs/log.txt';
        $doc = fopen($file, 'a');
   
        file_put_contents($file, PHP_EOL . $content, FILE_APPEND);
        fclose($doc);
       
    }
 	function checkIP(){
	    $ip_stack = array(
	        'ip_begin'=>'151.80.190.97',
	        'ip_end'=>'151.80.190.104'
	    );

	    if(ip2long($_SERVER['REMOTE_ADDR'])<ip2long($ip_stack['ip_begin']) || ip2long($_SERVER['REMOTE_ADDR'])>ip2long($ip_stack['ip_end'])){
	        $this->wrlog('REQUEST IP'.$_SERVER['REMOTE_ADDR'].'doesnt match');
	        die('Ты мошенник! Пшел вон отсюда!');
	    }
	    return true;
    }
}
