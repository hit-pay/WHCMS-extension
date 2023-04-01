<?php
/**
 * WHMCS HitPay Payment Gateway Module Webhook Page
 *
 * @copyright Copyright (c) WHMCS Limited 2023
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../../../../modules/gateways/hitpay.php';

use HitPay\Client;
use WHMCS\Modules\Gateways\Hitpay\Helper as HitpayHelper;

$gatewayModuleName = 'hitpay';
$gatewayParams = getGatewayVariables($gatewayModuleName);

try {
    $data = $_POST;
    $params = $data;
    HitpayHelper::log($gatewayParams['name'], $params, 'Recurring Webhook Triggered', $gatewayParams['debug']);

    if ((null === $_REQUEST['invoiceid'])  || empty($_REQUEST["invoiceid"]) || (null === $params['hmac'])) {
        $message = 'Hitpay: Suspected fraud. Code-001';
        throw new \Exception($message);
    }
    
    $invoiceid = HitpayHelper::getSanitizedInteger($_REQUEST["invoiceid"]);
    $invoiceid = checkCbInvoiceID($invoiceid, $gatewayParams['name']);
    
    $gatewayParams = getGatewayVariables($gatewayModuleName, $invoiceid);
    
    unset($data['hmac']);
    
    $salt = $gatewayParams['salt'];
    if (Client::generateSignatureArray($salt, $data) == $params['hmac']) {
        $status = HitpayHelper::getSanitizedText($params['status']);
        $payment_id = HitpayHelper::getSanitizedText($params['payment_id']);
        $recurring_billing_id = HitpayHelper::getSanitizedText($params['recurring_billing_id']);
        $currency = HitpayHelper::getSanitizedText($params['currency']);
        
        $transactionId = $recurring_billing_id.'__'.$payment_id;
        if (
            $status == 'succeeded'
            && $invoiceid == $params['reference']
            && strtolower($gatewayParams['currency']) == strtolower($currency)
        ) {
            addInvoicePayment($invoiceid,$transactionId,$params['amount'],0,$gatewayModuleName);
            $relid = get_query_val("tblinvoiceitems", "relid", array("invoiceid" => $invoiceid, "type" => "Hosting"));
            if ($relid) {
                update_query("tblhosting", array("subscriptionid" => $recurring_billing_id), array("id" => $relid));
            }
            HitpayHelper::updateRecurringTransaction($recurring_billing_id, 'status', $status);
            HitpayHelper::updateRecurringTransaction($recurring_billing_id, 'payment_id', $payment_id);
            HitpayHelper::log($gatewayParams['name'], 'Successful', 'Hitpay Recurring');
        } else {
            HitpayHelper::updateRecurringTransaction($recurring_billing_id, 'status', $status);
            HitpayHelper::updateRecurringTransaction($recurring_billing_id, 'payment_id', $payment_id);
            $message = 'Hitpay Recurring Failed: Status, Ref, and Currency match condition is failed. Code-004';
            throw new \Exception($message);
        }
    } else {
        $message = 'Hitpay: hmac is not the same like generated. Code-003';
        throw new \Exception($message);
    }
} catch (\Exception $e) {
    $error_message = $e->getMessage();
    $message = 'Payment error. '.$error_message;
    HitpayHelper::log($gatewayParams['name'], $message, 'Hitpay Recurring Webhook Catch');
    echo $message;
    exit;
}
echo 'Web hook finished';
exit;
