<?php

abstract class Billmate_CustomPay_Model_Methods extends Mage_Payment_Model_Method_Abstract
{
    /**
     * @param $quote
     *
     * @return bool
     */
    protected function isAllowedToUse($quote)
    {
        if(Mage::getSingleton('checkout/session')->getBillmateHash()) {
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
     * @return Billmate_CustomPay_Helper_Methods
     */
    protected function getHelper()
    {
        return Mage::helper('billmatecustompay/methods');
    }

    /**
     * @return bool
     */
    protected function isPushEvents()
    {
        return $this->getHelper()->isPushEvents();
    }

}