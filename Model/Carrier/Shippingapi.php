<?php

namespace Kingwebmaster\Shipping\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\App\State;

class Shippingapi extends \Magento\Shipping\Model\Carrier\AbstractCarrier implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * @var string
     * Esse codigo deve bater com o ID configurado etc/adminhtml/system.xml
     * o group definido na section carriers deve ser igual a esse codigo.
     *
     * Nao eh recomendado a utilizacao do caractere "_" nesse codigo
     */
    protected $_code = 'asmext';

    protected $getcartdetails;

    protected $scopeConfig;

    protected $state;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface          $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory  $rateErrorFactory,
        \Psr\Log\LoggerInterface                                    $logger,
        \Magento\Shipping\Model\Rate\ResultFactory                  $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Checkout\Model\Session                             $getcartdetails,
        State                                                       $state,
        array                                                       $data = []
    )
    {
        $this->_getcartdetails = $getcartdetails;
        $this->state = $state;
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * @return string
     */
    public function getStringCode($string)
    {
        if (is_string($string) && strlen($string) > 0) {
            $rateTitle = explode(" ", $string);
            if (!empty($rateTitle) && count($rateTitle) > 0) {
                $string = '';
                foreach ($rateTitle as $key => $value) {
                    $alpnum = preg_replace("/[^a-zA-Z0-9]+/", "", $value);
                    $string .= isset($alpnum) && strlen($alpnum) > 0 ? substr($alpnum, 0, 1) : 'na';
                }
            }
        }

        return $string;
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => $this->getConfigData('name')];
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');
        $store_base_url = $storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);

        $myarray = array();
        $result = $this->_rateResultFactory->create();

        // Get all items from the request
        $items = $request->getAllItems();
        if (empty($items)) {
            return false;
        }

        /** @var \Magento\Quote\Model\Quote\Item $firstItem */
        $firstItem = reset($items);
        if (!$firstItem) {
            return false;
        }

        // Get the quote from the first item
        $quote = $firstItem->getQuote();
        if (!($quote instanceof \Magento\Quote\Model\Quote)) {
            return false;
        }

        // Use the quote instead of the session-based quote
        $getfunctions = $quote;

        $couponCode = $getfunctions->getCouponCode();
        $discountAmount = $getfunctions->getSubtotal() - $getfunctions->getSubtotalWithDiscount();
        array_push($myarray, $getfunctions->debug());

        $customerSession = $objectManager->create('Magento\Customer\Model\Session');
        $groupId = $customerSession->getCustomer()->getGroupId();
        $customerGroupsCollection = $objectManager->get('\Magento\Customer\Model\ResourceModel\Group\Collection');
        $customerGroups = $customerGroupsCollection->toOptionArray();
        foreach ($customerGroups as $key => $value) {
            if ($groupId == $value['value']) {
                $CustomerTag = $value['label'];
            }
        }

        if ($getfunctions) {
            if ($getfunctions->getShippingAddress()) {
                array_push($myarray, $getfunctions->getShippingAddress()->debug());
            }
        }

        $TotalTaxable = 0;
        $TotalNonTaxable = 0;

        if (isset($myarray[0]['items'])) {
            foreach ($myarray[0]['items'] as $key => $value) {
                if ($value['tax_amount'] == 0) {
                    $TotalNonTaxable += $value['price'];
                }
                if ($value['tax_amount'] != 0) {
                    $TotalTaxable += $value['price'];
                }
            }
        } else if (isset($myarray[1]['cached_items_all'])) {
            foreach ($myarray[1]['cached_items_all'] as $key => $value) {
                if ($value['tax_amount'] == 0) {
                    $TotalNonTaxable += $value['price'];
                }
                if ($value['tax_amount'] != 0) {
                    $TotalTaxable += $value['price'];
                }
            }
        }
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $dimension = "";
        $free_ship = "";
        $weight = 0;
        $free_ship_methods = "";
        $ship_alone = "";
        $flat_ship_rates = "";
        $origi_zip = "";
        $multiple_box = "";
        $multiple_box_weights = "";
        $multiple_box_dimensions = "";
        $invalid_methods = "";
        $markup = "";
        $exclude_from_free_ship = "";
        $invalid_states = "";
        $invalid_countries = "";
        $item_points = "";
        $multiple_box_points = "";
        $bundled_qty = "";
        $bundled_weight = "";
        $bundled_dimensions = "";
        $bundled_points = "";
        $process_time = "";
        $haz_mat = "";
        $options_weight_points = "";
        $custom1 = "";
        $custom2 = "";
        $custom3 = "";
        $custom4 = "";
        $custom5 = "";
        $coupon_code = "";
        $coupon_value = "";
        $grand_total = "";
        $customer_firstname = "";
        $customer_lastname = "";
        $total_Weight = "";
        $street = "";
        $postcode = "";
        $Qty = "";
        $base_row_total = "";
        $base_price = "";
        $name = "";
        $sku = "";
        $free_ship = "N";
        $ship_alone = "N";
        $haz_mat = "N";
        $exclude_from_free_ship = "N";
        $giftcert = "N";
        $taxable = "Y";
        if (isset($myarray[1]['coupon_code'])) {
            $coupon_code = $myarray[0]['coupon_code'];
        }
        if (isset($myarray[1]['discount_amount'])) {
            $coupon_value = str_replace("-", "", $myarray[1]['discount_amount']);
        }
        if (isset($myarray[0]['grand_total'])) {
            $grand_total = $myarray[0]['grand_total'];
        }
        if (isset($myarray[0]['customer_firstname'])) {
            $customer_firstname = $myarray[0]['customer_firstname'];
        }
        if (isset($myarray[0]['customer_lastname'])) {
            $customer_lastname = $myarray[0]['customer_lastname'];
        }
        if (isset($myarray[1]['weight'])) {
            $total_Weight = $myarray[1]['weight'];
        }
        if (isset($myarray[1]['street'])) {
            $street = $myarray[1]['street'];
        }
        if (isset($myarray[1]['postcode'])) {
            $postcode = $myarray[1]['postcode'];
        }
        if (isset($myarray[1]['price'])) {
            $price = $myarray[1]['price'];
        }
        $parts = parse_url($store_base_url);
        $parts = explode('.', $parts['host']);
        if (is_numeric($parts[0])) {
            $DomainName = $_SERVER['HTTP_HOST'];
        } else {
            if ($parts[0] == "www") {
                $DomainName = $parts[1];
            } else {
                $DomainName = $parts[0];
            }
        }
        if (!empty($customer_firstname) && !empty($postcode)) {
            if (array_key_exists("city", $myarray[1])) {
                $city = $myarray[1]['city'];
            } else {
                $city = "";
            }
            if (array_key_exists("postcode", $myarray[1])) {
                $postcode = $myarray[1]['postcode'];
            } else {
                $postcode = "";
            }
            if (array_key_exists("region_code", $myarray[1])) {
                $region_code = $myarray[1]['region_code'];
            } else {
                $region_code = "";
            }
            if (array_key_exists("country_id", $myarray[1])) {
                $country_id = $myarray[1]['country_id'];
            } else {
                $country_id = "";
            }
            $XML_CODE = "<?xml version='1.0' encoding='UTF-8'?>
                        <ShippingQuery>
                            <AccountIdentifier>Hsgh7Hdhwt626gsj2A</AccountIdentifier>
                            <StoreIndicator><![CDATA[" . $DomainName . "]]></StoreIndicator>
                            <Total>" . $grand_total . "</Total>
                            <TotalTaxable>" . $TotalTaxable . "</TotalTaxable>
                            <TotalNonTaxable>" . $TotalNonTaxable . "</TotalNonTaxable>
                            <TotalWeight>" . $total_Weight . "</TotalWeight>
                            <CouponCode><![CDATA[" . $coupon_code . "]]></CouponCode>
                            <CouponValue>" . $discountAmount . "</CouponValue>
                            <CustomerTag><![CDATA[" . $CustomerTag . "]]></CustomerTag>
                            <ShipToAddress>
                                <Name><![CDATA[" . $customer_firstname . ' ' . $customer_lastname . "]]></Name>
                                <Address1><![CDATA[" . $street . ' ' . $city . ' ' . $region_code . ' ' . $postcode . "]]></Address1>
                                <Address2></Address2>
                                <City><![CDATA[" . $city . "]]></City>
                                <ZipCode><![CDATA[" . $postcode . "]]></ZipCode>
                                <State><![CDATA[" . $region_code . "]]></State>
                                <Country><![CDATA[" . $country_id . "]]></Country>
                            </ShipToAddress>
                            <Products>";
            $items = [];
            if (isset($myarray[0]['items']))
                $items = $myarray[0]['items'];
            elseif (isset($myarray[1]['cached_items_all']))
                $items = $myarray[1]['cached_items_all'];
            foreach ($items as $key => $value) {
                if (isset($value['parent_item_id'])) {
                    continue;
                }
                if (isset($value['weight'])) {
                    $weight = $value['weight'];
                }
                if (isset($value['qty'])) {
                    $Qty = $value['qty'];
                }
                if (isset($value['base_row_total'])) {
                    $base_row_total = $value['base_row_total'];
                }
                if (isset($value['base_price'])) {
                    $base_price = $value['base_price'];
                }
                if (isset($value['price'])) {
                    $price = $value['price'];
                }
                if (isset($value['name'])) {
                    $name = $value['name'];
                }
                if (isset($value['sku'])) {
                    $sku = $value['sku'];
                }
                if (isset($value['product (Magento\Catalog\Model\Product\Interceptor)']['tax_class_id'])) {
                    $taxable = ($value['product (Magento\Catalog\Model\Product\Interceptor)']['tax_class_id'] == 2) ? "Y" : "N";
                }
                if (isset($value['product (Magento\Catalog\Model\Product\Interceptor)']['gift_message_available'])) {
                    $giftcert = ($value['product (Magento\Catalog\Model\Product\Interceptor)']['gift_message_available'] == 2) ? "N" : "Y";
                }
                $XML_CODE = $XML_CODE . "
                                <Product>
                                    <Code><![CDATA[" . $sku . "]]></Code>
                                    <Qty>" . $Qty . "</Qty>
                                    <UnitPrice>" . $base_row_total . "</UnitPrice>
                                    <Attributes>
                                        <Name><![CDATA[" . $name . "]]></Name>
                                        <Price>" . $base_price . "</Price>
                                        <SalePrice>" . $price . "</SalePrice>
                                        <ShipWeight>" . $weight . "</ShipWeight>
                                        <Taxable>" . $taxable . "</Taxable>
                                        <GiftCertDownloadable>" . $giftcert . "</GiftCertDownloadable>";
                $product = $objectManager->create('Magento\Catalog\Model\Product')->load($value['product_id']);
                if (isset($product['free_ship'])) {
                    $free_ship = ($product['free_ship'] == 1) ? "Y" : "N";
                } else {
                    $free_ship = "N";
                }
                if (isset($product['ship_alone'])) {
                    $ship_alone = ($product['ship_alone'] == 1) ? "Y" : "N";
                } else {
                    $ship_alone = "N";
                }
                if (isset($product['haz_mat'])) {
                    $haz_mat = ($product['haz_mat'] == 1) ? "Y" : "N";
                } else {
                    $haz_mat = "N";
                }
                if (isset($product['exclude_from_free_ship'])) {
                    $exclude_from_free_ship = ($product['exclude_from_free_ship'] == 1) ? "Y" : "N";
                } else {
                    $exclude_from_free_ship = "N";
                }
                if (isset($product['dimension'])) {
                    $dimension = $product['dimension'];
                } else {
                    $dimension = '';
                }
                if (isset($product['free_ship_methods'])) {
                    $free_ship_methods = $product['free_ship_methods'];
                } else {
                    $free_ship_methods = '';
                }
                if (isset($product['flat_ship_rates'])) {
                    $flat_ship_rates = $product['flat_ship_rates'];
                } else {
                    $flat_ship_rates = '';
                }
                if (isset($product['origi_zip'])) {
                    $origi_zip = $product['origi_zip'];
                } else {
                    $origi_zip = '';
                }
                if (isset($product['multiple_box'])) {
                    $multiple_box = $product['multiple_box'];
                } else {
                    $multiple_box = '';
                }
                if (isset($product['multiple_box_weights'])) {
                    $multiple_box_weights = $product['multiple_box_weights'];
                } else {
                    $multiple_box_weights = '';
                }
                if (isset($product['multiple_box_dimensions'])) {
                    $multiple_box_dimensions = $product['multiple_box_dimensions'];
                } else {
                    $multiple_box_dimensions = '';
                }
                if (isset($product['invalid_methods'])) {
                    $invalid_methods = $product['invalid_methods'];
                } else {
                    $invalid_methods = '';
                }
                if (isset($product['markup'])) {
                    $markup = $product['markup'];
                } else {
                    $markup = '';
                }
                if (isset($product['invalid_states'])) {
                    $invalid_states = $product['invalid_states'];
                } else {
                    $invalid_states = '';
                }
                if (isset($product['invalid_countries'])) {
                    $invalid_countries = $product['invalid_countries'];
                } else {
                    $invalid_countries = '';
                }
                if (isset($product['item_points'])) {
                    $item_points = $product['item_points'];
                } else {
                    $item_points = '';
                }
                if (isset($product['multiple_box_points'])) {
                    $multiple_box_points = $product['multiple_box_points'];
                } else {
                    $multiple_box_points = '';
                }
                if (isset($product['bundled_qty'])) {
                    $bundled_qty = $product['bundled_qty'];
                } else {
                    $bundled_qty = '';
                }
                if (isset($product['bundled_weight'])) {
                    $bundled_weight = $product['bundled_weight'];
                } else {
                    $bundled_weight = '';
                }
                if (isset($product['bundled_dimensions'])) {
                    $bundled_dimensions = $product['bundled_dimensions'];
                } else {
                    $bundled_dimensions = '';
                }
                if (isset($product['bundled_points'])) {
                    $bundled_points = $product['bundled_points'];
                } else {
                    $bundled_points = '';
                }
                if (isset($product['process_time'])) {
                    $process_time = $product['process_time'];
                } else {
                    $process_time = '';
                }
                if (isset($product['options_weight_points'])) {
                    $options_weight_points = $product['options_weight_points'];
                } else {
                    $options_weight_points = '';
                }
                if (isset($product['custom1'])) {
                    $custom1 = $product['custom1'];
                } else {
                    $custom1 = '';
                }
                if (isset($product['custom2'])) {
                    $custom2 = $product['custom2'];
                } else {
                    $custom2 = '';
                }
                if (isset($product['custom3'])) {
                    $custom3 = $product['custom3'];
                } else {
                    $custom3 = '';
                }
                if (isset($product['custom4'])) {
                    $custom4 = $product['custom4'];
                } else {
                    $custom4 = '';
                }
                if (isset($product['custom5'])) {
                    $custom5 = $product['custom5'];
                } else {
                    $custom5 = '';
                }
                $XML_CODE = $XML_CODE . "
                                        <Dimensions>" . $dimension . "</Dimensions>
                                        <FreeShip>" . $free_ship . "</FreeShip>
                                        <FreeShipMethods>" . $free_ship_methods . "</FreeShipMethods>
                                        <ShipAlone>" . $ship_alone . "</ShipAlone>
                                        <FlatShipRates>" . $flat_ship_rates . "</FlatShipRates>
                                        <OriginZip>" . $origi_zip . "</OriginZip>
                                        <MultipleBox>" . $multiple_box . "</MultipleBox>
                                        <MultipleBoxWeights>" . $multiple_box_weights . "</MultipleBoxWeights>
                                        <MultipleBoxDimensions>" . $multiple_box_dimensions . "</MultipleBoxDimensions>
                                        <InvalidMethods>" . $invalid_methods . "</InvalidMethods>
                                        <Markup>" . $markup . "</Markup>
                                        <ExcludeFromFreeShip>" . $exclude_from_free_ship . "</ExcludeFromFreeShip>
                                        <InvalidStates>" . $invalid_states . "</InvalidStates>
                                        <InvalidCountries>" . $invalid_countries . "</InvalidCountries>
                                        <ItemPoints>" . $item_points . "</ItemPoints>
                                        <MultipleBoxPoints>" . $multiple_box_points . "</MultipleBoxPoints>
                                        <BundledQty>" . $bundled_qty . "</BundledQty>
                                        <BundledWeight>" . $bundled_weight . "</BundledWeight>
                                        <BundledDimensions>" . $bundled_dimensions . "</BundledDimensions>
                                        <BundledPoints>" . $bundled_points . "</BundledPoints>
                                        <ProcessTime>" . $process_time . "</ProcessTime>
                                        <HazMat>" . $haz_mat . "</HazMat>
                                        <OptionsWeightPoints>" . $options_weight_points . "</OptionsWeightPoints>
                                        <Custom1><![CDATA[" . $custom1 . "]]></Custom1>
                                        <Custom2><![CDATA[" . $custom2 . "]]></Custom2>
                                        <Custom3><![CDATA[" . $custom3 . "]]></Custom3>
                                        <Custom4><![CDATA[" . $custom4 . "]]></Custom4>
                                        <Custom5><![CDATA[" . $custom5 . "]]></Custom5>
                                    </Attributes>
                                </Product>";
            }
            $XML_CODE = $XML_CODE . " </Products>
                        </ShippingQuery>";
            $Api_Url = "https://www.advancedshippingmanager.com/clients/web_services/asm_web_service.php";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $Api_Url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $XML_CODE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $server_output = curl_exec($ch);
            if (!empty($server_output)) {
                $array = json_decode(json_encode((array)simplexml_load_string($server_output)), true);
                if (isset($array['AvailableMethods']['ShippingMethod']['Name'])) {
                    $name = !empty($array['AvailableMethods']['ShippingMethod']['Name']) ? $array['AvailableMethods']['ShippingMethod']['Name'] : '';
                    $note = !empty($array['AvailableMethods']['ShippingMethod']['Note']) ? $array['AvailableMethods']['ShippingMethod']['Note'] : '';
                    $rate = !empty($array['AvailableMethods']['ShippingMethod']['Rate']) ? $array['AvailableMethods']['ShippingMethod']['Rate'] : '';
                    $method = $this->_rateMethodFactory->create();
                    $method->setCarrier($this->getCarrierCode());

                    // Shipping method
                    $method->setMethod($this->getStringCode($name));
                    if ($note != '') {
                        $method->setMethodTitle($name . ' (' . $note . ')');
                    } else {
                        $method->setMethodTitle($name);
                    }
                    $method->setPrice($rate);
                    $method->setCost($rate);
                    $method->setCarrierTitle('');
                    if (!empty($name)) {
                        $result->append($method);
                    }

                } else {
                    if (isset($array['AvailableMethods']['ShippingMethod'])) {
                        foreach ($array['AvailableMethods']['ShippingMethod'] as $keyname => $datavalue) {
                            $name = !empty($datavalue['Name']) ? $datavalue['Name'] : '';
                            $note = !empty($datavalue['Note']) ? $datavalue['Note'] : '';
                            $rate = !empty($datavalue['Rate']) ? $datavalue['Rate'] : '';
                            $method = $this->_rateMethodFactory->create();
                            $method->setCarrier($this->getCarrierCode());

                            // Shipping method
                            $method->setMethod($this->getStringCode($name));
                            if ($note != '') {
                                $method->setMethodTitle($name . ' (' . $note . ')');
                            } else {
                                $method->setMethodTitle($name);
                            }
                            $method->setPrice($rate);
                            $method->setCost($rate);
                            $method->setCarrierTitle('');
                            if (!empty($name)) {
                                $result->append($method);
                            }
                        }
                    }
                }
            }
            return $result;
        } else {
            if (!empty($postcode)) {
                if (array_key_exists("firstname", $myarray[1])) {
                    $firstname = $myarray[1]['firstname'];
                } else {
                    $firstname = "";
                }
                if (array_key_exists("lastname", $myarray[1])) {
                    $lastname = $myarray[1]['lastname'];
                } else {
                    $lastname = "";
                }
                if (array_key_exists("street", $myarray[1])) {
                    $street = $myarray[1]['street'];
                } else {
                    $street = "";
                }
                if (array_key_exists("city", $myarray[1])) {
                    $city = $myarray[1]['city'];
                } else {
                    $city = "";
                }
                if (array_key_exists("country_id", $myarray[1])) {
                    $country_id = $myarray[1]['country_id'];
                } else {
                    $country_id = "";
                }
                $XML_CODE = "<?xml version='1.0' encoding='UTF-8'?>
                            <ShippingQuery>
                            <AccountIdentifier>Hsgh7Hdhwt626gsj2A</AccountIdentifier>
                            <StoreIndicator><![CDATA[" . $DomainName . "]]></StoreIndicator>
                            <Total>" . $grand_total . "</Total>
                            <TotalTaxable>" . $TotalTaxable . "</TotalTaxable>
                            <TotalNonTaxable>" . $TotalNonTaxable . "</TotalNonTaxable>
                            <TotalWeight>" . $total_Weight . "</TotalWeight>
                            <CouponCode><![CDATA[" . $coupon_code . "]]></CouponCode>
                            <CouponValue>" . $discountAmount . "</CouponValue>
                            <CustomerTag><![CDATA[" . $CustomerTag . "]]></CustomerTag>
                            <ShipToAddress>
                                <Name><![CDATA[" . $firstname . ' ' . $lastname . "]]></Name>
                                <Address1><![CDATA[" . $street . ' ' . $city . ' ' . $postcode . "]]></Address1>
                                <Address2></Address2>
                                <City><![CDATA[" . $city . "]]></City>
                                <ZipCode><![CDATA[" . $postcode . "]]></ZipCode>
                                <Country><![CDATA[" . $country_id . "]]></Country>
                            </ShipToAddress> 
                            <Products>";
                if (isset($myarray[0]['items']))
                    $items = $myarray[0]['items'];
                else if (isset($myarray[1]['cached_items_all']))
                    $items = $myarray[1]['cached_items_all'];
                foreach ($items as $key => $value) {
                    if (isset($value['weight'])) {
                        $weight = $value['weight'];
                    }
                    if (isset($value['qty'])) {
                        $Qty = $value['qty'];
                    }
                    if (isset($value['base_row_total'])) {
                        $base_row_total = $value['base_row_total'];
                    }
                    if (isset($value['base_price'])) {
                        $base_price = $value['base_price'];
                    }
                    if (isset($value['price'])) {
                        $price = $value['price'];
                    }
                    if (isset($value['name'])) {
                        $name = $value['name'];
                    }
                    if (isset($value['sku'])) {
                        $sku = $value['sku'];
                    }
                    if (isset($value['product (Magento\Catalog\Model\Product\Interceptor)']['tax_class_id'])) {
                        $taxable = ($value['product (Magento\Catalog\Model\Product\Interceptor)']['tax_class_id'] == 2) ? "Y" : "N";
                    }
                    if (isset($value['product (Magento\Catalog\Model\Product\Interceptor)']['gift_message_available'])) {
                        $giftcert = ($value['product (Magento\Catalog\Model\Product\Interceptor)']['gift_message_available'] == 2) ? "N" : "Y";
                    }
                    $XML_CODE = $XML_CODE . "
                                        <Product>
                                            <Code><![CDATA[" . $sku . "]]></Code>
                                            <Qty>" . $Qty . "</Qty>
                                            <UnitPrice>" . $base_row_total . "</UnitPrice>
                                            <Attributes>
                                                <Name><![CDATA[" . $name . "]]></Name>
                                                <Price>" . $base_price . "</Price>
                                                <SalePrice>" . $price . "</SalePrice>
                                                <ShipWeight>" . $weight . "</ShipWeight>
                                                <Taxable>" . $taxable . "</Taxable>
                                        <GiftCertDownloadable>" . $giftcert . "</GiftCertDownloadable>";
                    $product = $objectManager->create('Magento\Catalog\Model\Product')->load($value['product_id']);
                    if (isset($product['free_ship'])) {
                        $free_ship = ($product['free_ship'] == 1) ? "Y" : "N";
                    } else {
                        $free_ship = "N";
                    }
                    if (isset($product['ship_alone'])) {
                        $ship_alone = ($product['ship_alone'] == 1) ? "Y" : "N";
                    } else {
                        $ship_alone = "N";
                    }
                    if (isset($product['haz_mat'])) {
                        $haz_mat = ($product['haz_mat'] == 1) ? "Y" : "N";
                    } else {
                        $haz_mat = "N";
                    }
                    if (isset($product['exclude_from_free_ship'])) {
                        $exclude_from_free_ship = ($product['exclude_from_free_ship'] == 1) ? "Y" : "N";
                    } else {
                        $exclude_from_free_ship = "N";
                    }
                    if (isset($product['dimension'])) {
                        $dimension = $product['dimension'];
                    } else {
                        $dimension = '';
                    }
                    if (isset($product['free_ship_methods'])) {
                        $free_ship_methods = $product['free_ship_methods'];
                    } else {
                        $free_ship_methods = '';
                    }
                    if (isset($product['flat_ship_rates'])) {
                        $flat_ship_rates = $product['flat_ship_rates'];
                    } else {
                        $flat_ship_rates = '';
                    }
                    if (isset($product['origi_zip'])) {
                        $origi_zip = $product['origi_zip'];
                    } else {
                        $origi_zip = '';
                    }
                    if (isset($product['multiple_box'])) {
                        $multiple_box = $product['multiple_box'];
                    } else {
                        $multiple_box = '';
                    }
                    if (isset($product['multiple_box_weights'])) {
                        $multiple_box_weights = $product['multiple_box_weights'];
                    } else {
                        $multiple_box_weights = '';
                    }
                    if (isset($product['multiple_box_dimensions'])) {
                        $multiple_box_dimensions = $product['multiple_box_dimensions'];
                    } else {
                        $multiple_box_dimensions = '';
                    }
                    if (isset($product['invalid_methods'])) {
                        $invalid_methods = $product['invalid_methods'];
                    } else {
                        $invalid_methods = '';
                    }
                    if (isset($product['markup'])) {
                        $markup = $product['markup'];
                    } else {
                        $markup = '';
                    }
                    if (isset($product['invalid_states'])) {
                        $invalid_states = $product['invalid_states'];
                    } else {
                        $invalid_states = '';
                    }
                    if (isset($product['invalid_countries'])) {
                        $invalid_countries = $product['invalid_countries'];
                    } else {
                        $invalid_countries = '';
                    }
                    if (isset($product['item_points'])) {
                        $item_points = $product['item_points'];
                    } else {
                        $item_points = '';
                    }
                    if (isset($product['multiple_box_points'])) {
                        $multiple_box_points = $product['multiple_box_points'];
                    } else {
                        $multiple_box_points = '';
                    }
                    if (isset($product['bundled_qty'])) {
                        $bundled_qty = $product['bundled_qty'];
                    } else {
                        $bundled_qty = '';
                    }
                    if (isset($product['bundled_weight'])) {
                        $bundled_weight = $product['bundled_weight'];
                    } else {
                        $bundled_weight = '';
                    }
                    if (isset($product['bundled_dimensions'])) {
                        $bundled_dimensions = $product['bundled_dimensions'];
                    } else {
                        $bundled_dimensions = '';
                    }
                    if (isset($product['bundled_points'])) {
                        $bundled_points = $product['bundled_points'];
                    } else {
                        $bundled_points = '';
                    }
                    if (isset($product['process_time'])) {
                        $process_time = $product['process_time'];
                    } else {
                        $process_time = '';
                    }
                    if (isset($product['options_weight_points'])) {
                        $options_weight_points = $product['options_weight_points'];
                    } else {
                        $options_weight_points = '';
                    }
                    if (isset($product['custom1'])) {
                        $custom1 = $product['custom1'];
                    } else {
                        $custom1 = '';
                    }
                    if (isset($product['custom2'])) {
                        $custom2 = $product['custom2'];
                    } else {
                        $custom2 = '';
                    }
                    if (isset($product['custom3'])) {
                        $custom3 = $product['custom3'];
                    } else {
                        $custom3 = '';
                    }
                    if (isset($product['custom4'])) {
                        $custom4 = $product['custom4'];
                    } else {
                        $custom4 = '';
                    }
                    if (isset($product['custom5'])) {
                        $custom5 = $product['custom5'];
                    } else {
                        $custom5 = '';
                    }
                    $XML_CODE = $XML_CODE . "
                                            <Dimensions>" . $dimension . "</Dimensions>
                                            <FreeShip>" . $free_ship . "</FreeShip>
                                            <FreeShipMethods>" . $free_ship_methods . "</FreeShipMethods>
                                            <ShipAlone>" . $ship_alone . "</ShipAlone>
                                            <FlatShipRates>" . $flat_ship_rates . "</FlatShipRates>
                                            <OriginZip>" . $origi_zip . "</OriginZip>
                                            <MultipleBox>" . $multiple_box . "</MultipleBox>
                                            <MultipleBoxWeights>" . $multiple_box_weights . "</MultipleBoxWeights>
                                            <MultipleBoxDimensions>" . $multiple_box_dimensions . "</MultipleBoxDimensions>
                                            <InvalidMethods>" . $invalid_methods . "</InvalidMethods>
                                            <Markup>" . $markup . "</Markup>
                                            <ExcludeFromFreeShip>" . $exclude_from_free_ship . "</ExcludeFromFreeShip>
                                            <InvalidStates>" . $invalid_states . "</InvalidStates>
                                            <InvalidCountries>" . $invalid_countries . "</InvalidCountries>
                                            <ItemPoints>" . $item_points . "</ItemPoints>
                                            <MultipleBoxPoints>" . $multiple_box_points . "</MultipleBoxPoints>
                                            <BundledQty>" . $bundled_qty . "</BundledQty>
                                            <BundledWeight>" . $bundled_weight . "</BundledWeight>
                                            <BundledDimensions>" . $bundled_dimensions . "</BundledDimensions>
                                            <BundledPoints>" . $bundled_points . "</BundledPoints>
                                            <ProcessTime>" . $process_time . "</ProcessTime>
                                            <HazMat>" . $haz_mat . "</HazMat>
                                            <OptionsWeightPoints>" . $options_weight_points . "</OptionsWeightPoints>
                                            <Custom1><![CDATA[" . $custom1 . "]]></Custom1>
                                            <Custom2><![CDATA[" . $custom2 . "]]></Custom2>
                                            <Custom3><![CDATA[" . $custom3 . "]]></Custom3>
                                            <Custom4><![CDATA[" . $custom4 . "]]></Custom4>
                                            <Custom5><![CDATA[" . $custom5 . "]]></Custom5>
                                            </Attributes>
                                        </Product>";
                }
                $XML_CODE = $XML_CODE . " </Products>
                        </ShippingQuery>";
                $Api_Url = "https://www.advancedshippingmanager.com/clients/web_services/asm_web_service.php";

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $Api_Url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $XML_CODE);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                $server_output = curl_exec($ch);
                if (!empty($server_output)) {
                    $array = json_decode(json_encode((array)simplexml_load_string($server_output)), true);
                    if (isset($array['AvailableMethods']['ShippingMethod']['Name'])) {
                        $name = !empty($array['AvailableMethods']['ShippingMethod']['Name']) ? $array['AvailableMethods']['ShippingMethod']['Name'] : '';
                        $note = !empty($array['AvailableMethods']['ShippingMethod']['Note']) ? $array['AvailableMethods']['ShippingMethod']['Note'] : '';
                        $rate = !empty($array['AvailableMethods']['ShippingMethod']['Rate']) ? $array['AvailableMethods']['ShippingMethod']['Rate'] : '';
                        $method = $this->_rateMethodFactory->create();
                        $method->setCarrier($this->getCarrierCode());


                        // Shipping method
                        $method->setMethod($this->getStringCode($name));
                        if ($note != '') {
                            $method->setMethodTitle($name . ' (' . $note . ')');
                        } else {
                            $method->setMethodTitle($name);
                        }
                        $method->setPrice($rate);
                        $method->setCost($rate);
                        $method->setCarrierTitle('');
                        if (!empty($name)) {
                            $result->append($method);
                        }

                    } else {
                        if (isset($array['AvailableMethods']['ShippingMethod'])) {
                            foreach ($array['AvailableMethods']['ShippingMethod'] as $keyname => $datavalue) {
                                $name = !empty($datavalue['Name']) ? $datavalue['Name'] : '';
                                $note = !empty($datavalue['Note']) ? $datavalue['Note'] : '';
                                $rate = !empty($datavalue['Rate']) ? $datavalue['Rate'] : '';
                                $method = $this->_rateMethodFactory->create();
                                $method->setCarrier($this->getCarrierCode());

                                // Shipping method
                                $method->setMethod($this->getStringCode($name));
                                if ($note != '') {
                                    $method->setMethodTitle($name . ' (' . $note . ')');
                                } else {
                                    $method->setMethodTitle($name);
                                }
                                $method->setPrice($rate);
                                $method->setCost($rate);
                                $method->setCarrierTitle('');
                                if (!empty($name)) {
                                    $result->append($method);
                                }
                            }
                        }
                    }

                }
                return $result;
            }
        }
    }
}