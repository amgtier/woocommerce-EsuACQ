<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_ESUnionPay extends WC_Gateway_ESunACQBase {

    public $store_id;
    public $mac_key;
    public $test_mode;
    public $card_last_digits;
    public $request_builder;
    public $ESunHtml;
    public static $log_enabled = false;
    public static $log = false;
    public static $customize_order_received_text;
    private $len_ono_prefix = 16; # AWYYYYMMDDHHMMSS

    public function __construct() {
        require_once 'Endpoint.php';
        require_once 'ReturnMesg.php';
        $this -> init();
        if (empty($this -> store_id) || empty($this -> mac_key) ){
            $this -> enabled = 'no';
        }
        else {
            $this -> request_builder = new ESunACQRequestBuilder(
                $this -> store_id,
                $this -> mac_key,
                $this -> test_mode
            );
        }
        add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'handle_response' ) );
        add_filter( 'https_ssl_verify', '__return_false' );
    }

    public function init() {
        $this -> id = 'esunionpay';
        $this -> icon = apply_filters( 'woocommerce_' . $this -> id . '_icon', plugins_url('images/unionpay_logo.png', dirname( __FILE__ ) ) );
        $this -> has_fields = false;
        $this -> method_title = __( 'UnionPay', 'esunacq' );
        $this -> method_description = __( 'Credit Card Payment with UnionPay.', 'esunacq' );
        $this -> supports = array( 'products', 'refunds' );

        $this -> form_fields = WC_Gateway_ESunACQ_Settings::form_fields();

        $this -> enabled        = $this -> get_option( 'enabled' );
        $this -> title          = $this -> get_option( 'title' );
        $this -> description    = $this -> get_option( 'description' );
        $this -> store_id       = $this -> get_option( 'store_id' );
        $this -> mac_key        = $this -> get_option( 'mac_key' );
        $this -> test_mode      = ( $this -> get_option( 'test_mode' ) ) === 'yes' ? true : false;
        $this -> store_card_digits = ( $this -> get_option( 'store_card_digits' ) === 'yes' ) ? true : false;
        self::$log_enabled      = ( $this -> get_option( 'logging' ) ) === 'yes' ? true : false;
        self::$customize_order_received_text = $this -> get_option( 'thankyou_order_received_text' );

        $this -> get_order_from_data = $this -> id . '_get_order_form_data';
        add_action( 'woocommerce_api_' . $this -> get_order_from_data , array( $this, 'make_order_form_data' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this -> id, array( $this, 'process_admin_options' ) );
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {

        $esun_order_id  = get_post_meta( $order_id, '_' . $this -> id . '_orderid', true );
        $txnno          = get_post_meta( $order_id, '_' . $this -> id . '_txnno', true );
        $order = new WC_Order( $order_id );

        $res = $this -> request_builder -> up_request_refund( $new_order_id, $amount, '', $txnno );
        return true;
        // $DATA = $this -> get_api_DATA( $res );

        // if ( $DATA[ 'returnCode' ] == '00' ){
        //     return $this -> refund_success( $order, $DATA, $esun_order_id );
        // }
        // else if ( $DATA[ 'returnCode' ] == 'GF' ){
        //     return $this -> refund_failed_query( $order, $DATA, $esun_order_id );
        // }
        // else{
        //     $refund_note = sprintf( '退款失敗：%s', ReturnMesg::CODE[ $DATA[ 'returnCode' ] ] );
        //     $order->add_order_note( $refund_note, true );
        //     return false;
        // }
        // return false;
    }

    public function make_order_form_data() {
        if (array_key_exists('order_id', $_GET)){
            $order_id = $_GET['order_id'];
        }
        else{
            exit;
        }

        $order = new WC_Order( $order_id );
        $new_order_id = 'AW' . date('YmdHis') . $order -> get_order_number();
        $amount = ceil( $order -> get_total() );
        $res = $this -> request_builder -> up_json_action( 'order', $new_order_id, $amount, 'http://nuan.vatroc.net/wc-api/wc_gateway_esunionpay/' );

        echo sprintf( "
            <form id='esunacq' method='post' action='%s'>
                <input type='text' hidden name='MID' value='%s' />
                <input type='text' hidden name='CID' value='%s' />
                <input type='text' hidden name='ONO' value='%s' />
                <input type='text' hidden name='TA' value='%s' />
                <input type='text' hidden name='TT' value='%s' />
                <input type='text' hidden name='U' value='%s' />
                <input type='text' hidden name='TXNNO' value='%s' />
                <input type='text' hidden name='M' value='%s' />
                <button>submit</button>
            </form>
            <script>
                var esunacq_form = document.getElementById('esunacq');
                // esunacq_form.submit();
            </script>
            ",
            $this -> request_builder -> get_endpoint( 'UNIONPAY' ),
            $res[ 'MID' ],
            $res[ 'CID' ],
            $res[ 'ONO' ],
            $res[ 'TA' ],
            $res[ 'TT' ],
            $res[ 'U' ],
            $res[ 'TXNNO' ],
            $res[ 'M' ]
        );
        exit;
    }

    public function handle_response( $args ){
        $this -> check_RC_MID_ONO( $_GET );

        $order_id = substr( $DATA[ 'ONO' ], $this -> len_ono_prefix );
        $order = new WC_Order( $order_id );

        if ($DATA['RC'] != "00"){
            $this -> order_failed( $order, $DATA );
        }
        else{
            $this -> check_mac( $order, $_GET, $_GET[ 'DATA' ], 'M' );
        }

        $required_fields = [
            'LTD' => 'LTD',
            'LTT' => 'LTT',
            'TRACENUMBER' => 'TRACENUMBER',
            'TRACETIME' => 'TRACETIME',
            'TXNNO' => 'TXNNO',
        ];
        foreach ( $required_fields as $key => $name ){
            if (!array_key_exists( $key, $DATA )){
                $order->update_status('failed');
                wc_add_notice( sprintf( '%s No Not Found.', $name), 'error' );
                $this -> log( sprintf( '%s No Not Found.', $name) );
                $this -> log( $DATA );
                wp_redirect( $order -> get_cancel_order_url() );
                exit;
            }
        }
        wc_reduce_stock_levels( $order_id );

        $pay_type_note = '銀聯信用卡 付款（一次付清）';
        foreach ( $required_fields as $key => $name ){
            $pay_type_note .= sprintf('<br>%s：%s', $key, $DATA[ $key ]);
        }

        add_post_meta( $order_id, '_' . $this -> id . '_orderid', $DATA['ONO'] );
        add_post_meta( $order_id, '_' . $this -> id . '_txnno'  , $DATA['TXNNO'] );

        $order -> add_order_note( $pay_type_note, true );
        $order -> update_status( 'processing' );
        $order -> payment_complete();
        
        wp_redirect( $order -> get_checkout_order_received_url() );
        exit;
    }

    public function handle_refund( $args ){

        $this -> check_RC_MID_ONO( $_GET );

        $order_id = substr( $DATA[ 'ONO' ], $this -> len_ono_prefix );
        $order = new WC_Order( $order_id );

        if ($DATA['RC'] != "00"){
            $this -> order_failed( $order, $DATA );
        }
        else{
            $this -> check_mac( $order, $_GET, $_GET[ 'DATA' ], 'M' );
        }
        if ( $DATA[ 'returnCode' ] == '00' ){
            return $this -> refund_success( $order, $DATA, $esun_order_id );
        }
        // else if ( $DATA[ 'returnCode' ] == 'GF' ){
        //     return $this -> refund_failed_query( $order, $DATA, $esun_order_id );
        // }
        else{
            $refund_note = sprintf( '退款失敗：%s', ReturnMesg::CODE[ $DATA[ 'returnCode' ] ] );
            $order->add_order_note( $refund_note, true );
            return false;
        }
        return false;
    }

    private function refund_success( $order, $DATA, $esun_order_id ){
        if ( !$this -> check_MID_ONO( $DATA, $order, $esun_order_id, '退款' ) ){
            return false;
        }
        if ( $DATA[ 'RC' ] == "00" ){
            $refund_note  = sprintf( '交易序號: %s<br>退款成功', $DATA[ 'TXNNO' ]);
            $order -> add_order_note( $refund_note, true );
            $order -> update_status( 'refunded' );
            return true;
        }
        else{
            $refund_note .= sprintf( '退款失敗：%s<br>', ReturnMesg::CODE[ $DATA[ 'RC' ] ] );
            $order->add_order_note( $refund_note, true );
            return false;
        }
    }

    private function refund_failed_query( $order, $DATA, $esun_order_id ){
        $refund_note = sprintf( '退款失敗：%s<br>', ReturnMesg::CODE[ $DATA[ 'returnCode' ] ] );

        // $Qres = $this -> request_builder -> request_query( $esun_order_id );
        // $QDATA = $this -> get_api_DATA( $Qres );
        // if ($QDATA[ 'returnCode' ] == '00' ){
        //     $QtxnData = $QDATA[ 'txnData' ];
        //     if ( !$this -> check_MID_ONO( $QtxnData, $order, $esun_order_id, '查詢' ) ){
        //         return false;
        //     }
        //     if ( $QtxnData[ 'RC' ] == '49' ){
        //         $order -> update_status( 'refunded' );
        //         $refund_note .= '已退款<br>';
        //         $order->add_order_note( $refund_note, true );
        //         return false;
        //     }
        // }
        // else{
        //     $refund_note .= sprintf( '查詢失敗：%s', ReturnMesg::CODE[ $QDATA[ 'returnCode' ] ] );
        //     $order->add_order_note( $refund_note, true );
        //     return false;
        // }

        $order->add_order_note( $refund_note, true );
        return false;
    }
}

?>