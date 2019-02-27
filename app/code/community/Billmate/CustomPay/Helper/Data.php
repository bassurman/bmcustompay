<?php
class Billmate_CustomPay_Helper_Data extends Mage_Core_Helper_Abstract
{
    public function getBillmate($ssl = true, $debug = false )
    {
        return Mage::helper('billmatecommon')->getBillmate();
    }
    
}