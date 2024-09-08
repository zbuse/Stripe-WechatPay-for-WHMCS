<?php
use Stripe\StripeClient;
use Stripe\Webhook;


require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayName = 'stripewechatpay';
$gatewayParams = getGatewayVariables($gatewayName);
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$stripe = new Stripe\StripeClient($gatewayParams['StripeSkLive']);

if (isset($_POST['check'])) {
  	$sessionKey = 'pi_id' . $_POST['check'];
	$paymentId = $_SESSION[$sessionKey];
}
else {
if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    die("错误请求");
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
    $event = Webhook::constructEvent(
        $payload, $sig_header, $gatewayParams['StripeWebhookKey']
    );
    $paymentId = $event->data->object->id;
} catch(\UnexpectedValueException $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, $gatewayName.': Invalid payload');
    http_response_code(400);
    exit();
} catch(Stripe\Exception\SignatureVerificationException $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, $gatewayName.': Invalid signature');
    http_response_code(400);
    exit();
}
}

try {
        $paymentIntent = $stripe->paymentIntents->retrieve($paymentId,[]);
        if ($paymentIntent->status == 'succeeded') {
            $invoiceId = checkCbInvoiceID($paymentIntent['metadata']['invoice_id'], $gatewayParams['paymentmethod']);
			checkCbTransID($paymentId);
       //     echo "Pass the checkCbTransID check\n";
            logTransaction($gatewayParams['paymentmethod'], $paymentIntent, $gatewayName.': Callback successful');
            addInvoicePayment(
                $invoiceId,
                $paymentId,
                $paymentIntent['metadata']['original_amount'],
                0,
                $params['paymentmethod']
            );
//            echo "succeeded\n";
	}
	    echo json_encode(['status' => $paymentIntent->status ]);
    
} catch (Exception $e) {
    logTransaction($gatewayParams['paymentmethod'], $e, 'error-callback');
    http_response_code(400);
    echo $e;
}



