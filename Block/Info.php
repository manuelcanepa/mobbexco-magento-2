<?php

namespace Mobbex\Webpay\Block;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\OrderFactory;
use \Magento\Framework\App\State;

/**
 * Class Info
 *
 * @package Mobbex\Webpay\Block
 */
class Info extends \Magento\Payment\Block\Info
{

    /**
     * @var OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var State
     */
    protected $_state;

    /**
     * Constructor
     *
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        State $state,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_orderFactory = $orderFactory;
        $this->_state = $state;
    }

    /**
     * Prepare information specific to current payment method
     *
     * @param null | array $transport
     * @return DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $data = [];

        $info = $this->getInfo();

        $mobbexData   = $info->getAdditionalInformation("mobbex_data");
        $orderUrl     = $info->getAdditionalInformation('mobbex_order_url');
        $cardInfo     = $info->getAdditionalInformation('mobbex_card_info');
        $cardPlan     = $info->getAdditionalInformation('mobbex_card_plan');

        if (!empty($mobbexData['payment']['id'])) {
            $paymentId = $mobbexData['payment']['id'];
            $data[__("Transaction ID")] = $paymentId;
        }

        if (!empty($cardInfo)) {
            $data[__("Card Information")] = $cardInfo;
        }

        if (!empty($cardPlan)) {
            $data[__("Card Plan")] = $cardPlan;
        }

        // Only show in admin panel
        // It may be necessary to verify 'webapi_rest' also
        if ($this->_state->getAreaCode() === 'adminhtml') {
            if (!empty($orderUrl)) {
                $data[__("Order URL")] = $orderUrl;
            }
            if (!empty($mobbexData['payment']['riskAnalysis']['level'])) {
                $data[__("Risk Analysis")] = $mobbexData['payment']['riskAnalysis']['level'];
            }
        }

        return $transport->setData(array_merge($data, $transport->getData()));
    }
}
