<?php

/**
 * Fired each time an order is updated.
 *
 * Checks the Pargo address (stored as session)
 * If the Pargo address is not saved to the Magento order, save it
 *
 * If the order status has changed and no shipment is created,
 * Create a shipment (means adding order on the pargo system)
 *
 * All responses are logged to the comments section
 *
 * @author Emmanuel Minnaar (www.stock2shop.com)
 * @copyright  Copyright (c) 2016 Stock2Shop
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 */
class GoMedia_Pargo_Model_Observer {

    /**
     * Used to ensure the event is not fired multiple times
     * http://magento.stackexchange.com/questions/7730/sales-order-save-commit-after-event-triggered-twice
     *
     * It will only process the below code once per status / address change
     *
     * @var bool
     */
    private $_orderStatus = false;

    /** @var string  */
    private $_trackingTitle = 'Pargo';

    /** @var $order Mage_Sales_Model_Order */
    private $order;

    /** @var int  */
    private $_attempts = 0;

    function __construct() {
        $config = Mage::getStoreConfig('carriers/gomedia_pargo');
        $this->_orderStatus = $config['order_status'];
    }

    /**
     * Event is called when user is updating their shipping address
     * At this point we swap this with the pargo address
     *
     * event: checkout_controller_onepage_save_shipping_method
     * @param Varien_Event_Observer $observer
     * @return GoMedia_Pargo_Model_Observer
     */
    public function switchAddress($observer) {
        if(!Mage::getStoreConfigFlag('carriers/gomedia_pargo/active')) {
            return $this;
        }
        $pargoHelper = Mage::helper('pargo');

        // fetch session address
        $pargoAddress = $pargoHelper->getShipping();

        if(isset($pargoAddress['pargo']) && isset($pargoAddress['pargo']['pargoPointCode'])) {

            /** @var Mage_Sales_Model_Quote $quote */
            $quote = $observer->getEvent()->getQuote();

            /** @var Mage_Sales_Model_Quote_Address $address */
            $address = $quote->getShippingAddress();
            $address
                ->setLastname($address->getLastname() . " C/O " . $pargoAddress['pargo']['storeName'] . " pargoPointCode: " . $pargoAddress['pargo']['pargoPointCode'])
                ->setCompany($pargoAddress['pargo']['storeName'])
                ->setStreet($pargoAddress['pargo']['address1'] . ", " . $pargoAddress['pargo']['address2'])
                ->setCity($pargoAddress['pargo']['city'])
                ->setRegion($pargoAddress['pargo']['province'])
                ->setCountry_id("ZA")
                ->setPostcode($pargoAddress['pargo']['postalcode'])
//                ->setTelephone($pargoAddress['pargo']['phoneNumber'])
                ->setPup_id($pargoAddress['pargo']['pargoPointCode'])
                ->save();
        }
    }

    /**
     * event: sales_order_place_after
     * @param Varien_Event_Observer $observer
     * @return GoMedia_Pargo_Model_Observer
     */
    public function processOrder($observer) {
        if(!Mage::getStoreConfigFlag('carriers/gomedia_pargo/active')) {
            return $this;
        }

        // TODO if the status changes in one reuquest (like the pay u bug) it will skip the update
        $this->_attempts++;
        if($this->_attempts > 1) {
            return $this;
        }
        $pargoHelper = Mage::helper('pargo');

        // Fetch order and set status
        $this->order = $observer->getEvent()->getOrder();
        $orderStatus = $this->order->getStatus();

        // Do not continue if this is not a pargo order
        $carrierMethod = $pargoHelper->getShippingCode() . "_" . $pargoHelper->getShippingMethod();
        if($this->order->shipping_method != $carrierMethod) {
            return $this;
        }

        // Must be a PUP already added to shipping address.
        $shippingAddress = $this->order->getShippingAddress();
        $pup_id = $shippingAddress->getPupId();


        // Send order to Pargo
        // ------------------------------------------------------
        // make sure this has not already run and status has changed
        // each time the order is saved this observer is called.
        // doing this avoids endless loop since we save the order here to write comments

        // if this is set to automatically send order on certain status, then run.
        // if it is not, then only run if the shipment is created and there is no waybill for it.
        $sendOnStatus = Mage::getStoreConfigFlag('carriers/gomedia_pargo/send_on_status');

        // we can only send to pargo if we have a pup
        if($pup_id) {

            // Is there a waybill for the shipment?
            // We consider shipment complete once we have a waybill
            $shipmentID = $this->getShipmentID();
            $waybill = $this->getTrack($shipmentID);

            // Is this automatic or manual
            if(!$waybill) {
                if($sendOnStatus) {

                    // automatic send
                    if($this->_orderStatus == $orderStatus) {
                        $shipmentID = $this->touchShipment();
                        $this->doPargo($pup_id, $shipmentID);
                    }

                    // manual send
                } else {
                    if($shipmentID) {
                        $this->doPargo($pup_id, $shipmentID);
                    }
                }
            }
        }
        return $this;
    }

    private function doPargo($pupId, $shipmentID) {
        $waybill = false;

        // send data
        $data = $this->transform($pupId);
        try {
            $response = $this->pargoSend($data);
        } catch(Exception $e) {
            $response = $e->getMessage();
        }

        // save comment
        if(!isset($response->data) || !isset($response->data->waybillNumber)) {
            $message = "Error! " . json_encode($response);
        } else {
            $waybill = $response->data->waybillNumber;
            $pargoHelper = Mage::helper('pargo');
//            $waybillUrl = $pargoHelper->getPargoWaybillUrl() . $waybill;
            $message = "Success! created waybill <a href='" . $response->data->LabelReferenceBarcode . "' target='_blank'>" . $response->data->waybillNumber . "</a>";
            $this->createTrack($shipmentID, $waybill);
        }
        $message = substr($message, 0 , 25000);
        $this->setMessage($message);
        return $waybill;
    }

    private function transform($pup_id) {
        $warehouse = Mage::getStoreConfig('carriers/gomedia_pargo/warehouse_code');

        /** @var Mage_Sales_Model_Order_Address $address */
        $address  = $this->order->getShippingAddress();
        return array(
            "warehouse" => array (
                "warehouseCode" => $warehouse
            ),

            "consignee" => array (
                "firstName" => $this->order->getCustomerFirstname(),
                "lastName" => $this->order->getCustomerLastname(),
                "phoneNumber" => $address->getTelephone(),
                "mobileNumber" => $address->getTelephone(),
                "email" => $address->getEmail(),
                "address1" => $address->getStreet1(),
                "address2" => $address->getStreet2(),
                "suburb" => $address->getRegion(),
                "postalCode" => $address->getPostcode(),
                "city" => $address->getCity(),
                "language" => 'EN'
            ),

            "communication" => array (
                "informBySMS" => 1
            ),

            "delivery" => array (
                "pargoPointCode" => $pup_id
            ),

            "orderdata" => array (
                "returnWayBillNumber" => "",
                "productName" => ""
            ),

            "transportdata" => array (
                "insurance" => "0",
                "dimensions" => "0",
                "weight" => "0",
                "financialValue" => "0",
                "shippersReference" => $this->order->getIncrementId()
            )
        );
    }

    private function getShipmentID() {

        /* @var $existingShipment Mage_Sales_Model_Order_Shipment */
        $existingShipment = $this->order->getShipmentsCollection()->getFirstItem();
        return $existingShipment->getId();
    }

    private function touchShipment() {

        /* @var $existingShipment Mage_Sales_Model_Order_Shipment */
        $existingShipment = $this->order->getShipmentsCollection()->getFirstItem();
        $existingShipmentId = $existingShipment->getId();
        if(!$existingShipmentId) {
            $magentoShipment = array();
            foreach ($this->order->getAllItems() as $item) {
                $shipmentQTY = $item->getQtyOrdered()
                    - $item->getQtyShipped()
                    - $item->getQtyRefunded()
                    - $item->getQtyCanceled();
                $magentoShipment[$item->getId()] = $shipmentQTY;
            }

            /* @var $shipment Mage_Sales_Model_Order_Shipment */
            $shipment = $this->order->prepareShipment($magentoShipment);
            if ($shipment) {
                $shipment->register();
                $shipment->addComment("Automatically created shipment");
                $shipment->getOrder()->setIsInProcess(true);
                try {
                    Mage::getModel('core/resource_transaction')
                        ->addObject($shipment)
                        ->addObject($shipment->getOrder())
                        ->save();
//                    $this->setMessage("Shipment successfully created");

//                    // TODO, add in notification to customer
//                    if (Mage::getStoreConfigFlag('carriers/gomedia_pargo/email_customer')) {
//                        $shipment->sendEmail();
//                    }
                } catch (Mage_Core_Exception $e) {
                    $this->setMessage("Shipment could not be added " . $e->getMessage());
                }
            }
            return $shipment->getId();
        } else {
            return $existingShipmentId;
        }
    }

    private function getTrack($shipmentID) {

        /* @var $existingShipment Mage_Sales_Model_Order_Shipment */
        $existingShipment = $this->order->getShipmentsCollection()->getFirstItem();
        $tracks = $existingShipment->getTracksCollection();

        /* @var $track Mage_Sales_Model_Order_Shipment_Track */
        foreach($tracks as $track) {
            if($track->getTitle() == $this->_trackingTitle) {
                return $track->getNumber();
//                return $track->getDescription();
            }
        }
        return false;
    }

    private function createTrack($shipmentID, $waybill) {
        $pargoHelper = Mage::helper('pargo');

        /** @var $shipment Mage_Sales_Model_Order_Shipment */
        $shipment = Mage::getModel('sales/order_shipment')->load($shipmentID);
        $trackingDetail = array(
            'carrier_code' => $pargoHelper->getShippingCode(),
            'title' => $this->_trackingTitle,
            'number' => $waybill
        );

        /** @var $track Mage_Sales_Model_Order_Shipment_Track */
        $track = Mage::getModel('sales/order_shipment_track')
            ->addData($trackingDetail);
        $shipment->addTrack($track);
        Mage::getModel('core/resource_transaction')
            ->addObject($shipment)
            ->addObject($shipment->getOrder())
            ->save();
    }

    private function pargoSend($data) {
        $pargoHelper = Mage::helper('pargo');
        $response = $pargoHelper->proxy("orders", "POST", $data);
        return $response;

    }

    private function setMessage($message) {
        if($message != "") {
            $message = substr($message, 0 , 25000);
            $this->order->addStatusHistoryComment("Pargo: $message")
                ->setIsVisibleOnFront(false)
                ->setIsCustomerNotified(false);
            $this->order->save();
        }
    }

}