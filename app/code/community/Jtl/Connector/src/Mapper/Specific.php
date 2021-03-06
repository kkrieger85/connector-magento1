<?php

namespace jtl\Connector\Magento\Mapper;

use jtl\Connector\Core\Logger\Logger;
use jtl\Connector\Magento\Magento;
use jtl\Connector\Magento\Utilities\ArrayTools;
use jtl\Connector\Model\Identity;
use jtl\Connector\Model\Specific as ConnectorSpecific;
use jtl\Connector\Model\SpecificI18n as ConnectorSpecificI18n;

/**
 * Description of Specific
 */
class Specific
{
    private $stores;
    private $defaultLocale;
    private $defaultStoreId;

    private $websites;

    public function __construct()
    {
        Magento::getInstance();

        $this->stores = Magento::getInstance()->getStoreMapping();
        $this->defaultLocale = key($this->stores);
        $this->defaultStoreId = current($this->stores);

        Logger::write('default locale: ' . $this->defaultLocale, Logger::DEBUG);
        Logger::write('default Store ID: ' . $this->defaultStoreId, Logger::DEBUG);
    }

    private function findAttributeBySpecific(ConnectorSpecific $specific)
    {
        $defaultLanguageIso = LocaleMapper::localeToLanguageIso($this->defaultLocale);
        $specificI18n = ArrayTools::filterOneByLanguageOrFirst($specific->getI18ns(), $defaultLanguageIso);

        $attributes = \Mage::getModel('eav/entity_attribute')
            ->getCollection()
            ->addFieldToFilter('frontend_label', $specificI18n->getName());

        if ($attributes->count() > 0)
            return $attributes->getFirstItem();

        return NULL;
    }

    private function getDefaultSpecificName(ConnectorSpecific $specific)
    {
        $defaultLanguageIso = LocaleMapper::localeToLanguageIso($this->defaultLocale);

        $specificI18n = ArrayTools::filterOneByLanguage($specific->getI18ns(), $defaultLanguageIso);
        if ($specificI18n === null)
            $specificI18n = reset($specific->getI18ns());

        return $specificI18n->getName();
    }

    private static function getAttributeCodeForSpecificName($specificName)
    {
        $attributeCode = strtolower(str_replace(' ', '_', $specificName));
        $attributeCode = str_replace(
            array('ä', 'ö', 'ü', 'ß'),
            array('ae', 'oe', 'ue', 'ss'),
            $attributeCode
        );

        return substr($attributeCode, 0, 30);
    }

    public function insert(ConnectorSpecific $specific)
    {
        $defaultLanguageIso = LocaleMapper::localeToLanguageIso($this->defaultLocale);

        $result = new ConnectorSpecific();

        $defaultSpecificName = $this->getDefaultSpecificName($specific);
        $attributeCode = $this->getAttributeCodeForSpecificName($defaultSpecificName);

        // Collect all frontend labels
        $frontendLabels = array(
            \Mage_Core_Model_App::ADMIN_STORE_ID => $defaultSpecificName
        );
        foreach ($this->stores as $locale => $storeId) {
            $specificI18n = ArrayTools::filterOneByLanguage($specific->getI18ns(), LocaleMapper::localeToLanguageIso($locale));
            if (!($specificI18n instanceof ConnectorSpecificI18n))
                continue;

            $frontendLabels[$storeId] = $specificI18n->getName();
        }

        if ($specific->getType() === 'SELECTBOX') {
            $frontendType = 'multiselect';
        }
        else {
            $frontendType = 'textarea';
        }

        Logger::write('Creating specific: ' . $attributeCode, Logger::DEBUG);
        $attributeData = array(
            'attribute_code' => $attributeCode,
            'is_global' => 1,
            'is_visible' => 1,
            'is_searchable' => 0,
            'is_filterable' => 1,
            'is_comparable' => 1,
            'is_visible_on_front' => 1,
            'is_html_allowed_on_front' => 0,
            'is_used_for_price_rules' => 0,
            'is_filterable_in_search' => 0,
            'used_in_product_listing' => 1,
            'used_for_sort_by' => 1,
            'is_configurable' => 0,
            'frontend_input' => $frontendType,
            'is_wysiwyg_enabled' => 0,
            'is_unique' => 0,
            'is_required' => 0,
            'is_visible_in_advanced_search' => 0,
            'is_visible_on_checkout' => 1,
            'backend_model' => 'eav/entity_attribute_backend_array',
            'frontend_label' => $frontendLabels,
            'apply_to' => array()
        );

        $productEntityTypeId = \Mage::getModel('eav/entity')
            ->setType('catalog_product')
            ->getTypeId();

        $attrModel = \Mage::getModel('catalog/resource_eav_attribute');
        $attrModel->addData($attributeData);
        $attrModel->setEntityTypeId($productEntityTypeId);
        $attrModel->setIsUserDefined(1);

        $stores = Magento::getInstance()->getStoreMapping();
        $defaultValue = null;

        $attrModel->addData(array(
            'backend_type' => $attrModel->getBackendTypeByInput($attributeData['frontend_input'])
        ));

        foreach ($specific->getValues() as $specificValue) {
            $defaultSpecificValueI18n = ArrayTools::filterOneByLanguageOrFirst($specificValue->getI18ns(), $defaultLanguageIso);

            $adminValue = $defaultSpecificValueI18n->getValue();
            $optionData = array(
                \Mage_Core_Model_App::ADMIN_STORE_ID => $adminValue
            );

            foreach ($stores as $locale => $storeId) {
                $specificValueI18n = ArrayTools::filterOneByLanguageOrFirst($specificValue->getI18ns(), LocaleMapper::localeToLanguageIso($locale));
                $optionData[$storeId] = $specificValueI18n->getValue();
            }

            if (is_null($defaultValue)) {
                $defaultValue = $adminValue;
                $attrModel->setData('default_value', $defaultValue);
            }

            $attrModel->setData('option', array(
                    'value' => array(
                        'option' => $optionData
                    )
                )
            );
            $attrModel->save();
        }

        //

        $linkModel = \Mage::getModel('jtl_connector/specific_link');
        $linkModel->attribute_code = $attributeCode;
        $linkModel->jtl_erp_id = $specific->getId()->getHost();
        $linkModel->save();

        $this->addSpecificToAttributeSets($attrModel);

        $result->setId(new Identity($attributeCode, $specific->getId()->getHost()));
        foreach ($specific->getValues() as $specificValue) {
            $id = $specificValue->getId();
            $adminValue = ArrayTools::filterOneByLanguageOrFirst($specificValue->getI18ns(), $defaultLanguageIso)->getValue();

            $specificValue->setId(new Identity($attrModel->getSource()->getOptionId($adminValue), $id->getHost()));
            $result->addValue($specificValue);
        }
        
        return $result;
    }

    private function addSpecificToAttributeSets($attribute)
    {
        $productEntityTypeId = \Mage::getModel('eav/entity')
            ->setType('catalog_product')
            ->getTypeId();
        $attributeSetCollection = \Mage::getModel('eav/entity_attribute_set')
            ->getCollection()
            ->setEntityTypeFilter($productEntityTypeId);

        $i = 0;
        foreach ($attributeSetCollection as $attributeSet)
        {
            $defaultGroup = \Mage::getModel('eav/entity_attribute_group')
                ->getCollection()
                ->addFieldToFilter('attribute_set_id', $attributeSet->getId())
                ->setOrder('sort_order', 'ASC')
                ->getFirstItem();

            $newItem = \Mage::getModel('eav/entity_attribute')
                ->setEntityTypeId($productEntityTypeId)
                ->setAttributeSetId($attributeSet->getId())
                ->setAttributeGroupId($defaultGroup->getId())
                ->setAttributeId($attribute->getId())
                ->setSortOrder(10000)
                ->save();

            $i += 10;

            Logger::write(sprintf(
                'Add specific "%s" to attribute set "%s" in group "%s"',
                $attribute->getAttributeCode(),
                $attributeSet->getAttributeSetName(),
                $defaultGroup->getAttributeGroupName()
            ), Logger::DEBUG);
        }
    }

    private function updateSpecificValues(ConnectorSpecific $specific, $attribute)
    {
        $stores = Magento::getInstance()->getStoreMapping();
        $options = array();
        $defaultValue = null;
        $defaultLanguageIso = LocaleMapper::localeToLanguageIso($this->defaultLocale);

        foreach ($specific->getValues() as $specificValue) {
            $defaultSpecificValueI18n = ArrayTools::filterOneByLanguage($specificValue->getI18ns(), $defaultLanguageIso);
            if ($defaultSpecificValueI18n === null)
                $defaultSpecificValueI18n = reset($specificValue->getI18ns());

            $adminValue = $defaultSpecificValueI18n->getValue();
            $optionData = array(
                \Mage_Core_Model_App::ADMIN_STORE_ID => $adminValue
            );

            foreach ($stores as $locale => $storeId) {
                $specificValueI18n = ArrayTools::filterOneByLanguage($specificValue->getI18ns(), LocaleMapper::localeToLanguageIso($locale));
                if ($specificValueI18n === null) {
                    $i18ns = $specificValue->getI18ns();
                    $specificValueI18n = reset($i18ns);
                }

                $optionData[$storeId] = $specificValueI18n->getValue();
            }

            if (is_null($defaultValue)) {
                $defaultValue = $adminValue;
            }
            $options[$specificValue->getId()->getHost()] = $optionData;
        }

        $attrModel = \Mage::getModel('eav/entity_attribute')
            ->loadByCode('catalog_product', $attribute->attribute_code);
        $attrModel->addData(array(
            'backend_type' => $attrModel->getBackendTypeByInput('select')
        ));
        $attrModel->setData('option', array(
            'value' => $options
        ));
        $attrModel->setData('default_value', $defaultValue);
        $attrModel->save();
    }

    public function update(ConnectorSpecific $specific)
    {
        $result = new ConnectorSpecific();

        $productEntityTypeId = \Mage::getModel('eav/entity')
            ->setType('catalog_product')
            ->getTypeId();

        $attrModel = \Mage::getModel('eav/entity_attribute')
            ->loadByCode($productEntityTypeId, $specific->getId()->getEndpoint());

        $this->updateSpecificValues($specific, $attrModel);

        $result->setId(new Identity($attributeCode, $specific->getId()->getHost()));
        return $result;
    }

    public function push(ConnectorSpecific $specific)
    {
        $hostId = $specific->getId()->getHost();

        // Skip empty objects
        if ($hostId == 0)
            return null;

        Logger::write('push specific', Logger::DEBUG, 'general');
        if (null != $specific->getId()->getEndpoint())
            $result = $this->update($specific);
        else
            $result = $this->insert($specific);
        return $result;
    }

    public function getAvailableCount()
    {
        return 0;
    }
}
