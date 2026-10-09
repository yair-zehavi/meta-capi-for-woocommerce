<?php
/**
 * Offline test harness for meta-capi-for-woocommerce.php.
 * Mocks WordPress + WooCommerce, loads the plugin, fires every hook, and captures
 * the CAPI payload WITHOUT any network call. Proves: no fatal on load, no exception
 * leaks into WC, correct event names + values + hashed PII. Run: php tests/test-harness.php
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_DEBUG', true );

$GLOBALS['__hooks']    = array();
$GLOBALS['__options']  = array(
	'mcapi_pixel_id'   => '1234567890',
	'mcapi_capi_token' => 'test-token-not-real',
	'mcapi_test_code'  => 'TEST00000',
);
$GLOBALS['__sent']     = array(); // captured wp_remote_post payloads

/* ---------------- WP stubs ---------------- */
function add_action( $h, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__hooks'][ $h ][] = $cb; }
function add_filter( $h, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__hooks'][ $h ][] = $cb; }
function apply_filters( $h, $value ) { return $value; } // default passthrough (consent=true, event unchanged)
function register_setting( $g, $n, $a = array() ) {}
function get_option( $n, $d = '' ) { return $GLOBALS['__options'][ $n ] ?? $d; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }
function wp_unslash( $s ) { return $s; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function esc_js( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function esc_url_raw( $s ) { return $s; }
function rawurlencode_wp( $s ) { return rawurlencode( $s ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function home_url( $p = '' ) { return 'https://shop.example.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function is_wp_error( $x ) { return false; }
function current_user_can( $c ) { return true; }
function wp_doing_ajax() { return false; }
function is_admin() { return false; }
function wc_get_checkout_url() { return 'https://shop.example.com/checkout/'; }
function get_woocommerce_currency() { return 'USD'; }
function wp_remote_post( $url, $args ) {
	$GLOBALS['__sent'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ), 'blocking' => $args['blocking'] );
	return array( 'response' => array( 'code' => 200 ) );
}
// identity stubs for external_id + known-user enrichment
$GLOBALS['__logged_in'] = false; $GLOBALS['__uid'] = 0; $GLOBALS['__guest_email'] = '';
function is_user_logged_in() { return $GLOBALS['__logged_in']; }
function get_current_user_id() { return $GLOBALS['__uid']; }
function wp_get_current_user() { $o = new stdClass; $o->user_email = 'member@example.com'; $o->first_name = 'Alex'; $o->last_name = 'Smith'; return $o; }
function is_ssl() { return true; }
function wp_rand() { return 12345; }
// (headers_sent is a real PHP builtin; the harness pre-sets the cookie so ensure_extid
//  reads it and returns before the setcookie branch , no redeclare needed.)
class WC_Customer { // logged-in customer
	function __construct( $id = 0 ) {}
	function get_billing_email() { return 'member@example.com'; }
	function get_billing_phone() { return '555-999-8877'; }
	function get_billing_first_name() { return 'Alex'; }
	function get_billing_last_name() { return 'Smith'; }
	function get_billing_city() { return 'Springfield'; }
	function get_billing_state() { return ''; }
	function get_billing_postcode() { return '97201'; }
	function get_billing_country() { return 'US'; }
}
class WC_Session_Customer { // guest session (WC()->customer)
	function get_billing_email() { return $GLOBALS['__guest_email']; }
	function get_billing_phone() { return $GLOBALS['__guest_email'] ? '555-111-2222' : ''; }
	function get_billing_first_name() { return $GLOBALS['__guest_email'] ? 'Guest' : ''; }
	function get_billing_last_name() { return ''; }
	function get_billing_city() { return ''; }
	function get_billing_state() { return ''; }
	function get_billing_postcode() { return ''; }
	function get_billing_country() { return $GLOBALS['__guest_email'] ? 'US' : ''; }
}

/* ---------------- WC context (page-type) stubs, overridable per test ---------------- */
$GLOBALS['__ctx'] = array( 'is_product' => false, 'is_search' => false, 'qid' => 0, 'search' => '' );
function is_product() { return $GLOBALS['__ctx']['is_product']; }
function is_search() { return $GLOBALS['__ctx']['is_search']; }
function get_search_query() { return $GLOBALS['__ctx']['search']; }
function get_queried_object_id() { return $GLOBALS['__ctx']['qid']; }

/* ---------------- WooCommerce mocks ----------------
 * NOTE: the WooCommerce class is defined LATER (see bottom), AFTER the plugin is
 * required , mirroring real load order (meta-capi-for-woocommerce loads before woocommerce).
 * This is what caught the class_exists() registration bug.
 */
class WC_Product_Mock {
	public $id; public $name; public $price;
	function __construct( $id, $name, $price ) { $this->id = $id; $this->name = $name; $this->price = $price; }
	function get_name() { return $this->name; }
	function get_price() { return $this->price; }
}
class WC_Order_Item_Mock {
	public $pid; public $vid;
	function __construct( $pid, $vid = 0 ) { $this->pid = $pid; $this->vid = $vid; }
	function get_product_id() { return $this->pid; }
	function get_variation_id() { return $this->vid; }
}
class WC_Order {
	private static $meta = array(); // static = mirrors DB persistence across instances
	function get_id() { return 10001; }
	function get_total() { return 289.90; }
	function get_currency() { return 'USD'; }
	function get_item_count() { return 2; }
	function get_items() { return array( new WC_Order_Item_Mock( 101 ), new WC_Order_Item_Mock( 102, 205 ) ); }
	function get_billing_email() { return ' Jordan.Test@Example.com '; }
	function get_billing_phone() { return '555-123-4567'; }
	function get_billing_first_name() { return 'Jordan'; }
	function get_billing_last_name() { return "O'Brien"; }
	function get_billing_city() { return 'Portland'; }
	function get_billing_state() { return ''; }
	function get_billing_postcode() { return '97201'; }
	function get_billing_country() { return 'US'; }
	function get_customer_id() { return 42; }
	function get_meta( $k ) { return self::$meta[ $k ] ?? ''; }
	function update_meta_data( $k, $v ) { self::$meta[ $k ] = $v; }
	function save() { return true; }
}
class WC_Cart_Mock {
	function get_cart() { return array(
		array( 'product_id' => 101, 'variation_id' => 0 ),
		array( 'product_id' => 102, 'variation_id' => 205 ),
	); }
	function get_cart_contents_count() { return 2; }
	function get_total( $ctx = '' ) { return 289.90; }
}
class WC_Container_Mock { public $cart; public $customer; function __construct() { $this->cart = new WC_Cart_Mock(); $this->customer = new WC_Session_Customer(); } }
function WC() { static $c; if ( ! $c ) { $c = new WC_Container_Mock(); } return $c; }
function wc_get_product( $id ) {
	$map = array( 101 => array( 'Sample Product A', 39.90 ), 102 => array( 'Sample Product B', 249.90 ), 205 => array( 'Sample Product B Blue', 259.90 ) );
	if ( ! isset( $map[ $id ] ) ) { return false; }
	return new WC_Product_Mock( $id, $map[ $id ][0], $map[ $id ][1] );
}
// Real WooCommerce caches the order instance and save() persists meta to DB, so a
// later wc_get_order() in the SAME or a LATER request sees the sent-flag. Mirror that
// with a shared singleton so dedup is tested faithfully.
function wc_get_order( $id ) { static $o; if ( ! $id ) { return false; } if ( ! $o ) { $o = new WC_Order(); } return $o; }

/* ---------------- load the plugin ---------------- */
$_SERVER['REQUEST_URI']     = '/product/sample-product-b/';
$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
$_COOKIE['_fbp']            = 'fb.1.1700000000.1234567890';

// At THIS point WooCommerce is NOT defined (mirrors real load order: our plugin
// loads before woocommerce). class_exists('WooCommerce') is false right now.
$wc_loaded_at_construct = class_exists( 'WooCommerce' );

require dirname( __DIR__ ) . '/meta-capi-for-woocommerce.php';

// The commerce hooks MUST be registered even though WooCommerce wasn't loaded yet.
$atc_registered = ! empty( $GLOBALS['__hooks']['woocommerce_add_to_cart'] );

// Now WooCommerce "loads" (conditional so PHP does not hoist it above the require).
if ( true ) { class WooCommerce {} }

function fire( $hook, ...$args ) {
	foreach ( $GLOBALS['__hooks'][ $hook ] ?? array() as $cb ) { $cb( ...$args ); }
}

echo "== Load-order check ==\n";
echo "  WooCommerce defined at construct time: " . var_export( $wc_loaded_at_construct, true ) . " (real world: false)\n";
echo "  woocommerce_add_to_cart hook registered anyway: " . var_export( $atc_registered, true ) . "\n\n";

echo "== Plugin loaded OK (no fatal) ==\n";
echo "Registered hooks: " . implode( ', ', array_keys( $GLOBALS['__hooks'] ) ) . "\n\n";

// returning visitor: external_id cookie already present -> ensure_extid reads it on init
$_COOKIE['mcapi_extid'] = 'visitor-abc-123';
fire( 'init' );

// 1) ViewContent (single product page)
$GLOBALS['__ctx'] = array( 'is_product' => true, 'is_search' => false, 'qid' => 102, 'search' => '' );
fire( 'template_redirect' );

// 2) Search
$GLOBALS['__ctx'] = array( 'is_product' => false, 'is_search' => true, 'qid' => 0, 'search' => 'diapers' );
fire( 'template_redirect' );

// 3) AddToCart
fire( 'woocommerce_add_to_cart', 'ck_abc', 102, 1, 205, array(), array() );

// 4) InitiateCheckout
fire( 'woocommerce_before_checkout_form' );

// 5) AddPaymentInfo (order processed) , the customer's own checkout POST
$_SERVER['REQUEST_URI']  = '/?wc-ajax=checkout';   // real Woo checkout transport, not a page
$_SERVER['HTTP_REFERER'] = 'https://shop.example.com/checkout/';
fire( 'woocommerce_checkout_order_processed', 10001, array(), new WC_Order() );
unset( $_SERVER['HTTP_REFERER'] );

// 6) Purchase , fired from the payment gateway's server-to-server callback:
//    no user agent, no cookies, the GATEWAY's IP. This is the real-world path that
//    produced Meta's "IP address without user agent" error on Purchase.
$browser_ua      = $_SERVER['HTTP_USER_AGENT'];
$browser_cookies = $_COOKIE;
unset( $_SERVER['HTTP_USER_AGENT'] );
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';           // payment provider, not the shopper
$_SERVER['REQUEST_URI'] = '/?wc-api=gateway_callback';
$_COOKIE                = array();
fire( 'woocommerce_payment_complete', 10001 );
// ...then the shopper lands on the thank-you page (must dedup, not re-send)
$_SERVER['HTTP_USER_AGENT'] = $browser_ua;
$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
$_SERVER['REQUEST_URI']     = '/checkout/order-received/10001/';
$_COOKIE                    = $browser_cookies;
fire( 'woocommerce_thankyou', 10001 );

// flush (captures payload, no network)
fire( 'shutdown' );

echo "== Captured " . count( $GLOBALS['__sent'] ) . " CAPI POST(s) ==\n";
foreach ( $GLOBALS['__sent'] as $i => $s ) {
	echo "\nPOST #" . ( $i + 1 ) . "  blocking=" . var_export( $s['blocking'], true ) . "  -> " . $s['url'] . "\n";
	echo "  test_event_code: " . ( $s['body']['test_event_code'] ?? '(none)' ) . "\n";
	foreach ( $s['body']['data'] as $ev ) {
		$ud   = $ev['user_data'];
		$cd   = $ev['custom_data'] ?? array();
		$pii  = array_intersect_key( $ud, array_flip( array( 'em','ph','fn','ln','ct','st','zp','country','external_id','fbp','fbc' ) ) );
		echo sprintf(
			"   - %-16s id=%-22s val=%-8s cur=%-3s ids=%s\n       user_data keys: %s\n",
			$ev['event_name'],
			$ev['event_id'],
			isset( $cd['value'] ) ? $cd['value'] : '-',
			isset( $cd['currency'] ) ? $cd['currency'] : '-',
			isset( $cd['content_ids'] ) ? json_encode( $cd['content_ids'] ) : '-',
			implode( ',', array_keys( $ud ) )
		);
		// show that PII is hashed (64-hex), never raw
		foreach ( array( 'em','ph','fn','ln' ) as $k ) {
			if ( isset( $ud[ $k ][0] ) ) {
				$h = $ud[ $k ][0];
				echo "        $k = " . substr( $h, 0, 16 ) . "...  (" . ( preg_match( '/^[a-f0-9]{64}$/', $h ) ? 'valid sha256' : 'NOT HASHED!' ) . ")\n";
			}
		}
	}
}

/* ---------------- assertions ---------------- */
echo "\n== Assertions ==\n";
$events = array();
foreach ( $GLOBALS['__sent'] as $s ) { foreach ( $s['body']['data'] as $ev ) { $events[] = $ev['event_name']; } }
$counts = array_count_values( $events );
function check( $label, $cond ) { echo ( $cond ? "  PASS  " : "  FAIL  " ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['__fail'] = true; } }

check( 'Commerce hooks register even though WooCommerce loads AFTER us (load-order bug fix)', $atc_registered === true );
check( 'ViewContent fired once',       ( $counts['ViewContent'] ?? 0 ) === 1 );
check( 'Search fired once',            ( $counts['Search'] ?? 0 ) === 1 );
check( 'AddToCart fired once',         ( $counts['AddToCart'] ?? 0 ) === 1 );
check( 'InitiateCheckout fired once',  ( $counts['InitiateCheckout'] ?? 0 ) === 1 );
check( 'AddPaymentInfo fired once',    ( $counts['AddPaymentInfo'] ?? 0 ) === 1 );
check( 'Purchase fired EXACTLY once (dedup across payment_complete+thankyou)', ( $counts['Purchase'] ?? 0 ) === 1 );
check( 'No PageView sent server-side', ! isset( $counts['PageView'] ) );

// find purchase event, verify hashed PII + value
$purchase = null;
foreach ( $GLOBALS['__sent'] as $s ) { foreach ( $s['body']['data'] as $ev ) { if ( 'Purchase' === $ev['event_name'] ) { $purchase = $ev; } } }
check( 'Purchase carries hashed email',  isset( $purchase['user_data']['em'][0] ) && preg_match( '/^[a-f0-9]{64}$/', $purchase['user_data']['em'][0] ) );
check( 'Purchase carries hashed phone',  isset( $purchase['user_data']['ph'][0] ) );
check( 'Purchase value = 289.9',         abs( ( $purchase['custom_data']['value'] ?? 0 ) - 289.90 ) < 0.001 );
check( 'Purchase event_id = purchase.10001', ( $purchase['event_id'] ?? '' ) === 'purchase.10001' );
check( 'client_ip captured',             ( $purchase['user_data']['client_ip_address'] ?? '' ) === '203.0.113.9' );
check( 'fbp cookie captured',            ( $purchase['user_data']['fbp'] ?? '' ) === 'fb.1.1700000000.1234567890' );

// --- v1.0.4: IP + UA must travel together, from the customer's request ---
check( 'Purchase from gateway callback still carries client_user_agent',
	( $purchase['user_data']['client_user_agent'] ?? '' ) === 'Mozilla/5.0 Test' );
check( 'Purchase IP is the SHOPPER\'s, not the gateway\'s',
	( $purchase['user_data']['client_ip_address'] ?? '' ) === '203.0.113.9'
	&& false === strpos( json_encode( $purchase['user_data'] ), '198.51.100.77' ) );
check( 'Purchase event_source_url is the checkout PAGE, not the gateway callback endpoint, not wc-ajax',
	( $purchase['event_source_url'] ?? '' ) === 'https://shop.example.com/checkout/' );
$ip_no_ua = array();
foreach ( $GLOBALS['__sent'] as $s2 ) { foreach ( $s2['body']['data'] as $ev ) {
	if ( ! empty( $ev['user_data']['client_ip_address'] ) && empty( $ev['user_data']['client_user_agent'] ) ) { $ip_no_ua[] = $ev['event_name']; }
} }
check( 'NO event sends client_ip_address without client_user_agent' . ( $ip_no_ua ? ' (offenders: ' . implode( ',', $ip_no_ua ) . ')' : '' ), empty( $ip_no_ua ) );
check( 'test_event_code present (TEST mode)', ( $GLOBALS['__sent'][0]['body']['test_event_code'] ?? '' ) === 'TEST00000' );

// verify raw PII never leaks
$raw = json_encode( $GLOBALS['__sent'] );
check( 'No raw email in payload',  stripos( $raw, 'jordan.test@' ) === false );
check( 'No raw phone in payload',  strpos( $raw, '5551234567' ) === false && strpos( $raw, '15551234567' ) === false );

// --- v1.0.3: external_id everywhere + known-user enrichment ---
$vc = null;
foreach ( $GLOBALS['__sent'] as $s ) { foreach ( $s['body']['data'] as $ev ) { if ( 'ViewContent' === $ev['event_name'] ) { $vc = $ev; } } }
$expect_ext = hash( 'sha256', 'visitor-abc-123' );
check( 'external_id on anonymous ViewContent (first-party cookie)', isset( $vc['user_data']['external_id'] ) && in_array( $expect_ext, $vc['user_data']['external_id'], true ) );
check( 'anonymous ViewContent has NO email (guest, no session email)', ! isset( $vc['user_data']['em'] ) );

// logged-in visitor: top-funnel ViewContent should now carry hashed email + uid external_id
$GLOBALS['__sent'] = array();
$GLOBALS['__logged_in'] = true; $GLOBALS['__uid'] = 42;
$GLOBALS['__ctx'] = array( 'is_product' => true, 'is_search' => false, 'qid' => 102, 'search' => '' );
fire( 'template_redirect' );
fire( 'shutdown' );
$vc2 = null;
foreach ( $GLOBALS['__sent'] as $s ) { foreach ( $s['body']['data'] as $ev ) { if ( 'ViewContent' === $ev['event_name'] && isset( $ev['user_data']['em'] ) ) { $vc2 = $ev; } } }
check( 'logged-in ViewContent carries hashed email', isset( $vc2['user_data']['em'][0] ) && preg_match( '/^[a-f0-9]{64}$/', $vc2['user_data']['em'][0] ) );
check( 'logged-in ViewContent external_id has cookie + uid (>=2)', isset( $vc2['user_data']['external_id'] ) && count( $vc2['user_data']['external_id'] ) >= 2 );
check( 'logged-in enrichment never leaks raw email', stripos( json_encode( $GLOBALS['__sent'] ), 'member@example.com' ) === false );

// --- v1.0.4: bot traffic never becomes an event ---
$GLOBALS['__sent'] = array();
$GLOBALS['__logged_in'] = false; $GLOBALS['__uid'] = 0;
$real_ua = $_SERVER['HTTP_USER_AGENT'];
$GLOBALS['__ctx'] = array( 'is_product' => false, 'is_search' => true, 'qid' => 0, 'search' => 'walker' );
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ExampleBot/1.0; +http://example.com/bot/)';
fire( 'template_redirect' );
fire( 'shutdown' );
check( 'Search from a named crawler is dropped', empty( $GLOBALS['__sent'] ) );

$GLOBALS['__sent'] = array();
unset( $_SERVER['HTTP_USER_AGENT'] );
fire( 'template_redirect' );
fire( 'shutdown' );
check( 'Search from a UA-less scraper is dropped', empty( $GLOBALS['__sent'] ) );
$_SERVER['HTTP_USER_AGENT'] = $real_ua;

// --- v1.0.4: fbc coverage , click id persisted first-party, milliseconds format ---
$GLOBALS['__sent'] = array();
$_GET['fbclid'] = 'IwAR0TestClickId';
fire( 'init' );                       // ensure_fbc_cookie mints mcapi_fbc
unset( $_GET['fbclid'] );             // click id gone from the URL, as on any later page
$GLOBALS['__ctx'] = array( 'is_product' => true, 'is_search' => false, 'qid' => 102, 'search' => '' );
fire( 'template_redirect' );
fire( 'shutdown' );
$fbc_ev = $GLOBALS['__sent'][0]['body']['data'][0]['user_data']['fbc'] ?? '';
check( 'fbc survives after fbclid leaves the URL (first-party cookie)', false !== strpos( $fbc_ev, 'IwAR0TestClickId' ) );
check( 'fbc timestamp is in MILLISECONDS (Meta format)', (bool) preg_match( '/^fb\.1\.\d{13}\./', $fbc_ev ) );

echo "\n" . ( empty( $GLOBALS['__fail'] ) ? "ALL PASS " : "SOME FAILED " ) . "\n";
exit( empty( $GLOBALS['__fail'] ) ? 0 : 1 );
