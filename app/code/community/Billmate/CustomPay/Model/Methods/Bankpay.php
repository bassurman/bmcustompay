<?php
class Billmate_CustomPay_Model_Methods_Bankpay extends Billmate_CustomPay_Model_Methods
{

    const ALLOWED_CURRENCY_CODES = ['SEK'];

    /**
     * @var string
     */
    protected $_code = 'bmcustom_bankpay';

    /**
     * @var string
     */
    protected $_formBlockType = 'billmatecustompay/bankpay_form';

    /**
     * @var array
     */
    protected $allowedRefundStatuses = [
        'Paid',
    ];

    /**
     * @var array
     */
    protected $allowedCaptureStatuses = [
        'Created'
    ];
    
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
        if ($this->isPushEvents()) {
            $bmConnection = $this->getBMConnection();
            $invoiceId = $payment->getMethodInstance()
                ->getInfoInstance()
                ->getAdditionalInformation('invoiceid');

            $values = array(
                'number' => $invoiceId
            );
            $paymentInfo = $bmConnection->getPaymentInfo($values);
            if ($paymentInfo['PaymentData']['status'] == 'Created') {
                $result = $bmConnection->cancelPayment($values);
                if (isset($result['code'])) {
                    Mage::throwException($result['message']);
                }
                Mage::dispatchEvent('billmate_bankpay_voided',array('payment' => $payment));

                $payment->setTransactionId($result['number']);
                $payment->setIsTransactionClosed(1);
            }
            if ($paymentInfo['PaymentData']['status'] == 'Paid') {
                $values['partcredit'] = false;
                $paymentData['PaymentData'] = $values;
                $result = $bmConnection->creditPayment($paymentData);
                if (!isset($result['code'])) {
                    $bmConnection->activatePayment(array('number' => $result['number']));

                    $payment->setTransactionId($result['number']);
                    $payment->setIsTransactionClosed(1);
                    Mage::dispatchEvent('billmate_bankpay_voided',array('payment' => $payment));
                }
            }

            return $this;
        }
	}

    /**
     * @return string
     */
    public function getCheckoutRedirectUrl()
    {
        $this->updateBmDataInSession();
        $gateway = Mage::getSingleton('billmatecustompay/gateway_bankpay');
        $result = $gateway->makePayment();

        return $result['url'];
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
            return $this->doCapture($payment, $amount);
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
        if (!$this->isPushEvents()) {
            return $this;
        }

        return $this->doRefund($payment, $amount);
    }
}