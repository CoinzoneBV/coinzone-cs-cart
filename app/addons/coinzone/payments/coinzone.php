<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

if (defined('PAYMENT_NOTIFICATION')) {
    $headers = getallheaders();
    $content = file_get_contents("php://input");
    $input = json_decode($content);

    if (fn_check_payment_script('coinzone.php', $input->merchantReference)) {
        $order_info = fn_get_order_info($input->merchantReference);
        $processor_data = fn_get_processor_data($order_info['payment_id']);

        $schema = isset($_SERVER['HTTPS']) ? "https://" : "http://";
        $currentUrl = $schema . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        $stringToSign = $content . $currentUrl . $headers['timestamp'];
        $signature = hash_hmac('sha256', $stringToSign, $processor_data['processor_params']['apiKey']);
        if ($signature !== $headers['signature']) {
            header("HTTP/1.0 400 Bad Request");
            exit("Invalid callback");
        }

        switch($input->status) {
            case "PAID":
            case "COMPLETE":
                $response = array();
                $response["order_status"] = 'P';
                $response["reason_text"] = 'Completed';
                fn_finish_payment($input->merchantReference, $response);
                exit('OK_PAID');
                break;
        }

    }
    header("HTTP/1.0 400 Bad Request");
    exit("Error");
} else {
    $response = _coinzoneTransaction(
        $order_info,
        $processor_data['processor_params']['clientCode'],
        $processor_data['processor_params']['apiKey']
    );
    if ($response->status->code !== 201) {
        var_dump($response);
        die('Error. Cannot proceed to payment. Please try again');
    }

    fn_create_payment_form($response->response->url, array(), 'Coinzone', false);
}

function _coinzoneTransaction($orderInfo, $clientCode, $apiKey) {
    $url = 'https://api.coinzone.com/v2/transaction';

    $payload = array(
        'amount' => $orderInfo['total'],
        'currency' => $orderInfo['secondary_currency'],
        'merchantReference' => $orderInfo['order_id'],
        'email' => $orderInfo['email'],
        'notificationUrl' => fn_url("payment_notification.coinzone&payment=coinzone", AREA, 'current'),
    );

    $payload = json_encode($payload);

    $timestamp = time();
    $stringToSign = $payload . $url . $timestamp;
    $signature = hash_hmac('sha256', $stringToSign, $apiKey);

    $headers = array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
        'clientCode: ' . $clientCode,
        'timestamp: ' . $timestamp,
        'signature: ' . $signature
    );

    $curlHandler = curl_init($url);
    curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlHandler, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curlHandler, CURLOPT_POSTFIELDS, $payload);

    $result = curl_exec($curlHandler);
    if ($result === false) {
        return false;
    }
    $response = json_decode($result);
    curl_close($curlHandler);

    return $response;
}
