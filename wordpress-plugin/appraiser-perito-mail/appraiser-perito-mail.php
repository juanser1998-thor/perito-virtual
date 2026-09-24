<?php
/**
 * Plugin Name: Appraiser Perito Virtual - Correo PDF
 * Description: Genera y envía por correo el concepto preliminar de Perito Virtual.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Juan Sebastian
 * Text Domain: appraiser-perito-mail
 *
 * @package Appraiser_Perito_Mail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-appraiser-perito-pdf.php';

final class Appraiser_Perito_Mail {

	const VERSION = '1.0.0';
	const OPTION_KEY = 'appraiser_perito_mail_options';
	const REST_NAMESPACE = 'appraiser-perito/v1';
	const REST_ROUTE = '/enviar';

	/**
	 * Inicia el plugin.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Registra el endpoint público utilizado por el formulario.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'send_report' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Atiende la solicitud, genera el PDF y envía el mensaje.
	 *
	 * @param WP_REST_Request $request Solicitud REST.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function send_report( WP_REST_Request $request ) {
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;
		if ( $content_length > 100000 ) {
			return new WP_Error( 'payload_too_large', 'La solicitud supera el tamaño permitido.', array( 'status' => 413 ) );
		}

		if ( ! self::origin_is_allowed() ) {
			return new WP_Error(
				'origin_not_allowed',
				'El origen de la solicitud no está autorizado.',
				array( 'status' => 403 )
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		if ( ! empty( $params['website'] ) ) {
			return new WP_Error( 'invalid_request', 'No fue posible procesar la solicitud.', array( 'status' => 400 ) );
		}

		$data = self::sanitize_payload( $params );
		$validation = self::validate_payload( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! self::consume_rate_limit() ) {
			return new WP_Error(
				'rate_limit',
				'Se alcanzó el límite temporal de envíos. Intenta nuevamente más tarde.',
				array( 'status' => 429 )
			);
		}

		$estimate = self::calculate_estimate( $data );
		$pdf = Appraiser_Perito_PDF::create( $data, $estimate );
		$pdf_path = trailingslashit( get_temp_dir() ) . 'concepto-perito-' . wp_generate_password( 12, false, false ) . '.pdf';

		if ( false === file_put_contents( $pdf_path, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'pdf_error', 'No fue posible crear el PDF.', array( 'status' => 500 ) );
		}

		$options = self::options();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $options['copy_email'] ) && is_email( $options['copy_email'] ) ) {
			$headers[] = 'Bcc: ' . sanitize_email( $options['copy_email'] );
		}

		if ( ! empty( $options['from_email'] ) && is_email( $options['from_email'] ) ) {
			add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ) );
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ) );
		}

		$subject = sprintf( 'Concepto preliminar - %s, %s', $data['address'], $data['city'] );
		$message = self::email_html( $data, $estimate );
		$sent = wp_mail( $data['email'], $subject, $message, $headers, array( $pdf_path ) );

		remove_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ) );
		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ) );
		wp_delete_file( $pdf_path );

		if ( ! $sent ) {
			return new WP_Error(
				'mail_error',
				'WordPress no pudo entregar el mensaje al servidor de correo. Revisa la configuración de correo del alojamiento.',
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf( 'El PDF fue enviado a %s.', $data['email'] ),
			),
			200
		);
	}

	/**
	 * Limpia todos los campos recibidos.
	 *
	 * @param array $params Datos originales.
	 * @return array
	 */
	private static function sanitize_payload( $params ) {
		$text_fields = array(
			'city', 'neighborhood', 'address', 'stratum', 'propertyType', 'finishes',
			'condition', 'storage', 'titleType', 'purpose', 'latitude', 'longitude',
		);
		$number_fields = array(
			'age', 'privateArea', 'freeArea', 'parking', 'bedrooms', 'privateBathrooms',
			'socialBathrooms', 'cadastralValue', 'administration',
		);
		$data = array();

		foreach ( $text_fields as $field ) {
			$data[ $field ] = isset( $params[ $field ] ) ? sanitize_text_field( wp_unslash( $params[ $field ] ) ) : '';
		}
		foreach ( $number_fields as $field ) {
			$data[ $field ] = isset( $params[ $field ] ) && is_numeric( $params[ $field ] ) ? (float) $params[ $field ] : 0;
		}

		$data['email'] = isset( $params['email'] ) ? sanitize_email( wp_unslash( $params['email'] ) ) : '';
		$data['gated'] = ! empty( $params['gated'] );
		$data['serviceRoom'] = ! empty( $params['serviceRoom'] );
		$data['serviceBathroom'] = ! empty( $params['serviceBathroom'] );
		$data['amenities'] = array();

		if ( ! empty( $params['amenities'] ) && is_array( $params['amenities'] ) ) {
			$data['amenities'] = array_values( array_unique( array_map( 'sanitize_text_field', $params['amenities'] ) ) );
			$data['amenities'] = array_slice( $data['amenities'], 0, 12 );
		}

		return $data;
	}

	/**
	 * Valida los campos indispensables y sus límites.
	 *
	 * @param array $data Datos normalizados.
	 * @return true|WP_Error
	 */
	private static function validate_payload( $data ) {
		$required = array( 'city', 'neighborhood', 'address', 'stratum', 'propertyType', 'finishes', 'condition', 'titleType', 'purpose' );
		foreach ( $required as $field ) {
			if ( '' === trim( (string) $data[ $field ] ) ) {
				return new WP_Error( 'missing_fields', 'Faltan datos obligatorios del inmueble.', array( 'status' => 422 ) );
			}
		}

		if ( ! is_email( $data['email'] ) ) {
			return new WP_Error( 'invalid_email', 'El correo destinatario no es válido.', array( 'status' => 422 ) );
		}

		if ( $data['privateArea'] < 15 || $data['privateArea'] > 5000 || $data['age'] < 0 || $data['age'] > 150 ) {
			return new WP_Error( 'invalid_values', 'El área o la edad del inmueble están fuera del rango permitido.', array( 'status' => 422 ) );
		}

		$allowed_types = array( 'apartment', 'house' );
		$allowed_finishes = array( 'basic', 'standard', 'superior', 'luxury' );
		$allowed_conditions = array( 'new', 'excellent', 'good', 'renovation' );
		$allowed_strata = array( '1', '2', '3', '4', '5', '6' );
		if ( ! in_array( (string) $data['stratum'], $allowed_strata, true ) || ! in_array( $data['propertyType'], $allowed_types, true ) || ! in_array( $data['finishes'], $allowed_finishes, true ) || ! in_array( $data['condition'], $allowed_conditions, true ) ) {
			return new WP_Error( 'invalid_options', 'Una de las opciones recibidas no es válida.', array( 'status' => 422 ) );
		}

		return true;
	}

	/**
	 * Repite el cálculo en el servidor para no confiar en valores del navegador.
	 *
	 * @param array $data Datos normalizados.
	 * @return array
	 */
	private static function calculate_estimate( $data ) {
		$rates = array(
			'bogota'       => 5900000,
			'medellin'     => 5300000,
			'cali'         => 3900000,
			'barranquilla' => 4100000,
			'cartagena'    => 5100000,
			'bucaramanga'  => 3600000,
			'pereira'      => 3300000,
			'manizales'    => 3200000,
			'armenia'      => 3000000,
			'ibague'       => 2900000,
		);

		$city = sanitize_title( $data['city'] );
		$base_rate = 3200000;
		foreach ( $rates as $name => $rate ) {
			if ( false !== strpos( $city, $name ) ) {
				$base_rate = $rate;
				break;
			}
		}

		$stratum_factors = array( '1' => 0.62, '2' => 0.72, '3' => 0.86, '4' => 1, '5' => 1.2, '6' => 1.42 );
		$finish_factors = array( 'basic' => 0.86, 'standard' => 1, 'superior' => 1.13, 'luxury' => 1.28 );
		$condition_factors = array( 'new' => 1.08, 'excellent' => 1.03, 'good' => 0.96, 'renovation' => 0.78 );
		$age_factor = max( 0.72, 1 - ( max( 0, $data['age'] - 5 ) * 0.006 ) );
		$weighted_area = $data['privateArea'] + ( $data['freeArea'] * 0.38 ) + ( $data['parking'] * 6.5 ) + ( 'yes' === $data['storage'] ? 3.5 : 0 );
		$amenity_factor = 1 + ( min( count( $data['amenities'] ), 6 ) * 0.008 ) + ( $data['gated'] ? 0.025 : 0 );
		$property_factor = 'house' === $data['propertyType'] ? 1.04 : 1;
		$adjusted_rate = $base_rate * $stratum_factors[ $data['stratum'] ] * $finish_factors[ $data['finishes'] ] * $condition_factors[ $data['condition'] ] * $age_factor * $amenity_factor * $property_factor;
		$value = round( ( $weighted_area * $adjusted_rate ) / 1000000 ) * 1000000;

		return array(
			'value'         => $value,
			'low'           => round( ( $value * 0.92 ) / 1000000 ) * 1000000,
			'high'          => round( ( $value * 1.08 ) / 1000000 ) * 1000000,
			'rate'          => round( $adjusted_rate / 10000 ) * 10000,
			'weighted_area' => $weighted_area,
		);
	}

	/**
	 * Aplica un límite por dirección de red sin conservar la dirección original.
	 *
	 * @return bool
	 */
	private static function consume_rate_limit() {
		$options = self::options();
		$limit = max( 1, min( 50, absint( $options['hourly_limit'] ) ) );
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'appraiser_perito_' . md5( wp_salt( 'nonce' ) . '|' . $remote );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Comprueba el origen contra el sitio o la lista configurada.
	 *
	 * @return bool
	 */
	private static function origin_is_allowed() {
		$origin = get_http_origin();
		if ( ! $origin ) {
			return true;
		}

		$origin = untrailingslashit( $origin );
		$site_parts = wp_parse_url( home_url() );
		$site_origin = isset( $site_parts['scheme'], $site_parts['host'] ) ? $site_parts['scheme'] . '://' . $site_parts['host'] : '';
		if ( $site_origin && ! empty( $site_parts['port'] ) ) {
			$site_origin .= ':' . absint( $site_parts['port'] );
		}
		if ( $origin === $site_origin ) {
			return true;
		}

		$options = self::options();
		$allowed = preg_split( '/[\r\n,]+/', (string) $options['allowed_origins'] );
		foreach ( $allowed as $item ) {
			if ( $origin === untrailingslashit( trim( $item ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Construye el cuerpo HTML del correo.
	 *
	 * @param array $data Datos del inmueble.
	 * @param array $estimate Estimación.
	 * @return string
	 */
	private static function email_html( $data, $estimate ) {
		return sprintf(
			'<div style="font-family:Arial,sans-serif;color:#152033;line-height:1.55;max-width:620px"><h1 style="color:#10878f">Perito Virtual</h1><p>Adjuntamos el concepto preliminar solicitado para el inmueble ubicado en <strong>%1$s, %2$s, %3$s</strong>.</p><div style="padding:18px;border-radius:12px;background:#eef9f7"><span style="color:#526074">Estimación central</span><div style="font-size:30px;font-weight:700;color:#087f76">%4$s</div><span style="color:#526074">Rango orientativo: %5$s – %6$s</span></div><p style="font-size:12px;color:#67758c">Este resultado es una orientación comercial. No reemplaza un avalúo certificado, una visita técnica ni un estudio de mercado específico.</p></div>',
			esc_html( $data['address'] ),
			esc_html( $data['neighborhood'] ),
			esc_html( $data['city'] ),
			esc_html( '$ ' . number_format_i18n( $estimate['value'], 0 ) ),
			esc_html( '$ ' . number_format_i18n( $estimate['low'], 0 ) ),
			esc_html( '$ ' . number_format_i18n( $estimate['high'], 0 ) )
		);
	}

	/**
	 * Obtiene las opciones con valores predeterminados.
	 *
	 * @return array
	 */
	private static function options() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'from_name'       => 'Perito Virtual',
				'from_email'      => '',
				'copy_email'      => '',
				'allowed_origins' => '',
				'hourly_limit'    => 5,
			)
		);
	}

	/**
	 * Filtra el remitente únicamente durante el envío del concepto.
	 *
	 * @return string
	 */
	public static function filter_from_email() {
		$options = self::options();
		return sanitize_email( $options['from_email'] );
	}

	/**
	 * Filtra el nombre del remitente.
	 *
	 * @return string
	 */
	public static function filter_from_name() {
		$options = self::options();
		return sanitize_text_field( $options['from_name'] );
	}

	/**
	 * Añade la página de ajustes.
	 */
	public static function add_settings_page() {
		add_options_page(
			'Perito Virtual - Correo',
			'Perito Virtual - Correo',
			'manage_options',
			'appraiser-perito-mail',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Registra las opciones del plugin.
	 */
	public static function register_settings() {
		register_setting(
			'appraiser_perito_mail_group',
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_options' ) )
		);
	}

	/**
	 * Limpia las opciones administrativas.
	 *
	 * @param array $input Opciones recibidas.
	 * @return array
	 */
	public static function sanitize_options( $input ) {
		return array(
			'from_name'       => isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : 'Perito Virtual',
			'from_email'      => isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '',
			'copy_email'      => isset( $input['copy_email'] ) ? sanitize_email( $input['copy_email'] ) : '',
			'allowed_origins' => isset( $input['allowed_origins'] ) ? sanitize_textarea_field( $input['allowed_origins'] ) : '',
			'hourly_limit'    => isset( $input['hourly_limit'] ) ? max( 1, min( 50, absint( $input['hourly_limit'] ) ) ) : 5,
		);
	}

	/**
	 * Muestra el formulario de configuración.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = self::options();
		$endpoint = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
		?>
		<div class="wrap">
			<h1>Perito Virtual - Correo PDF</h1>
			<p>Configura el envío de conceptos desde el formulario de Perito Virtual.</p>
			<table class="widefat striped" style="max-width:900px;margin:18px 0">
				<tbody><tr><th style="width:190px">URL del endpoint</th><td><code><?php echo esc_html( $endpoint ); ?></code></td></tr></tbody>
			</table>
			<form method="post" action="options.php">
				<?php settings_fields( 'appraiser_perito_mail_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="apm-from-name">Nombre del remitente</label></th><td><input class="regular-text" id="apm-from-name" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[from_name]" value="<?php echo esc_attr( $options['from_name'] ); ?>"><p class="description">Ejemplo: Perito Virtual.</p></td></tr>
					<tr><th scope="row"><label for="apm-from-email">Correo del remitente</label></th><td><input class="regular-text" type="email" id="apm-from-email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[from_email]" value="<?php echo esc_attr( $options['from_email'] ); ?>"><p class="description">Déjalo vacío para usar el remitente configurado por WordPress o GoDaddy.</p></td></tr>
					<tr><th scope="row"><label for="apm-copy-email">Copia interna</label></th><td><input class="regular-text" type="email" id="apm-copy-email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[copy_email]" value="<?php echo esc_attr( $options['copy_email'] ); ?>"><p class="description">Opcional. Recibirá una copia oculta de cada concepto.</p></td></tr>
					<tr><th scope="row"><label for="apm-origins">Orígenes permitidos</label></th><td><textarea class="large-text code" rows="4" id="apm-origins" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allowed_origins]"><?php echo esc_textarea( $options['allowed_origins'] ); ?></textarea><p class="description">Una dirección por línea, sin ruta final. Ejemplo: <code>https://perito.example.com</code>. No hace falta añadir el propio WordPress.</p></td></tr>
					<tr><th scope="row"><label for="apm-limit">Envíos por hora y dirección de red</label></th><td><input type="number" min="1" max="50" id="apm-limit" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hourly_limit]" value="<?php echo esc_attr( $options['hourly_limit'] ); ?>"></td></tr>
				</table>
				<?php submit_button( 'Guardar configuración' ); ?>
			</form>
			<p><strong>Importante:</strong> que WordPress acepte el mensaje no garantiza su llegada. Revisa también el registro de correo del alojamiento y la carpeta de no deseados durante las pruebas.</p>
		</div>
		<?php
	}
}

Appraiser_Perito_Mail::init();
