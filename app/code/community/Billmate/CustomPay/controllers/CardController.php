<?php

class Billmate_CustomPay_CardController extends Mage_Core_Controller_Front_Action
{
    public function notifyAction()
    {
        $bmRequestData = $this->getBmRequestData();

        $k = Mage::helper('billmatecustompay')->getBillmate();
        $data = $k->verify_hash($bmRequestData);
        $this->getCheckoutSession()->setData('last_real_order_id', $data['orderid']);


        $order = Mage::getModel('sales/order')->loadByIncrementId($data['orderid']);
        if($data['status'] == 'Cancelled' && !$order->isCanceled()){

            if (!$order->isCanceled() && !$order->hasInvoices()) {

                $message = Mage::helper('billmatecustompay')->__('Order canceled by user');
                $order->cancel();
                $order->addStatusToHistory(Mage_Sales_Model_Order::STATE_CANCELED, $message);
                $order->save();
            }

            if ($data['orderid']) {
                $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
                if ($quote->getId()) {
                    $quote->setIsActive(true)->save();
                    $this->getCheckoutSession()->setQuoteId($quote->getId());
                }

                $quoteItems = $quote->getAllItems();
                if ( sizeof( $quoteItems ) <=0 ) {
                    $items = $order->getAllItems();
                    if ( $items ) {
                        foreach ( $items as $item ) {
                            $product1 = Mage::getModel('catalog/product')->load($item->getProductId());
                            $qty = $item->getQtyOrdered();
                            $quote->addProduct($product1, $qty);
                        }
                    }else{
                        $quote->setIsActive(false)->save();

                    }
                    $quote->setReservedOrderId(null);
                    $quote->collectTotals()->save();
                }
                $this->getCheckoutSession()->unsRebuildCart();

            }
            die('OK');
        }
        if($order->isCanceled()){
            die('OK');
        }

        try{
            $status = Mage::getStoreConfig('payment/bmcustom_card/order_status');

            if( $order->getStatus() == $status ){

                $this->getCheckoutSession()->setLastSuccessQuoteId($order->getQuoteId());
                $this->getCheckoutSession()->setOrderId($data['orderid']);
                $this->getCheckoutSession()->setQuoteId($order->getQuoteId());
                Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
                $this->getCheckoutSession()->unsRebuildCart();
                die('OK');
            }

            $payment = $order->getPayment();
            $info = $payment->getMethodInstance()->getInfoInstance();
            $info->setAdditionalInformation('invoiceid',$data['number']);
            $data1 = $data;

            $order->addStatusHistoryComment(
                Mage::helper('payment')->__('Order processing completed'.'<br/>Billmate status: '.$data1['status'].'<br/>'.'Transaction ID: '.$data1['number'])           );
            $payment->setTransactionId($data['number']);
	        $payment->setIsTransactionClosed(0);
	        $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH,null,false, false);
	        $transaction->setOrderId($order->getId())->setIsClosed(0)->setTxnId($data['number'])->setPaymentId($payment->getId())
	                    ->save();
	        $payment->save();
            $this->getCheckoutSession()->unsRebuildCart();

            $isCustomerNotified = false;
            $order->setState('new', $status, '', $isCustomerNotified);
            $order->save();

            $this->sendNewOrderMail($order);

            $this->clearAllCache();

        }catch(Exception $ex){
            Mage::log($ex->getMessage());
        }
    }

    public function clearAllCache()
    {
        try {
            $cacheTypes = Mage::app()->useCache();
            foreach ($cacheTypes as $type => $option) {
                Mage::app()->getCacheInstance()->cleanType($type);
            }
        } catch (Exception $e) {
            Mage::log($e->getMessage());
        }
    }

    public function redirectAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setBillmateQuoteId($session->getQuoteId());
		$session->setBillmateCheckOutUrl($_SERVER['HTTP_REFERER']);

        $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
		
		$status = 'pending_payment';
		$isCustomerNotified = false;
		$order->setState('new', $status, '', $isCustomerNotified);
		$order->save();

		$session->getQuote()->setIsActive(false)->save();
		$session->clear();	
		
        $this->getResponse()->setBody($this->getLayout()->createBlock('billmatecustompay/cardpay_redirect')->toHtml());
        $session->unsQuoteId();
        $session->unsRedirectUrl();
    }

    /**
     * When a customer cancel payment from paypal.
     */
    public function cancelAction()
    {
        $k = Mage::helper('billmatebankpay')->getBillmate();
        $bmRequestData = $this->getBmRequestData();
        $data = $k->verify_hash($bmRequestData);


        if(isset($data['code'])){
            Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
            $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
            return;
        }
        if(isset($data['status'])){
            switch(strtolower($data['status'])){
                case 'cancelled':
                    Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('The card payment has been canceled. Please try again or choose a different payment method.'));
                    $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                    break;
                case 'failed':
                    Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
                    $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                    break;
            }
        }
        $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
        return;
    }

    public function callbackAction()
    {
        $quoteId = $this->getRequest()->getParam('billmate_quote_id');

        $bmRequestData = $this->getBmRequestData();

        $k = Mage::helper('billmatecustompay')->getBillmate();
        $data = $k->verify_hash($bmRequestData);

        if (isset($data['code'])) {
            Mage::log('Something went wrong billmate bank'. print_r($data,true),0,'billmate.log',true);
            return;
        }

        $quote = Mage::getModel('sales/quote')->load($quoteId);
        switch(strtolower($data['status']))
        {
            case 'pending':
                $order = $this->place($quote);

                if ($order ) {
                    if($order->getStatus() != Mage::getStoreConfig('payment/bmcustom_card/order_status')) {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', 'pending_payment', '', false);
                        $order->save();
                        $this->sendNewOrderMail($order);
                    } else {
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                } else {
                    Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'paid':
                $order = $this->place($quote);
                if ($order) {

                    if($order->getStatus() != Mage::getStoreConfig('payment/bmcustom_card/order_status')) {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', Mage::getStoreConfig('payment/bmcustom_card/order_status'), '', false);
                        $order->save();
                        $this->addTransaction($order, $data);
                        $this->sendNewOrderMail($order);
                    } else {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new',  Mage::getStoreConfig('payment/bmcustom_card/order_status'), '', false);
                        $order->save();

                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                } else {
                    Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'cancelled':
                Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('The card payment has been canceled. Please try again or choose a different payment method.'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;
            case 'failed':
                Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;

        }
    }

    public function acceptAction()
    {
        /** @var  $quote Mage_Sales_Model_Quote */
        $quote = Mage::getSingleton('checkout/session')->getQuote();


        $k = Mage::helper('billmatecustompay')->getBillmate();
        $bmRequestData = $this->getBmRequestData();

        $data = $k->verify_hash($bmRequestData);
        if (isset($data['code'])) {
            Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Something went wrong with your payment'));
            $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
            return;
        }
        switch(strtolower($data['status']))
        {
            case 'pending':
                $order = $this->place($quote);
                if($order && $order->getStatus()) {
                    if($order->getStatus() != Mage::getStoreConfig('payment/bmcustom_card/order_status')) {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', 'pending_payment', '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);

                        $order->save();
                        $this->addTransaction($order, $data);

                        $this->sendNewOrderMail($order);
                    }  else {

                        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] == 1){
                            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => Mage::getSingleton('checkout/session')->getBillmateHash()),'_secure' => true));
                            return;

                        }
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                }
                else {
                    Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Something went wrong with your order'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'paid':
                $order = $this->place($quote);
                if($order) {
                    if($order->getStatus() != Mage::getStoreConfig('payment/bmcustom_card/order_status')) {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', Mage::getStoreConfig('payment/bmcustom_card/order_status'), '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);

                        $order->save();

                        $this->addTransaction($order, $data);
                        $this->sendNewOrderMail($order);
                    } else {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', Mage::getStoreConfig('payment/bmcustom_card/order_status'), '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);

                        $order->save();
                        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] == 1){
                            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => Mage::getSingleton('checkout/session')->getBillmateHash()),'_secure' => true));
                            return;

                        }
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }

                }
                else {
                    Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Something went wrong with your order'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;                }
                break;
            case 'cancelled':
                Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('You have cancelled your payment, do you want to use another payment method?'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;
            case 'failed':
                Mage::getSingleton('core/session')->addError(Mage::helper('billmatecustompay')->__('Something went wrong with your payment'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;

        }
        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] == 1){
            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => Mage::getSingleton('checkout/session')->getBillmateHash()),'_secure' => true));
            return;

        }
        $this->_redirect('checkout/onepage/success',array('_secure' => true));
        return;


    }

    public function place($quote)
    {
        /** @var  $quote Mage_Sales_Model_Quote */
        $orderModel = Mage::getModel('sales/order');
        $orderModel->load($quote->getId(), 'quote_id');
        if($orderModel->getId()){
            return $orderModel;
        }
        $quote->collectTotals();
        $service = Mage::getModel('sales/service_quote',$quote);
        $service->submitAll();
        Mage::getSingleton('checkout/session')->setLastQuoteId($quote->getId())
            ->setLastSuccessQuoteId($quote->getId())
            ->clearHelperData();
        $order = $service->getOrder();
        if($order){
            Mage::getSingleton('checkout/session')->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId());

        }
        $quote->setIsActive(false)->save();
        return ($order) ? $order : false;
    }
    
    /**
     * when paypal returns
     * The order information at this point is in POST
     * variables.  However, you don't want to "process" the order until you
     * get validation from the IPN.
     */
    public function successAction()
    {
        $k = Mage::helper('billmatecustompay')->getBillmate();
        $session = Mage::getSingleton('checkout/session');
        $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());


        $status = Mage::getStoreConfig('payment/bmcustom_card/order_status');

		$session->setLastSuccessQuoteId($session->getBillmateQuoteId());
        $session->setLastQuoteId($session->getBillmateQuoteId());
        $session->setLastOrderId($session->getLastOrderId());

		if(empty($_POST)) $_POST = $_GET;
        $data = $k->verify_hash($_POST);
        if ( $order->getStatus() == $status ) {

            $session->setLastSuccessQuoteId($session->getLastRealOrderId());
            $session->setOrderId($data['orderid']);
            $session->setQuoteId($session->getBillmateQuoteId(true));
            Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
            $session->unsRebuildCart();

            $this->_redirect('checkout/onepage/success', array('_secure'=>true));
            return;
        }
        
        if (isset($data['code']) || isset($data['error'])) {

            $status = 'pending_payment';
            $comment = $this->__('Unable to complete order, Reason : ').$data['message'] ;
            $isCustomerNotified = true;
            $order->setState('new', $status, $comment, $isCustomerNotified);
            $order->save();
            $magentoVersion = Mage::getVersion();
            if(version_compare($magentoVersion,'1.9.1','>=')) {
                $order->queueOrderUpdateEmail(true, $comment);
            } else {
                $order->sendOrderUpdateEmail(true,$comment);
            }
            
            Mage::getSingleton('core/session')->addError($this->__('Unable to process with payment gateway :').$data['message']);
            if(isset($data['code'])){
                Mage::log('hash:'.$data['hash'].' recieved'.$data['hash_received']);
            }
            $checkoutUrl = $session->getBillmateCheckOutUrl();
            $checkoutUrl = empty($checkoutUrl)?Mage::helper('checkout/url')->getCheckoutUrl():$checkoutUrl;
            $this->_redirect($checkoutUrl);
        } else {

			$status = Mage::getStoreConfig('payment/bmcustom_card/order_status');
			$isCustomerNotified = true;
			$order->setState('new', $status, '', $isCustomerNotified);
            $payment = $order->getPayment();
            $info = $payment->getMethodInstance()->getInfoInstance();
            $info->setAdditionalInformation('invoiceid',$data['number']);
            $data1 = $data;

            $session->unsRebuildCart();

            $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed'.'<br/>Billmate status: '.$data1['status'].'<br/>'.'Transaction ID: '.$data1['number']));

            $payment->setTransactionId($data['number']);
	        $payment->setIsTransactionClosed(0);
	        $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH,null,false, false);
	        $transaction->setOrderId($order->getId())->setIsClosed(0)->setTxnId($data['number'])->setPaymentId($payment->getId())
	                    ->save();
	        $payment->save();

			$order->save();
            $session->setQuoteId($session->getBillmateQuoteId(true));
            Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();

            $this->sendNewOrderMail($order);

            $this->_redirect('checkout/onepage/success', array('_secure'=>true));
        }
    }

    /**
     * @param $order
     * @param $data
     */
    public function addTransaction($order, $data)
    {
        $payment = $order->getPayment();
        $info = $payment->getMethodInstance()->getInfoInstance();
        $info->setAdditionalInformation('invoiceid', $data['number']);

        $payment->setTransactionId($data['number']);
        $payment->setIsTransactionClosed(0);
        $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH, null, false, false);
        $transaction->setOrderId($order->getId())->setIsClosed(0)->setTxnId($data['number'])->setPaymentId($payment->getId())
            ->save();
        $payment->save();
    }

    /**
     * @param $order
     */
    public function sendNewOrderMail($order)
    {
        $magentoVersion = Mage::getVersion();
        $isEE = Mage::helper('core')->isModuleEnabled('Enterprise_Enterprise');
        if (version_compare($magentoVersion, '1.9.1', '>=') && !$isEE) {
            $order->queueNewOrderEmail();
        } else {
            $order->sendNewOrderEmail();
        }
    }

    /**
     * @return mixed
     * @throws Exception
     */
    public function getBmRequestData()
    {
        $bmData = $this->getRequest()->getParam('data');
        $credentials = $this->getRequest()->getParam('credentials');
        if ($bmData && $credentials) {
            $postData['data'] = json_decode($bmData, true);
            $postData['credentials'] = json_decode($credentials, true);
            return $postData;
        }

        $jsonBodyRequest = file_get_contents('php://input');
        if ($jsonBodyRequest) {
            return json_decode($jsonBodyRequest, true);
        }
        throw new Exception('The request does not contain information');
    }

    /**
     * @return Mage_Core_Model_Abstract
     */
    public function getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }
}