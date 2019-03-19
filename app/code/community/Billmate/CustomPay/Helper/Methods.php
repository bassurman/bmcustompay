<?php
class Billmate_CustomPay_Helper_Methods extends Mage_Core_Helper_Abstract
{
    const METHOD_INVOICE_FEE_PATH = 'payment/bmcustom_invoice/billmate_fee';

    /**
     * @var Billmate_Connection_Helper_Data
     */
    protected $connectionHelper;

    public function __construct()
    {
        $this->connectionHelper = Mage::helper('bmconnection');
    }

    /**
     * @param $paymentCode
     *
     * @return bool
     */
    public function isActivePayment($paymentCode)
    {
        return  (bool)$this->getConfigValue('payment/' . $paymentCode . '/active') ;
    }

    /**
     * @return array
     */
    public function getMethodCountries($paymentCode)
    {
        return explode(',', $this->getConfigValue('payment/' . $paymentCode . '/countries'));
    }

    /**
     * @return float
     */
    public function getMinAmount($paymentCode)
    {
        return $this->getConfigValue('payment/' . $paymentCode . '/min_amount');
    }

    /**
     * @return float
     */
    public function getMaxAmount($paymentCode)
    {
        return $this->getConfigValue('payment/' . $paymentCode . '/max_amount');
    }

    /**
     * @return string
     */
    public function getPaymentAction($paymentCode)
    {
        return $this->getConfigValue('payment/' . $paymentCode . '/payment_action');
    }

    /**
     * @param $paymentCode
     *
     * @return string
     */
    public function getTitle($paymentCode)
    {
        return $this->getConfigValue('payment/' . $paymentCode . '/title');
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    protected function getConfigValue($path)
    {
        return Mage::getStoreConfig($path);
    }

    /**
     * @return int|mixed|string
     */
    public function getInvoiceFee()
    {
        $invoiceFee = $this->getConfigValue( self::METHOD_INVOICE_FEE_PATH);
        $invoiceFee = $this->replaceSeparator( $invoiceFee );

        $invoiceFee = ($invoiceFee) ? $invoiceFee : 0;
        return $invoiceFee;
    }

    /**
     * @param        $amount
     * @param string $thousand
     * @param string $decimal
     *
     * @return mixed|string
     */
    public function replaceSeparator($amount, $thousand = '.', $decimal = ',')
    {
        return $this->convert2Decimal($amount);
    }

    /**
     * @param $amount
     *
     * @return mixed|string
     */
    protected function convert2Decimal($amount)
    {
        if( empty( $amount)) {
            return '';
        }
        $dotPosition = strpos($amount, '.');
        $CommaPosition = strpos($amount, ',');
        if( $dotPosition > $CommaPosition ){
            return str_replace(',', '', $amount);
        }else{
            $data = explode(',', $amount);
            $data[1] = empty($data[1])?'':$data[1];
            $data[0] = empty($data[0])?'':$data[0];
            $p = str_replace( '.' ,'', $data[0]);
            return $p.'.'.$data[1];
        }
    }

    /**
     * @return bool
     */
    public function isPushEvents()
    {
        return $this->connectionHelper->isPushEvents();
    }

    /**
     * @param $base
     * @param $address
     * @param $taxClassId
     *
     * @return array
     */
    public function getInvoiceFeeArray($base, $address, $taxClassId)
    {
        //Get the correct rate to use
        $store = Mage::app()->getStore();
        $calc = Mage::getSingleton('tax/calculation');
        $rateRequest = $calc->getRateRequest(
            $address, $address, $taxClassId, $store
        );
        $taxClass = (int) Mage::getStoreConfig('payment/billmateinvoice/tax_class');;
        $rateRequest->setProductClassId($taxClass);
        $rate = $calc->getRate($rateRequest);
        //Get the vat display options for products from Magento tax settings
        $VatOptions = Mage::getStoreConfig(
            "tax/calculation/price_includes_tax", $store->getId()
        );

        if ($VatOptions == 1) {
            //Catalog prices are set to include taxes
            $value = $calc->calcTaxAmount($base, $rate, false, false);
            $excl = $base;
            return array(
                'excl' => $excl,
                'base_excl' => $this->calcBaseValue($excl),
                'incl' => $base + $value,
                'base_incl' => $this->calcBaseValue($base + $value),
                'taxamount' => $value,
                'base_taxamount' => $this->calcBaseValue($value),
                'rate' => $rate
            );
        }
        //Catalog prices are set to exclude taxes
        $value = $calc->calcTaxAmount($base, $rate, false, false);
        $incl = ($base + $value);

        return array(
            'excl' => $base,
            'base_excl' => $this->calcBaseValue($base),
            'incl' => $incl,
            'base_incl' => $this->calcBaseValue($incl),
            'taxamount' => $value,
            'base_taxamount' => $this->calcBaseValue($value),
            'rate' => $rate
        );
    }

    /**
     * Try to calculate the value of the invoice fee with the base currency
     * of the store if the purchase was done with a different currency.
     *
     * @param float $value value to calculate on
     *
     * @return float
     */
    protected function calcBaseValue($value)
    {
        $baseCurrencyCode = Mage::app()->getStore()->getBaseCurrencyCode();
        $currentCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        $value = Mage::helper('directory')->currencyConvert($value,$baseCurrencyCode,$currentCurrencyCode);
        return $value;
    }
}