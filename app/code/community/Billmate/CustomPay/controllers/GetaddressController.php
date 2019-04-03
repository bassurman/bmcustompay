<?php

class Billmate_CustomPay_GetaddressController extends Billmate_CustomPay_Controller_Methods
{

    public function indexAction()
    {
        $pno = $this->getRequest()->getParam('billmate_pno');
        Mage::getSingleton('checkout/session')->setBillmatePno($pno);

        $billmateConnection = $this->getBmConnection();
        $bmResponse = $billmateConnection->getAddress(
            ['pno' => $pno]
        );

        $status = (!isset($bmResponse['code'])) ? true : false;
        $result['success'] = $status;
        $result['message'] = (isset($bmResponse['code'])) ? utf8_encode($bmResponse['message']) : '';
        $result['data'] = $bmResponse;

        $this->getResponse()->setBody(Zend_Json::encode($result));
    }
}