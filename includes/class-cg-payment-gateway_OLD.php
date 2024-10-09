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
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->terminal_number = $this->get_option('terminal_number');
        $this->user = $this->get_option('user');
        $this->mpi_mid = $this->get_option('mpi_mid');
        $this->password = $this->get_option('password');
        $this->GoodURL = $this->get_option('GoodURL');
        $this->ErrorURL = $this->get_option('ErrorURL');

        // פעולות
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // מאזין תשלומים/וו API
        add_action('woocommerce_api_wc_gateway_cg', array($this, 'check_response'));

        add_action('wp_footer', array($this, 'redirect_after_payment') );

        add_action('woocommerce_thankyou', array($this, 'redirect_after_payment'), 10, 1);


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
        $total = $order->get_total() * 100; // convert to minor units
        $total = 1 * 100; // convert to minor units
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
        update_post_meta($order_id, '_cg_unique_id', $uniqueID);
        // Handle response here



        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
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

        // if( 1 ){
        //     echo '<pre class="kung-fu-panda" style="direction: ltr;">';
        //     // print_r( get_class_vars( $xmlObject ) );
        //     // echo '<br>';
        //     print_r( get_class_methods( $xmlObject ) );
        //     echo '<br>';
        //     print_r( $xmlObject );
        //     // echo '<br>';
        //     // print_r( $xmlObject );
        //     echo '</pre>';
        //     die();
        // }

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

    public function check_response( $_this ) {
        // התמודדות עם התגובה מהסליקה

        // if( 1 ){
        //     echo '<pre class="kung-fu-panda" style="direction: ltr;">';
        //     print_r( $_this );
        //     echo '</pre>';
        //     die('admin test');
        // }
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

        var_dump($order_id);

        // Get an instance of the order object
        $order = wc_get_order($order_id);
        if ($order) {

            $order->update_meta_data( WC_PROCESS_PAYMENT_GET_DATA, json_encode( $_GET ), true );

            // Check if the order is already processing
            if ($order->get_status() == 'completed') {
                return;
            }

            $order->add_order_note('פרטים נוספים'.json_encode($_GET));

            // Update order status to completed
            $order->update_status('completed');

            $thank_you_url = $order->get_checkout_order_received_url();

            if( isset( $_GET['txId'] ) ){

                $requestId = uniqid();
                $dateTime = date('Y-m-d\TH:i:s');

                $xmlRequest = '
                <ashrait>
                    <request>
                    <requestId>'.$requestId.'</requestId>
                    <version>2000</version>
                    <language>HEB</language>
                    <dateTime/>
                    <command>inquireTransactions</command>
                    <inquireTransactions>
                    <terminalNumber>'.$this->terminal_number.'</terminalNumber>
                    <mainTerminalNumber/>
                    <queryName>mpiTransaction</queryName>
                    <mid>'.$this->mpi_mid.'</mid>
                    <mpiTransactionId>'.$_GET['txId'].'</mpiTransactionId>
                    </inquireTransactions>
                    </request>
                </ashrait>';


                $encodedXmlRequest = urlencode($xmlRequest);
                $postFields = "user={$this->user}&password={$this->password}&int_in={$encodedXmlRequest}";

                $response = $this->send_request($postFields);

                $response = mb_convert_encoding($response, 'ISO-8859-8', 'auto');
                $xmlObject = simplexml_load_string($response);


                // if( 1 ){
                //     echo '<pre class="kung-fu-panda" style="direction: ltr;">';
                //     print_r( $xmlObject );
                //     echo '<br>';
                //     print_r( get_class_methods( $xmlObject->response->inquireTransactions->row->authNumber ) );
                //     echo '</pre>';
                // }
            }

            echo '<script type="text/javascript">window.parent.location.href = "' . esc_url($thank_you_url) . '";</script>';
        }

        // Example: Update custom fields or perform any action
        // update_post_meta($order_id, '_your_custom_field', 'custom_value');
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



// <!-- 

// verify or autocom


// -->
}
?>
