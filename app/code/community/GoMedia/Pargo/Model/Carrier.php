<?php

/**
 * Copyright 2017 Stock2Shop.com
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

class GoMedia_Pargo_Model_Carrier extends Mage_Shipping_Model_Carrier_Abstract implements Mage_Shipping_Model_Carrier_Interface {

    /** @var string $_code */
    protected $_code = 'gomedia_pargo';

    /** @var string $_method */
    protected $_method = 'standard';

    public function collectRates(Mage_Shipping_Model_Rate_Request $request) {
        $result = Mage::getModel('shipping/rate_result');
        $result->append($this->_getDefaultRate());
        return $result;
    }

    public function getAllowedMethods() {
        return array(
            $this->_code => $this->getConfigData('name'),
        );
    }

    public function isTrackingAvailable() {
        return true;
    }

    public function getTrackingInfo($tracking)
    {
        $pargoHelper = Mage::helper('pargo');
        $track = Mage::getModel('shipping/tracking_result_status');
        $track->setUrl($pargoHelper->getPargoTrackUrl() . $tracking)
            ->setTracking($tracking)
            ->setCarrierTitle($this->getConfigData('title'));
        return $track;
    }

    /**
     * @return Mage_Shipping_Model_Rate_Result_Method
     */
    protected function _getDefaultRate() {
        $rate = Mage::getModel('shipping/rate_result_method');
        $rate->setCarrier($this->_code);
        $rate->setCarrierTitle($this->getConfigData('title'));
        $rate->setMethod($this->_method);
        $rate->setMethodTitle("Collect from local shop when it suits you best");
        $rate->setPrice($this->getConfigData('price'));
        $rate->setCost(0);
        return $rate;
    }
}