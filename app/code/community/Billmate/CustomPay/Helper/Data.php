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
     * @param bool $ssl
     * @param bool $debug
     *
     * @return BillMate
     */
    public function getBillmate()
    {
        return Mage::helper('bmconnection')->getBmProvider();
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
            '/bmcustompay/images/' . $langPath. DIRECTORY_SEPARATOR . $methodCode . '.png';
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


}