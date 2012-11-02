<?php
/**
 * Plugin Name: WooCommerce Bcash
 * Plugin URI: http://claudiosmweb.com/plugins/bcash-para-woocommerce/
 * Description: Gateway de pagamento Bcash para WooCommerce.
 * Author: claudiosanches
 * Author URI: http://www.claudiosmweb.com/
 * Version: 1.2
 * License: GPLv2 or later
 * Text Domain: wcbcash
 * Domain Path: /languages/
 */

/**
 * WooCommerce fallback notice.
 */
function wcbcash_woocommerce_fallback_notice(){
    $message = '<div class="error">';
        $message .= '<p>' . __( 'WooCommerce Bcash Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!' , 'wcbcash' ) . '</p>';
    $message .= '</div>';

    echo $message;
}

/**
 * Load functions.
 */
add_action( 'plugins_loaded', 'wcbcash_gateway_load', 0 );

function wcbcash_gateway_load() {

    if ( !class_exists( 'WC_Payment_Gateway' ) || !class_exists( 'WC_Order_Item_Meta' ) ) {
        add_action( 'admin_notices', 'wcbcash_woocommerce_fallback_notice' );

        return;
    }

    /**
     * Load textdomain.
     */
    load_plugin_textdomain( 'wcbcash', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    /**
     * Add the gateway to Bcash.
     *
     * @access public
     * @param array $methods
     * @return array
     */
    add_filter( 'woocommerce_payment_gateways', 'wcbcash_add_gateway' );

    function wcbcash_add_gateway( $methods ) {
        $methods[] = 'WC_BCash_Gateway';
        return $methods;
    }

    /**
     * WC Bcash Gateway Class.
     *
     * Built the Bcash method.
     */
    class WC_BCash_Gateway extends WC_Payment_Gateway {

        /**
         * Constructor for the gateway.
         *
         * @return void
         */
        public function __construct() {
            global $woocommerce;

            $this->id            = 'bcash';
            $this->icon          = plugins_url( 'images/bcash.png', __FILE__ );
            $this->has_fields    = false;
            $this->bcash_url     = 'https://www.bcash.com.br/checkout/pay/';
            $this->bcash_ipn     = 'https://www.bcash.com.br/checkout/verify/';
            $this->method_title  = __( 'Bcash', 'wcbcash' );

            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables.
            $this->title            = $this->settings['title'];
            $this->description      = $this->settings['description'];
            $this->email            = $this->settings['email'];
            $this->token            = $this->settings['token'];
            $this->invoice_prefix   = !empty( $this->settings['invoice_prefix'] ) ? $this->settings['invoice_prefix'] : 'WC-';

            // Actions.
            add_action( 'init', array( &$this, 'check_bcash_ipn_response' ) );
            add_action( 'valid_bcash_ipn_request', array( &$this, 'successful_request' ) );
            add_action( 'woocommerce_receipt_bcash', array( &$this, 'receipt_page' ) );
            add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );

            // Valid for use.
            $this->enabled = ( 'yes' == $this->settings['enabled'] ) && !empty( $this->email ) && !empty( $this->token ) && $this->is_valid_for_use();

            // Checks if email is not empty.
            $this->email == '' ? add_action( 'admin_notices', array( &$this, 'mail_missing_message' ) ) : '';

            // Checks if token is not empty.
            $this->token == '' ? add_action( 'admin_notices', array( &$this, 'token_missing_message' ) ) : '';
        }

        /**
         * Check if this gateway is enabled and available in the user's country.
         *
         * @return bool
         */
        public function is_valid_for_use() {
            if ( !in_array( get_woocommerce_currency() , array( 'BRL' ) ) ) {
                return false;
            }

            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis.
         *
         * @since 1.0.0
         */
        public function admin_options() {

            ?>
            <h3><?php _e( 'Bcash standard', 'wcbcash' ); ?></h3>
            <p><?php _e( 'Bcash standard works by sending the user to Bcash to enter their payment information.', 'wcbcash' ); ?></p>
            <table class="form-table">
            <?php
                if ( !$this->is_valid_for_use() ) {

                    // Valid currency.
                    echo '<div class="inline error"><p><strong>' . __( 'Gateway Disabled', 'wcbcash' ) . '</strong>: ' . __( 'Bcash does not support your store currency.', 'wcbcash' ) . '</p></div>';

                } else {

                    // Generate the HTML For the settings form.
                    $this->generate_settings_html();
                }
            ?>
            </table><!--/.form-table-->
            <?php
        }

        /**
         * Initialise Gateway Settings Form Fields.
         *
         * @return void
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable/Disable', 'wcbcash' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable Bcash standard', 'wcbcash' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __( 'Title', 'wcbcash' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'wcbcash' ),
                    'default' => __( 'Bcash', 'wcbcash' )
                ),
                'description' => array(
                    'title' => __( 'Description', 'wcbcash' ),
                    'type' => 'textarea',
                    'description' => __( 'This controls the description which the user sees during checkout.', 'wcbcash' ),
                    'default' => __( 'Pay via Bcash', 'wcbcash' )
                ),
                'email' => array(
                    'title' => __( 'Bcash Email', 'wcbcash' ),
                    'type' => 'text',
                    'description' => __( 'Please enter your Bcash email address; this is needed in order to take payment.', 'wcbcash' ),
                    'default' => ''
                ),
                'token' => array(
                    'title' => __( 'Bcash Token', 'wcbcash' ),
                    'type' => 'text',
                    'description' => __( 'Please enter your Bcash token; is necessary to process the payment and notifications.', 'wcbcash' ),
                    'default' => ''
                ),
                'invoice_prefix' => array(
                    'title' => __( 'Invoice Prefix', 'wcbcash' ),
                    'type' => 'text',
                    'description' => __( 'Please enter a prefix for your invoice numbers. If you use your Bcash account for multiple stores ensure this prefix is unqiue as Bcash will not allow orders with the same invoice number.', 'wcbcash' ),
                    'default' => 'WC-'
                )
            );

        }

        /**
         * Get Bcash Args.
         *
         * @param mixed $order
         * @return array
         */
        public function get_bcash_args( $order ) {
            global $woocommerce;

            $order_id = $order->id;

            // Fixed phone number.
            $order->billing_phone = str_replace( array( '(', '-', ' ', ')' ), '', $order->billing_phone );

            // Bcash Args.
            $bcash_args = array(
                'email_loja'      => $this->email,
                'tipo_integracao' => 'PAD',

                // Sender info.
                'nome'            => $order->billing_first_name . ' ' . $order->billing_last_name,
                'email'           => $order->billing_email,
                'telefone'        => $order->billing_phone,
                //'rg'
                //'data_emissao_rg'
                //'orgao_emissor_rg'
                //'estado_emissor_rg'
                //'cpf'
                //'sexo'
                //'data_nascimento'
                //'celular'
                //'cliente_razao_social'
                //'cliente_cnpj'

                // Address info.
                'endereco'        => $order->billing_address_1,
                'complemento'     => $order->billing_address_2,
                //'bairro'
                'cidade'          => $order->billing_city,
                'estado'          => $order->billing_state,
                'cep'             => $order->billing_postcode,

                // Tax.
                'acrescimo'        => $order->get_total_tax(),

                // Discount/Coupon.
                'desconto'         => $order->get_order_discount(),

                // Payment Info.
                'id_pedido'        => $this->invoice_prefix . $order_id,

                // Shipping info.
                'frete'            => number_format( $order->get_shipping(), 2, '.', '' ),
                'tipo_frete'       => $order->shipping_method_title,

                // Return.
                'url_retorno'      => $this->get_return_url( $order ),
                'redirect'         => 'true',
                'redirect_time'    => '0',

                // Notification url.
                'url_aviso'        => $this->get_return_url( $order ),

                // Others fields.
                //'parcela_maxima'
                //'meio_pagamento'
                //'meses_garantia'
                //'free'
                //'hash'
            );

            // Cart Contents.
            $item_loop = 0;
            if ( sizeof( $order->get_items() ) > 0 ) {
                foreach ( $order->get_items() as $item ) {
                    if ( $item['qty'] ) {

                        $item_loop++;

                        $product = $order->get_product_from_item( $item );

                        $item_name  = $item['name'];

                        $item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
                        if ( $meta = $item_meta->display( true, true ) ) {
                            $item_name .= ' (' . $meta . ')';
                        }

                        $bcash_args['produto_codigo_' . $item_loop]    = $item_loop;
                        $bcash_args['produto_descricao_' . $item_loop] = $item_name;
                        $bcash_args['produto_qtde_' . $item_loop]      = $item['qty'];
                        $bcash_args['produto_valor_' . $item_loop]     = $order->get_item_total( $item, false );

                    }
                }
            }

            $bcash_args = apply_filters( 'woocommerce_bcash_args', $bcash_args );

            return $bcash_args;
        }

        /**
         * Generate the Bcash button link.
         *
         * @param mixed $order_id
         * @return string
         */
        public function generate_bcash_form( $order_id ) {
            global $woocommerce;

            $order = new WC_Order( $order_id );

            $bcash_adr = $this->bcash_url;

            $bcash_args = $this->get_bcash_args( $order );

            $bcash_args_array = array();

            foreach ( $bcash_args as $key => $value ) {
                $bcash_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
            }

            $woocommerce->add_inline_js( '
                jQuery("body").block({
                        message: "<img src=\"' . esc_url( $woocommerce->plugin_url() . '/assets/images/ajax-loader.gif' ) . '\" alt=\"Redirecting&hellip;\" style=\"float:left; margin-right: 10px;\" />'.__( 'Thank you for your order. We are now redirecting you to Bcash to make payment.', 'wcbcash' ).'",
                        overlayCSS:
                        {
                            background: "#fff",
                            opacity:    0.6
                        },
                        css: {
                            padding:         20,
                            textAlign:       "center",
                            color:           "#555",
                            border:          "3px solid #aaa",
                            backgroundColor: "#fff",
                            cursor:          "wait",
                            lineHeight:      "32px",
                            zIndex:          "9999"
                        }
                    });
                jQuery("#submit_bcash_payment_form").click();
            ' );

            return '<form action="' . esc_url( $bcash_adr ) . '" method="post" id="bcash_payment_form" target="_top">
                    ' . implode( '', $bcash_args_array ) . '
                    <input type="submit" class="button alt" id="submit_bcash_payment_form" value="' . __( 'Pay via Bcash', 'wcbcash' ).'" /> <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart', 'wcbcash' ) . '</a>
                </form>';

        }

        /**
         * Process the payment and return the result.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = new WC_Order( $order_id );

            return array(
                'result'    => 'success',
                'redirect'  => add_query_arg( 'order', $order->id, add_query_arg( 'key', $order->order_key, get_permalink( woocommerce_get_page_id( 'pay' ) ) ) )
            );

        }

        /**
         * Output for the order received page.
         *
         * @return void
         */
        public function receipt_page( $order ) {
            global $woocommerce;

            echo '<p>' . __( 'Thank you for your order, please click the button below to pay with Bcash.', 'wcbcash' ).'</p>';

            echo $this->generate_bcash_form( $order );

            // Remove cart.
            $woocommerce->cart->empty_cart();
        }

        /**
         * Check Bcash ipn validity.
         *
         * @return bool
         */
        function check_ipn_request_is_valid() {
            global $woocommerce;

            // Get recieved values from post data.
            $received_values = (array) stripslashes_deep( $_POST );

            $postdata  = 'transacao=' . $_POST['id_transacao'];
            $postdata .= '&status=' . $_POST['status'];
            $postdata .= '&cod_status=' . $_POST['cod_status'];
            $postdata .= '&valor_original=' . $_POST['valor_original'];
            $postdata .= '&valor_loja=' . $_POST['valor_loja'];
            $postdata .= '&token=' . $this->token;

            // Send back post vars to Bcash.
            $params = array(
                'body'          => $postdata,
                'sslverify'     => false,
                'timeout'       => 30
            );

            // Post back to get a response.
            $response = wp_remote_post( $this->bcash_ipn, $params );

            // Check to see if the request was valid.
            if ( !is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && ( strcmp( $response['body'], 'VERIFICADO' ) == 0 ) ) {

                return true;
            }

            return false;
        }

        /**
         * Check Bcash API Response.
         *
         * @return void
         */
        public function check_bcash_ipn_response() {

            if ( isset( $_POST['id_pedido'] ) ) {

                if ( !empty( $this->token ) ) {

                    @ob_clean();

                    $posted = stripslashes_deep( $_POST );

                    if ( $this->check_ipn_request_is_valid() ) {

                        header( 'HTTP/1.1 200 OK' );

                        do_action( 'valid_bcash_ipn_request', $posted );

                    } else {

                        wp_die( __( 'Bcash Request Failure', 'wcbcash' ) );

                    }
                }

            }

        }

        /**
         * Successful Payment!
         *
         * @param array $posted
         * @return void
         */
        public function successful_request( $posted ) {
            global $woocommerce;

            if ( !empty( $posted['id_pedido'] ) ) {
                $order_key = $posted['id_pedido'];
                $order_id = (int) str_replace( $this->invoice_prefix, '', $order_key );

                $order = new WC_Order( $order_id );

                // Checks whether the invoice number matches the order.
                // If true processes the payment.
                if ( $order->id === $order_id ) {

                    switch ( $posted['cod_status'] ) {
                        case '0':
                            $order->update_status( 'on-hold', __( 'Payment under review by Bcash.', 'wcbcash' ) );

                            break;
                        case '1':

                            // Order details.
                            if ( !empty( $posted['id_transacao'] ) ) {
                                update_post_meta(
                                    $order_id,
                                    __( 'Bcash Transaction ID', 'wcbcash' ),
                                    $posted['id_transacao']
                                );
                            }
                            if ( !empty( $posted['cliente_email'] ) ) {
                                update_post_meta(
                                    $order_id,
                                    __( 'Payer email', 'wcbcash' ),
                                    $posted['cliente_email']
                                );
                            }
                            if ( !empty( $posted['cliente_nome'] ) ) {
                                update_post_meta(
                                    $order_id,
                                    __( 'Payer name', 'wcbcash' ),
                                    $posted['cliente_nome']
                                );
                            }
                            if ( !empty( $posted['tipo_pagamento'] ) ) {
                                update_post_meta(
                                    $order_id,
                                    __( 'Payment type', 'wcbcash' ),
                                    $posted['tipo_pagamento']
                                );
                            }

                            // Payment completed.
                            $order->add_order_note( __( 'Payment completed.', 'wcbcash' ) );
                            $order->payment_complete();

                            break;
                        case '2':
                            $order->update_status( 'cancelled', __( 'Payment canceled by Bcash.', 'wcbcash' ) );

                            break;

                        default:
                            // No action xD.
                            break;
                    }
                }
            }
        }

        /**
         * Adds error message when not configured the Bcash email.
         *
         * @return string Error Mensage.
         */
        public function mail_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should inform your email address in Bcash. %sClick here to configure!%s' , 'wcbcash' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }

        /**
         * Adds error message when not configured the Bcash token.
         *
         * @return string Error Mensage.
         */
        public function token_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' .sprintf( __( '<strong>Gateway Disabled</strong> You should inform your token in Bcash. %sClick here to configure!%s' , 'wcbcash' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }

    } // class WC_BCash_Gateway.
} // function wcbcash_gateway_load.

/**
 * Hidden when the purchase is outside the Brazil.
 */
add_filter( 'woocommerce_available_payment_gateways', 'wcbcash_hidden_when_is_outside_brasil' );

function wcbcash_hidden_when_is_outside_brasil( $available_gateways ) {

    if ( isset( $_REQUEST['country'] ) && $_REQUEST['country'] != 'BR' ) {

        // remove standard shipping option.
        unset( $available_gateways['bcash'] );
    }

    return $available_gateways;
}