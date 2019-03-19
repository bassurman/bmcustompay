<?php

/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Mage
 * @package    Phoenix_BankPayment
 * @copyright  Copyright (c) 2008 Andrej Sinicyn
 * @copyright  Copyright (c) 2010 Phoenix Medien GmbH & Co. KG (http://www.phoenix-medien.de)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Billmate_CustomPay_Block_Invoice_Complete extends Mage_Payment_Block_Form
{
    /**
     * @return Billmate_CustomPay_Model_Gateway_Invoice
     */
    public function getGatewayPayment()
    {
        return Mage::getSingleton('billmatecustompay/gateway_invoice');
    }

    public function getAddress()
    {

    }
}
