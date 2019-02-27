<?php

class Billmate_CustomPay_Model_Adminhtml_Comment
{
    public function getCommentText()
    {
        $lang = explode('_',Mage::app()->getLocale()->getLocaleCode());
        $langCode = ($lang[0] == 'sv') ? $lang[0] : 'en';
        return '<img src="'.Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA)."billmate/images/".$langCode."/cardpay.png".'"/>';
    }
}