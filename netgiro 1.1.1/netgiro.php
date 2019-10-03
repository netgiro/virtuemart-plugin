<?php

/**
 * Netgiro - Payment-Plugin for VirtueMart 2
 *
 * @author Stefan Novaković | Program5
 * @version 1.0.0
 * @package VirtueMart
 * @subpackage payment
 * @Copyright( C) 2013 Program5 - All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 *
 * http://virtuemart.org
 */
	
defined('_JEXEC') or die('Restricted access');

// all the jdump calls keep staying in the code
// makes a later client-side debugging easier
// just by install the JDump-Compo and Plugin
if(!function_exists('dump')) {
    function dump(){}
    function dumpMessage(){}
    function dumpTemplate(){}
    function dumpSysinfo(){}
    function dumpTrace(){}
}

if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentNetgiro extends vmPSPlugin {

	// instance of class
	public static $_this = false;

    function __construct(& $subject, $config) {

		parent::__construct($subject, $config);

		$this->_loggable = true;
		/*$this->_tablepkey = 'id';
		$this->_tableId = 'id';*/
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; 
		$this->_tableId = 'id'; 

		$varsToPush = array(
		    'payment_logos' => array('', 'char'),
		    'netgiro_testmode' => array('', 'int'),
		    'application_id' => array('', 'char'),
		    'secret_key' => array('', 'char'),
		    'max_installments' => array(0, 'int'),
		    'payment_currency' => array('', 'int'),
		    'default_payment' => array('', 'int'),
		    'partial_payment' => array('', 'int'),
		    'no_interest_payment' => array('', 'int'),
		    'status_pending' => array('', 'char'),
		    'status_success' => array('', 'char'),
		    'status_canceled' => array('', 'char'),
		);

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     */
    protected function getVmPluginCreateTableSQL() {
    	dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		return $this->createTableSQL('Payment Netgiro Table');
    }


    /**
     * Fields to create the payment table
     * @return string SQL Fileds
     */
    function getTableSQLFields() {

		$SQLfields = array(
		    'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
		    'virtuemart_order_id' => 'int(1) UNSIGNED',
		    'order_number' => ' char(64)',
		    'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
		    'payment_name' => 'varchar(5000)',
    		'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
            'tax_id' => 'smallint(11) DEFAULT NULL',
		);

		return $SQLfields;
	}

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 */
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		return $this->onStoreInstallPluginTable($jplugin_id);
	}


/*
**********************************************************************************************************************************************************************
CHECKOUT PROCES
**********************************************************************************************************************************************************************
*/

	function plgVmConfirmedOrder($cart, $order) {

		dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');

		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}

		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}

		dump($cart, 'cart');
		dump($order, 'order');

		$new_status = '';			

		$netgiroUrl = ($method->netgiro_testmode) ? 'http://test.netgiro.is/user/securepay' : 'https://SecurePay.netgiro.is/V1/';
		

		$secretKey = $method->secret_key;
		$applicationId = $method->application_id;
		$totalAmount = $cart->pricesUnformatted['billTotal'];
		$orderId = $cart->order_number;
		$signature = hash('sha256', $secretKey . $orderId . $totalAmount . $applicationId);
		$paymentSuccessfulURL = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
		$cancelUrl = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);	

		/*In netgiro 14 dayys is 1 and in our application it's 0 that's why we add it by 1*/
		if($method->default_payment == "true") {
			$paymentOption = 1;
		}
		else if($method->partial_payment == "true") {
			$paymentOption = 2;
		}
		else if($method->no_interest_payment == "true") {
			$paymentOption = 3;
		}
		

		/*Data to be posted to Netgiro*/
		$post_variables = Array(
			'ApplicationID' => $applicationId,
			'Signature' => $signature,
			'PaymentSuccessfulURL' => $paymentSuccessfulURL,
			'PaymentCancelledURL' => $cancelUrl,
			'ReturnCustomerInfo' => 'false',
			'Iframe' => 'false',
			'OrderId' => $orderId,
			'TotalAmount' => $totalAmount,
			'ShippingAmount' =>  $cart->pricesUnformatted['salesPriceShipment'],
			'DiscountAmount' => $cart->pricesUnformatted['discountAmount'],
			'PaymentOption' => $paymentOption
		);

		//add max installments
		$maxInstallments = (int)$method->max_installments;

		if($maxInstallments  >= 1  && $maxInstallments <= 12) {
			$post_variables['MaxNumberOfInstallments'] = $maxInstallments;
		}

		$n = 0;
		foreach($cart->products as $key => $product) {
			$unitPrice = intval($product->product_price);

			$post_variables["Items[$n].ProductNo"] = $product->product_sku;
			$post_variables["Items[$n].Name"] = $product->product_name;
			$post_variables["Items[$n].UnitPrice"] = $unitPrice;
			$post_variables["Items[$n].Quantity"] = $product->amount * 1000; //Quantity should be passed in 1/1000 units. For example if the quantity is 2 then it should be represented as 2000
			$post_variables["Items[$n].Amount"] = $unitPrice * $product->amount;
			
			$n++;
		}

		//Add tax as a final product
		$post_variables["Items[$n].ProductNo"] = "vsk";
		$post_variables["Items[$n].Name"] = "vsk";
		$post_variables["Items[$n].UnitPrice"] = $cart->pricesUnformatted['billTaxAmount'];
		$post_variables["Items[$n].Quantity"] = 1000; //Quantity should be passed in 1/1000 units. For example if the quantity is 2 then it should be represented as 2000
		$post_variables["Items[$n].Amount"] = $cart->pricesUnformatted['billTaxAmount'];


		// Prepare data that should be stored in the database
		$dbValues['order_number'] = $cart->order_number; //$order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_order_total'] = $totalAmount;
		$dbValues['tax_id'] = $method->tax_id;
		$this->storePSPluginInternalData($dbValues);

		$html = $this->generateCheckoutPage($method, $netgiroUrl, $post_variables);

		//$cart->emptyCart();
		// 	2 = don't delete the cart, don't send email and don't redirect
		$this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $dbValues['payment_name'], $new_status);
	}

	/*
	* Generete cart/confirm page 
	 */
	function generateCheckoutPage ($method, $netgiroUrl, $post_variables) {
		//ADD a style sheet
		$document = & JFactory::getDocument();
    	$document->addStyleSheet(JURI::base(). "plugins/vmpayment/netgiro/netgiro.css");

		// add spin image
		//$html = '<html><head><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
		$html = '';

		//INCLUDE NETGIRO BRANDING
		$html.= "
			<div id='netgiro-branding-container'></div>
			<script src=//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js></script>
			<script src='https://api.netgiro.is/scripts/netgiro.api.js'></script>
			<script>
			var jQ = jQuery.noConflict(),
				optIndex = 1,
				chosenOption;

				netgiro.branding.options = {
					showP1: ".$method->default_payment .",
					showP2: ".$method->partial_payment .",		
					showP3:	".$method->no_interest_payment .",
				};

				netgiro.branding.init('" . $method->application_id ."');

				//Add radio buttons
				jQ.each( netgiro.branding.options , function( key, showPaymentOpt ) {
					if(showPaymentOpt) {
						var containerId = '#netgiro-branding-p' + optIndex;
						jQ(containerId).prepend('<div class=\'netgiro-radiobutton\'>' +
													'<input type=\'radio\' name=\'chosenOption\' class=\'netgiro-payment-option\' value=\' ' + optIndex +' \'>' +
												'</div>');
					}
					optIndex++;
				});
				
				//If there is only one payment option auto submit form (payment option is already selected in )
				if(optIndex <= 2) {
					document.netgiro_form.submit();
				}

				//auto select first payment option
				jQ('input.netgiro-payment-option').first().attr('checked','checked');
				
				//on payment option select (radio-button click)
				jQ('input.netgiro-payment-option').on('click', function() {
					var selectedValue = jQ(this).val(); 
					var paymentOption = jQ('#netgiro-form input[name=\'PaymentOption\']');
					//If payment options exist update it's value
					if (paymentOption.length > 0){
						 paymentOption.val(selectedValue);
					} else {
						//payment option doesn't exist create one
						$('#netgiro-form').append('<input type=\'hidden\' name=\'PaymentOption\' value=\''+ selectedValue +'\'>');
					}
				});	

		
			</script>";

		//Generate POST form
		$html .= '<form action="' . $netgiroUrl . '" method="post" id="netgiro-form" name="netgiro_form" >';
		//$html.= '<input type="submit"  value="' . JText::_('VMPAYMENT_NETGIRO_REDIRECT_BUTTON') . '" />';
			
			foreach ($post_variables as $name => $value) {
				$html.= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '" />';
			}

		$html.= '<input type="submit" class="vm-button-correct" value="' . JText::_('VMPAYMENT_NETGIRO_REDIRECT_BUTTON'). '"></input>';
		$html.= '</form>';

		return $html;
	}

/*
**********************************************************************************************************************************************************************
Netgiro notification has been recived
**********************************************************************************************************************************************************************
*/

	/*
	* Successful notification from Netgiro is received update the order
	*/
	function plgVmOnPaymentResponseReceived (&$html) {
dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		
		//ADD a style sheet
		$document = & JFactory::getDocument();
    	$document->addStyleSheet(JURI::base(). "plugins/vmpayment/netgiro/netgiro.css");

		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$order_number = JRequest::getVar('on', 0);
		
		$vendorId = 0;
		$app = JFactory::getApplication();

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}

		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}

		if (!class_exists('VirtueMartCart'))
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');

		$payment_data = JRequest::get('get');
        $payment_name = $this->renderPluginName($method);
        

		$order['customer_notified'] = 0;
		if (!empty($payment_data)) {
			vmdebug('plgVmOnPaymentResponseReceived', $payment_data);

			$secretKey = $method->secret_key;
			$order_number = $payment_data['on'];
			$netgiroOrderNumber = $payment_data['orderid'];

			$signature = hash('sha256', $secretKey . $order_number);
			$netgiroSignature = $payment_data['signature'];


			if($order_number == $netgiroOrderNumber  && $signature == $netgiroSignature) {

				if (!class_exists('VirtueMartModelOrders'))
           			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

            	$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);

            	if ($virtuemart_order_id) {

            		$new_status = $method->status_success;

            		$modelOrder = VmModel::getModel('orders');
					$orderitems = $modelOrder->getOrder($virtuemart_order_id);
					$nb_history = count($orderitems['history']);

					//We have allready updated this status do nothing
					if ($orderitems['history'][$nb_history - 1]->order_status_code == $new_status) {
						$html = $this->_getPaymentResponseHtml($payment_data, $payment_name);
						return;
					}

            		$vendorId = 0;
					$payment = $this->getDataByOrderId($virtuemart_order_id);
					$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);

					if (!$this->selectedThisElement($method->payment_element)) {
						return false;
					}

					/*$this->_debug = $method->debug;*/

					if (!$payment) {
						$this->logInfo('getDataByOrderId payment not found: exit ', 'ERROR');
						return null;
					}

					//$this->logInfo('datatrans_data ' . implode('   ', $datatrans_data), 'message');

					//$this->logInfo('plgVmOnPaymentNotification return new_status:' . $new_status, 'message');

					$modelOrder = VmModel::getModel('orders');

					$order = array();
					$order['order_status'] = $new_status;
					$order['customer_notified'] =1;
					$order['comments'] = JText::sprintf('VMPAYMENT_NETGIRO_PAYMENT_STATUS_CONFIRMED', $order_number);
					$order['comments'] .= "</br>";
					$order['comments'] .= JText::sprintf('VMPAYMENT_NETGIRO_INVOICE_NUMBER') . ": " . $payment_data['invoiceNumber'] . "</br>";
					$order['comments'] .= JText::sprintf('VMPAYMENT_NETGIRO_CONFIRMATION_CODE'). ": " . $payment_data['confirmationCode'];

					$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);

					//$this->logInfo('Notification, sentOrderConfirmedEmail ' . $order_number . ' ' . $new_status, 'message');

					$this->emptyCart($return_context);

            	} 

            	else {
            		vmError('Netgiro data received, but no order number');
					return;
            	}
			} 

			else {
				vmError('Netgiro data received, signature check failed');
				return;
			}
	
		}

		else {
			dump($datatrans_data['status'], 'status error');
			//$this ->_storeDatatransInternalData($method, $datatrans_data, $virtuemart_order_id);
			$errorCode 		= $datatrans_data['errorCode'];
			$errorMessage	= $datatrans_data['errorMessage'].' '.$datatrans_data['errorDetail'];
			$msg			= JText::_('VMPAYMENT_NETGIRO_ERROR_RESPONSE') . $errorCode . ' ' . $errorMessage;
			$app->enqueueMessage($msg, 'error');

			return;
		}

        $html = $this->_getPaymentResponseHtml($payment_data, $payment_name);

		$cart = VirtueMartCart::getCart();
        //We delete the old stuff
        // get the correct cart / session
        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();
        return true;
	}


	/*
	* Cancel notification from Netgiro
	*/
	function plgVmOnUserPaymentCancel() { 

dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');

		if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

		$order_number = JRequest::getVar('on');

		if (!$order_number)
			return false;

		$db = JFactory::getDBO();

		$query = 'SELECT ' . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->_tablename . " WHERE  `order_number`= '" . $order_number . "'";
		$db->setQuery($query);

		$virtuemart_order_id = $db->loadResult();

		if (!$virtuemart_order_id) {
			return null;
		}

		$modelOrder = VmModel::getModel('orders');
		$orderitems = $modelOrder->getOrder($virtuemart_order_id);
		$nb_history = count($orderitems['history']);

		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}

		//We have allready updated this status do nothing
		if ($orderitems['history'][$nb_history - 1]->order_status_code == $method->status_canceled) {
			$html = $this->_getPaymentResponseHtml($payment_data, $payment_name);
			return;
		}

		$this->handlePaymentUserCancel($virtuemart_order_id);

		if (!class_exists('VirtueMartCart'))
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');

		$cart = VirtueMartCart::getCart();
        $cart->emptyCart();
        return true;

	}

	function _getPaymentResponseHtml($payment_data, $payment_name) {

dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		
		$html  = "<div class='netgiro-small-box'> <div id='netgiro-response' class='netgiro-box'>";
			$html .= "<h3>$payment_name </h3>";
			$html .= "<p><b>" . JText::sprintf('VMPAYMENT_NETGIRO_PURCHASE') . "</b></p>";
			$html .= "<div class='row'>". JText::sprintf('VMPAYMENT_NETGIRO_ORDER_ID')  .": " . $payment_data['orderid'] . "</div>";
			$html .= "<div class='row'>". JText::sprintf('VMPAYMENT_NETGIRO_CONFIRMATION_CODE') .": " . $payment_data['confirmationCode'] ."</div>";
			$html .= "<div class='row'>". JText::sprintf('VMPAYMENT_NETGIRO_INVOICE_NUMBER') .": " . $payment_data['invoiceNumber'] ."</div>";
		$html .= "</div></div>";

		/*$html = '<table>' . "\n";

		$leftSpace = "<span style='display:inline-block; width: 10px;'></span>";

		$html .= $this->getHtmlRow($leftSpace . JText::sprintf('VMPAYMENT_NETGIRO_PAYMENT_METHOD'), $leftSpace . $payment_name);

		dump($payment_data, "pd");
		if (!empty($payment_data)) {
			$html .= $this->getHtmlRow($leftSpace .JText::sprintf('VMPAYMENT_NETGIRO_ORDER_ID'), $leftSpace . $payment_data['orderid']);
			$html .= $this->getHtmlRow($leftSpace .JText::sprintf('VMPAYMENT_NETGIRO_CONFIRMATION_CODE'), $leftSpace . $payment_data['confirmationCode']);
			$html .= $this->getHtmlRow($leftSpace .JText::sprintf('VMPAYMENT_NETGIRO_INVOICE_NUMBER'), $leftSpace  .$payment_data['invoiceNumber']);
		}

		$html .= '</table>' . "\n";*/

		return $html;
	}

		
	function _storeNetgiroInternalData($method, $netgiro_data, $virtuemart_order_id) {

dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');

		// get all know columns of the table

		$db = JFactory::getDBO();
		$query = 'SHOW COLUMNS FROM `' . $this->_tablename . '` ';
		$db->setQuery($query);
		$columns = $db->loadResultArray(0);
		$post_msg = '';

		foreach ($netgiro_data as $key => $value) {
			$post_msg .= $key . "=" . $value . "<br />";
			$table_key = 'netgiro_response_' . $key;
			
			if (in_array($table_key, $columns)) {
				$response_fields[$table_key] = $value;
			}
		}

		$response_fields['payment_name'] = $this->renderPluginName($method);
		$return_context = $netgiro_data['custom'];
		$response_fields['order_number'] = $netgiro_data['orderid'];
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;

		$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);
	}

	
	function _getPaymentStatus($method, $status) {

dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');

		$new_status = '';

		if (strcmp($status, 'success') == 0) {
			$new_status = $method->status_success;
		} elseif (strcmp($status, 'pending') == 0) {
			$new_status = $method->status_pending;
		} else {
			$new_status = $method->status_canceled;
		}

		return $new_status;

	}


/*
**********************************************************************************************************************************************************************
SHOW AND SELECT PAYMENT METHOD
**********************************************************************************************************************************************************************
*/

	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 * if false this method will not be shown
	 */
	protected function checkConditions($cart, $method, $cart_prices) {
dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $cart->pricesCurrency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$currency_code_3 = strtolower($currency_code_3);

		if($method->application_id == "" || $method->secret_key == "" || $currency_code_3 != "isk") {
			return false;
		} 

		//If at least one payment option is not choosen
		if($method->default_payment != "true" && $method->partial_payment != "true" && $method->no_interest_payment != "true") {
			return false;
		}
		
		return true;
	}


	 /**
     * This shows the plugin for choosing in the payment list of the checkout process.
     */
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	/**
     * After the plugin is selected from the list
     */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		return $this->OnSelectCheck($cart);
	}

	/**
     * Calculate the price to be shown in the list beside plugin name
     */
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}


	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
   	 	dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
   	 	return $this->onCheckAutomaticSelected($cart, $cart_prices);
	}

	function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		return $this->declarePluginParams('payment', $name, $id, $data);
	}

	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	public function plgVmOnUpdateOrderLine ($_formData) {
		return null;
	}

	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		dumpMessage(__CLASS__.'::'.__FUNCTION__.' is triggered!');
		return $this->setOnTablePluginParams($name, $id, $table);
	}

}


?>