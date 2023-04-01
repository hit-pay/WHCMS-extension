<?php
/**
 * WHMCS HitPay Payment Gateway Module Return Page
 *
 * @copyright Copyright (c) WHMCS Limited 2023
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../../../../modules/gateways/hitpay.php';

use WHMCS\Modules\Gateways\Hitpay\Helper as HitpayHelper;

define("CLIENTAREA", true);
define("FORCESSL", true);  // Force https

global $CONFIG;

$gatewayModuleName = 'hitpay';

$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$systemUrl = $gatewayParams['systemurl'];
$redirectUrl = $systemUrl;

try {
    if (isset($_REQUEST["invoiceid"]) && !empty($_REQUEST["invoiceid"])) {
        $invoiceid = HitpayHelper::getSanitizedInteger($_REQUEST["invoiceid"]);
        $invoiceid = checkCbInvoiceID($invoiceid, $gatewayParams['name']);
        
        $redirectUrl = HitpayHelper::getUrl($systemUrl, '/viewinvoice.php', '?id='.$invoiceid);

        if (isset($_REQUEST['status']) &&  ($_REQUEST['status'] == 'canceled')) {
             throw new \Exception('Transaction canceled by customer/gateway. ');
        }
        
        if (!isset($_REQUEST['reference'])) {
            throw new \Exception('Transaction references check failed. ');
        }

        HitpayHelper::redirect($redirectUrl.'&type=recurring');
    } else {
        throw new \Exception('Empty response received from gateway.');
    }
} catch (\Exception $e) {
    $error_message = $e->getMessage();
    HitpayHelper::log($gatewayParams['name'], $error_message, 'Return from gateway', $gatewayParams['debug']);
    $message = 'An error occurred while returning from payment gateway: '.$error_message;
    HitpayHelper::displayErrorContent('Returned from HitPay Payment Gateway', $message, $redirectUrl, 5);
}
