<?php
use Stripe\StripeClient;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function stripewechatpay_MetaData()
{
    return array(
        'DisplayName' => 'Stripe Wechat_Pay',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function stripewechatpay_config($params)
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Stripe Wechat_Pay',
        ),
        'StripeSkLive' => array(
            'FriendlyName' => 'SK_LIVE 密钥',
            'Type' => 'text',
            'Size' => 30,
            'Description' => '填写从Stripe获取到的密钥（SK_LIVE）',
        ),
        'StripeWebhookKey' => array(
            'FriendlyName' => 'Webhook 密钥',
            'Type' => 'text',
            'Size' => 30,
            'Description' => "填写从Stripe获取到的Webhook密钥签名< <br><br> <div class='alert alert-success' role='alert' style='margin-bottom: 0px;'>Webhook设置 <a href='https://dashboard.stripe.com/webhooks' target='_blank'><span class='glyphicon glyphicon-new-window'></span> Stripe webhooks</a> 侦听的事件:payment_intent.succeeded <br>
      Stripe webhook " .$params['systemurl']."modules/gateways/stripewechatpay/webhooks.php
               </div><style>* {font-family: Microsoft YaHei Light , Microsoft YaHei}</style>",
        ),
        'StripeCurrency' => array(
            'FriendlyName' => '发起交易货币[默认 CNY]',
            'Type' => 'text',
            'Size' => 30,
	    "Default" => "CNY",
            'Description' => '默认获取WHMCS的货币，与您设置的发起交易货币进行汇率转换，再使用转换后的价格和货币向Stripe请求',
        ),
        'RefundFixed' => array(
            'FriendlyName' => '退款扣除固定金额',
            'Type' => 'text',
            'Size' => 30,
      'Default' => '0.00',
      'Description' => '$'
        ),
        'RefundPercent' => array(
            'FriendlyName' => '退款扣除百分比金额',
            'Type' => 'text',
            'Size' => 30,
      'Default' => '0.00',
      'Description' => "%"
        )
    );
}

function stripewechatpay_link($params)
{
  session_start();
  global $_LANG;
  $originalAmount = isset($params['basecurrencyamount']) ? $params['basecurrencyamount'] : $params['amount']; //解决Convert To For Processing后出现入账金额不对问题
  $StripeCurrency = empty($params['StripeCurrency']) ? "CNY" : $params['StripeCurrency'];
  $amount = abs(ceil($params['amount'] * 100.00));
  $setcurrency = $params['currency'];
  $paymentmethod = $params['paymentmethod'];
  $sessionKey = $paymentmethod . $params['invoiceid'];
  $return_url = $params['systemurl'] . 'viewinvoice.php?paymentsuccess=true&id=' . $params['invoiceid'];
      if (strtoupper($StripeCurrency) !=  strtoupper($setcurrency) ) {
          $exchange = stripewechatpay_exchange( $setcurrency , $StripeCurrency );
      if (!$exchange) {
          return '<div class="alert alert-danger text-center" role="alert">支付汇率错误，请联系客服进行处理</div>';
      }
      $setcurrency = $StripeCurrency;
      $amount = floor($params['amount'] * $exchange * 100.00);
      }

    try {
        $stripe = new Stripe\StripeClient($params['StripeSkLive']);
	$paymentIntent = null;
        $paymentIntentParams = [
        'amount' => $amount,
        'currency' => $setcurrency ,
	'payment_method_types' => ['wechat_pay'],
	'payment_method_options' => ['wechat_pay' => ['client' => 'web']],
        'payment_method' => $stripe->paymentMethods->create(['type' =>'wechat_pay'])->id,
	'description' => $params['companyname'] . $_LANG['invoicenumber'] . $params['invoiceid'],
	'confirm' => true,
	'metadata' => [
          'invoice_id' => $params['invoiceid'],
          'original_amount' => $originalAmount,
          'description' => $params['companyname'],
            ]];

if (isset($_SESSION[$sessionKey])) {
    $paymentIntentId = $_SESSION[$sessionKey];
    $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);
}
else
{
        $paymentIntent = $stripe->paymentIntents->create($paymentIntentParams);
        $_SESSION[$sessionKey] = $paymentIntent->id;
}
    } catch (Exception $e) {
        return '<div class="alert alert-danger text-center" role="alert">支付网关错误，请联系客服进行处理'. $e->getMessage() .'</div>';
    }

	
	if ($paymentIntent->status == 'requires_action') {
    $url = $paymentIntent->next_action->wechat_pay_display_qr_code->image_data_url;
    $transId = $paymentIntent->id;
    $checkPaymentStatusUrl = $params['systemurl'] . "modules/gateways/stripewechatpay/webhooks.php";

    return "
        <img width='200' src='$url'>
        <div id='payment-status'>Checking payment status...</div>
        <script>
        $(document).ready(function() {
            const transId = '$transId'; // 获取 PaymentIntent ID
            const checkPaymentStatusUrl = '$checkPaymentStatusUrl'; // 处理 PaymentIntent 状态的后端 PHP 脚本

            function checkPaymentStatus() {
                $.ajax({
                    url: checkPaymentStatusUrl,
                    method: 'POST',
                    data: { check: transId },
                    success: function(response) {
                        const data = JSON.parse(response);
                        if (data.status !== 'succeeded') {
                            // Payment is not succeeded; retry checking
                            setTimeout(checkPaymentStatus, 5000); // Retry every 5 seconds
                        } else {
                            // Payment succeeded; refresh page or show success message
                            $('#payment-status').text('Payment succeeded! Redirecting...');
                            setTimeout(function() {
                                var urlParams = new URLSearchParams(window.location.search);
                                urlParams.set('paymentsuccess', 'true');
                                window.location.href = window.location.pathname + '?' + urlParams.toString();
                            }, 2000); // Wait 2 seconds before refreshing
                        }
                    },
                    error: function() {
                        $('#payment-status').text('Error checking payment status.');
                    }
                });
            }
            // Start checking payment status
            checkPaymentStatus();
        });
        </script>
    ";
}
    return '<div class="alert alert-danger text-center" role="alert">发生错误，请创建工单联系客服处理</div>';
}
function stripewechatpay_refund($params)
{
    $stripe = new Stripe\StripeClient($params['StripeSkLive']);
    $amount = ($params['amount'] - $params['RefundFixed']) / ($params['RefundPercent'] / 100 + 1);
    try {
        $responseData = $stripe->refunds->create([
            'payment_intent' => $params['transid'],
            'amount' => $amount * 100.00,
            'metadata' => [
                'invoice_id' => $params['invoiceid'],
                'original_amount' => $originalAmount,
            ]
        ]);
        return array(
            'status' => ($responseData->status === 'succeeded' || $responseData->status === 'pending') ? 'success' : 'error',
            'rawdata' => $responseData,
            'transid' => $params['transid'],
            'fees' => $params['amount'],
        );
    } catch (Exception $e) {
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
            'transid' => $params['transid'],
            'fees' => $params['amount'],
        );
    }
}
function stripewechatpay_exchange($from, $to)
{
    try {
        $url = 'https://raw.githubusercontent.com/DyAxy/ExchangeRatesTable/main/data.json';

        $result = file_get_contents($url, false);
        $result = json_decode($result, true);
        return $result['rates'][strtoupper($to)] / $result['rates'][strtoupper($from)];
    } catch (Exception $e) {
        echo "Exchange error: " . $e;
        return "Exchange error: " . $e;
    }
}
