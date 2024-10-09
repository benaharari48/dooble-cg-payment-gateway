<?php
/*
Plugin Name: פלאגין תשלום קרדיטגארד
Description: שער תשלום מותאם אישית עבור WooCommerce באמצעות קרדיטגארד פותח ע"י חברת Dooble.
Version: 1.0
Author: Dooble
*/

// הגדרה כללית של הפלגין והכללת הקבצים הנדרשים
if (!defined('ABSPATH')) {
    exit; // יציאה אם ניגשו ישירות
}

add_action('plugins_loaded', 'cg_payment_gateway_init', 11);

function cg_payment_gateway_init() {
    if (class_exists('WC_Payment_Gateway')) {
        include_once('includes/class-cg-payment-gateway.php');
    }
}

add_filter('woocommerce_payment_gateways', 'add_cg_payment_gateway');

function add_cg_payment_gateway($gateways) {
    $gateways[] = 'WC_Gateway_cg';
    return $gateways;
}
?>