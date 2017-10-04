<?php

/**
 * Class Divante_Bus_Model_Api2_Product_Rest_Guest_V1
 */
class Divante_Bus_Model_Api2_Product_Rest_Guest_V1 extends Divante_Bus_Model_Api2_Product
{
    /**
     * Current loaded product
     *
     * @var Mage_Catalog_Model_Product
     */
    protected $_product;

    /**
     * GET /api/rest/bus/products
     * Returns JSON array
     *
     * curl -i -H 'Accept: application/json' http://localhost/api/rest/bus/products
     *
     * @return array
     */
    public function _retrieveCollection()
    {
        return [];
    }

    /**
     * GET /api/rest/bus/product/:id
     * Returns JSON array
     *
     * @return array
     */
    public function _retrieve()
    {
        return [];
    }

    /**
     * POST /api/rest/bus/products
     * Create product
     *
     * curl -i -H 'Accept: application/json' -H 'Content-type: application/json; charset=utf-8' -d '{"website_ids": [1], "attribute_set_id": 4, "type_id": "simple", "name": "Example 1", "description": "Abc...", "short_description": "Abc", "sku": "EXAMPLE1",  "weight": 1.5, "status": 1, "tax_class_id": 1, "price": 250}' http://localhost/api/rest/bus/products
     *
     * @param array $data
     * @return string
     */
    public function _create(array $data) {
        try {

            /* @var $validator Mage_Catalog_Model_Api2_Product_Validator_Product */
            $validator = Mage::getModel('catalog/api2_product_validator_product', array(
                'operation' => self::OPERATION_CREATE
            ));

            if (!$validator->isValidData($data)) {
                foreach ($validator->getErrors() as $error) {
                    $this->_error($error, Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
                }
                $this->_critical(self::RESOURCE_DATA_PRE_VALIDATION_ERROR);
            }

            $type = $data['type_id'];
            if ($type !== 'simple') {
                $this->_critical("Creation of products with type '$type' is not implemented",
                    Mage_Api2_Model_Server::HTTP_METHOD_NOT_ALLOWED);
            }
            $set = $data['attribute_set_id'];
            $sku = $data['sku'];

            /** @var $product Mage_Catalog_Model_Product */
            $product = Mage::getModel('catalog/product')
                ->setStoreId(Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID)
                ->setAttributeSetId($set)
                ->setTypeId($type)
                ->setSku($sku);

            foreach ($product->getMediaAttributes() as $mediaAttribute) {
                $mediaAttrCode = $mediaAttribute->getAttributeCode();
                $product->setData($mediaAttrCode, 'no_selection');
            }

            $this->_prepareDataForSave($product, $data);

            try {
                $product->validate();
                $product->save();
                $this->_multicall($product->getId());
            } catch (Mage_Eav_Model_Entity_Attribute_Exception $e) {
                $this->_critical(sprintf('Invalid attribute "%s": %s', $e->getAttributeCode(), $e->getMessage()),
                    Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
            } catch (Mage_Core_Exception $e) {
                $this->_critical($e->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
            } catch (Exception $e) {
                $this->_critical(self::RESOURCE_UNKNOWN_ERROR);
            }

            return $this->jsonResponse(['message' => 'Product created', 'product' => $product->toArray()], 201);
        } catch (Exception $e) {
            Mage::log($e->getMessage());

            return $this->jsonResponse(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    /**
     * PUT /api/rest/bus/product/:id
     * Returns JSON array
     *
     * @return array
     */
    public function _update($data)
    {
        /** @var $product Mage_Catalog_Model_Product */
        $product = $this->_getProduct();
        /* @var $validator Mage_Catalog_Model_Api2_Product_Validator_Product */
        $validator = Mage::getModel('catalog/api2_product_validator_product', array(
            'operation' => self::OPERATION_UPDATE,
            'product'   => $product
        ));

        if (!$validator->isValidData($data)) {
            foreach ($validator->getErrors() as $error) {
                $this->_error($error, Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
            }
            $this->_critical(self::RESOURCE_DATA_PRE_VALIDATION_ERROR);
        }
        if (isset($data['sku'])) {
            $product->setSku($data['sku']);
        }
        // attribute set and product type cannot be updated
        unset($data['attribute_set_id']);
        unset($data['type_id']);

        $this->_prepareDataForSave($product, $data);

        try {
            $product->validate();
            $product->save();
            return $this->jsonResponse(['message' => 'Product updated', 'product' => $product->toArray()], 201);
        } catch (Mage_Eav_Model_Entity_Attribute_Exception $e) {
            $this->_critical(sprintf('Invalid attribute "%s": %s', $e->getAttributeCode(), $e->getMessage()),
                Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
        } catch (Mage_Core_Exception $e) {
            $this->_critical($e->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
        } catch (Exception $e) {
            $this->_critical(self::RESOURCE_UNKNOWN_ERROR);
        }


    }

    /**
     * DELETE /api/rest/bus/product/:id
     * Returns JSON array
     *
     * @return array
     */
    protected function _delete()
    {
        $product = $this->_getProduct();
        try {
            //$product->delete();
            $product->setStatus(2); // disable
            $product->save();

            return $this->jsonResponse(['message' => 'Product removed', 'product' => $product->toArray()], 201);
        } catch (Mage_Core_Exception $e) {
            $this->_critical($e->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
        } catch (Exception $e) {
            $this->_critical(self::RESOURCE_INTERNAL_ERROR);
        }
    }

    /**
     * Load product by its SKU or ID provided in request
     *
     * @return Mage_Catalog_Model_Product
     */
    protected function _getProduct()
    {
        if (is_null($this->_product)) {
            $productId = $this->getRequest()->getParam('id');

            /** @var $productHelper Mage_Catalog_Helper_Product */
            $productHelper = Mage::helper('catalog/product');
            $product = $productHelper->getProduct($productId, $this->_getStore()->getId());
            if (!($product->getId())) {
                $this->_critical(self::RESOURCE_NOT_FOUND);
            }
            // check if product belongs to website current
            if ($this->_getStore()->getId()) {
                $isValidWebsite = in_array($this->_getStore()->getWebsiteId(), $product->getWebsiteIds());
                if (!$isValidWebsite) {
                    $this->_critical(self::RESOURCE_NOT_FOUND);
                }
            }
            // Check display settings for customers & guests
            if ($this->getApiUser()->getType() != Mage_Api2_Model_Auth_User_Admin::USER_TYPE) {
                // check if product assigned to any website and can be shown
                if ((!Mage::app()->isSingleStoreMode() && !count($product->getWebsiteIds()))
                    || !$productHelper->canShow($product)
                ) {
                    $this->_critical(self::RESOURCE_NOT_FOUND);
                }
            }
            $this->_product = $product;
        }
        return $this->_product;
    }

    /**
     * Set product
     *
     * @param Mage_Catalog_Model_Product $product
     */
    protected function _setProduct(Mage_Catalog_Model_Product $product)
    {
        $this->_product = $product;
    }

    /**
     * Determine if stock management is enabled
     *
     * @param array $stockData
     * @return bool
     */
    protected function _isManageStockEnabled($stockData)
    {
        if (!(isset($stockData['use_config_manage_stock']) && $stockData['use_config_manage_stock'])) {
            $manageStock = isset($stockData['manage_stock']) && $stockData['manage_stock'];
        } else {
            $manageStock = Mage::getStoreConfig(
                Mage_CatalogInventory_Model_Stock_Item::XML_PATH_ITEM . 'manage_stock');
        }
        return (bool) $manageStock;
    }

    /**
     * Check if value from config is used
     *
     * @param array $data
     * @param string $field
     * @return bool
     */
    protected function _isConfigValueUsed($data, $field)
    {
        return isset($data["use_config_$field"]) && $data["use_config_$field"];
    }

    /**
     * Set additional data before product save
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array $productData
     */
    protected function _prepareDataForSave($product, $productData)
    {
        if (isset($productData['stock_data'])) {
            if (!$product->isObjectNew() && !isset($productData['stock_data']['manage_stock'])) {
                $productData['stock_data']['manage_stock'] = $product->getStockItem()->getManageStock();
            }
            $this->_filterStockData($productData['stock_data']);
        } else {
            $productData['stock_data'] = array(
                'use_config_manage_stock' => 1,
                'use_config_min_sale_qty' => 1,
                'use_config_max_sale_qty' => 1,
            );
        }
        $product->setStockData($productData['stock_data']);
        // save gift options
        $this->_filterConfigValueUsed($productData, array('gift_message_available', 'gift_wrapping_available'));
        if (isset($productData['use_config_gift_message_available'])) {
            $product->setData('use_config_gift_message_available', $productData['use_config_gift_message_available']);
            if (!$productData['use_config_gift_message_available']
                && ($product->getData('gift_message_available') === null)) {
                $product->setData('gift_message_available', (int) Mage::getStoreConfig(
                    Mage_GiftMessage_Helper_Message::XPATH_CONFIG_GIFT_MESSAGE_ALLOW_ITEMS, $product->getStoreId()));
            }
        }
        if (isset($productData['use_config_gift_wrapping_available'])) {
            $product->setData('use_config_gift_wrapping_available', $productData['use_config_gift_wrapping_available']);
            if (!$productData['use_config_gift_wrapping_available']
                && ($product->getData('gift_wrapping_available') === null)
            ) {
                $xmlPathGiftWrappingAvailable = 'sales/gift_options/wrapping_allow_items';
                $product->setData('gift_wrapping_available', (int)Mage::getStoreConfig(
                    $xmlPathGiftWrappingAvailable, $product->getStoreId()));
            }
        }

        if (isset($productData['website_ids']) && is_array($productData['website_ids'])) {
            $product->setWebsiteIds($productData['website_ids']);
        }
        // Create Permanent Redirect for old URL key
        if (!$product->isObjectNew()  && isset($productData['url_key'])
            && isset($productData['url_key_create_redirect'])
        ) {
            $product->setData('save_rewrites_history', (bool)$productData['url_key_create_redirect']);
        }
        /** @var $attribute Mage_Catalog_Model_Resource_Eav_Attribute */
        foreach ($product->getTypeInstance(true)->getEditableAttributes($product) as $attribute) {
            //Unset data if object attribute has no value in current store
            if (Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID !== (int)$product->getStoreId()
                && !$product->getExistsStoreValueFlag($attribute->getAttributeCode())
                && !$attribute->isScopeGlobal()
            ) {
                $product->setData($attribute->getAttributeCode(), false);
            }

            if ($this->_isAllowedAttribute($attribute)) {
                if (isset($productData[$attribute->getAttributeCode()])) {
                    $product->setData(
                        $attribute->getAttributeCode(),
                        $productData[$attribute->getAttributeCode()]
                    );
                }
            }
        }
    }

    /**
     * Filter stock data values
     *
     * @param array $stockData
     */
    protected function _filterStockData(&$stockData)
    {
        $fieldsWithPossibleDefautlValuesInConfig = array('manage_stock', 'min_sale_qty', 'max_sale_qty', 'backorders',
            'qty_increments', 'notify_stock_qty', 'min_qty', 'enable_qty_increments');
        $this->_filterConfigValueUsed($stockData, $fieldsWithPossibleDefautlValuesInConfig);

        if ($this->_isManageStockEnabled($stockData)) {
            if (isset($stockData['qty']) && (float)$stockData['qty'] > self::MAX_DECIMAL_VALUE) {
                $stockData['qty'] = self::MAX_DECIMAL_VALUE;
            }
            if (isset($stockData['min_qty']) && (int)$stockData['min_qty'] < 0) {
                $stockData['min_qty'] = 0;
            }
            if (!isset($stockData['is_decimal_divided']) || $stockData['is_qty_decimal'] == 0) {
                $stockData['is_decimal_divided'] = 0;
            }
        } else {
            $nonManageStockFields = array('manage_stock', 'use_config_manage_stock', 'min_sale_qty',
                'use_config_min_sale_qty', 'max_sale_qty', 'use_config_max_sale_qty');
            foreach ($stockData as $field => $value) {
                if (!in_array($field, $nonManageStockFields)) {
                    unset($stockData[$field]);
                }
            }
        }
    }

    /**
     * Filter out fields if Use Config Settings option used
     *
     * @param array $data
     * @param string $fields
     */
    protected function _filterConfigValueUsed(&$data, $fields) {
        foreach($fields as $field) {
            if ($this->_isConfigValueUsed($data, $field)) {
                unset($data[$field]);
            }
        }
    }

    /**
     * Check if attribute is allowed
     *
     * @param Mage_Eav_Model_Entity_Attribute_Abstract $attribute
     * @param array $attributes
     * @return boolean
     */
    protected function _isAllowedAttribute($attribute, $attributes = null)
    {
        $isAllowed = true;
        if (is_array($attributes)
            && !(in_array($attribute->getAttributeCode(), $attributes)
                || in_array($attribute->getAttributeId(), $attributes))
        ) {
            $isAllowed = false;
        }
        return $isAllowed;
    }
}