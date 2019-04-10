<?php

abstract class Billmate_CustomPay_Controller_InstantMethods extends Billmate_CustomPay_Controller_Methods
{
    /**
     * @param $quote
     *
     * @return false|Mage_Core_Model_Abstract
     * @throws Exception
     */
    public function placeOrder($quote)
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
        $this->getCheckoutSession()
            ->setLastQuoteId($quote->getId())
            ->setLastSuccessQuoteId($quote->getId())
            ->clearHelperData();
        $order = $service->getOrder();
        if ($order) {
            $this->getCheckoutSession()
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId());
        }

        $quote->setIsActive(false)->save();
        return $order;
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
        $transaction->setOrderId($order->getId())
            ->setIsClosed(0)
            ->setTxnId($bmResponseData['number'])
            ->setPaymentId($payment->getId())
            ->save();
        $payment->save();
    }

    public function doProcessCancel()
    {
        $messageModel = $this->getMethodMessageModel();
        try {
            $bmRequestData = $this->getBmRequestData();
            $bmConnection = $this->getBmConnection();
            $bmResponseData = $bmConnection->verify_hash($bmRequestData);

            if (isset($bmResponseData['code'])) {
                if (isset($bmResponseData['code'])) {
                    $this->getHelper()->addLog($bmResponseData);
                    throw new Exception($bmResponseData['message']);
                }
            }

            if (isset($bmResponseData['status'])) {
                switch (strtolower($bmResponseData['status'])) {
                    case 'cancelled':
                        throw new Exception(
                            $this->getHelper()->__($messageModel->getCancelMessage())
                        );
                        break;
                    case 'failed':
                        throw new Exception(
                            $this->getHelper()->__($messageModel->getFailedMessage())
                        );
                        break;
                }
            }
        } catch (Exception $e) {
            $this->getCoreSession()->addError($e->getMessage());
        }
        return $this;
    }

    /**
     * @param $order
     */
    public function sendNewOrderMail($order)
    {
        if ($this->getHelper()->useEmailQueue()) {
            $order->queueNewOrderEmail();
        } else {
            $order->sendNewOrderEmail();
        }
    }

    /**
     * @return Mage_Core_Model_Session
     */
    protected function getCoreSession()
    {
        return Mage::getSingleton('core/session');
    }

    /**
     * @param $quoteId
     *
     * @return Mage_Sales_Model_Quote
     */
    protected function getActiveQuote($quoteId)
    {
        return Mage::getModel('sales/quote')->load($quoteId);
    }

    /**
     * @return mixed
     */
    protected function getCheckoutUrl()
    {
        return Mage::helper('checkout/url')->getCheckoutUrl();
    }
}