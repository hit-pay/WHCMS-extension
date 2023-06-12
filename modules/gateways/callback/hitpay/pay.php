<?php
/**
 * WHMCS Sellix Pay Payment Gateway Module Return Page
 *
 * @copyright Copyright (c) WHMCS Limited 2023
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../../../../modules/gateways/hitpay.php';

use HitPay\Client as HitpayClient;
use HitPay\Request\CreatePayment as HitpayCreatePayment;
use WHMCS\Modules\Gateways\Hitpay\Helper as HitpayHelper;
use HitPay\Request\CreateSubscriptionPlan as HitpayCreateSubscriptionPlan;
use HitPay\Request\RecurringBilling as HitpayRecurringBilling;

$gatewayModuleName = 'hitpay';

$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$systemUrl = $gatewayParams['systemurl'];
$redirectUrl = $systemUrl;

try {
    if (isset($_REQUEST["invoiceid"]) && !empty($_REQUEST["invoiceid"])  && ($_REQUEST["invoiceid"] > 0) ) {
        $invoiceid = HitpayHelper::getSanitizedInteger($_REQUEST["invoiceid"]);
        $invoiceid = checkCbInvoiceID($invoiceid, $gatewayParams['name']);
        
        $redirectUrl = HitpayHelper::getUrl($systemUrl, '/viewinvoice.php', '?id='.$invoiceid);
        
        $gatewayParams = getGatewayVariables($gatewayModuleName, $invoiceid);
        
        $hitpayClient = new HitpayClient(
            $gatewayParams['api_key'],
            HitpayHelper::getTrueOrFalse($gatewayParams['live_mode'])
        );
        
        $recurrings = getRecurringBillingValues($invoiceid);
        
        $subnotpossible = false;
        if (!$recurrings) {
            $subnotpossible = true;
        } else {
            if (false){//isset($recurrings["overdue"]) && $recurrings["overdue"]) {
                $subnotpossible = true;
            } else {
                $recurringamount = $recurrings["recurringamount"];
                $recurringcycleperiod = $recurrings["recurringcycleperiod"];
                $recurringcycleunits = strtoupper(substr($recurrings["recurringcycleunits"], 0, 1));
                if ($recurringamount <= 0) {
                    $subnotpossible = true;
                }
            }
        }

        if (!$subnotpossible) {//recurring
            /* Check if plan id exist for the current product */
            $plan_name = HitpayHelper::getSubscriptionPlanName($gatewayParams, $recurrings);
            $plan_id = HitpayHelper::getSubscriptionPlanFromDb($plan_name, 'plan_id');
            $reference = $plan_name;
            $cycle = HitpayHelper::getCycle($recurrings);

            if (!$plan_id) { // Plan does not exist
                $createSubscriptionPlanRequest = new HitpayCreateSubscriptionPlan();
                $createSubscriptionPlanRequest->setAmount($gatewayParams['amount'])
                    ->setCurrency($gatewayParams['currency'])
                    ->setReference($reference)
                    ->setName($plan_name)
                    ->setCycle($cycle);
                
                if ($cycle == 'custom') {
                    $customCycle = HitpayHelper::getCustomCycle($recurrings);
                    $createSubscriptionPlanRequest->setCycleFrequency($customCycle['frequency']);
                    $createSubscriptionPlanRequest->setCycleRepeat($customCycle['repeat']); 
                }
                
                HitpayHelper::log($gatewayParams['name'], (array)$createSubscriptionPlanRequest, 'Create Recurring Plan Request:', $gatewayParams['debug']);
                $result = $hitpayClient->createSubscriptionPlan($createSubscriptionPlanRequest);
                HitpayHelper::log($gatewayParams['name'], (array)$result, 'Create Recurring Plan Response:', $gatewayParams['debug']);

                if (!empty($result->getId())) {
                    HitpayHelper::addSubscriptionPlanToDb($result);
                    $plan_id = trim($result->getId());
                } else {
                    throw new \Exception('Plan failed to create. Please contact the merchant with code WCSP001.');
                }
            }
            
            $returnUrl = HitpayHelper::getUrl($systemUrl, '/modules/gateways/callback/hitpay/recurringreturn.php', '?invoiceid='.$invoiceid);
            $webhookUrl = HitpayHelper::getUrl($systemUrl, '/modules/gateways/callback/hitpay/recurringwebhook.php', '?invoiceid='.$invoiceid);
            
            $from = new DateTimeZone('GMT');
            $to = new DateTimeZone('Asia/Singapore');
            $currDate = new DateTime('now', $from);
            $currDate->setTimezone($to);
            $start_date = $currDate->format('Y-m-d');

            $create_recurring_billing = new HitpayRecurringBilling();
            $create_recurring_billing->setPlanId($plan_id)
                ->setStartDate($start_date)
                ->setReference($invoiceid)
                ->setWebhook($webhookUrl)
                ->setRedirectUrl($returnUrl)
                ->setCustomerName($gatewayParams['clientdetails']['firstname'] . ' ' . $gatewayParams['clientdetails']['lastname'])
                ->setCustomerEmail($gatewayParams['clientdetails']['email'])
            ;
            
            HitpayHelper::log($gatewayParams['name'], (array)$create_recurring_billing, 'Create Recurring Billing Request:', $gatewayParams['debug']);
            $result = $hitpayClient->recurringBilling($create_recurring_billing);
            HitpayHelper::log($gatewayParams['name'], (array)$result, 'Create Recurring Billing Response:', $gatewayParams['debug']);

            if (!empty($result->getId())) {
                HitpayHelper::updateRecurringTransactionToDb($result->getId(), $result, $invoiceid);
                HitpayHelper::redirect($result->getUrl());
            } else {
                throw new \Exception('Recurring Billing failed. Please contact the merchant with code WCRB001.');
            }
        } else { //one time
            $returnUrl = HitpayHelper::getUrl($systemUrl, '/modules/gateways/callback/hitpay/return.php', '?invoiceid='.$invoiceid);
            $webhookUrl = HitpayHelper::getUrl($systemUrl, '/modules/gateways/callback/hitpay/webhook.php', '?invoiceid='.$invoiceid);

            $createPaymentRequest = new HitpayCreatePayment();
            $createPaymentRequest->setAmount($gatewayParams['amount'])
                ->setCurrency($gatewayParams['currency'])
                ->setReferenceNumber($invoiceid)
                ->setWebhook($webhookUrl)
                ->setRedirectUrl($returnUrl);
                //->setChannel('api_whmcs');

            $createPaymentRequest->setName($gatewayParams['clientdetails']['firstname'] . ' ' . $gatewayParams['clientdetails']['lastname']);
            $createPaymentRequest->setEmail($gatewayParams['clientdetails']['email']);

            $createPaymentRequest->setPurpose($gatewayParams['companyname']);

            HitpayHelper::log($gatewayParams['name'], (array)$createPaymentRequest, 'Create Payment Request:', $gatewayParams['debug']);

            $result = $hitpayClient->createPayment($createPaymentRequest);

            HitpayHelper::log($gatewayParams['name'], (array)$result, 'Create Payment Response:', $gatewayParams['debug']);

            $savePayment = [
                'payment_id' => $result->getId(),
                'amount' => $gatewayParams['amount'],
                'currency_id' => $gatewayParams['currency'],
                'status' => $result->getStatus(),
                'invoiceid' => $invoiceid,
            ];
            HitpayHelper::addPaymentResponse($invoiceid, json_encode($savePayment));

            if ($result->getStatus() == 'pending') {
                HitpayHelper::redirect($result->getUrl());
            } else {
                throw new \Exception(sprintf(__('Status from gateway is %s .'), $result->getStatus()));
            }
        }
    } else {
        throw new \Exception('Invalid Invoice ID');
    }
} catch (\Exception $e) {
    $error_message = $e->getMessage();
    $message = 'An error occurred while creating payment: '.$error_message;
    HitpayHelper::displayErrorContent('Creating Payment Request on HitPay Payment Gateway', $message, $redirectUrl, 5);
}
