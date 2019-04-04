<?php

abstract class Billmate_CustomPay_Controller_InstantMethods extends Billmate_CustomPay_Controller_Methods
{
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