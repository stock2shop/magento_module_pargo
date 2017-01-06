<?php
/**
 * Helper class for Pargo module
 *
 * Copyright 2017 Stock2Shop.com
 *
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
class GoMedia_Pargo_Helper_Data extends Mage_Core_Helper_Abstract
{
    private $_carrierCode = 'gomedia_pargo';
    private $_carrierMethod = 'standard';
    private $_pargoPointUrl = 'https://pargopickuppoints.appspot.com/';
    private $_pargoTrackUrl = 'https://pargo.co.za/track-trace/?track-trace=';

    /**
     * Create a customer session to store Pargo address
     *
     * @param $data
     */
    public function setShipping($data) {

        /** @var $currentAddress Mage_Customer_Model_Session */
        Mage::getSingleton("customer/session")->setPargoShippingAddress($data);
    }

    /**
     * returns session with pargo shipping address
     * @param array
     */
    public function getShipping() {
        return Mage::getSingleton("customer/session")->getPargoShippingAddress();
    }

    /**
     * returns pargo shipping method/code
     */
    public function getShippingCode() {
        return $this->_carrierCode;
    }

    /**
     * returns pargo shipping method/code
     */
    public function getShippingMethod() {
        return $this->_carrierMethod;
    }

    /**
     * returns pargo point url
     */
    public function getPargoPointUrl() {
        return $this->_pargoPointUrl;
    }

    /**
     * returns pargo point url
     */
    public function getPargoTrackUrl() {
        return $this->_pargoTrackUrl;
    }

    /**
     * @param $type error, success
     * @param $message
     * @return string
     */
    public function showMessage($type, $message) {
        $html = '<ul class="messages"><li class="'.$type.'-msg"><ul><li><span>'.$message.'</span></li></ul></li></ul>';
        return $html;
    }

    /**
     * @param string $resource
     * @return string
     */
    public function getAPIUrl($resource = 'orders') {
        $config = Mage::getStoreConfig('carriers/gomedia_pargo');
        $pargoApiUrl = $config['api_url'];
        $pargoApiId = $config['api_id'];
        $pargoApiToken = $config['api_token'];
        return $pargoApiUrl . $resource . '?api_id=' . $pargoApiId . '&token=' . $pargoApiToken;
    }


    /**
     * Curl wrapper
     *
     * @param $resource
     * @param $method
     * @param $postData
     * @return mixed
     * @throws Exception
     */
    public function proxy($resource, $method, $postData) {
        $payload = json_encode($postData);
        $url = $this->getAPIUrl($resource);
        $ch = \curl_init();
        $headers = array(
            'Content-Type:application/json',
            'Accept:application/json'
        );
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if($method == "POST" || "PUT") {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            array_push($headers, 'Content-Length: ' . strlen($payload));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $arr = explode("\r\n\r\n", $response, 2);
        if (count($arr) == 2) {
            $data = $arr[1];
        } else {

            // this would be thrown if es was down, for example
            throw new \Exception("Invalid Response from Pargo, status was: $http_status response was: $response");
        }
        $results = json_decode($data);

        if(!isset($results->data)) {
            // handle error
        }

        // no valid json, throw exception with body
        if (empty($results)) {
            throw new \Exception("Invalid Response from Pargo: $data");
        }
        return $results;
    }
}