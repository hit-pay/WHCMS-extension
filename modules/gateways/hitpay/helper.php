<?php
/**
 * WHMCS HitPay Payment Gateway Module Helper
 *
 * @copyright Copyright (c) WHMCS Limited 2023
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

namespace WHMCS\Modules\Gateways\Hitpay;

use WHMCS\Database\Capsule;

class Helper
{
    public static function getUrl($baseurl, $route, $querystring)
    {
        $baseurl = rtrim($baseurl, '/');
        $url = $baseurl.$route.$querystring;
        return $url;
    }
    
    public static function getSanitizedInteger($value)
    {
        $value = trim($value);
        $value = strip_tags($value);
        return (int)$value;
    }
    
    public static function getSanitizedText($value)
    {
        $value = trim($value);
        $value = strip_tags($value);
        return $value;
    }
    
    public static function getTrueOrFalse($value)
    {
        $status = false;
        if ($value == 'on') {
            $status = true;
        }
        return $status;
    }
    
    public static function redirect($url)
    {
        header('Location:'.$url);
        echo '<script>location.href = "'.$url.'";</script>';
        exit;
    }

    public static function log($gatewayName, $debugData, $transactionStatus, $debug='on')
    {
        if ($debug == 'on') {
            logTransaction($gatewayName, $debugData, $transactionStatus);
        }
    }
    
    public static function createHitpayDbTable()
    {
        if (!Capsule::schema()->hasTable('hitpay_orders')) {
            try {
                Capsule::schema()->create(
                    'hitpay_orders',
                    function ($table) {
                        $table->increments('id');
                        $table->integer('invoiceid');
                        $table->string('payment_url');
                        $table->string('transaction_id');
                        $table->string('status');
                        $table->text('response');
                    }
                );
            }
            catch (\Exception $e) { }
        }
    }

    public static function updateHitpayOrder($invoiceid, $column, $value)
    {
        if (!empty($value)) {
            try {
                $query = Capsule::table("hitpay_orders")->where("invoiceid", $invoiceid);
                if (!empty($query->value('id'))) {
                    $query->update(array($column => $value));
                } else {
                    Capsule::table("hitpay_orders")->insert(
                        array(
                            'invoiceid'=>$invoiceid,
                            $column => $value
                        )
                    );
                }
            }
            catch (\Exception $e) { }
        }
    }

    public static function getHitpayOrderByColumn($invoiceid, $column)
    {
        try {
            return Capsule::table("hitpay_orders")->where("invoiceid", $invoiceid)->value($column);
        }
        catch (\Exception $e) { 
            return false;
        }
    }

    public static function getUserLastInvoiceId($userid)
    {
        try {
            return Capsule::table("tblinvoices")->where("userid", $userid)->orderBy('id', 'desc')->limit(1)->value('id');
        }
        catch (\Exception $e) { 
            return false;
        }
    }
    
    public static function getInvoiceStatus($invoiceid)
    {
        try {
            return Capsule::table("tblinvoices")->where("id", $invoiceid)->value('status');
        }
        catch (\Exception $e) { 
            return false;
        }
    }
    
    public static function getPaymentResponseSingle($invoiceid, $key)
    {
        $response = self::getHitpayOrderByColumn($invoiceid, 'response');
        if ($response) {
            $result = json_decode($response, true);
            if (isset($result[$key])) {
                return $result[$key];
            }
        }
        return false;
    }
    
    public static function addPaymentResponse($invoiceid, $response)
    {
        $metaData = self::getHitpayOrderByColumn($invoiceid, 'response');
        if (!empty($metaData)) {
            $metaData = json_decode($response, true);
            foreach ($metaData as $key => $val) {
                self::updatePaymentData($invoiceid, $key, $val);
            }
        } else {
            self::updateHitpayOrder($invoiceid, 'response', $response);
        }
    }
    
    public static function updatePaymentData($invoiceid, $param, $value)
    {
        $metaData = self::getHitpayOrderByColumn($invoiceid, 'response');
        if (!empty($metaData)) {
            $metaData = json_decode($metaData, true);
            $metaData[$param] = $value;
            $paymentData = json_encode($metaData);
            
            self::updateHitpayOrder($invoiceid, 'response', $paymentData);
        }
    }
    
    public static function deletePaymentData($invoiceid, $param)
    {
        $metaData = self::getHitpayOrderByColumn($invoiceid, 'response');
        if (!empty($metaData)) {
            $metaData = json_decode($metaData, true);
            if (isset($metaData[$param])) {
                unset($metaData[$param]);
            }
            $paymentData = json_encode($metaData);
            
            self::updateHitpayOrder($invoiceid, 'response', $paymentData);
        }
    }
    
    public static function displayErrorContent($title, $message, $url, $seconds = 5)
    {
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <title>'.$title.'</title>
        </head>
        <body>
            <h3 style="text-align:center;color:red;">
            '.$message.'
            </h3>
            <p style="text-align:center;"> 
              You will be redirected to <a href="'.$url.'">'.$url.'</a> in <span id="timer">'.$seconds.'</span> seconds.
            </p>
            <script>
                var targetURL = "'.$url.'"
                var currentsecond = parseInt('.$seconds.');
                function countredirect(){
                    if (currentsecond !=1 ){
                        currentsecond -= 1;
                        document.getElementById("timer").innerHTML = currentsecond;
                    }
                    else{
                        window.location=targetURL;
                        return;
                    }
                    setTimeout("countredirect()",1000);
                }
                countredirect();
            </script>
        </body>
        </html>';
        echo $html;
        exit;
        
    }
    
    public static function createHitpayRecurringPlanDbTable()
    {
        if (!Capsule::schema()->hasTable('hitpay_recurring_plans')) {
            try {
                Capsule::schema()->create(
                    'hitpay_recurring_plans',
                    function ($table) {
                        $table->increments('id');
                        $table->string('plan_id');
                        $table->string('plan_name');
                        $table->string('cycle');
                        $table->string('currency');
                        $table->string('amount');
                        $table->string('reference')->nullable();
                        $table->text('response')->nullable();
                    }
                );
            }
            catch (\Exception $e) { }
        }
    }
    
    public static function createHitpayRecurringTransactionDbTable()
    {
        if (!Capsule::schema()->hasTable('hitpay_recurring_transactions')) {
            try {
                Capsule::schema()->create(
                    'hitpay_recurring_transactions',
                    function ($table) {
                        $table->increments('id');
                        $table->integer('invoiceid');
                        $table->string('plan_id');
                        $table->string('recurring_id');
                        $table->string('customer_name')->nullable();
                        $table->string('customer_email')->nullable();
                        $table->string('cycle')->nullable();
                        $table->string('currency')->nullable();
                        $table->string('amount')->nullable();
                        $table->string('status')->nullable();
                        $table->string('payment_method')->nullable();
                        $table->string('created_at')->nullable();
                        $table->string('updated_at')->nullable();
                        $table->string('expires_at')->nullable();
                        $table->text('response')->nullable();
                        $table->string('payment_id')->nullable();
                    }
                );
            }
            catch (\Exception $e) { }
        }
    }
    
    public static function getValidName($name)
    {
        $name = str_replace(['https://', 'http://'], '', $name);
        $name = rtrim($name, "/");
        $name = preg_replace('/[^A-Za-z0-9\_]/','_', $name);
        return $name;
    }
    
    public static function getCycle($recurrings)
    {
        $cycle = '';
        $recurringcycleperiod = $recurrings['recurringcycleperiod'];
        $recurringcycleunits = strtolower($recurrings['recurringcycleunits']);
        if ($recurringcycleunits == 'months') {
            if ($recurringcycleperiod == 1) {
                $cycle = 'monthly';
            } else {
                $cycle = 'custom';
            }
        } else if ($recurringcycleunits == 'years') {
            if ($recurringcycleperiod == 1) {
                $cycle = 'yearly';
            } else {
                $cycle = 'custom';
            }
        } else if ($recurringcycleunits == 'weeks') {
            if ($recurringcycleperiod == 1) {
                $cycle = 'weekly';
            } else {
                $cycle = 'custom';
            }
        } else if ($recurringcycleunits == 'days') {
                $cycle = 'custom';
        }
        return $cycle;
    }
    
    public static function getCustomCycle($recurrings)
    {
        $cycle = array();
        $recurringcycleperiod = $recurrings['recurringcycleperiod'];
        $recurringcycleunits = strtolower($recurrings['recurringcycleunits']);
        if ($recurringcycleunits == 'months') {
            $cycle['repeat'] = $recurringcycleperiod;
            $cycle['frequency'] = 'month';
        } else if ($recurringcycleunits == 'years') {
            $cycle['repeat'] = $recurringcycleperiod;
            $cycle['frequency'] = 'year';
        } else if ($recurringcycleunits == 'days') {
            $cycle['repeat'] = $recurringcycleperiod;
            $cycle['frequency'] = 'day';
        } else if ($recurringcycleunits == 'weeks') {
            $cycle['repeat'] = $recurringcycleperiod;
            $cycle['frequency'] = 'week';
        }
        return $cycle;
    }
    
    public static function getCycleDisplayName($recurrings)
    {
        $cycle = '';
        $recurringcycleperiod = $recurrings['recurringcycleperiod'];
        $recurringcycleunits = strtolower($recurrings['recurringcycleunits']);
        if ($recurringcycleunits == 'months') {
            if ($recurringcycleperiod == 1) {
                $cycle = 'monthly';
            } else if ($recurringcycleperiod == 3) {
                $cycle = 'quarterly';
            } else  if ($recurringcycleperiod == 6) {
                $cycle = 'semiannually';
            } else {
                $cycle = 'once_every_'.$recurringcycleperiod.'_months';
            }
        } else if ($recurringcycleunits == 'years') {
            if ($recurringcycleperiod == 1) {
                $cycle = 'yearly';
            } else {
                $cycle = 'every_'.$recurringcycleperiod.'_years';
            }
        }
        return $cycle;
    }
    
    public static function getSubscriptionPlanName($params,$recurrings)
    {
        $amount = $recurrings['recurringamount'];
        $cycle = self::getCycleDisplayName($recurrings);
        $currency = $params['currency'];
        $domain = self::getValidName($params['systemurl']);
        $amount = str_replace(['.', ','], '_', $amount);
        $name = $domain.'_Invoice_'.$amount.'_'.$currency.'_'.$cycle;
        return $name;
    }
    
    public static function getSubscriptionPlanReference($params,$recurrings)
    {
        return self::getSubscriptionPlanName($params,$recurrings);
    }
    
    public static function getSubscriptionPlanFromDb($plan_name, $column='')
    {
        try {
            $result = Capsule::table("hitpay_recurring_plans")->where("plan_name", $plan_name);
            if (!empty($result->value('id'))) {
                if (!empty($column)) {
                    $result = $result->value($column);
                }
                return $result;
            }
            return false;
        }
        catch (\Exception $e) { 
            return false;
        }
    }
    
    public static function addSubscriptionPlanToDb($result)
    {
        try {
            Capsule::table("hitpay_recurring_plans")->insert(
                array(
                    'plan_id' =>  $result->getId(),
                    'plan_name' => $result->getName(),
                    'cycle' => $result->getCycle(),
                    'currency' => $result->getCurrency(),
                    'amount' => $result->getAmount(),
                    'reference' => $result->getReference(),
                    'response' => json_encode((array)$result)
                )
            );
        }
        catch (\Exception $e) {
            return false;
        }
    }
    
    public static function getRecurringTransactionFromDb($recurring_id, $column='')
    {
        try {
            $result = Capsule::table("hitpay_recurring_transactions")->where("recurring_id", $recurring_id);
            if (!empty($result->value('id'))) {
                if (!empty($column)) {
                    $result = $result->value($column);
                }
                return $result;
            }
            return false;
        }
        catch (\Exception $e) { 
            return false;
        }
    }
    
    public static function getRecurringTransactionFromDbByInvoiceId($invoiceid, $column='')
    {
        try {
            $result = Capsule::table("hitpay_recurring_transactions")->where("invoiceid", $invoiceid);
            if (!empty($result->value('id'))) {
                if (!empty($column)) {
                    $result = $result->value($column);
                }
                return $result;
            }
            return false;
        }
        catch (\Exception $e) { 
            return false;
        }
    }
    
    public static function updateRecurringTransaction($recurring_id, $column, $value)
    {
        if (!empty($value)) {
            try {
                $query = Capsule::table("hitpay_recurring_transactions")->where("recurring_id", $recurring_id);
                if (!empty($query->value('id'))) {
                    $query->update(array($column => $value));
                } else {
                    Capsule::table("hitpay_recurring_transactions")->insert(
                        array(
                            'recurring_id' => $recurring_id,
                            $column => $value
                        )
                    );
                }
            }
            catch (\Exception $e) {
                return false;
            }
        }
    }
    
    public static function updateRecurringTransactionToDb($recurring_id, $result, $invoiceid='')
    {
        try {
            $query = Capsule::table("hitpay_recurring_transactions")->where("recurring_id", $recurring_id);
            if (!empty($query->value('id'))) {
                $query->update(
                    array(
                        'invoiceid' =>  $invoiceid,
                        'plan_id' =>  $result->getBusinessRecurringPlansId(),
                        'customer_name' => $result->getCustomerName(),
                        'customer_email' => $result->getCustomerEmail(),
                        'cycle' => $result->getCycle(),
                        'currency' => $result->getCurrency(),
                        'amount' => $result->getAmount(),
                        'status' => $result->getStatus(),
                        'created_at' => $result->getCreatedAt(),
                        'updated_at' => $result->getUpdatedAt(),
                        'expires_at' => $result->getExpiresAt(),
                        'payment_method' => json_encode($result->getPaymentMethods()),
                        'response' => json_encode((array)$result)
                    )
                );
            } else {
                Capsule::table("hitpay_recurring_transactions")->insert(
                    array(
                        'invoiceid' =>  $invoiceid,
                        'recurring_id' =>  $result->getId(),
                        'plan_id' =>  $result->getBusinessRecurringPlansId(),
                        'customer_name' => $result->getCustomerName(),
                        'customer_email' => $result->getCustomerEmail(),
                        'cycle' => $result->getCycle(),
                        'currency' => $result->getCurrency(),
                        'amount' => $result->getAmount(),
                        'status' => $result->getStatus(),
                        'created_at' => $result->getCreatedAt(),
                        'updated_at' => $result->getUpdatedAt(),
                        'expires_at' => $result->getExpiresAt(),
                        'payment_method' => json_encode($result->getPaymentMethods()),
                        'response' => json_encode((array)$result)
                    )
                );
            }
        }
        catch (\Exception $e) {
            return false;
        }
    }
}
