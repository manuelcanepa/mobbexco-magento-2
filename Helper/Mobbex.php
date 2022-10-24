<?php

namespace Mobbex\Webpay\Helper;

/**
 * Class Mobbex
 * @package Mobbex\Webpay\Helper
 */
class Mobbex extends \Magento\Framework\App\Helper\AbstractHelper
{
    const VERSION = '3.1.2';

    /** @var \Mobbex\Webpay\Helper\Instantiator */
    public $instantiator;

    /** @var ScopeConfigInterface */
    public $scopeConfig;

    /** @var ObjectManagerInterface */
    protected $_objectManager;

    /** @var StoreManagerInterface */
    protected $_storeManager;

    /** @var Image */
    protected $imageHelper;
    
    /** @var ProductMetadataInterface */
    protected $productMetadata;
    
    /** @var Session */
    protected $customerSession;

    /** @var \Magento\Sales\Api\Data\OrderInterface */
    protected $_orderInterface;

    /** @var ProductRepository */
    protected $productRepository;

    /** @var \Magento\Framework\Event\ConfigInterface */
    public $eventConfig;

    /** @var \Magento\Framework\Event\ObserverFactory */
    public $observerFactory;

    public function __construct(
        \Mobbex\Webpay\Helper\Instantiator $instantiator,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $_storeManager,
        \Magento\Catalog\Helper\Image $imageHelper,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Sales\Api\Data\OrderInterface $_orderInterface,
        \Magento\Catalog\Model\ProductRepository $productRepository,
        \Magento\Framework\Event\ConfigInterface $eventConfig,
        \Magento\Framework\Event\ObserverFactory $observerFactory
    ) {
        $instantiator->setProperties($this, ['config', 'logger', 'customFieldFactory', 'quoteFactory', '_cart', '_order', '_urlBuilder', '_checkoutSession']);
        $this->scopeConfig        = $scopeConfig;
        $this->_storeManager      = $_storeManager;
        $this->imageHelper        = $imageHelper;
        $this->productMetadata    = $productMetadata;
        $this->customerSession    = $customerSession;
        $this->productRepository  = $productRepository;
        $this->eventConfig        = $eventConfig;
        $this->observerFactory    = $observerFactory;
        $this->_orderInterface    = $_orderInterface;
    }

    /**
     * @return bool
     */
    public function getCheckout()
    {
        // get order data
        $orderId     = $this->_checkoutSession->getLastRealOrderId();
        $orderData   = $this->_order->load($this->_checkoutSession->getLastRealOrder()->getEntityId());
        $orderAmount = round($this->_orderInterface->getData('base_grand_total'), 2);

        // Get customer data
        if ($orderData->getBillingAddress()){
            if (!empty($orderData->getBillingAddress()->getTelephone())) {
                $phone = $orderData->getBillingAddress()->getTelephone();
            }
        }

        $customer = [
            'name'           => $orderData->getCustomerName(),
            'email'          => $orderData->getCustomerEmail(), 
            'uid'            => $orderData->getCustomerId(),
            'phone'          => isset($phone) ? $phone : '',
            'identification' => $this->getDni($orderData->getQuoteId()),
        ];

        //Get Items
        $items = $products = [];
        $orderedItems = $this->_orderInterface->getAllVisibleItems();
        
        foreach ($orderedItems as $item) {

            $product      = $item->getProduct();
            $products[]   = $product;
            $price        = $item->getRowTotalInclTax() ? : $product->getFinalPrice();
            $subscription = $this->getProductSubscription($product->getId());
            $entity       = $this->getEntity($product);
            
            if($subscription['enable'] === 'yes') {
                $items[] = [
                    'type'      => 'subscription',
                    'reference' => $subscription['uid']
                ];
            } else {
                $items[] = [
                    "image"       => $this->imageHelper->init($product, 'product_small_image')->getUrl(),
                    "description" => $product->getName(),
                    "quantity"    => $item->getQtyOrdered(),
                    "total"       => round($price, 2),
                    "entity"      => $entity
                ];
            }
        }

        //Get products active plans
        extract($this->config->getProductPlans($products));
        
        if (!empty($this->_orderInterface->getShippingDescription())) {
            $items[] = [
                'description' => __('Shipping') . ': ' . $this->_orderInterface->getShippingDescription(),
                'total' => $this->_orderInterface->getShippingInclTax(),
            ];
        }

        $mobbexCheckout = new \Mobbex\Modules\Checkout(
            $orderId,
            (float) $orderAmount,
            $this->getEndpointUrl('paymentreturn', ['order_id' => $orderId]),
            $this->getEndpointUrl('webhook', ['order_id' => $orderId]),
            $items,
            \Mobbex\Repository::getInstallments($orderedItems, $common_plans, $advanced_plans),
            $customer,
            'mobbexProcessPayment'
        );

        $this->logger->debug('debug', "Checkout Response: ", $mobbexCheckout->response);

        return $mobbexCheckout->response;
    }

    /**
     * Create a checkout from the given quote.
     * 
     * @param Magento\Quote\Model\Quote $quote
     * 
     * @return array
     */
    public function createCheckoutFromQuote()
    {
        // Get quote
        $quote = $this->_checkoutSession->getQuote();
        
        // Get customer and shipping data
        $shippingAddress = $quote->getBillingAddress()->getData();
        $shippingAmount  = $quote->getShippingAddress()->getShippingAmount();
        
        foreach ($quote->getItemsCollection() as $item) {
            $subscriptionConfig = $this->getProductSubscription($item->getProductId());
            
            if ($subscriptionConfig['enable'] === 'yes') {
                $items[] = [
                    'type'      => 'subscription',
                    'reference' => $subscriptionConfig['uid']
                ];
            } else {
                $items[] = [
                    'description' => $item->getName(),
                    'quantity'    => $item->getQty(),
                    'total'       => (float) $item->getPrice(),
                    'entity'      => $this->getEntity($item->getProduct()),
                ];
            }
        }

            //Get products active plans
            $products = [];
            foreach ($quote->getItemsCollection() as $item)
            $products[] = $item->getProduct();
            extract($this->config->getProductPlans($products));
            
            // Add shipping item if possible
            if ($shippingAmount)
            $items[] = [
                'description' => 'Shipping',
                'total'       => $shippingAmount,
            ];
            
            $customer = [
                'email'          => $quote->getCustomerEmail(),
                'name'           => "$shippingAddress[firstname] $shippingAddress[lastname]",
                'identification' => $this->getDni($quote->getId()),
                'uid'            => $quote->getCustomerId(),
                'phone'          => $shippingAddress['telephone'],
            ];
            
            try {

            $mobbexCheckout = new \Mobbex\Modules\Checkout(
                $quote->getId(),
                (float) $quote->getGrandTotal(),
                $this->getEndpointUrl('paymentreturn', ['order_id' => $quote->getId()]),
                $this->getEndpointUrl('webhook', ['order_id' => $quote->getId()]),
                isset($items) ? $items : [],
                \Mobbex\Repository::getInstallments($quote->getItemsCollection(), $common_plans, $advanced_plans),
                $customer,
                'mobbexProcessPayment'
            );

            $this->logger->debug('debug', "Checkout Response: ", $mobbexCheckout->response); 
            
            return ['data' => $mobbexCheckout->response, 'order_id' => $quote->getId()];

        } catch (\Exception $e) {
            $this->logger->debug('err', $e->getMessage(), $e->data);
            return false;
        }
        
    }

    public function getEndpointUrl($controller, $data = [])
    {   
        return $this->_urlBuilder->getUrl("webpay/payment/$controller", [
            '_secure'      => true,
            '_current'     => true,
            '_use_rewrite' => true,
            '_query'       => $data,
        ]);
    }

    /**
     * Get yhe entity of a product
     * @param object $product
     * @return string $entity
     */
    public function getEntity($product)
    {
        if($this->config->getCatalogSetting($product->getId(), 'entity'))
            return $this->config->getCatalogSetting($product->getId(), 'entity');

        $categories = $product->getCategoryIds();
        foreach ($categories as $category) {
            if($this->config->getCatalogSetting($category, 'entity', 'category'))
                return $this->config->getCatalogSetting($category, 'entity', 'category'); 
        }

        return '';
	}

    /**
     * Retrieve product subscription data.
     * 
     * @param int|string $id
     * 
     * @return array
     */
    public function getProductSubscription($id)
    {
        foreach (['is_subscription', 'subscription_uid'] as $value)
            ${$value} = $this->config->getCatalogSetting($id, $value);

        return ['enable' => $is_subscription, 'uid' => $subscription_uid];
    }

    /**
     * Get DNI configured by quote or current user if logged in.
     * 
     * @param int|string $quoteId
     * 
     * @return string $dni 
     */
    public function getDni($quoteId)
    {
        $customField = $this->customFieldFactory->create();

        // Get dni custom field from quote or current user if logged in
        $customerId = $this->customerSession->getCustomer()->getId();
        $object     = $customerId ? 'customer' : 'quote';
        $rowId      = $customerId ? $customerId : $quoteId;

        return $customField->getCustomField($rowId, $object, 'dni') ?: '';
    }

    /**
     * Execute a hook and retrieve the response.
     * 
     * @param string $name The hook name (in camel case).
     * @param bool $filter Filter first arg in each execution.
     * @param mixed ...$args Arguments to pass.
     * 
     * @return mixed Last execution response or value filtered. Null on exceptions.
     */
    public function executeHook($name, $filter = false, ...$args)
    {
        try {
            // Use snake case to search event
            $eventName = ltrim(strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/', '_$0', $name)), '_');

            // Get registered observers and first arg to return as default
            $observers = $this->eventConfig->getObservers($eventName) ?: [];
            $value     = $filter ? reset($args) : false;

            foreach ($observers as $observerData) {
                // Instance observer
                $instanceMethod = !empty($observerData['shared']) ? 'get' : 'create';
                $observer       = $this->observerFactory->$instanceMethod($observerData['instance']);

                // Get method to execute
                $method = [$observer, $name];

                // Only execute if is callable
                if (!empty($observerData['disabled']) || !is_callable($method))
                    continue;

                $value = call_user_func_array($method, $args);

                if ($filter)
                    $args[0] = $value;
            }

            return $value;
        } catch (\Exception $e) {
            $this->logger->debug('err', 'Mobbex Hook Error: ', $e->getMessage());
        }
    }

}
