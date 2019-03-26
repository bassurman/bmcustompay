<?php

abstract class Billmate_CustomPay_Controller_Methods extends Mage_Core_Controller_Front_Action
{
    /**
     * @var Mage_Core_Helper_Abstract
     */
    protected $helper;

    /**
     * Billmate_CustomPay_Controller_Methods constructor.
     *
     * @param Zend_Controller_Request_Abstract  $request
     * @param Zend_Controller_Response_Abstract $response
     * @param array                             $invokeArgs
     */
    public function __construct(
        Zend_Controller_Request_Abstract $request,
        Zend_Controller_Response_Abstract $response,
        array $invokeArgs = array()
    ) {
        $this->helper = Mage::helper('billmatecustompay');
        parent::__construct($request, $response, $invokeArgs);
    }

    /**
     * @return mixed
     * @throws Exception
     */
    protected function getBmRequestData()
    {
        $bmRequestData = $this->getRequest()->getParam('data');
        $bmRequestCredentials = $this->getRequest()->getParam('credentials');

        if ($bmRequestData && $bmRequestCredentials) {
            $postData['data'] = json_decode($bmRequestData, true);
            $postData['credentials'] = json_decode($bmRequestCredentials, true);
            return $postData;
        }

        $jsonBodyRequest = file_get_contents('php://input');
        if ($jsonBodyRequest) {
            return json_decode($jsonBodyRequest, true);
        }
        throw new Exception('The request does not contain information');
    }

    /**
     * @return Mage_Core_Helper_Abstract
     */
    protected function getHelper()
    {
        return $this->helper;
    }

    /**
     * @return BillMate
     */
    public function getBmConnection()
    {
        return $this->getHelper()->getBillmate();
    }
}