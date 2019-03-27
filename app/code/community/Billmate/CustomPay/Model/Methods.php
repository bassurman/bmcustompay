<?php

abstract class Billmate_CustomPay_Model_Methods extends Mage_Payment_Model_Method_Abstract
{
    const ALLOWED_CURRENCY_CODES = [];

    /**
     * @param null $quote
     *
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        return $this->isAllowedToUse($quote);
    }

    /**
     * @return Billmate_CustomPay_Helper_Methods
     */
    protected function getHelper()
    {
        return Mage::helper('billmatecustompay/methods');
    }

    /**
     * @return Billmate_CustomPay_Helper_Data
     */
    protected function getDataHelper()
    {
        return Mage::helper('billmatecustompay');
    }

    /**
     * @return BillMate
     */
    protected function getBMConnection()
    {
        return $this->getDataHelper()->getBillmate();
    }

    /**
     * @return bool
     */
    protected function isPushEvents()
    {
        return $this->getHelper()->isPushEvents();
    }

    /**
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * @param Varien_Object $payment
     *
     * @return $this
     */
    public function cancel( Varien_Object $payment )
    {
        $this->void($payment);
        return $this;
    }

    /**
     * @param $quote
     *
     * @return bool
     */
    protected function isAllowedToUse($quote)
    {
        if($this->getCheckoutSession()->getBillmateHash()) {
            return true;
        }

        if (is_null($quote) || !$this->getHelper()->isActivePayment($this->getCode())) {
            return false;
        }

        $isAllowed = false;
        $countries = $this->getHelper()->getMethodCountries($this->getCode());
        if (in_array($quote->getShippingAddress()->getCountry(), $countries ) ) {
            $isAllowed = $this->isAllowedByTotal($quote->getSubtotal());
        }

        return $isAllowed;
    }

    /**
     * @param $total
     *
     * @return bool
     */
    protected function isAllowedByTotal($total)
    {
        $status = true;
        $min_total = $this->getHelper()->getMinAmount($this->getCode());
        $max_total = $this->getHelper()->getMaxAmount($this->getCode());

        if (!(($total > $min_total) && ($total < $max_total))
            && (!empty($min_total) && !empty($max_total))
        ) {
            $status = false;
        }

        return $status;
    }

    /**
     * @param string $currencyCode
     *
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        $allowedCurrencies = $this->getAllowedCurrencies();
        if(!$allowedCurrencies) {
            return parent::canUseForCurrency($currencyCode);
        }

        $currencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        return in_array($currencyCode, $allowedCurrencies);
    }

    /**
     * @return array
     */
    protected function getAllowedCurrencies()
    {
        return static::ALLOWED_CURRENCY_CODES;
    }
}