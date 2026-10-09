<?php
/**
 * Plugin Name: Meta CAPI for WooCommerce
 * Description: Server-side Meta Conversions API for WooCommerce (ViewContent, AddToCart, Search, InitiateCheckout, AddPaymentInfo, Purchase). Deduplicated by event_id, non-blocking, and safe when an order completes through a payment-gateway callback with no browser present.
 * Version: 1.0.0
 * Author: Yair Zehavi
 * License: MIT
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * CONFIG (WordPress options, or wp-config constants which take precedence):
 *   mcapi_pixel_id   / MCAPI_PIXEL_ID    REQUIRED. Meta Pixel / Dataset ID. Plugin is inert until set.
 *   mcapi_capi_token / MCAPI_CAPI_TOKEN  SECRET. Events Manager > Conversions API > Generate access token.
 *   mcapi_test_code  / MCAPI_TEST_CODE   optional. Events Manager > Test Events code. Set to validate, clear to go live.
 *
 * CONSTANT-ONLY toggles (optional):
 *   define( 'MCAPI_TRUST_PROXY_HEADERS', false ); // default false. Set true ONLY behind a trusted CDN/proxy that
 *                                                 // normalizes CF-Connecting-IP / X-Forwarded-For.
 *   define( 'MCAPI_BROWSER_PIXEL', false );       // default false. Set true to render a minimal browser pixel (PageView).
 *   define( 'MCAPI_PHONE_COUNTRY_CODE', '' );     // optional, digits only (e.g. '44'). Local numbers with a leading 0
 *                                                 // are rewritten to <code><number without 0> before hashing.
 *
 * FILTERS:
 *   mcapi_has_consent( bool )                 // master gate; default true. Return false to gate on your consent tool.
 *   mcapi_is_bot( bool, string $ua )          // treat this request as a bot (non-order events are dropped)
 *   mcapi_trust_proxy_headers( bool )         // whether to read CF/XFF client IP headers
 *   mcapi_event( array $event, string $name ) // mutate a built event before it is queued
 *
 * Commerce events are sent server-side only. To deduplicate against a browser pixel, fire the
 * browser event with the same event ID (see README: "Deduplication").
 */

defined( 'ABSPATH' ) || exit;

class MCAPI_Plugin {

	const API_VERSION = 'v22.0';
	const PURCHASE_META  = '_mcapi_purchase_sent';
	const ORDER_CTX_META = '_mcapi_ctx'; // browser context (ua/ip/fbp/fbc/extid/url) snapshotted at checkout

	/** @var array queued events flushed on shutdown */
	private $events = array();

	/** @var string stable first-party visitor id (external_id), from a cookie */
	private $extid = '';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'rest_api_init', array( $this, 'register_settings' ) );

		if ( '' === $this->pixel_id() ) {
			add_action( 'admin_notices', array( $this, 'admin_notice' ) );
			return; // inert until a Pixel ID is configured
		}

		// Ensure a stable first-party external_id cookie exists (before headers).
		add_action( 'init', array( $this, 'ensure_extid' ), 1 );

		// Optional browser pixel (default OFF).
		if ( $this->cfg_bool( 'MCAPI_BROWSER_PIXEL', false ) ) {
			add_action( 'wp_head', array( $this, 'render_pixel' ), 5 );
		}

		// Commerce events. Registered UNCONDITIONALLY: this plugin loads alphabetically
		// BEFORE WooCommerce, so class_exists('WooCommerce') is still false here. add_action
		// only stores the callback, WooCommerce's do_action fires it later once loaded.
		// Each handler self-guards (function_exists / instanceof), so these are inert if
		// WooCommerce is absent.
		add_action( 'template_redirect', array( $this, 'on_template_redirect' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'on_initiate_checkout' ), 10, 0 );
		// Snapshot the CUSTOMER's browser context onto the order at checkout (priority 5, before
		// AddPaymentInfo). Purchase often fires later from a payment-gateway callback or cron,
		// where there is no UA and no cookies, those events replay this snapshot instead.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_order_context' ), 5, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_order_context' ), 5, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed' ), 10, 3 );
		add_action( 'woocommerce_payment_complete', array( $this, 'on_purchase' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'on_purchase' ), 10, 1 );

		add_action( 'shutdown', array( $this, 'flush_events' ), 99 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/* ---------------- config (REST options, constant override) ---------------- */

	public function register_settings() {
		$args = array( 'type' => 'string', 'show_in_rest' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' );
		register_setting( 'general', 'mcapi_pixel_id', $args );
		register_setting( 'general', 'mcapi_capi_token', $args );
		register_setting( 'general', 'mcapi_test_code', $args );
	}

	private function opt( $const, $option ) {
		if ( defined( $const ) ) {
			return (string) constant( $const );
		}
		return (string) get_option( $option, '' );
	}
	private function cfg_string( $name ) {
		return defined( $name ) ? (string) constant( $name ) : '';
	}
	private function cfg_bool( $name, $default ) {
		return defined( $name ) ? (bool) constant( $name ) : $default;
	}
	private function pixel_id() { return trim( $this->opt( 'MCAPI_PIXEL_ID', 'mcapi_pixel_id' ) ); }
	private function token()    { return trim( $this->opt( 'MCAPI_CAPI_TOKEN', 'mcapi_capi_token' ) ); }
	private function test_code(){ return trim( $this->opt( 'MCAPI_TEST_CODE', 'mcapi_test_code' ) ); }

	/* ---------------- consent (default: always on) ---------------- */

	private function has_consent() {
		return (bool) apply_filters( 'mcapi_has_consent', true );
	}

	/* ---------------- browser pixel (optional fallback) ---------------- */

	public function render_pixel() {
		if ( is_admin() || ! $this->has_consent() ) {
			return;
		}
		$pid          = esc_js( $this->pixel_id() );
		$noscript_src = add_query_arg(
			array( 'id' => rawurlencode( $this->pixel_id() ), 'ev' => 'PageView', 'noscript' => '1' ),
			'https://www.facebook.com/tr'
		);
		?>
<!-- Meta CAPI for WooCommerce: Meta Pixel base -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init','<?php echo $pid; ?>');
fbq('track','PageView');
</script>
<noscript><img height="1" width="1" style="display:none" src="<?php echo esc_url( $noscript_src ); ?>"/></noscript>
<!-- /Meta CAPI for WooCommerce -->
		<?php
	}

	/* ---------------- event handlers ----------------
	 * Every handler body is wrapped in try/catch: a failure in our code must NEVER
	 * bubble into WooCommerce's cart/checkout flow. Worst case an event is skipped;
	 * the add-to-cart / order / purchase itself always completes.
	 */

	// ViewContent (single product) + Search (results page).
	public function on_template_redirect() {
		try {
			if ( is_admin() || wp_doing_ajax() || ! $this->has_consent() ) {
				return;
			}
			if ( function_exists( 'is_product' ) && is_product() ) {
				$pid     = get_queried_object_id();
				$product = $pid ? wc_get_product( $pid ) : null;
				if ( ! $product ) {
					return;
				}
				$custom = array(
					'content_type' => 'product',
					'content_ids'  => array( (string) $pid ),
					'content_name' => $product->get_name(),
					'value'        => (float) $product->get_price(),
					'currency'     => get_woocommerce_currency(),
				);
				$this->queue( 'ViewContent', 'vc.' . $pid . '.' . $this->req_hash(), $custom );
				return;
			}
			if ( is_search() ) {
				$q = trim( (string) get_search_query() );
				if ( '' === $q ) {
					return;
				}
				$this->queue( 'Search', 'se.' . md5( $q ) . '.' . $this->req_hash(), array( 'search_string' => $q ) );
			}
		} catch ( \Throwable $e ) {
			$this->log_err( 'ViewContent/Search', $e );
		}
	}

	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		try {
			if ( ! $this->has_consent() ) {
				return;
			}
			$id      = $variation_id ? $variation_id : $product_id;
			$product = wc_get_product( $id );
			if ( ! $product ) {
				return;
			}
			$custom = array(
				'content_type' => 'product',
				'content_ids'  => array( (string) $id ),
				'content_name' => $product->get_name(),
				'value'        => (float) $product->get_price() * max( 1, (int) $quantity ),
				'currency'     => get_woocommerce_currency(),
			);
			$this->queue( 'AddToCart', 'atc.' . $id . '.' . $cart_item_key, $custom );
		} catch ( \Throwable $e ) {
			$this->log_err( 'AddToCart', $e );
		}
	}

	public function on_initiate_checkout() {
		try {
			if ( ! $this->has_consent() || ! function_exists( 'WC' ) || ! WC()->cart ) {
				return;
			}
			$cart = WC()->cart;
			$ids  = array();
			foreach ( $cart->get_cart() as $item ) {
				$ids[] = (string) ( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
			}
			$custom = array(
				'content_type' => 'product',
				'content_ids'  => array_values( array_unique( $ids ) ),
				'num_items'    => (int) $cart->get_cart_contents_count(),
				'value'        => (float) $cart->get_total( 'edit' ),
				'currency'     => get_woocommerce_currency(),
			);
			$this->queue( 'InitiateCheckout', 'ic.' . md5( implode( ',', $ids ) . $this->req_hash() ), $custom );
		} catch ( \Throwable $e ) {
			$this->log_err( 'InitiateCheckout', $e );
		}
	}

	// AddPaymentInfo: no faithful server hook exists (it is a client-side step);
	// approximated at order submission.
	public function on_order_processed( $order_id, $posted_data, $order ) {
		try {
			if ( ! $this->has_consent() || ! ( $order instanceof WC_Order ) ) {
				return;
			}
			$custom = array(
				'content_type' => 'product',
				'value'        => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
			);
			$this->queue( 'AddPaymentInfo', 'api.' . $order_id, $custom, $order );
		} catch ( \Throwable $e ) {
			$this->log_err( 'AddPaymentInfo', $e );
		}
	}

	public function on_purchase( $order_id ) {
		try {
			if ( ! $this->has_consent() || ! $order_id ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( ! ( $order instanceof WC_Order ) || $order->get_meta( self::PURCHASE_META ) ) {
				return; // already sent (dedup across payment_complete + thankyou)
			}
			$ids = array();
			foreach ( $order->get_items() as $item ) {
				$pid   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$ids[] = (string) $pid;
			}
			$custom = array(
				'content_type' => 'product',
				'content_ids'  => array_values( array_unique( $ids ) ),
				'num_items'    => (int) $order->get_item_count(),
				'value'        => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
				'order_id'     => (string) $order_id,
			);
			$this->queue( 'Purchase', 'purchase.' . $order_id, $custom, $order );
			$order->update_meta_data( self::PURCHASE_META, 1 );
			$order->save();
		} catch ( \Throwable $e ) {
			$this->log_err( 'Purchase', $e );
		}
	}

	private function log_err( $where, $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Meta CAPI for WooCommerce [' . $where . ']: ' . $e->getMessage() );
		}
	}

	/* ---------------- build + queue ---------------- */

	private function queue( $name, $event_id, $custom = array(), $order = null ) {
		$ctx = $this->order_ctx( $order );

		// Bots have no purchase intent and no usable identifiers, they only dilute the
		// dataset. Order-backed
		// events are never dropped: a gateway callback is UA-less but the sale is real.
		if ( empty( $ctx ) && ! $order instanceof WC_Order && $this->is_bot() ) {
			return;
		}

		// A gateway/cron request's URL is not the page the customer was on.
		$url = $this->current_url();
		if ( ! empty( $ctx['url'] ) && $this->request_ua() !== (string) $ctx['ua'] ) {
			$url = (string) $ctx['url'];
		}

		$event = array(
			'event_name'       => $name,
			'event_time'       => time(),
			'event_id'         => $event_id,
			'event_source_url' => $url,
			'action_source'    => 'website',
			'user_data'        => $this->user_data( $order, $ctx ),
		);
		if ( ! empty( $custom ) ) {
			$event['custom_data'] = $custom;
		}
		$event = apply_filters( 'mcapi_event', $event, $name );
		$this->events[] = $event;
	}

	/**
	 * Read or mint the first-party external_id cookie. Runs on `init` (before output),
	 * so the value persists across sessions and is available to every event this request.
	 */
	public function ensure_extid() {
		$this->ensure_fbc_cookie();
		if ( ! empty( $_COOKIE['mcapi_extid'] ) ) {
			$this->extid = sanitize_text_field( wp_unslash( $_COOKIE['mcapi_extid'] ) );
			return;
		}
		// Never mint an id for a request that cannot carry a cookie back (gateway
		// callbacks, cron, bots), a throwaway random external_id matches nobody.
		if ( '' === $this->request_ua() ) {
			return;
		}
		try {
			$this->extid = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			$this->extid = md5( uniqid( (string) wp_rand(), true ) );
		}
		if ( ! headers_sent() ) {
			$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
			$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
			setcookie( 'mcapi_extid', $this->extid, time() + 15552000, $path, $domain, is_ssl(), false ); // 180d, not httponly (usable by browser pixel later)
			$_COOKIE['mcapi_extid'] = $this->extid;
		}
	}

	private function user_data( $order = null, $ctx = null ) {
		$ctx = is_array( $ctx ) ? $ctx : $this->order_ctx( $order );

		// IP and UA must travel together and must come from the SAME request, or Meta
		// discards the pair ("sending an IP address but no user agent"). Prefer the
		// snapshot taken at checkout: on the request that actually fires Purchase the
		// live UA/IP is often the payment gateway's, or missing entirely.
		$ua = $this->request_ua();
		$ip = $this->client_ip();
		if ( ! empty( $ctx['ua'] ) ) {
			$ua = (string) $ctx['ua'];
			$ip = isset( $ctx['ip'] ) ? (string) $ctx['ip'] : '';
		}

		$ud = array();
		if ( '' !== $ua ) {
			$ud['client_user_agent'] = $ua;
			if ( '' !== $ip ) {
				$ud['client_ip_address'] = $ip;
			}
		}

		$fbp = ! empty( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '';
		if ( '' === $fbp && ! empty( $ctx['fbp'] ) ) {
			$fbp = (string) $ctx['fbp'];
		}
		if ( '' !== $fbp ) {
			$ud['fbp'] = $fbp;
		}
		$fbc = $this->fbc();
		if ( '' === $fbc && ! empty( $ctx['fbc'] ) ) {
			$fbc = (string) $ctx['fbc'];
		}
		if ( '' !== $fbc ) {
			$ud['fbc'] = $fbc;
		}

		// external_id on EVERY event: stable first-party cookie + (when known) the customer id.
		$ext   = array();
		$extid = ! empty( $ctx['extid'] ) ? (string) $ctx['extid'] : (string) $this->extid;
		if ( '' !== $extid ) {
			$ext[] = hash( 'sha256', $extid );
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$ext[] = hash( 'sha256', 'uid.' . get_current_user_id() );
		}
		if ( $order instanceof WC_Order && $order->get_customer_id() ) {
			$ext[] = hash( 'sha256', 'uid.' . $order->get_customer_id() );
		}
		if ( $ext ) {
			$ud['external_id'] = array_values( array_unique( $ext ) );
		}

		if ( $order instanceof WC_Order ) {
			// Order events: richest PII, straight from the order.
			$this->hset( $ud, 'em', $order->get_billing_email(), 'email' );
			$this->hset( $ud, 'ph', $order->get_billing_phone(), 'phone' );
			$this->hset( $ud, 'fn', $order->get_billing_first_name() );
			$this->hset( $ud, 'ln', $order->get_billing_last_name() );
			$this->hset( $ud, 'ct', $order->get_billing_city() );
			$this->hset( $ud, 'st', $order->get_billing_state() );
			$this->hset( $ud, 'zp', $order->get_billing_postcode() );
			$this->hset( $ud, 'country', $order->get_billing_country() );
		} else {
			// Top-of-funnel events: attach PII for KNOWN visitors to raise match quality.
			$this->add_known_user_pii( $ud );
		}
		return $ud;
	}

	/**
	 * Attach hashed PII on non-order events when the visitor is identifiable:
	 * a logged-in account, or a guest whose checkout email is already in the WC session.
	 */
	private function add_known_user_pii( &$ud ) {
		try {
			if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
				$u = wp_get_current_user();
				$this->hset( $ud, 'em', $u->user_email, 'email' );
				if ( class_exists( 'WC_Customer' ) ) {
					$c = new WC_Customer( get_current_user_id() );
					$this->hset( $ud, 'ph', $c->get_billing_phone(), 'phone' );
					$this->hset( $ud, 'fn', $c->get_billing_first_name() ?: $u->first_name );
					$this->hset( $ud, 'ln', $c->get_billing_last_name() ?: $u->last_name );
					$this->hset( $ud, 'ct', $c->get_billing_city() );
					$this->hset( $ud, 'st', $c->get_billing_state() );
					$this->hset( $ud, 'zp', $c->get_billing_postcode() );
					$this->hset( $ud, 'country', $c->get_billing_country() );
				}
			} elseif ( function_exists( 'WC' ) && WC()->customer && WC()->customer->get_billing_email() ) {
				$c = WC()->customer; // guest who entered email at checkout (session)
				$this->hset( $ud, 'em', $c->get_billing_email(), 'email' );
				$this->hset( $ud, 'ph', $c->get_billing_phone(), 'phone' );
				$this->hset( $ud, 'fn', $c->get_billing_first_name() );
				$this->hset( $ud, 'ln', $c->get_billing_last_name() );
				$this->hset( $ud, 'ct', $c->get_billing_city() );
				$this->hset( $ud, 'st', $c->get_billing_state() );
				$this->hset( $ud, 'zp', $c->get_billing_postcode() );
				$this->hset( $ud, 'country', $c->get_billing_country() );
			}
		} catch ( \Throwable $e ) {
			$this->log_err( 'known_user_pii', $e );
		}
	}

	// Normalize + SHA-256 hash a user_data field, appending as a single-element array.
	private function hset( &$ud, $key, $value, $kind = 'text' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return;
		}
		if ( 'email' === $kind ) {
			$value = strtolower( $value );
			if ( ! is_email( $value ) ) {
				return;
			}
		} elseif ( 'phone' === $kind ) {
			$digits = preg_replace( '/[^0-9]/', '', $value );
			// Optional normalization: local 0XXXXXXXXX -> <cc>XXXXXXXXX (E.164 without +).
			$cc = preg_replace( '/[^0-9]/', '', $this->cfg_string( 'MCAPI_PHONE_COUNTRY_CODE' ) );
			if ( '' !== $cc && '' !== $digits && '0' === $digits[0] ) {
				$digits = $cc . ltrim( $digits, '0' );
			}
			if ( '' === $digits ) {
				return;
			}
			$value = $digits;
		} else {
			// names/city/state/country: lowercase, strip whitespace + punctuation.
			$value = mb_strtolower( $value, 'UTF-8' );
			$value = preg_replace( '/[^\p{L}\p{N}]/u', '', $value );
			if ( '' === $value ) {
				return;
			}
		}
		$ud[ $key ] = array( hash( 'sha256', $value ) );
	}

	/**
	 * Snapshot the customer's browser context onto the order at checkout.
	 * Purchase (and often AddPaymentInfo) is emitted later from a request that is not the
	 * customer's, a payment-gateway callback, a cron status transition, or an admin action , 
	 * where HTTP_USER_AGENT and the _fbp/_fbc cookies simply do not exist. Replaying this
	 * snapshot is what keeps client_user_agent (and a real fbc) on those events.
	 */
	public function capture_order_context( $order_id, $posted_data = array(), $order = null ) {
		try {
			if ( $order_id instanceof WC_Order ) { // store-api signature: ( $order )
				$order = $order_id;
			}
			if ( ! ( $order instanceof WC_Order ) && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
			}
			if ( ! ( $order instanceof WC_Order ) ) {
				return;
			}
			$ua = $this->request_ua();
			if ( '' === $ua ) {
				return; // not a browser request, nothing worth snapshotting
			}
			$order->update_meta_data( self::ORDER_CTX_META, array(
				'ua'    => $ua,
				'ip'    => $this->client_ip(),
				'fbp'   => ! empty( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '',
				'fbc'   => $this->fbc(),
				'extid' => (string) $this->extid,
				'url'   => $this->checkout_source_url(),
			) );
			$order->save();
		} catch ( \Throwable $e ) {
			$this->log_err( 'capture_order_context', $e );
		}
	}

	/**
	 * The page URL to report for order events. The checkout POST lands on
	 * `/?wc-ajax=checkout`, which is a transport endpoint, not a page anyone visited , 
	 * reporting it as event_source_url pollutes URL-based rules. Prefer the referer the
	 * browser sent (the real checkout page), then the checkout page itself.
	 */
	private function checkout_source_url() {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( false === strpos( $req, 'wc-ajax' ) && false === strpos( $req, '/wp-json/' ) && false === strpos( $req, 'admin-ajax.php' ) ) {
			return $this->current_url();
		}
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$ref  = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			$host = wp_parse_url( $ref, PHP_URL_HOST );
			if ( $host && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return $ref;
			}
		}
		if ( function_exists( 'wc_get_checkout_url' ) ) {
			return esc_url_raw( wc_get_checkout_url() );
		}
		return home_url( '/checkout/' );
	}

	private function order_ctx( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return array();
		}
		try {
			$ctx = $order->get_meta( self::ORDER_CTX_META );
		} catch ( \Throwable $e ) {
			return array();
		}
		return is_array( $ctx ) ? $ctx : array();
	}

	private function request_ua() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		$ua = (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] );
		$ua = preg_replace( '/[\x00-\x1F\x7F]/', '', $ua );
		return trim( substr( $ua, 0, 512 ) );
	}

	/**
	 * Crawlers and uptime probes fire ViewContent/Search all day with an IP but no usable
	 * identity. Dropping them cleans the dataset; order-backed events bypass this check.
	 */
	private function is_bot() {
		$ua  = $this->request_ua();
		$bot = ( '' === $ua ) || (bool) preg_match(
			'~bot\b|bot/|spider|crawl|slurp|curl/|wget|headless|phantomjs|python-requests|python-urllib|scrapy|okhttp|java/|go-http|libwww|httpclient|apachebench|facebookexternalhit|ahrefs|semrush|mj12|dotbot|petalbot|bingpreview|pingdom|gtmetrix|lighthouse|uptimerobot|newrelic|datadog~i',
			$ua
		);
		return (bool) apply_filters( 'mcapi_is_bot', $bot, $ua );
	}

	private function fbc() {
		if ( ! empty( $_COOKIE['_fbc'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
		}
		if ( ! empty( $_COOKIE['mcapi_fbc'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['mcapi_fbc'] ) );
		}
		if ( ! empty( $_GET['fbclid'] ) ) {
			return $this->build_fbc( wp_unslash( $_GET['fbclid'] ) );
		}
		return '';
	}

	/** Meta's _fbc format: fb.<subdomainIndex>.<creationTime in MILLISECONDS>.<fbclid> */
	private function build_fbc( $fbclid ) {
		return 'fb.1.' . (string) (int) round( microtime( true ) * 1000 ) . '.' . sanitize_text_field( $fbclid );
	}

	/**
	 * Persist the click id ourselves, first-party. fbevents.js writes `_fbc` only when the
	 * browser pixel actually runs (blocked, late, or consent-gated often enough), and the
	 * fbclid is gone from the URL by the time the customer reaches checkout, which is why
	 * fbc coverage on server events is low. A 90-day first-party cookie keeps the click id
	 * available to every later event, Purchase included.
	 */
	private function ensure_fbc_cookie() {
		if ( empty( $_GET['fbclid'] ) || ! empty( $_COOKIE['_fbc'] ) || ! empty( $_COOKIE['mcapi_fbc'] ) ) {
			return;
		}
		$fbc = $this->build_fbc( wp_unslash( $_GET['fbclid'] ) );
		if ( ! headers_sent() ) {
			$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
			$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
			setcookie( 'mcapi_fbc', $fbc, time() + 7776000, $path, $domain, is_ssl(), false ); // 90d, matches Meta's fbc window
		}
		$_COOKIE['mcapi_fbc'] = $fbc;
	}

	private function trust_proxy() {
		return (bool) apply_filters( 'mcapi_trust_proxy_headers', $this->cfg_bool( 'MCAPI_TRUST_PROXY_HEADERS', false ) );
	}

	private function client_ip() {
		if ( $this->trust_proxy() ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) as $key ) {
				if ( ! empty( $_SERVER[ $key ] ) ) {
					$ip = trim( explode( ',', wp_unslash( $_SERVER[ $key ] ) )[0] );
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return $ip;
					}
				}
			}
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	// Page URL from the TRUSTED site host, not request-controlled HTTP_HOST.
	private function current_url() {
		$req   = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path  = wp_parse_url( $req, PHP_URL_PATH );
		$query = wp_parse_url( $req, PHP_URL_QUERY );
		if ( ! $path ) {
			$path = '/';
		}
		return esc_url_raw( home_url( $path . ( $query ? '?' . $query : '' ) ) );
	}

	private function req_hash() {
		return substr( md5( ( $_SERVER['REQUEST_URI'] ?? '' ) . ( $_SERVER['REMOTE_ADDR'] ?? '' ) . gmdate( 'YmdH' ) ), 0, 10 );
	}

	/* ---------------- send ---------------- */

	public function flush_events() {
		try {
			if ( empty( $this->events ) || '' === $this->pixel_id() || '' === $this->token() ) {
				return;
			}
			$payload      = array( 'data' => $this->events, 'access_token' => $this->token() );
			$this->events = array(); // drain: a second shutdown pass must not re-send the batch
			if ( '' !== $this->test_code() ) {
				$payload['test_event_code'] = $this->test_code();
			}
			// Flush the response to the visitor FIRST, then send server-to-server.
			// If FPM's fastcgi_finish_request is available the visitor never waits and we
			// can afford a blocking send; otherwise send fire-and-forget so page load is
			// never delayed by Meta's endpoint.
			$flushed = false;
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				$flushed = @fastcgi_finish_request();
			}
			$url = sprintf( 'https://graph.facebook.com/%s/%s/events', self::API_VERSION, $this->pixel_id() );
			$res = wp_remote_post( $url, array(
				'timeout'  => $flushed ? 5 : 2,
				'blocking' => (bool) $flushed, // non-blocking when we could not pre-flush to zero page latency
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $payload ),
			) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_wp_error( $res ) ) {
				error_log( 'Meta CAPI for WooCommerce send error: ' . $res->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			$this->log_err( 'flush', $e );
		}
	}

	public function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '' === $this->pixel_id() || '' === $this->token() ) {
			echo '<div class="notice notice-warning"><p><strong>Meta CAPI for WooCommerce:</strong> idle: pixel ID and/or CAPI token not set yet.</p></div>';
		} elseif ( '' !== $this->test_code() ) {
			echo '<div class="notice notice-info"><p><strong>Meta CAPI for WooCommerce:</strong> TEST mode: events go to Events Manager &rsaquo; Test Events. Clear <code>mcapi_test_code</code> to go live.</p></div>';
		}
	}
}

new MCAPI_Plugin();
