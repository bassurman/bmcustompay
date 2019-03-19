<?php

class Billmate_CustomPay_Model_Methods_Invoice extends Billmate_CustomPay_Model_Methods
{
    protected $_code = 'bmcustom_invoice';

    protected $_formBlockType = 'billmatecustompay/invoice_form';
    
    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid                 = true;
    protected $_canUseInternal          = false;
    protected $_canUseCheckout          = true;

    public function isAvailable($quote = null)
    {
        return $this->isAllowedToUse($quote);
    }

	public function cancel( Varien_Object $payment )
	{
		$this->void($payment);
		return $this;
	}

	public function void( Varien_Object $payment )
	{
        if($this->isPushEvents()) {
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
                $payment->setTransactionId($result['number']);
                $payment->setIsTransactionClosed(1);
            }
            if ($paymentInfo['PaymentData']['status'] == 'Paid') {
                $values['partcredit'] = false;
                $paymentData['PaymentData'] = $values;
                $result = $k->creditPayment($paymentData);
                if (!isset($result['code'])) {
                    $k->activatePayment(array('number' => $result['number']));

                    $payment->setTransactionId($result['number']);
                    $payment->setIsTransactionClosed(1);
                    Mage::dispatchEvent('billmate_invoice_voided',array('payment' => $payment));

                }
            }

            return $this;
        }
	}

    public function authorize(Varien_Object $payment, $amount)
    {
        if(
            $hash = Mage::getSingleton('checkout/session')->getBillmateHash()
                && Mage::registry('billmate_checkout_complete')
        ) {

            $result = Mage::helper('billmatecustompay')->getBillmate()->getCheckout(array('PaymentData' => array('hash' => $hash)));
            $payment->setTransactionId($result['PaymentData']['order']['number']);
            $payment->setIsTransactionClosed(0);
        } else {
            $gateway = Mage::getSingleton('billmatecustompay/gateway_invoice');
            $invoiceId = $gateway->makePayment();
            $payment->setTransactionId($invoiceId);

            $payment->setIsTransactionClosed(0);
        }
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        $invoiceFee = $this->getHelper()->getInvoiceFee();
        $methodTitle = $this->getHelper()->getTitle($this->getCode());
        if ($invoiceFee > 0) {
            $quote = Mage::getModel('checkout/cart')->getQuote();
            $shipping = $quote->getShippingAddress();

            $feeinfo = $this->getHelper()->getInvoiceFeeArray($invoiceFee, $shipping, $quote->getCustomerTaxClassId());

            $invFee = (isset($feeinfo['rate']) && $feeinfo['rate'] != 0 && Mage::getStoreConfig('payment/bmcustom_invoice/include_tax')) ? ($feeinfo['rate'] / 100 + 1) * $invoiceFee : $invoiceFee;


            $invFee = Mage::helper('core')->currency($invFee, true, false);
            return $this->getHelper()->__($methodTitle, $invFee);
        }
        return $this->getHelper()->__($methodTitle);
    }

    /**
     * @param string $currencyCode
     *
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        $currencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        if(in_array($currencyCode,array('SEK','USD','EUR','GBP'))) {
            return true;
        }

        return false;
    }

    /**
     * @param Varien_Object $payment
     * @param float         $amount
     *
     * @return $this
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if ($this->isPushEvents()) {
            $k = Mage::helper('billmatecustompay')->getBillmate();
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');

            $values = array(
                'number' => $invoiceId
            );

            $paymentInfo = $k->getPaymentInfo($values);
            if ($paymentInfo['PaymentData']['status'] == 'Created') {
                $boTotal = $paymentInfo['Cart']['Total']['withtax']/100;
                if($amount != $boTotal){
                    Mage::throwException(Mage::helper('billmatecustompay')->__('The amounts don\'t match. Billmate Online %s and Store %s. Activate manually in Billmate.',$boTotal,$amount));
                }
                $result = $k->activatePayment(array('PaymentData' => $values));
                if(isset($result['code']) )
                    Mage::throwException(utf8_encode($result['message']));
                if(!isset($result['code'])){
                    $payment->setTransactionId($result['number']);
                    $payment->setIsTransactionClosed(1);
                    Mage::dispatchEvent('billmate_invoice_capture',array('payment' => $payment, 'amount' => $amount));

                }

            }
        }
        return $this;
    }

    public function refund(Varien_Object $payment, $amount)
    {
        if ($this->isPushEvents()) {
            $k = Mage::helper('billmatecustompay')->getBillmate();
            $invoiceId = $payment->getMethodInstance()->getInfoInstance()->getAdditionalInformation('invoiceid');

            $values = array(
                'number' => $invoiceId
            );
            $paymentInfo = $k->getPaymentInfo($values);
            if ($paymentInfo['PaymentData']['status'] == 'Paid' || $paymentInfo['PaymentData']['status'] == 'Factoring') {
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

    public function validate()
    {
        parent::validate();
        $paymentData = $this->getInfoInstance()->getData();
        if (!isset($paymentData[Billmate_CustomPay_Block_Invoice_Form::PNO_INPUT_CODE])) {
            return $this;
        }

        if (Mage::getStoreConfig('firecheckout/general/enabled') || Mage::getStoreConfig('streamcheckout/general/enabled')) {
            if (empty($paymentData['person_number']) && empty($paymentData[Billmate_CustomPay_Block_Invoice_Form::PNO_INPUT_CODE])) {
                Mage::throwException(Mage::helper('payment')->__('Missing Personal number'));
            }
        } else {
            if (empty($paymentData[Billmate_CustomPay_Block_Invoice_Form::PNO_INPUT_CODE])) {
                Mage::throwException(Mage::helper('payment')->__('Missing Personal number'));
            }
        }

        if (empty($paymentData[Billmate_CustomPay_Block_Invoice_Form::PHONE_INPUT_CODE])) {
            Mage::throwException(Mage::helper('payment')->__('Missing phone number'));
        }

    }
}
