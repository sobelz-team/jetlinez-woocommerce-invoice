<?php
/** Standalone API discovery, routing and admin authorization regression tests. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'JLWI_OPTION', 'jlwi_settings' );
define( 'JLWI_TEXT_DOMAIN', 'jetlinez-woocommerce-invoice' );
define( 'JLWI_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );

$settings = array();
$requests = array();
$responses = array();
$authorized = true;
$valid_nonce = true;

function get_option( $key, $default = false ) { return JLWI_OPTION === $key ? $GLOBALS['settings'] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['settings'] = $value; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function home_url( $path = '' ) { return 'https://store.test' . $path; }
function admin_url( $path = '' ) { return 'https://store.test/wp-admin/' . $path; }
function apply_filters( $hook, $value ) { return $value; }
function __( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_uuid4() { return '12345678-1234-4234-8234-123456789abc'; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function wp_unslash( $value ) { return $value; }
function esc_url_raw( $value ) { return $value; }
function current_user_can( $capability ) { return $GLOBALS['authorized']; }
function check_ajax_referer( $action ) { if ( ! $GLOBALS['valid_nonce'] ) throw new RuntimeException( 'invalid nonce' ); }
function check_admin_referer( $action ) { check_ajax_referer( $action ); }
function wp_send_json_error( $data, $status = 200 ) { throw new JLWI_JSON_Result( false, $data, $status ); }
function wp_send_json_success( $data ) { throw new JLWI_JSON_Result( true, $data ); }
function set_transient() {}
function get_current_user_id() { return 1; }
function wp_safe_redirect( $url ) { throw new RuntimeException( 'redirect' ); }

function wp_remote_request( $url, $args ) {
	$GLOBALS['requests'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['responses'] );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $header ) { return ''; }

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class JLWI_JSON_Result extends RuntimeException {
	public $success;
	public $data;
	public $status;
	public function __construct( $success, $data, $status = 200 ) { $this->success = $success; $this->data = $data; $this->status = $status; }
}
class JLWI_Daily_Report { public static function reschedule() {} }
class JLWI_Weekly_Report { public static function reschedule() {} }

require dirname( __DIR__ ) . '/includes/class-jlwi-settings.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-api-client.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-admin.php';

function check( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function response( $data, $status = 200 ) {
	return array( 'response' => array( 'code' => $status ), 'body' => json_encode( $data ) );
}
function discover_ajax() {
	try { ( new JLWI_Admin() )->discover_destinations(); } catch ( JLWI_JSON_Result $result ) { return $result; }
	throw new RuntimeException( 'Missing JSON response' );
}

// Account discovery must work before the first device or flow has been chosen.
$settings = array( 'api_key' => 'saved-key' );
$responses = array(
	response( array( 'data' => array(
		array( 'id' => 'wa-1', 'name' => 'WhatsApp', 'channel' => 'whatsapp', 'private' => 'hidden' ),
		array( 'id' => 'tg-1', 'name' => 'Telegram', 'channel' => 'telegram' ),
		array( 'id' => 'other', 'channel' => 'unsupported' ),
		array( 'name' => 'missing-id' ),
	) ) ),
	response( array( 'data' => array( array( 'id' => 'flow-1', 'name' => 'Fallback', 'steps' => array() ) ) ) ),
);
$client = new JLWI_API_Client();
$lists = $client->discover_destinations();
check( ! is_wp_error( $lists ), 'Discovery incorrectly requires a destination.' );
check( count( $lists['devices'] ) === 2 && count( $lists['flows'] ) === 1, 'Account lists were not normalized.' );
check( ! isset( $lists['devices'][0]['private'] ), 'Discovery returned unrelated account data.' );
check( '/api/v1/devices' === parse_url( $requests[0]['url'], PHP_URL_PATH ), 'Device list endpoint is incorrect.' );
check( '/api/v1/send-flows' === parse_url( $requests[1]['url'], PHP_URL_PATH ), 'Flow list endpoint is incorrect.' );
check( 'GET' === $requests[0]['args']['method'] && 'saved-key' === $requests[0]['args']['headers']['X-API-KEY'], 'Discovery authentication is incorrect.' );
check( ! JLWI_Settings::is_api_configured(), 'Sending must still require a destination.' );

// Independent permissions and malformed responses must not erase a usable list.
$responses = array(
	response( array( 'message' => 'Devices forbidden for saved-key' ), 403 ),
	response( array( 'data' => array( 'items' => array( array( 'id' => 'flow-2' ) ) ) ) ),
);
$lists = $client->discover_destinations();
check( isset( $lists['errors']['devices'] ) && count( $lists['flows'] ) === 1, 'Partial discovery failure hid the valid flow list.' );
check( false === strpos( json_encode( $lists ), 'saved-key' ), 'Discovery exposed a key echoed in an upstream error.' );
$responses = array( response( array( 'data' => array( 'unexpected' => 'shape' ) ) ), response( array( 'data' => array() ) ) );
$lists = $client->discover_destinations();
check( isset( $lists['errors']['devices'] ) && ! isset( $lists['errors']['flows'] ), 'Malformed and empty lists were not distinguished.' );

// Legacy settings retain direct WhatsApp behavior without any migration write.
$settings = array( 'api_key' => 'saved-key', 'device_id' => 'legacy-device' );
check( 'whatsapp' === JLWI_Settings::get( 'send_method' ) && JLWI_Settings::is_api_configured(), 'Legacy configuration regressed.' );

foreach ( array( 'whatsapp', 'telegram', 'flow' ) as $method ) {
	$settings = array( 'api_key' => 'key', 'send_method' => $method, 'without_delay' => 'yes' );
	$destination_key = array( 'whatsapp' => 'device_id', 'telegram' => 'telegram_device_id', 'flow' => 'flow_id' )[ $method ];
	$settings[ $destination_key ] = 'destination/id';
	check( JLWI_Settings::is_api_configured(), $method . ' required a destination from another method.' );
	$client = new JLWI_API_Client();
	foreach ( array( array( 'Hello فارسی', '' ), array( '', 'media-1' ) ) as $content ) {
		$responses = array( response( array( 'success' => true, 'data' => array( 'id' => 'accepted' ) ) ) );
		$result = $client->send_message( '989121234567', $content[0], $content[1] );
		check( ! is_wp_error( $result ), $method . ' send failed.' );
		$request = end( $requests );
		$endpoint = array( 'whatsapp' => 'whatsapps', 'telegram' => 'telegrams', 'flow' => 'send-flows' )[ $method ];
		check( false !== strpos( $request['url'], '/' . $endpoint . '/destination%2Fid/message' ), $method . ' used the wrong endpoint.' );
		$payload = json_decode( $request['args']['body'], true );
		$recipient_key = 'whatsapp' === $method ? 'phonenumber' : 'phone';
		check( '989121234567' === $payload[ $recipient_key ], $method . ' used the wrong recipient field.' );
		check( 'POST' === $request['args']['method'], 'Messages must use POST.' );
		check( '' === $content[0] ? ! isset( $payload['text'] ) : $payload['text'] === $content[0], 'Text payload changed.' );
		check( '' === $content[1] ? ! isset( $payload['mediaId'] ) : $payload['mediaId'] === $content[1], 'File payload changed.' );
		if ( 'flow' === $method ) {
			check( false === $payload['withDelay'] && ! empty( $payload['requestId'] ), 'Flow delay or request ID is missing.' );
			check( false === strpos( $request['url'], '?' ), 'Flow delay must be in the JSON body.' );
		} else {
			check( false !== strpos( $request['url'], '?without_delay=true' ), 'Direct delay query is missing.' );
		}
	}
	$count = count( $requests );
	check( is_wp_error( $client->send_message( 'bad', 'text' ) ), 'Invalid recipient accepted.' );
	check( is_wp_error( $client->send_message( '989121234567' ) ), 'Empty content accepted.' );
	check( $count === count( $requests ), 'Invalid messages made an HTTP request.' );
	unset( $settings[ $destination_key ] );
	check( ! JLWI_Settings::is_api_configured() && is_wp_error( ( new JLWI_API_Client() )->validate_configuration() ), 'Missing selected destination accepted.' );
}

// Invalid credentials and method fail before the network call.
$count = count( $requests );
check( is_wp_error( ( new JLWI_API_Client( array( 'api_key' => '' ) ) )->discover_destinations() ), 'Missing API key accepted.' );
check( is_wp_error( ( new JLWI_API_Client( array( 'send_method' => array() ) ) )->validate_configuration() ), 'Invalid send method accepted.' );
check( count( $requests ) === $count, 'Invalid configuration made a request.' );

// Admin discovery must authorize both capability and nonce before contacting Jetlinez.
$admin = new JLWI_Admin();
$authorized = false;
$json = discover_ajax();
check( ! $json->success && 403 === $json->status && count( $requests ) === $count, 'Unauthorized discovery was not rejected.' );
$authorized = true;
$valid_nonce = false;
try { discover_ajax(); check( false, 'Invalid nonce accepted.' ); } catch ( RuntimeException $error ) { check( 'invalid nonce' === $error->getMessage(), 'Unexpected nonce failure.' ); }
$valid_nonce = true;
$settings = array( 'api_key' => 'saved-key' );
$_POST = array( 'api_key' => "new-key\r\n", 'api_base_url' => 'https://account.test/api/v1' );
$responses = array( response( array( 'data' => array() ) ), response( array( 'data' => array() ) ) );
$json = discover_ajax();
check( $json->success && 'saved-key' === $settings['api_key'], 'Discovery persisted an unsaved API key.' );
$request = end( $requests );
check( 'new-key' === $request['args']['headers']['X-API-KEY'] && false !== strpos( $request['url'], 'https://account.test/' ), 'Unsaved credentials were not used.' );
check( false === strpos( json_encode( $json->data ), 'new-key' ), 'Discovery exposed credentials.' );

// Connection saves preserve all other tabs and retain inactive destinations.
$settings = array_merge( JLWI_Settings::defaults(), array( 'api_key' => 'saved-key', 'report_recipients' => '09123456789' ) );
$_POST = array( 'jlwi_tab' => 'connection', 'jlwi' => array( 'api_base_url' => 'https://my.jetlinez.com/api/v1', 'send_method' => 'flow', 'device_id' => 'wa', 'telegram_device_id' => 'tg', 'flow_id' => 'flow' ) );
try { $admin->save_settings(); } catch ( RuntimeException $error ) { check( 'redirect' === $error->getMessage(), 'Unexpected save failure: ' . $error->getMessage() ); }
check( 'flow' === $settings['send_method'] && 'flow' === $settings['flow_id'], 'Connection route was not saved.' );
check( 'wa' === $settings['device_id'] && 'tg' === $settings['telegram_device_id'], 'Inactive destinations were lost.' );
check( 'saved-key' === $settings['api_key'] && '09123456789' === $settings['report_recipients'], 'Saving connection overwrote unrelated settings.' );

// Constants cannot be replaced by browser input during discovery or saving.
define( 'JLWI_API_KEY', 'constant-key' );
define( 'JLWI_API_BASE_URL', 'https://constant.test/api/v1' );
define( 'JLWI_DEVICE_ID', 'constant-device' );
$_POST = array( 'api_key' => 'untrusted-override', 'api_base_url' => 'https://override.test' );
$responses = array( response( array( 'data' => array() ) ), response( array( 'data' => array() ) ) );
check( discover_ajax()->success, 'Constant-based discovery failed.' );
$request = end( $requests );
check( 'constant-key' === $request['args']['headers']['X-API-KEY'] && false !== strpos( $request['url'], 'https://constant.test/' ), 'Discovery overrode a locked constant.' );
$_POST = array( 'jlwi_tab' => 'connection', 'jlwi' => array( 'send_method' => 'whatsapp', 'api_key' => 'override', 'clear_api_key' => 'yes', 'device_id' => 'override' ) );
try { $admin->save_settings(); } catch ( RuntimeException $error ) { check( 'redirect' === $error->getMessage(), 'Constant save failed.' ); }
check( 'saved-key' === $settings['api_key'] && 'wa' === $settings['device_id'] && 'constant-device' === JLWI_Settings::get( 'device_id' ), 'Saving replaced a locked constant or its underlying saved value.' );

fwrite( STDOUT, "API routing and discovery smoke test passed.\n" );
