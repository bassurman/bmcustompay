<?php
class Billmate_CustomPay_Model_Methods_Card extends Billmate_CustomPay_Model_Methods
{
    const ALLOWED_PAYMENT_ACTION = 'authorize';

    protected $_code = 'bmcustom_card';

    protected $_formBlockType = 'billmatecustompay/card_form';
    
    protected $_isGateway               = false;
    protected $_canAuthorize            = true;
    protected $_isInitializeNeeded      = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid                 = true;
    protected $_canUseInternal          = false;
    protected $_canUseCheckout          = true;

    /**
     * @param string $paymentAction
     * @param object $stateObject
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }

    /**
     * @param Varien_Object $payment
     *
     * @return $this
     */
    public function void( Varien_Object $payment )
    {
        if (Mage::getStoreConfig('billmate/settings/activation')) {
            $bmConnection = $this->getBMConnection();
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');
            $values = array(
                'number' => $invoiceId
            );
            $paymentInfo = $bmConnection->getPaymentInfo($values);
            if ($paymentInfo['PaymentData']['status'] == 'Created') {
                $result = $bmConnection->cancelPayment($values);
                if (isset($result['code'])) {
                    Mage::throwException($result['message']);
                }
                $payment->setTransactionId($result['number']);
                $payment->setIsTransactionClosed(1);
                Mage::dispatchEvent('billmate_cardpay_voided',array('payment' => $payment));

            }
            if($paymentInfo['PaymentData']['status'] == 'Paid'){
                $values['partcredit'] = false;
                $paymentData['PaymentData'] = $values;
                $result = $bmConnection->creditPayment($paymentData);
                if(!isset($result['code'])){
                    $bmConnection->activatePayment(array('number' => $result['number']));

                    $payment->setTransactionId($result['number']);
                    $payment->setIsTransactionClosed(1);
                    Mage::dispatchEvent('billmate_cardpay_voided',array('payment' => $payment));
                }
            }

            return $this;
        }
    }

    /**
     * @param Varien_Object $payment
     * @param float         $amount
     *
     * @return $this
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if (!$this->isAllowedToCapture()) {
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');
            $payment->setTransactionId($invoiceId);
            $payment->setIsTransactionClosed(1);
            return $this;
        }

        $bmConnection = $this->getBMConnection();
        $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');
        $values = array(
            'number' => $invoiceId
        );

        $paymentInfo = $bmConnection->getPaymentInfo($values);
        if (is_array($paymentInfo) && $paymentInfo['PaymentData']['status'] == 'Created') {
            $boTotal = $paymentInfo['Cart']['Total']['withtax']/100;
            if ($amount != $boTotal) {
                
                Mage::throwException($this->getHelper()
                ->__(
                        'The amounts don\'t match. Billmate Online %s and Store %s. Activate manually in Billmate.',
                        $boTotal,
                        $amount
                ));
            }
            $result = $bmConnection->activatePayment(array('PaymentData' => $values));
            if(isset($result['code']) )
                Mage::throwException(utf8_encode($result['message']));
            if (!isset($result['code'])) {
                $payment->setTransactionId($result['number']);
                $payment->setIsTransactionClosed(1);
                Mage::dispatchEvent('billmate_cardpay_capture',array('payment' => $payment, 'amount' => $amount));

            }

        }

        return $this;
    }

    /**
     * @param Varien_Object $payment
     * @param float         $amount
     *
     * @return $this
     */
    public function refund(Varien_Object $payment, $amount)
    {
        if ($this->isPushEvents()) {
            $bmConnection = $this->getBMConnection();
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');
            $values = array(
                'number' => $invoiceId
            );
            $paymentInfo = $bmConnection->getPaymentInfo($values);
            if ($paymentInfo['PaymentData']['status'] == 'Paid') {
                $values['partcredit'] = false;
                $result = $bmConnection->creditPayment(array('PaymentData' => $values));

                if(isset($result['code']) ) {
                    Mage::throwException(utf8_encode($result['message']));
                }
                if (!isset($result['code'])) {
                    $payment->setTransactionId($result['number']);
                    $payment->setIsTransactionClosed(1);
                    Mage::dispatchEvent('billmate_cardpay_refund',array('payment' => $payment, 'amount' => $amount));
                }
            }
        } else {
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');
            $payment->setTransactionId($invoiceId);
            $payment->setIsTransactionClosed(1);
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getCheckoutRedirectUrl()
    {
        $session = $this->getCheckoutSession();
        $session->setBillmateQuoteId($session->getQuoteId());
        $session->setRebuildCart(true);

        $gateway = Mage::getSingleton('billmatecustompay/gateway_card');
        $result = $gateway->makePayment();
        
        return $result['url'];
    }

    protected function isAllowedToCapture()
    {
        return $this->isPushEvents() &&
            $this->getHelper()->getPaymentAction($this->getCode()) == self::ALLOWED_PAYMENT_ACTION;
    }
}
