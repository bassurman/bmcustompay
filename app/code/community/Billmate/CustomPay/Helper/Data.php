<?php
class Billmate_CustomPay_Helper_Data extends Mage_Core_Helper_Abstract
{
    const SV_PATH_CODE = 'sv';

    const DEFAULT_PATH_CODE = 'en';

    /**
     * @var array
     */
    protected $_svLocales = [
        'sv_SE'
    ];

    /**
     * @var Billmate_Connection_Helper_Data
     */
    protected $connectionHelper;

    public function __construct()
    {
        $this->connectionHelper = Mage::helper('bmconnection');
    }

    /**
     * @param bool $ssl
     * @param bool $debug
     *
     * @return BillMate
     */
    public function getBillmate()
    {
        return $this->connectionHelper->getBmProvider();
    }

    /**
     * @param $paymentCode
     *
     * @return bool
     */
    public function isActivePayment($paymentCode)
    {
        return  (bool)Mage::getStoreConfig('payment/' . $paymentCode . '/active') ;
    }

    /**
     * @param $methodCode
     *
     * @return string
     */
    public function getMethodLogo($methodCode)
    {
        $langPath = $this->getLogoLangPath();
        return Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) .
            'bmcustompay/images/' . $langPath . DIRECTORY_SEPARATOR . $methodCode . '.png';
    }

    /**
     * @return string
     */
    protected function getLogoLangPath()
    {
        $localeCode = Mage::app()->getLocale()->getLocaleCode();
        if (in_array($localeCode, $this->getSvLocales())) {
           return self::SV_PATH_CODE;
        }
        return self::DEFAULT_PATH_CODE;
    }

    /**
     * @return array
     */
    protected function getSvLocales()
    {
        return $this->_svLocales;
    }

    /**
     * @return int
     */
    public function getStoreIdForConfig()
    {
        if (strlen($code = Mage::getSingleton('adminhtml/config_data')->getStore())) {
            $store_id = Mage::getModel('core/store')->load($code)->getId();
        } elseif (strlen($code = Mage::getSingleton('adminhtml/config_data')->getWebsite())) {
            $website_id = Mage::getModel('core/website')->load($code)->getId();
            $store_id = Mage::app()->getWebsite($website_id)->getDefaultStore()->getId();
        } else {
            $store_id = 0;
        }

        return $store_id;
    }
}