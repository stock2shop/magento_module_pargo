<?php
/* @var $installer Mage_Customer_Model_Entity_Setup */
$installer = $this;
$installer->startSetup();

/**
 * Adding Extra Column to sales_flat_quote_address
 * to store the Pargo Pickup Point ID
 */
$sales_quote_address = $installer->getTable('sales/quote_address');
$installer->getConnection()
    ->addColumn($sales_quote_address, 'pup_id', array(
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT,
        'comment' => 'New Pargo Pickup Point ID Added'
    ));

/**
 * Adding Extra Column to sales_flat_order_address
 * to store the Pargo Pickup Point ID
 */
$sales_order_address = $installer->getTable('sales/order_address');
$installer->getConnection()
    ->addColumn($sales_order_address, 'pup_id', array(
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT,
        'comment' => 'New Pargo Pickup Point ID Added'
    ));