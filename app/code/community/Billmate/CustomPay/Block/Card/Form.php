<?php
class Billmate_CustomPay_Block_Card_Form extends Mage_Payment_Block_Form
{
    public function _construct()
    {
        parent::_construct();
        $this->setTemplate('billmatecustompay/method/card.phtml');
    }

    public function getMethodLogo()
    {
        return Mage::helper('billmatecustompay')->getMethodLogo($this->getMethodCode());
    }
}