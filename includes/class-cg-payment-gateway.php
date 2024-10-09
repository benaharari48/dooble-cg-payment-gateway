<?php

if (!defined('ABSPATH')) {
    exit; // יציאה אם ניגשו ישירות
}

class WC_Gateway_cg extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'cg';
        $this->method_title = __('תשלום קרדיטגארד', 'woocommerce');
        $this->method_description = __('שער תשלום מותאם אישית עבור WooCommerce באמצעות קרדיטגארד פותח ע"י חברת Dooble.', 'woocommerce');
        $this->has_fields = false;

        // טעינת ההגדרות
        $this->init_form_fields();
        $this->init_settings();

        // הגדרת משתנים שהוגדרו על ידי המשתמש
        $this->title           = $this->get_option('title');
        $this->description     = $this->get_option('description');
        $this->terminal_number = $this->get_option('terminal_number');
        $this->user            = $this->get_option('user');
        $this->mpi_mid         = $this->get_option('mpi_mid');
        $this->password        = $this->get_option('password');
        $this->GoodURL         = $this->get_option('GoodURL');
        $this->ErrorURL        = $this->get_option('ErrorURL');

        // פעולות
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // מאזין תשלומים/וו API
        add_action('woocommerce_api_wc_gateway_cg', array($this, 'check_response'));

        add_action('wp_footer', array($this, 'redirect_after_payment') );

        add_action('woocommerce_thankyou', array($this, 'redirect_after_payment'), 10, 1);





        add_filter('body_class', array($this,'my_custom_body_class'));

        add_action('wp_head', array($this,'my_custom_inline_styles') );


    }

    public function my_custom_body_class($classes) {

        // You can add conditional classes based on certain conditions
        if ( isset($_GET['txId']) ) {
            $classes[] = 'is-cg-page';
        }

        return $classes;
    }

    public function my_custom_inline_styles()
    {
        ?>
        <style>
            body.is-cg-page{
                display: none !important;
            }
        </style>
        <?php
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable CreditGuard Payment Gateway', 'woocommerce'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default' => __('CreditGuard', 'woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                'default' => __('Pay securely using CreditGuard.', 'woocommerce')
            ),
            'user' => array(
                'title' => __('API User', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your API user for CreditGuard.', 'woocommerce'),
                'default' => ''
            ),
            'password' => array(
                'title' => __('API Password', 'woocommerce'),
                'type' => 'password',
                'description' => __('Your API password for CreditGuard.', 'woocommerce'),
                'default' => ''
            ),
            'terminal_number' => array(
                'title' => __('Terminal Number', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your terminal number for CreditGuard.', 'woocommerce'),
                'default' => ''
            ),
            'mpi_mid' => array(
                'title' => __('MPI MID', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your MPI MID for CreditGuard.', 'woocommerce'),
                'default' => ''
            )
        );
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        $requestId = uniqid();
        // $total  = $order->get_total() * 100; // convert to minor units
        $total = $order->get_total() * 100; // convert to minor units

        $currency = 'ILS';
        $uniqueid = uniqid();
        $dateTime = date('Y-m-d\TH:i:s');

        $process_payment_start_data = array(
            'requestId' => $requestId,
            'total'     => $total,
            'currency'  => $currency,
            'uniqueid'  => $uniqueid,
            'dateTime'  => $dateTime,
        );
        $order->update_meta_data( WC_PROCESS_PAYMENT_START_KEY, $process_payment_start_data, true );

        $xmlRequest = '
        <ashrait>
            <request>
                <version>2000</version>
                <language>HEB</language>
                <dateTime>'.$dateTime.'</dateTime>
                <requestId>'.$requestId.'</requestId>
                <command>doDeal</command>
                <doDeal>
                    <terminalNumber>'.$this->terminal_number.'</terminalNumber>
                    <cardNo>CGMPI</cardNo>
                    <total>'.$total.'</total>
                    <transactionType>Debit</transactionType>
                    <creditType>RegularCredit</creditType>
                    <currency>'.$currency.'</currency>
                    <transactionCode>Internet</transactionCode>
                    <validation>TxnSetup</validation>
                    <mid>'.$this->mpi_mid.'</mid>
                    <uniqueid>'.$uniqueid.'</uniqueid>
                    <mpiValidation>Token</mpiValidation>
                    <keepCD>1</keepCD>
                    <successUrl>'.esc_url($this->get_return_url($order)).'</successUrl>
                    <errorUrl>'.esc_url($this->get_return_url($order)).'</errorUrl>
                    <cancelUrl>'.esc_url($this->get_return_url($order)).'</cancelUrl>
                </doDeal>
            </request>
        </ashrait>';

        $encodedXmlRequest = urlencode($xmlRequest);
        $postFields = "user={$this->user}&password={$this->password}&int_in={$encodedXmlRequest}";

        $response = $this->send_request($postFields);

        $process_payment_data = array(
            'total'    => $total,
            'response' => json_encode( $response ),
        );
        $order->update_meta_data( WC_PROCESS_PAYMENT_KEY, $process_payment_data, true );

        // Parse the XML response
        $xmlObject = simplexml_load_string($response);

        // if( is_super_admin() ){
        //     echo '<pre class="kung-fu-panda" style="direction: ltr;">';
        //     print_r( $xmlObject );
        //     echo '</pre>';
        //     die();
        // }

        $xmlObject_json = array(
            'obj_json' => json_encode( $xmlObject ),
        );
        $order->update_meta_data( WC_PROCESS_PAYMENT_OBJ_KEY, $xmlObject_json, true );

        if ($xmlObject === false) {
            exit('Failed to parse XML');
        }

        // Convert SimpleXMLElement object to JSON string
        $jsonString = json_encode($xmlObject);

        // Convert JSON string to PHP associative array
        $arrayResponse = json_decode($jsonString);

        if( $arrayResponse->response->result!='000' ){
            exit($arrayResponse->message);
        }

        // Example: Generate or fetch the payment URL from creditguard API
        $payment_url = $arrayResponse->response->doDeal->mpiHostedPageUrl; // Replace with actual URL

        // Mark order as pending payment and set creditguard payment URL
        $order->update_status('pending', __('Awaiting payment via creditguard.', 'woocommerce'));

        update_post_meta($order_id, '_creditguard_payment_url', $payment_url);
        update_post_meta($order_id, '_cg_unique_id', $uniqueid);
        // Handle response here

        return array(
            'result'        => 'success',
            'payment_url'   => $payment_url,
            'redirect'      => $order->get_checkout_payment_url(true)
        );
    }

    private function send_request($postFields) {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://cguat2.creditguard.co.il/xpo/Relay',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        if ($response === false) {
            wc_add_notice(__('Error occurred during cURL request', 'woocommerce'), 'error');
            return;
        }

        $response = mb_convert_encoding($response, 'ISO-8859-8', 'auto');
        $xmlObject = simplexml_load_string($response);

        if ($xmlObject === false) {
            wc_add_notice(__('Failed to parse XML', 'woocommerce'), 'error');
            return;
        }

        $jsonString = json_encode($xmlObject);
        $arrayResponse = json_decode($jsonString, true);

        // Process response here

        return $response;
    }


    public function receipt_page($order) {

        $order_id = get_query_var('order-pay');

        $payment_url = get_post_meta($order_id, '_creditguard_payment_url', true);

        // if( is_super_admin() ){
        //     echo '<pre class="kung-fu-panda" style="direction: ltr;">';
        //     print_r('admin test');
        //     echo '<br>------------------------------------------<br>';
        //     print_r( $this );
        //     echo '<br>------------------------------------------<br>';
        //     echo '</pre>';
        // }

        if( is_wc_endpoint_url( 'order-pay' ) ){
            if (!empty($payment_url)) {
                ?>
                <div class="cg-iframe-container">
                    <iframe src="<?php echo esc_url($payment_url); ?>" width="100%" height="700px" frameborder="0"></iframe>
                </div>
                <?php
            }
        }

    }

    public function check_response() {
        // התמודדות עם התגובה מהסליקה
    }


    public function redirect_after_payment($order_id) {

        if( !isset($_GET['key']) || !isset($_GET['txId']) ){
            return;
        }


        // Example order key
        $order_key = esc_attr($_GET['key']);

        // Get order ID by order key
        $order_id = wc_get_order_id_by_order_key($order_key);

        if (!$order_id) {
            return;
        }

        // Get an instance of the order object
        $order = wc_get_order($order_id);

        $inquireTransaction = $this->inquireTransaction( $_GET['txId'] );

        // echo '<pre class="kung-fu-panda" style="direction: ltr;">';
        // print_r( $_GET );
        // echo '<br>';
        // print_r( $inquireTransaction );
        // echo '</pre>';

        $verifyJ5           = $this->verifyJ5($order,$inquireTransaction);


        if( isset($verifyJ5->response->result) ){

            if( $verifyJ5->response->result=='000' ){

                if ($order) {

                    $total = $order->get_total() * 100;

                    $_GET['total'] = $total;

                    $order->update_meta_data( WC_PROCESS_PAYMENT_GET_DATA, json_encode( $_GET ), true );

                    // Check if the order is already processing
                    if ($order->get_status() == 'processing') {
                        return;
                    }

                    // authNumber
                    $authNumber = reset($verifyJ5->response->doDeal->authNumber);

                    $auth_number_array = array( 'auth_number' => $authNumber );
                    $order->update_meta_data( WC_PROCESS_PAYMENT_GET_AUTH_NUMBER, $auth_number_array, true );

                    if( $authNumber && $total ){

                        // Update order status to processing
                        $order->update_status('completed');
                        
                        $order->add_order_note('תשובה מקרדיט גארד'.json_encode($_GET));

                        $thank_you_url = $order->get_checkout_order_received_url();

                        echo '<script type="text/javascript">window.parent.location.href = "' . esc_url($thank_you_url) . '";</script>';

                    }

                }

            }else{

                // echo '<h1>';
                // if(isset( $verifyJ5->response->userMessage[0] )){
                //     print_r($verifyJ5->response->userMessage[0]);
                // } else {
                //     print_r(reset($verifyJ5->response->userMessage));
                // }
                // echo '</h1>';

                $process_payment = $this->process_payment($order_id);
                $payment_url     = $process_payment['payment_url'];
                echo '<script type="text/javascript">alert("'.esc_attr($verifyJ5->response->userMessage[0]).'");</script>';
                echo '<script type="text/javascript">window.location.href = "' . esc_url($payment_url) . '";</script>';

                // $new_process_payment = $this->process_payment($order_id);
                // array_splice($_GET, 1, 1);
                // $this->terminal_number = '';
                // echo '<pre class="kung-fu-panda" style="direction: ltr;">';
                // print_r( get_object_vars( $this ) );
                // echo '<br>';
                // print_r( 'ADMIN TEST' );
                // echo '</pre>';
                // echo '<script type="text/javascript">iframe.contentWindow.location.reload(true);</script>';
                // echo '<script type="text/javascript">window.location.href = "' .'https://www.google.co.il/' . '";</script>';
            }

        }

        // Example: Update custom fields or perform any action
        // update_post_meta($order_id, '_your_custom_field', 'custom_value');

    }

    private function inquireTransaction($txId=false)
    {

        if(!$txId) return false;


        $requestId = uniqid();
        $dateTime = date('Y-m-d\TH:i:s');

        $xmlRequest = '
        <ashrait>
            <request>
                <version>2000</version>
                <language>HEB</language>
                <dateTime>'.$dateTime.'</dateTime>
                <requestId>'.$requestId.'</requestId>
                <command>inquireTransactions</command>
                <inquireTransactions>
                    <terminalNumber>'.$this->terminal_number.'</terminalNumber>
                    <queryName>mpiTransaction</queryName>
                    <mid>'.$this->mpi_mid.'</mid><mpiTransactionId>'.$txId.'</mpiTransactionId>
                </inquireTransactions>
            </request>
        </ashrait>
        ';

        $encodedXmlRequest = urlencode($xmlRequest);

        $postFields = "user={$this->user}&password={$this->password}&int_in={$encodedXmlRequest}";

        $response = $this->send_request($postFields);

        // Parse the XML response
        $xmlObject = simplexml_load_string($response);

        return $xmlObject;

    }

    private function verifyJ5($order=false,$inquireTransaction=false)
    {

        if( !$order || !$inquireTransaction ) return false;

        $requestId = uniqid();
        $dateTime = date('Y-m-d\TH:i:s');
        $currency = 'ILS';
        // $total = $order->get_total() * 100; // convert to minor units
        $total = $order->get_total() * 100; // convert to minor units

        if( isset($inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->cardExpiration[0]) ){
            $cardExpiration = $inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->cardExpiration[0];
        } else {
            $cardExpiration = current($inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->cardExpiration);
        }
        if( isset($inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->cardId[0]) ){
            $cardId = $inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->cardId[0];
        } else {
            $cardId = current($inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->cardId);
        }
        if( isset($inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->sessionCD[0]) ){
            $sessionCD = $inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->sessionCD[0];
        } else {
            $sessionCD = current($inquireTransaction->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->sessionCD);
        }


        $xmlRequest = '
        <ashrait>
            <request>
            <version>2000</version>
            <language>HEB</language>
            <dateTime>'.$dateTime.'</dateTime>
            <requestId>'.$requestId.'</requestId>
            <command>doDeal</command>
            <doDeal>
                <terminalNumber>'.$this->terminal_number.'</terminalNumber>
                <cardExpiration>'.$cardExpiration.'</cardExpiration>
                <cardId>'.$cardId.'</cardId>
                <transactionType>Debit</transactionType>
                <creditType>RegularCredit</creditType>
                <currency>'.$currency.'</currency>
                <transactionCode>Phone</transactionCode>
                <total>'.$total.'</total>
                <validation>Verify</validation>
                <useCD>1</useCD>
                <sessionCD>'.$sessionCD.'</sessionCD>
            </doDeal>
            </request>
        </ashrait>
        ';

        $encodedXmlRequest = urlencode($xmlRequest);

        $postFields = "user={$this->user}&password={$this->password}&int_in={$encodedXmlRequest}";

        $response = $this->send_request($postFields);

        // Parse the XML response
        $xmlObject = simplexml_load_string($response);

        // echo '<pre class="kung-fu-panda" style="direction: ltr;">';
        // print_r( $xmlRequest );
        // echo '<br>';
        // print_r( $xmlObject );
        // echo '</pre>';

        return $xmlObject;

    }

    // public function redirect_after_payment() {

    //     if (is_page('thank-you-page-slug')) {
    //         // Ensure you have logic to check if this is coming from cg
    //         // For example, by checking a specific query parameter or session variable
    //         if (isset($_GET['payment_status']) && $_GET['payment_status'] == 'success') {
    //             wp_redirect('https://yourwebsite.com/thank-you');
    //             exit();
    //         }
    //     }

    // }


}
?>
