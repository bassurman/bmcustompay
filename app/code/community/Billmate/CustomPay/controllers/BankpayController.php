<?php

class Billmate_CustomPay_BankpayController extends Billmate_CustomPay_Controller_InstantMethods
{
    const PAYMENT_METHOD_CODE = 'bmcustom_bankpay';

    public function redirectAction()
    {
        $session = $this->getCheckoutSession();
        $session->getBillmateStandardQuoteId($session->getQuoteId());
        $session->setBillmateCheckOutUrl($_SERVER['HTTP_REFERER']);

        $order = Mage::getModel('sales/order')
            ->loadByIncrementId($session->getLastRealOrderId());

        $status = 'pending_payment';
        $isCustomerNotified = false;
        $order->setState('new', $status, '', $isCustomerNotified);
        $order->save();

        $session->getQuote()->setIsActive(false)->save();
        $session->clear();

        $this->getResponse()->setBody($this->getLayout()->createBlock('billmatecustompay/bankpay_redirect')->toHtml());
        $session->unsQuoteId();
        $session->unsRedirectUrl();
    }

    /**
     * When a customer cancel payment from Billmate.
     */
    public function cancelAction()
    {
        $bmConnection = $this->getBmConnection();
        $bmRequestedData = $this->getBmRequestData();

        $data = $bmConnection->verify_hash($bmRequestedData);

        if (isset($data['code'])) {
            Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
            $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
            return;
        }

        if (isset($data['status'])) {
            switch (strtolower($data['status'])) {
                case 'cancelled':
                    Mage::getSingleton('core/session')
                        ->addError(
                            $this->getHelper()->__('The bank payment has been canceled. Please try again or choose a different payment method.')
                        );
                    $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                    break;
                case 'failed':
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
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

        $bmRequestedData = $this->getBmRequestData();
        $bmConnection = $this->getBmConnection();
        $data = $bmConnection->verify_hash($bmRequestedData);

        if (isset($data['code'])) {
            Mage::log('Something went wrong billmate bank'. print_r($data,true),0,'billmate.log',true);
            return;
        }
        $quote = Mage::getModel('sales/quote')->load($quoteId);

        switch(strtolower($data['status'])) {
            case 'pending':
                $order = $this->place($quote);

                if ($order) {
                    if ($order->getStatus() == $this->getDefOrderStatus()) {
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        break;
                    }  else {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', 'pending_payment', '', false);
                        $order->save();

                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                }
                else {
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'paid':
                $order = $this->place($quote);
                if ($order) {
                    if ($order->getStatus() != $this->getDefOrderStatus()) {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', $this->getDefOrderStatus(), '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);

                        $order->save();
                        $this->addTransaction($order,$data);

                        $this->sendNewOrderMail($order);

                    } else {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', 'pending_payment', '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);

                        $order->save();

                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                }
                else {
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'cancelled':
                Mage::getSingleton('core/session')->addError($this->getHelper()->__('The bank payment has been canceled. Please try again or choose a different payment method.'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;
            case 'failed':
                Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;

        }
    }

    public function acceptAction()
    {
        $quote = $this->getCheckoutSession()->getQuote();
        $bmConnection = $this->getBmConnection();

        $bmRequestedData = $this->getBmRequestData();
        $data = $bmConnection->verify_hash($bmRequestedData);
        if (isset($data['code'])) {
            Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
            $this->getResponse()->setRedirect(Mage::helper('checkout/url')->getCheckoutUrl());
            return;
        }
        switch(strtolower($data['status']))
        {
            case 'pending':
                $order = $this->place($quote);
                if ($order && $order->getStatus()) {
                    if($order->getStatus() == $this->getDefOrderStatus()) {
                        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] ==1){
                            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => $this->getCheckoutSession()->getBillmateHash()),'_secure' => true));
                            return;
                        }
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        break;
                    } else {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', 'pending_payment', '', false);
                        $order->save();
                        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] ==1){
                            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => $this->getCheckoutSession()->getBillmateHash()),'_secure' => true));
                            return;
                        }
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }
                } else {
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;
                }
                break;
            case 'paid':
                $order = $this->place($quote);
                if($order) {
                    if ($order->getStatus() != $this->getDefOrderStatus()) {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new', $this->getDefOrderStatus(), '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);
                        $order->save();
                        $this->addTransaction($order,$data);
                        $this->sendNewOrderMail($order);
                    } else {
                        $order->addStatusHistoryComment(Mage::helper('payment')->__('Order processing completed' . '<br/>Billmate status: ' . $data['status'] . '<br/>' . 'Transaction ID: ' . $data['number']));
                        $order->setState('new',$this->getDefOrderStatus(), '', false);
                        $order->setCustomerIsGuest(($quote->getCustomerId() == NULL) ? 1 : 0);
                        $order->save();
                        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] == 1){
                            $this->_redirect('billmatecommon/billmatecheckout/confirmation',array('_query' => array('hash' => $this->getCheckoutSession()->getBillmateHash()),'_secure' => true));
                            return;

                        }
                        $this->_redirect('checkout/onepage/success',array('_secure' => true));
                        return;
                    }

                }
                else {
                    Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
                    $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                    return;                }
                break;
            case 'cancelled':
                Mage::getSingleton('core/session')->addError($this->getHelper()->__('The bank payment has been canceled. Please try again or choose a different payment method.'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;
            case 'failed':
                Mage::getSingleton('core/session')->addError($this->getHelper()->__('Unfortunately your bank payment was not processed with the provided bank details. Please try again or choose another payment method.'));
                $this->_redirect(Mage::helper('checkout/url')->getCheckoutUrl());
                return;
                break;

        }
        if(isset($_GET['billmate_checkout']) && $_GET['billmate_checkout'] === true){
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
        if($orderModel->getId()){
            return $orderModel;
        }
        $quote->collectTotals();
        $service = Mage::getModel('sales/service_quote',$quote);
        $service->submitAll();
        $this->getCheckoutSession()->setLastQuoteId($quote->getId())
            ->setLastSuccessQuoteId($quote->getId())
            ->clearHelperData();
        $order = $service->getOrder();
        if($order){
            $this->getCheckoutSession()->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId());

        }
        $quote->setIsActive(false)->save();
        return ($order) ? $order : false;
    }

    /**
     * when Billmate returns
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

        $bmRequestedData = $this->getBmRequestData();
        $data = $bmConnection->verify_hash($bmRequestedData);

        if ($order->getStatus() == $status ) {
            $session->setOrderId($data['orderid']);
            $session->setQuoteId($session->getBillmateQuoteId(true));
            $this->getCheckoutSession()->getQuote()->setIsActive(false)->save();
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
            if(version_compare($magentoVersion,'1.9.1','>='))
                $order->queueOrderUpdateEmail(true, $comment);
            else
                $order->sendOrderUpdateEmail(true,$comment);

            Mage::getSingleton('core/session')->addError($this->__('Unable to process with payment gateway :').$data['message']);
            if(isset($data['error'])){
                Mage::log('hash:'.$data['hash'].' recieved'.$data['hash_recieved']);
            }
            $checkouturl = $session->getBillmateCheckOutUrl();
            $checkouturl = empty($checkouturl)?Mage::helper('checkout/url')->getCheckoutUrl():$checkouturl;
            $this->_redirect($checkouturl);
        } else {
            $status = $this->getDefOrderStatus();
            if ($data['status'] == 'Pending' && $order->getStatus() != $status) {
                $status = 'pending_payment';
            }

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
            $transaction = $payment->addTransaction(
                Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH, null, false, false
            );
            $transaction->setOrderId($order->getId())
                ->setIsClosed(0)
                ->setTxnId($data['number'])
                ->setPaymentId($payment->getId())
                ->save();
            $payment->save();
            $order->save();

            $session->setQuoteId($session->getBillmateStandardQuoteId(true));
            $this->getCheckoutSession()->getQuote()->setIsActive(false)->save();
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
        $transaction = $payment->addTransaction(
            Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH, null, false, false
        );
        $transaction->setOrderId($order->getId())
            ->setIsClosed(0)
            ->setTxnId($data['number'])
            ->setPaymentId($payment->getId())
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
}