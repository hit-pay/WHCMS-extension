<?php
/**
 * WHMCS HitPay Payment Gateway Module
 *
 * HitPay Payment Gateway Plugin allows merchants to accept PayNow QR, Cards, Apple Pay, Google Pay, WeChatPay, AliPay and GrabPay Payments.
 *
 * @copyright Copyright (c) WHMCS Limited 2023
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

require_once __DIR__ . '/hitpay/helper.php';
require_once __DIR__ . '/hitpay/vendor/autoload.php';

use WHMCS\Database\Capsule;
use WHMCS\Modules\Gateways\Hitpay\Helper as HitpayHelper;
use HitPay\Client as HitpayClient;
 
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

define('HITPAY_VERSION', '1.1');

/**
 * Define module related meta data.
 *
 * @return array
 */
function hitpay_MetaData()
{
    return array(
        'DisplayName' => 'Sellix Pay',
        'APIVersion' => '1.1',
        'DisableLocalCredtCardInput' => false,
        'TokenisedStorage' => false,
    );
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * @return array
 */
function hitpay_config()
{
    if (HITPAY_VERSION == '1.1') {
        HitpayHelper::createHitpayDbTable();
        HitpayHelper::createHitpayRecurringPlanDbTable();
        HitpayHelper::createHitpayRecurringTransactionDbTable();
    }
    
    $inputs = array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'HitPay Payment Gateway',
        ),
        'live_mode' => array(
            'FriendlyName' => 'Live Mode',
            'Type' => 'yesno',
            'Description' => 'Use this module in live mode',
        ),
        'api_key' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Description' => 'Please enter your HitPay API Key',
        ),
        'salt' => array(
            'FriendlyName' => 'Salt',
            'Type' => 'text',
            'Description' => 'Please enter your HitPay API Salt',
        ),
    );
    
    $inputs['debug'] = array(
        'FriendlyName' => 'Debug mode',
        'Type' => 'yesno',
    );
    
    return $inputs;
}

function hitpay_link($params) {
    global $_LANG;

    $htmlOutput = '';
    if (isset($params['invoiceid']) && $params['invoiceid'] > 0) {
        
        $clientArea = new WHMCS\ClientArea();
        $pageName = $clientArea->getCurrentPageName();
        if ($pageName == 'viewinvoice' && isset($_REQUEST['type']) && ($_REQUEST['type'] == 'recurring') ) {
            $invoicestatus = HitpayHelper::getInvoiceStatus($params['invoiceid']);
            if (strtolower($invoicestatus) == 'unpaid') {
                $htmlOutput .= '<h6 style="padding: 10px 10px 10px 10px;background-color: #f3f3f3; border: 1px solid #369;  border-radius: 4px; text-align: left; line-height: 25px;">'
                        . 'Payment confirmation is not received from the gateway yet.<br/>'
                        . 'We will update the invoice status and send you the notification as soon as we receive the payment status.'
                        . '</h6>';
            }
        }
        
        $payment_url = HitpayHelper::getUrl($params['systemurl'], '/modules/gateways/callback/hitpay/pay.php', '');
        $htmlOutput .= '<form action="' . $payment_url . '">';
        $htmlOutput .= '<input type="hidden" name="invoiceid" value="'.$params['invoiceid'].'" />';
        $htmlOutput .= '<input class="btn btn-primary" type="submit" value="' . $params['langpaynow'] . '" />';
        $htmlOutput .= '</form>';
    }
    return $htmlOutput;
}

function hitpay_refund($params)
{
    $response = array();
    try {
        $hitpayClient = new HitpayClient(
            $params['api_key'],
            HitpayHelper::getTrueOrFalse($params['live_mode'])
        );

        $transaction_id = $params['transid'];
        $amount = $params['amount'];

        $result = $hitpayClient->refund($transaction_id, $amount);
        $response['status'] = 'success';
        $response['rawdata'] = (array)$result;
        $response['transid'] = $result->getId();  
        
    } catch (\Exception $e) {
        $error = 'Refund Payment Failed: '.$e->getMessage();
        $response['status'] = 'error';
        $response['rawdata'] = $error;
    }
 
    return $response;
}

function hitpay_cancelSubscription($params)
{
    $response = [];
    HitpayHelper::log($params['name'], $params["subscriptionID"], 'HitPay Module Cancel Subscription Triggered');
    try {
        $id = $params["subscriptionID"];
        if (!empty($id)) {
            $hitpayClient = new HitpayClient(
                $params['api_key'],
                HitpayHelper::getTrueOrFalse($params['live_mode'])
            );
            $result = $hitpayClient->cancelSubscription($id);
            HitpayHelper::log($params['name'], (array)$result, 'Cancel Recurring Billing Response:', $params['debug']);

            if (!empty($result->getId())) {
                $response['status'] = 'success';
                $response['rawdata'] = 'Cancelled Successfully';
                HitpayHelper::updateRecurringTransaction($id, 'status', 'canceled');
            } else {
                throw new \Exception('Cancel Subscription failed.');
            }
        }
    } catch(\Exception $e) {
        $response['status'] = 'error';
        $response['rawdata'] = 'Hitpay Cancel Subscription error: '.$e->getMessage();
        HitpayHelper::log($params['name'], $e->getMessage(), 'Hitpay Cancel Subscription error');
    }
    return $response;
}