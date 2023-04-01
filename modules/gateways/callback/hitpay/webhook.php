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
    HitpayHelper::log($gatewayParams['name'], $params, 'Webhook Triggered', $gatewayParams['debug']);

    if ((null === $_REQUEST['invoiceid'])  || empty($_REQUEST["invoiceid"]) || (null === $params['hmac'])) {
        $message = 'Hitpay: Suspected fraud. Code-001';
        throw new \Exception($message);
    }
    
    $invoiceid = HitpayHelper::getSanitizedInteger($_REQUEST["invoiceid"]);
    $invoiceid = checkCbInvoiceID($invoiceid, $gatewayParams['name']);
    
    $gatewayParams = getGatewayVariables($gatewayModuleName, $invoiceid);
    
    $HitPay_payment_id = HitpayHelper::getPaymentResponseSingle($invoiceid, 'payment_id');
    if (!$HitPay_payment_id || empty($HitPay_payment_id)) {
        $message = 'Hitpay: Saved payment not valid. Code-002';
        throw new \Exception($message);
    }
    
    unset($data['hmac']);
    
    $salt = $gatewayParams['salt'];
    if (Client::generateSignatureArray($salt, $data) == $params['hmac']) {
        $HitPay_is_paid = HitpayHelper::getPaymentResponseSingle($invoiceid, 'is_paid');
        if ($HitPay_is_paid != 1) {
            $status = HitpayHelper::getSanitizedText($params['status']);
            $transactionId = HitpayHelper::getSanitizedText($params['payment_id']);
            $payment_request_id = HitpayHelper::getSanitizedText($params['payment_request_id']);
            if (
                $status == 'completed'
                && $invoiceid == $params['reference_number']
                && $gatewayParams['currency'] == $params['currency']
            ) {
                addInvoicePayment($invoiceid,$transactionId,$params['amount'],0,$gatewayModuleName);
                HitpayHelper::updatePaymentData($invoiceid, 'transaction_id', $transactionId);
                HitpayHelper::updatePaymentData($invoiceid, 'payment_request_id', $payment_request_id);
                HitpayHelper::updatePaymentData($invoiceid, 'is_paid', 1);
                HitpayHelper::updatePaymentData($invoiceid, 'status', $status);
            } else {
                HitpayHelper::updatePaymentData($invoiceid, 'transaction_id', $transactionId);
                HitpayHelper::updatePaymentData($invoiceid, 'is_paid', 2);
                HitpayHelper::updatePaymentData($invoiceid, 'status', $status);
                $message = 'Hitpay: Status, Ref, and Currency match condition is failed. Code-004';
                throw new \Exception($message);
            }
        } else {
            $message = 'Hitpay: This invoice is already paid. Code-005';
            throw new \Exception($message);
        }
    } else {
        $message = 'Hitpay: hmac is not the same like generated. Code-003';
        throw new \Exception($message);
    }
} catch (\Exception $e) {
    $error_message = $e->getMessage();
    $message = 'Payment error. '.$error_message;
    HitpayHelper::log($gatewayParams['name'], $message, 'Webhook Catch', $gatewayParams['debug']);
    echo $message;
    exit;
}
echo 'Web hook finished';
exit;
