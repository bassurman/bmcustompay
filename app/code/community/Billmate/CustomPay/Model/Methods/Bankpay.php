<?php
class Billmate_CustomPay_Model_Methods_Bankpay extends Billmate_CustomPay_Model_Methods
{
    protected $_code = 'bmcustom_bankpay';
    protected $_formBlockType = 'billmatecustompay/bankpay_form';
    
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

    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }

	public function cancel( Varien_Object $payment )
	{
		$this->void($payment);
		return $this;
	}

	public function void( Varien_Object $payment )
	{
        if(Mage::getStoreConfig('billmate/settings/activation')) {
            $k = Mage::helper('billmatecustompay')->getBillmate();
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');
            $values = array(
                'number' => $invoiceId
            );
            $paymentInfo = $k->getPaymentInfo($values);
            if ($paymentInfo['PaymentData']['status'] == 'Created') {
                $result = $k->cancelPayment($values);
                if (isset($result['code'])) {
                    Mage::throwException($result['message']);
                }
                Mage::dispatchEvent('billmate_bankpay_voided',array('payment' => $payment));

                $payment->setTransactionId($result['number']);
                $payment->setIsTransactionClosed(1);
            }
            if($paymentInfo['PaymentData']['status'] == 'Paid'){
                $values['partcredit'] = false;
                $paymentData['PaymentData'] = $values;
                $result = $k->creditPayment($paymentData);
                if(!isset($result['code'])){
                    $k->activatePayment(array('number' => $result['number']));

                    $payment->setTransactionId($result['number']);
                    $payment->setIsTransactionClosed(1);
                    Mage::dispatchEvent('billmate_bankpay_voided',array('payment' => $payment));
                }
            }

            return $this;
        }
	}
    public function canUseForCurrency($currencyCode)
    {
        $currencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();

        if($currencyCode == 'SEK')
            return true;
        return false;
    }
    public function isAvailable($quote = null)
    {
        if ($quote == null ) {
            return false;
        }
        if (Mage::getSingleton('checkout/session')->getBillmateHash()) {
            return true;
        }

		if (Mage::getStoreConfig('payment/bmcustom_bankpay/active')) {
            return false;
        }

        $countries = explode(',', Mage::getStoreConfig('payment/bmcustom_bankpay/countries'));

        if (in_array($quote->getShippingAddress()->getCountry(), $countries ) ) {
			$total = $quote->getSubtotal();
			$min_total = Mage::getStoreConfig('payment/bmcustom_bankpay/min_amount');
			$max_total = Mage::getStoreConfig('payment/bmcustom_bankpay/max_amount');
            if(!empty($min_total) && $min_total > 0){

                $status = $total >= $min_total;

            } else {
                $status = true;
            }

            if ($status && (!empty($max_total) && $max_total > 0)) {
                $status = $total <= $max_total;
            } else {
                $status = $status;
            }

            return $status;
		}

		return false;
    }

    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    public function getTitle()
    {
        return (strlen(Mage::getStoreConfig('payment/bmcustom_bankpay/title')) > 0) ? Mage::helper('billmatecustompay')->__(Mage::getStoreConfig('payment/bmcustom_bankpay/title')) : Mage::helper('billmatecustompay')->__('Billmate Bank');
    }

    public function getCheckoutRedirectUrl()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setBillmateQuoteId($session->getQuoteId());
        $session->setRebuildCart(true);

        $gateway = Mage::getSingleton('billmatecustompay/gateway_bankpay');
        $result = $gateway->makePayment();

        return $result['url'];
    }

    public function capture(Varien_Object $payment, $amount)
    {
        if(Mage::getStoreConfig('billmate/settings/activation')) {
            $k = Mage::helper('billmatecustompay')->getBillmate();
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');
            $values = array(
                'number' => $invoiceId
            );

            $paymentInfo = $k->getPaymentInfo($values);
            if ($paymentInfo['PaymentData']['status'] == 'Created') {
                $boTotal = $paymentInfo['Cart']['Total']['withtax']/100;
                if($amount != $boTotal){
                    Mage::throwException($this->getHelper()->__('The amounts don\'t match. Billmate Online %s and Store %s. Activate manually in Billmate.',$boTotal,$amount));
                }
                $result = $k->activatePayment(array('PaymentData' => $values));
                if(isset($result['code']) )
                    Mage::throwException(utf8_encode($result['message']));
                if(!isset($result['code'])){
                    $payment->setTransactionId($result['number']);
                    $payment->setIsTransactionClosed(1);
                    Mage::dispatchEvent('billmate_bankpay_capture',array('payment' => $payment, 'amount' => $amount));
                }
            }

        }
        return $this;
    }

    public function refund(Varien_Object $payment, $amount)
    {
        if (Mage::getStoreConfig('billmate/settings/activation')) {
            $k = Mage::helper('billmatecustompay')->getBillmate();
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');
            $values = array(
                'number' => $invoiceId
            );
            $paymentInfo = $k->getPaymentInfo($values);
            if ($paymentInfo['PaymentData']['status'] == 'Paid') {
                $values['partcredit'] = false;
                $result = $k->creditPayment(array('PaymentData' => $values));
                if(isset($result['code']) )
                    Mage::throwException(utf8_encode($result['message']));
                if(!isset($result['code'])){
                    $payment->setTransactionId($result['number']);
                    $payment->setIsTransactionClosed(1);
                    Mage::dispatchEvent('billmate_bankpay_refund',array('payment' => $payment, 'amount' => $amount));

                }
            }
        }
        return $this;
    }
}