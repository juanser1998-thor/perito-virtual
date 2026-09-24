<?php
/**
 * Generador PDF liviano, sin dependencias externas.
 *
 * @package Appraiser_Perito_Mail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Appraiser_Perito_PDF {

	/**
	 * Crea un PDF textual con el concepto preliminar.
	 *
	 * @param array $data Datos normalizados.
	 * @param array $estimate Resultado del cálculo.
	 * @return string Contenido binario del PDF.
	 */
	public static function create( $data, $estimate ) {
		$lines = self::report_lines( $data, $estimate );
		$pages = array_chunk( $lines, 39 );
		$page_count = count( $pages );
		$font_id = 3 + ( 2 * $page_count );
		$objects = array();

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		$kids = array();
		foreach ( $pages as $index => $page_lines ) {
			$page_id = 3 + ( 2 * $index );
			$content_id = $page_id + 1;
			$kids[] = $page_id . ' 0 R';

			$stream = self::page_stream( $page_lines, $index + 1, $page_count );
			$objects[ $page_id ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>',
				$font_id,
				$content_id
			);
			$objects[ $content_id ] = "<< /Length " . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
		}

		$objects[2] = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', implode( ' ', $kids ), $page_count );
		$objects[ $font_id ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

		ksort( $objects );
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array( 0 );

		foreach ( $objects as $id => $object ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
		}

		$xref = strlen( $pdf );
		$size = max( array_keys( $objects ) ) + 1;
		$pdf .= "xref\n0 " . $size . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $id = 1; $id < $size; $id++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", isset( $offsets[ $id ] ) ? $offsets[ $id ] : 0 );
		}
		$pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Compone las líneas legibles del informe.
	 *
	 * @param array $data Datos normalizados.
	 * @param array $estimate Resultado del cálculo.
	 * @return array
	 */
	private static function report_lines( $data, $estimate ) {
		$currency = static function ( $value ) {
			return '$ ' . number_format_i18n( (float) $value, 0 );
		};

		$type_labels = array(
			'apartment' => 'Apartamento',
			'house'     => 'Casa en conjunto',
		);
		$finish_labels = array(
			'basic'    => 'Basico',
			'standard' => 'Estandar',
			'superior' => 'Superior',
			'luxury'   => 'Lujo',
		);
		$condition_labels = array(
			'new'        => 'Nuevo',
			'excellent'  => 'Usado - excelente',
			'good'       => 'Usado - bueno',
			'renovation' => 'Requiere adecuaciones',
		);

		$lines = array(
			'PERITO VIRTUAL - CONCEPTO TECNICO DE VALOR COMERCIAL',
			'Fecha de generacion: ' . wp_date( 'd/m/Y H:i' ),
			'',
			'RESULTADO PRELIMINAR',
			'Estimacion central: ' . $currency( $estimate['value'] ),
			'Rango orientativo: ' . $currency( $estimate['low'] ) . ' - ' . $currency( $estimate['high'] ),
			'Valor de referencia por m2: ' . $currency( $estimate['rate'] ),
			'Area ponderada: ' . number_format_i18n( $estimate['weighted_area'], 1 ) . ' m2',
			'',
			'1. UBICACION GEOGRAFICA Y CONTEXTO',
			'Ciudad / Municipio: ' . $data['city'],
			'Barrio / Sector: ' . $data['neighborhood'],
			'Direccion: ' . $data['address'],
			'Estrato socioeconomico: ' . $data['stratum'],
			'',
			'2. CARACTERISTICAS DEL INMUEBLE',
			'Tipo: ' . ( isset( $type_labels[ $data['propertyType'] ] ) ? $type_labels[ $data['propertyType'] ] : $data['propertyType'] ),
			'Nivel de acabados: ' . ( isset( $finish_labels[ $data['finishes'] ] ) ? $finish_labels[ $data['finishes'] ] : $data['finishes'] ),
			'Estado: ' . ( isset( $condition_labels[ $data['condition'] ] ) ? $condition_labels[ $data['condition'] ] : $data['condition'] ),
			'Edad estructural: ' . $data['age'] . ' anos',
			'Area privada: ' . $data['privateArea'] . ' m2',
			'Area libre: ' . $data['freeArea'] . ' m2',
			'Parqueaderos: ' . $data['parking'],
			'Deposito: ' . ( 'yes' === $data['storage'] ? 'Si' : 'No' ),
			'',
			'3. HABITACIONES, BANOS Y DOTACIONES',
			'Alcobas principales: ' . $data['bedrooms'],
			'Banos privados: ' . $data['privateBathrooms'],
			'Banos sociales: ' . $data['socialBathrooms'],
			'Conjunto cerrado: ' . ( $data['gated'] ? 'Si' : 'No' ),
			'Cuarto de servicio: ' . ( $data['serviceRoom'] ? 'Si' : 'No' ),
			'Bano de servicio: ' . ( $data['serviceBathroom'] ? 'Si' : 'No' ),
			'Amenities: ' . ( empty( $data['amenities'] ) ? 'No registrados' : implode( ', ', $data['amenities'] ) ),
			'',
			'4. INFORMACION COMERCIAL Y LEGAL',
			'Tipo de titulacion: ' . $data['titleType'],
			'Proposito del avaluo: ' . $data['purpose'],
			'Avaluo catastral informado: ' . ( $data['cadastralValue'] ? $currency( $data['cadastralValue'] ) : 'No informado' ),
			'Valor de administracion: ' . ( $data['administration'] ? $currency( $data['administration'] ) : 'No informado' ),
			'',
			'ALCANCE DEL CONCEPTO',
			'Este resultado es una orientacion comercial generada con la informacion',
			'suministrada por el usuario. No reemplaza un avaluo certificado, una',
			'visita tecnica ni un estudio de mercado especifico.',
		);

		return $lines;
	}

	/**
	 * Convierte las líneas en instrucciones de contenido PDF.
	 *
	 * @param array $lines Líneas de la página.
	 * @param int   $page Número de página.
	 * @param int   $total Total de páginas.
	 * @return string
	 */
	private static function page_stream( $lines, $page, $total ) {
		$commands = array( 'BT', '/F1 11 Tf', '0.12 0.20 0.28 rg' );
		$y = 754;

		foreach ( $lines as $index => $line ) {
			$font_size = ( 0 === $index && 1 === $page ) ? 16 : 11;
			$commands[] = '/F1 ' . $font_size . ' Tf';
			$commands[] = '1 0 0 1 54 ' . $y . ' Tm';
			$commands[] = '(' . self::pdf_text( $line ) . ') Tj';
			$y -= ( 0 === $index && 1 === $page ) ? 24 : 17;
		}

		$commands[] = '/F1 9 Tf';
		$commands[] = '1 0 0 1 490 28 Tm';
		$commands[] = '(Pagina ' . $page . ' de ' . $total . ') Tj';
		$commands[] = 'ET';

		return implode( "\n", $commands );
	}

	/**
	 * Codifica y escapa texto para una cadena PDF WinAnsi.
	 *
	 * @param string $text Texto UTF-8.
	 * @return string
	 */
	private static function pdf_text( $text ) {
		$text = (string) $text;
		if ( function_exists( 'iconv' ) ) {
			$converted = iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );
			if ( false !== $converted ) {
				$text = $converted;
			}
		}

		return str_replace(
			array( '\\', '(', ')', "\r", "\n" ),
			array( '\\\\', '\\(', '\\)', ' ', ' ' ),
			$text
		);
	}
}
