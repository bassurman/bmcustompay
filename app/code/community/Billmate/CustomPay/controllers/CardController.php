<?php

class Billmate_CustomPay_CardController extends Billmate_CustomPay_Controller_InstantMethods
{
    const PAYMENT_METHOD_CODE = 'bmcustom_card';

    public function redirectAction()
    {
        $session = $this->getCheckoutSession();
        $session->setBillmateQuoteId($session->getQuoteId());
		$session->setBillmateCheckOutUrl($_SERVER['HTTP_REFERER']);

        $order = Mage::getModel('sales/order')
            ->loadByIncrementId($session->getLastRealOrderId());
		
		$status = 'pending_payment';
		$isCustomerNotified = false;
		$order->setState('new', $status, '', $isCustomerNotified);
		$order->save();

		$session->getQuote()->setIsActive(false)->save();
		$session->clear();	
		
        $this->getResponse()->setBody($this->getLayout()
            ->createBlock('billmatecustompay/cardpay_redirect')->toHtml());
        $session->unsQuoteId();
        $session->unsRedirectUrl();
    }

    /**
     * When a customer cancel payment from paypal.
     */
    public function cancelAction()
    {
        $bmConnection = $this->getBmConnection();
        $bmRequestData = $this->getBmRequestData();
        $bmResponseData = $bmConnection->verify_hash($bmRequestData);
        
        if (isset($bmResponseData['code'])) {
            Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
            $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
            return;
        }
        if (isset($bmResponseData['status'])) {
            switch (strtolower($bmResponseData['status'])) {
                case 'cancelled':
                    Mage::getSingleton('core/session')
                        ->addError($this->getHelper()->__('The card payment has been canceled. Please try again or choose a different payment method.'));
                    $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                    break;
                case 'failed':
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
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
        $bmConnection = $this->getBmConnection();

        $bmResponseData = $bmConnection->verify_hash($bmRequestData);

        if (isset($bmResponseData['code'])) {
            Mage::log('Something went wrong billmate bank' . print_r($bmResponseData,true),0,'billmate.log',true);
            return;
        }

        $quote = Mage::getModel('sales/quote')->load($quoteId);
        switch (strtolower($bmResponseData['status'])) {
            case 'pending':
                $order = $this->place($quote);

                if ($order ) {
                    if($order->getStatus() != $this->getDefOrderStatus()) {
                        $order->addStatusHistoryComment($this->getHelper()->__('Order processing completed' . '<br/>Billmate status: ' . $bmResponseData['status'] . '<br/>' . 'Transaction ID: ' . $bmResponseData['number']));
                        $order->setState('new', 'pending_payment', '', false);
                        $order->save();
                        $this->sendNewOrderMail($order);
                    } else {
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                } else {
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'paid':
                $order = $this->place($quote);
                if ($order) {
                    if ($order->getStatus() != $this->getDefOrderStatus()) {
                        $order->addStatusHistoryComment($this->getHelper()->__('Order processing completed' . '<br/>Billmate status: ' . $bmResponseData['status'] . '<br/>' . 'Transaction ID: ' . $bmResponseData['number']));
                        $order->setState('new', $this->getDefOrderStatus(), '', false);
                        $order->save();
                        $this->addTransaction($order, $bmResponseData);
                        $this->sendNewOrderMail($order);
                    } else {
                        $order->addStatusHistoryComment($this->getHelper()->__('Order processing completed' . '<br/>Billmate status: ' . $bmResponseData['status'] . '<br/>' . 'Transaction ID: ' . $bmResponseData['number']));
                        $order->setState('new',  $this->getDefOrderStatus(), '', false);
                        $order->save();
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                } else {
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'cancelled':
                Mage::getSingleton('core/session')->addError($this->getHelper()->__('The card payment has been canceled. Please try again or choose a different payment method.'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;
            case 'failed':
                Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;

        }
    }

    public function acceptAction()
    {
        /** @var  $quote Mage_Sales_Model_Quote */
        $quote = $this->getCheckoutSession()->getQuote();

        $bmConnection = $this->getBmConnection();
        $bmRequestData = $this->getBmRequestData();

        $bmResponseData = $bmConnection->verify_hash($bmRequestData);

        if (isset($bmResponseData['code'])) {
            Mage::getSingleton('core/session')->addError($this->getHelper()->__('Something went wrong with your payment'));
            $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
            return;
        }

        switch (strtolower($bmResponseData['status'])) {
            case 'pending':
                $order = $this->place($quote);
                if ($order && $order->getStatus()) {
                    if($order->getStatus() != $this->getMethodsHelper()->getDefaultOrderStatus(self::PAYMENT_METHOD_CODE)) {
                        $order->addStatusHistoryComment($this->getHelper()->__('Order processing completed' . '<br/>Billmate status: ' . $bmResponseData['status'] . '<br/>' . 'Transaction ID: ' . $bmResponseData['number']));
                        $order->setState('new', 'pending_payment', '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);

                        $order->save();
                        $this->addTransaction($order, $bmResponseData);

                        $this->sendNewOrderMail($order);
                    } else {

                        if (isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] == 1) {
                            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => $this->getCheckoutSession()->getBillmateHash()),'_secure' => true));
                            return;
                        }
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                } else {
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Something went wrong with your order'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'paid':
                $order = $this->place($quote);
                if ($order) {
                    if ($order->getStatus() != $this->getDefOrderStatus()) {
                        $order->addStatusHistoryComment($this->getHelper()->__('Order processing completed' . '<br/>Billmate status: ' . $bmResponseData['status'] . '<br/>' . 'Transaction ID: ' . $bmResponseData['number']));
                        $order->setState('new', $this->getDefOrderStatus(), '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);

                        $order->save();

                        $this->addTransaction($order, $bmResponseData);
                        $this->sendNewOrderMail($order);
                    } else {
                        $order->addStatusHistoryComment($this->getHelper()->__('Order processing completed' . '<br/>Billmate status: ' . $bmResponseData['status'] . '<br/>' . 'Transaction ID: ' . $bmResponseData['number']));
                        $order->setState('new', $this->getDefOrderStatus(), '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);

                        $order->save();
                        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] == 1){
                            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => $this->getCheckoutSession()->getBillmateHash()),'_secure' => true));
                            return;

                        }
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }

                } else {
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Something went wrong with your order'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'cancelled':
                Mage::getSingleton('core/session')->addError($this->getHelper()->__('You have cancelled your payment, do you want to use another payment method?'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;
            case 'failed':
                Mage::getSingleton('core/session')->addError($this->getHelper()->__('Something went wrong with your payment'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;

        }
        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] == 1){
            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => $this->getCheckoutSession()->getBillmateHash()),'_secure' => true));
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
        if ($orderModel->getId()) {
            return $orderModel;
        }
        $quote->collectTotals();
        $service = Mage::getModel('sales/service_quote',$quote);
        $service->submitAll();
        $this->getCheckoutSession()->setLastQuoteId($quote->getId())
            ->setLastSuccessQuoteId($quote->getId())
            ->clearHelperData();
        $order = $service->getOrder();
        if ($order) {
            $this->getCheckoutSession()->setLastOrderId($order->getId())
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
        $bmConnection = $this->getBmConnection();
        $session = Mage::getSingleton('checkout/session');
        $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());

        $status = $this->getDefOrderStatus();

		$session->setLastSuccessQuoteId($session->getBillmateQuoteId());
        $session->setLastQuoteId($session->getBillmateQuoteId());
        $session->setLastOrderId($session->getLastOrderId());

        $bmRequestData = $this->getBmRequestData();
        $bmResponseData = $bmConnection->verify_hash($bmRequestData);

        if ( $order->getStatus() == $status ) {
            $session->setLastSuccessQuoteId($session->getLastRealOrderId());
            $session->setOrderId($bmResponseData['orderid']);
            $session->setQuoteId($session->getBillmateQuoteId(true));
            $this->getCheckoutSession()->getQuote()->setIsActive(false)->save();
            $session->unsRebuildCart();

            $this->_redirect('checkout/onepage/success', array('_secure'=>true));
            return;
        }
        
        if (isset($bmResponseData['code']) || isset($bmResponseData['error'])) {

            $status = 'pending_payment';
            $comment = $this->__('Unable to complete order, Reason : ').$bmResponseData['message'] ;
            $isCustomerNotified = true;
            $order->setState('new', $status, $comment, $isCustomerNotified);
            $order->save();
            $magentoVersion = Mage::getVersion();
            if(version_compare($magentoVersion,'1.9.1','>=')) {
                $order->queueOrderUpdateEmail(true, $comment);
            } else {
                $order->sendOrderUpdateEmail(true,$comment);
            }
            
            Mage::getSingleton('core/session')->addError($this->__('Unable to process with payment gateway :').$bmResponseData['message']);
            if(isset($bmResponseData['code'])){
                Mage::log('hash:'.$bmResponseData['hash'].' recieved'.$bmResponseData['hash_received']);
            }
            $checkoutUrl = $session->getBillmateCheckOutUrl();
            $checkoutUrl = empty($checkoutUrl)?Mage::helper('checkout/url')->getCheckoutUrl():$checkoutUrl;
            $this->_redirect($checkoutUrl);
        } else {
			$status = $this->getDefOrderStatus();
			$isCustomerNotified = true;
			$order->setState('new', $status, '', $isCustomerNotified);
            $payment = $order->getPayment();
            $info = $payment->getMethodInstance()->getInfoInstance();
            $info->setAdditionalInformation('invoiceid',$bmResponseData['number']);
            $data1 = $bmResponseData;

            $session->unsRebuildCart();

            $order->addStatusHistoryComment($this->getHelper()->__('Order processing completed'.'<br/>Billmate status: '.$data1['status'].'<br/>'.'Transaction ID: '.$data1['number']));

            $payment->setTransactionId($bmResponseData['number']);
	        $payment->setIsTransactionClosed(0);
	        $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH,null,false, false);
	        $transaction->setOrderId($order->getId())->setIsClosed(0)->setTxnId($bmResponseData['number'])->setPaymentId($payment->getId())
	                    ->save();
	        $payment->save();

			$order->save();
            $session->setQuoteId($session->getBillmateQuoteId(true));
            $this->getCheckoutSession()->getQuote()->setIsActive(false)->save();

            $this->sendNewOrderMail($order);

            $this->_redirect('checkout/onepage/success', array('_secure'=>true));
        }
    }

    /**
     * @param $order
     * @param $bmResponseData
     */
    public function addTransaction($order, $bmResponseData)
    {
        $payment = $order->getPayment();
        $info = $payment->getMethodInstance()->getInfoInstance();
        $info->setAdditionalInformation('invoiceid', $bmResponseData['number']);

        $payment->setTransactionId($bmResponseData['number']);
        $payment->setIsTransactionClosed(0);
        $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH, null, false, false);
        $transaction->setOrderId($order->getId())->setIsClosed(0)->setTxnId($bmResponseData['number'])->setPaymentId($payment->getId())
            ->save();
        $payment->save();
    }
}