<?php
/**
 * Pix payment gateway.
 *
 * @package PagBank_WooCommerce\Gateways
 */

namespace PagBank_WooCommerce\Gateways;

use Exception;
use PagBank_WooCommerce\Presentation\Api;
use PagBank_WooCommerce\Presentation\Connect;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

use function PagBank_WooCommerce\Presentation\get_pix_payment_api_data;

/**
 * Class PixPaymentGateway.
 */
class PixPaymentGateway extends WC_Payment_Gateway {

	/**
	 * Api instance.
	 *
	 * @var Api
	 */
	private $api;

	/**
	 * Api instance.
	 *
	 * @var Connect
	 */
	private $connect;

	/**
	 * Environment.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Logs enabled.
	 *
	 * @var string yes|no.
	 */
	private $logs_enabled;

	/**
	 * PixPaymentGateway constructor.
	 */
	public function __construct() {
		$this->id                 = 'pagbank_pix';
		$this->method_title       = __( 'PagBank Pix', 'pagbank-woocommerce' );
		$this->method_description = __( 'Aceite pagamentos via Pix através do PagBank.', 'pagbank-woocommerce' );
		$this->description        = $this->get_option( 'description' );
		$this->has_fields         = ! empty( $this->description );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title' );
		$this->environment  = $this->get_option( 'environment' );
		$this->logs_enabled = $this->get_option( 'logs_enabled' );
		$this->connect      = new Connect( $this->environment );
		$this->api          = new Api( $this->environment, $this->logs_enabled === 'yes' ? $this->id : null );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Initialize form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Habilitar/Desabilitar', 'pagbank-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Habilitar boleto', 'pagbank-woocommerce' ),
				'default' => 'no',
			),
			'environment'        => array(
				'title'       => __( 'Ambiente', 'pagbank-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Isso irá definir o ambiente de testes ou produção.', 'pagbank-woocommerce' ),
				'default'     => 'sandbox',
				'options'     => array(
					'sandbox'    => __( 'Ambiente de testes', 'pagbank-woocommerce' ),
					'production' => __( 'Produção', 'pagbank-woocommerce' ),
				),
				'desc_tip'    => true,
			),
			'pagbank_connect'    => array(
				'title'       => __( 'Conta PagBank', 'pagbank-woocommerce' ),
				'type'        => 'pagbank_connect',
				'description' => __( 'Conecte a sua conta PagBank para aceitar pagamentos.', 'pagbank-woocommerce' ),
			),
			'title'              => array(
				'title'       => __( 'Título', 'pagbank-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Isso irá controlar o título que o cliente verá durante o checkout.', 'pagbank-woocommerce' ),
				'default'     => __( 'Boleto', 'pagbank-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __( 'Descrição', 'pagbank-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Isso irá controlar a descrição que o cliente verá durante o checkout.', 'pagbank-woocommerce' ),
				'default'     => __( 'O código Pix será gerado assim que você finalizar o pedido.', 'pagbank-woocommerce' ),
				'desc_tip'    => true,
			),
			'expiration_minutes' => array(
				'title'             => __( 'Expiração em minutos', 'pagbank-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Isso irá controlar o tempo em minutos que o Pix será válido.', 'pagbank-woocommerce' ),
				'default'           => '15',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min' => 1,
				),
			),
			'logs_enabled'       => array(
				'title'       => __( 'Logs para depuração', 'pagbank-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Ativar logs', 'pagbank-woocommerce' ),
				'description' => __( 'Isso irá ativar os logs para depuração para auxiliar em caso de suporte.', 'pagbank-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Process order payment.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 *
	 * @throws Exception When an error occurs.
	 */
	public function process_payment( $order_id ) {
		try {
			$order                 = wc_get_order( $order_id );
			$expiration_in_minutes = $this->get_option( 'expiration_minutes' );
			$data                  = get_pix_payment_api_data( $order, $expiration_in_minutes );
			$response              = $this->api->create_order( $data );

			if ( is_wp_error( $response ) ) {
				wc_add_notice( __( 'Houve um erro ao processar o pagamento.', 'pagbank-woocommerce' ), 'error' );
				return;
			}

			// Update status to on-hold.
			$order->update_status( 'on-hold', __( 'Aguardando pagamento do Pix.', 'pagbank-woocommerce' ) );

			// Add order details.
			$this->save_order_meta_data( $order, $response );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Process a refund.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $amount   Refund amount.
	 * @param string $reason   Refund reason.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$amount = floatval( $amount );

		if ( $amount <= 0 ) {
			return new WP_Error( 'error', __( 'O valor para reembolso deve ser maior que zero', 'pagbank-woocommerce' ) );
		}

		$pagbank_order_id = get_post_meta( $order_id, '_pagbank_order_id', true );

		try {
			$refund = $this->api->refund( $pagbank_order_id, $amount );

			if ( is_wp_error( $refund ) ) {
				return $refund;
			}

			if ( $refund['status'] === 'CANCELED' ) {
				return true;
			}

			return new WP_Error( 'error', __( 'Houve um erro ao tentar realizar o reembolso.', 'pagbank-woocommerce' ) );
		} catch ( Exception $ex ) {
			return new WP_Error( 'error', __( 'Houve um erro ao tentar realizar o reembolso.', 'pagbank-woocommerce' ) );
		}
	}

	/**
	 * Save order meta data.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $response Response data.
	 *
	 * @return void
	 */
	private function save_order_meta_data( WC_Order $order, array $response ) {
		$order->update_meta_data( '_pagbank_order_id', $response['id'] );
		$order->update_meta_data( '_pagbank_pix_expiration_date', $response['qr_codes'][0]['expiration_date'] );
		$order->update_meta_data( '_pagbank_pix_text', $response['qr_codes'][0]['text'] );
		$order->update_meta_data( '_pagbank_pix_qr_code', $response['qr_codes'][0]['links'][0]['href'] );
		$order->update_meta_data( '_pagbank_environment', $this->environment );

		$order->save_meta_data();
	}

	/**
	 * Thanks you page HTML content.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return void
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		$pix_expiration_date = $order->get_meta( '_pagbank_pix_expiration_date' );
		$pix_text            = $order->get_meta( '_pagbank_pix_text' );
		$pix_qr_code         = $order->get_meta( '_pagbank_pix_qr_code' );

		wc_get_template(
			'payment-instructions-pix.php',
			array(
				'pix_expiration_date' => $pix_expiration_date,
				'pix_text'            => $pix_text,
				'pix_qr_code'         => $pix_qr_code,
			),
			'woocommerce/pagbank/',
			PAGBANK_WOOCOMMERCE_TEMPLATES_PATH
		);

	}

	/**
	 * Check if gateway needs setup.
	 *
	 * TODO: implement method.
	 *
	 * @return bool
	 */
	public function needs_setup() {
		$is_connected = ! ! $this->connect->get_data();

		return ! $is_connected;
	}

	/**
	 * Check if gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );

		if ( ! $is_available ) {
			return false;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			return false;
		}

		$is_connected          = ! ! $this->connect->get_data();
		$is_brazilian_currency = get_woocommerce_currency() === 'BRL';

		if ( ! $is_connected || ! $is_brazilian_currency ) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueue scripts.
	 */
	public function enqueue_styles() {
		$is_order_received_page = is_checkout() && ! empty( is_wc_endpoint_url( 'order-received' ) );

		if ( ! $is_order_received_page ) {
			return;
		}

		$order_id               = get_query_var( 'order-received' );
		$payment_method         = get_post_meta( $order_id, '_payment_method', true );
		$is_order_paid_with_pix = $this->id === $payment_method;

		if ( ! $is_order_paid_with_pix ) {
			return;
		}

		wp_enqueue_style(
			'pagbank-order-pix',
			plugins_url( 'styles/order-pix.css', PAGBANK_WOOCOMMERCE_FILE_PATH ),
			array(),
			PAGBANK_WOOCOMMERCE_VERSION,
			'all'
		);

		wp_enqueue_script(
			'pagbank-order-pix',
			plugins_url( 'dist/public/order.js', PAGBANK_WOOCOMMERCE_FILE_PATH ),
			array(),
			PAGBANK_WOOCOMMERCE_VERSION,
			true
		);
	}

}
