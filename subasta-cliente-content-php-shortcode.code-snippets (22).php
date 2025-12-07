<?php

/**
 * Subasta-cliente-content.php SHORTCODE
 */
/**
 * Subasta-cliente-content.php SHORTCODE
 */
/**
 * =================================================================================
 * CAMPO BROKER - UI MODULE - CUENTAS DE ALQUILER v37.0.0 (Fix OCR IAAI + Full Security)
 * Shortcode: [campo_cuentas_alquiler_ui]
 * =================================================================================
 */

// --- Requisito de Composer ---
if (file_exists(ABSPATH . 'vendor/autoload.php')) {
	require_once ABSPATH . 'vendor/autoload.php';
}

if (!defined('ABSPATH')) { exit; }

// --- 1. CONSTANTES Y CONFIGURACIÓN ---
if (!defined('CAMPO_RENTAL_PRICE')) define('CAMPO_RENTAL_PRICE', '300.00');
if (!defined('CAMPO_RENTAL_CURRENCY')) define('CAMPO_RENTAL_CURRENCY', 'USD');
if (!defined('CAMPO_TERMS_URL')) define('CAMPO_TERMS_URL', home_url('/terminos-condiciones/'));
if (!defined('CAMPO_DASHBOARD_URL')) define('CAMPO_DASHBOARD_URL', home_url('/mi-dashboard/'));
if (!defined('CAMPO_PREALERTA_FEE')) define('CAMPO_PREALERTA_FEE', 114.50);

// === Comisión PayPal alineada con Carfax (5.4% + $0.30) ===
if (!defined('CAMPO_PP_FEE_PCT')) define('CAMPO_PP_FEE_PCT', 0.054);
if (!defined('CAMPO_PP_FEE_FIXED')) define('CAMPO_PP_FEE_FIXED', 0.30);

if (!defined('GOOGLE_APPLICATION_CREDENTIALS')) {
	define('GOOGLE_APPLICATION_CREDENTIALS', WP_CONTENT_DIR . '/keys/imposing-kayak-455004-a8-d79dc3fbe5d1.json');
}

/** Helper: convertir URL de uploads → path local (para adjuntos PDF) */
function campo_pdf_url_to_path_cb($url){
	$up = wp_upload_dir();
	$baseurl = trailingslashit($up['baseurl']);
	$basedir = trailingslashit($up['basedir']);
	if (strpos($url, $baseurl) === 0) {
		return $basedir . ltrim(substr($url, strlen($baseurl)), '/');
	}
	return false;
}

/** Helper de Seguridad: Validar tipo de archivo real (MIME Type) */
function campo_security_validate_file($file_path) {
	$finfo = finfo_open(FILEINFO_MIME_TYPE);
	$mime = finfo_file($finfo, $file_path);
	finfo_close($finfo);
	
	$allowed_mimes = [
		'application/pdf',
		'image/jpeg',
		'image/png',
		'image/jpg'
	];
	
	return in_array($mime, $allowed_mimes);
}

// --- 2. MANEJADORES AJAX Y ACCIONES ---

/** Obtener credenciales de la cuenta alquilada */
add_action('wp_ajax_campo_get_rental_credentials', function () {
	if (!is_user_logged_in()) { wp_send_json_error(['message' => 'Autenticación requerida.'], 403); }
	check_ajax_referer('campo_rental_nonce', 'nonce');
	global $wpdb;
	$user_id = get_current_user_id();
	$user_info = get_userdata($user_id);
	$cliente_nombre = $user_info ? esc_html($user_info->display_name) : 'Cliente';
	$nip_input = sanitize_text_field(get_user_meta($user_id, 'nip', true));
	if (empty($nip_input)) { wp_send_json_error(['message' => 'No hay un NIP asociado a tu cuenta.']); }
	$account = $wpdb->get_row($wpdb->prepare("SELECT email, password FROM " . $wpdb->prefix . "campo_cuentas WHERE nip_asignado = %s AND status = 'alquilada'", $nip_input));
	if (!$account) { wp_send_json_error(['message' => 'No se encontraron credenciales para una cuenta de alquiler activa.']); }
	wp_send_json_success([
		"nombre" => $cliente_nombre,
		"email" => $account->email,
		"password" => $account->password
	]);
});

/** Pago de alquiler de cuenta (incluye facturación B02 + correo) */
add_action('wp_ajax_campo_process_rental_payment', function () {
	if (!is_user_logged_in()) { wp_send_json_error(['message' => 'Autenticación requerida.'], 403); }
	check_ajax_referer('campo_rental_nonce', 'nonce');

	global $wpdb;
	$user_id = get_current_user_id();
	$user_nip = sanitize_text_field(get_user_meta($user_id, 'nip', true));

	$paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
	// Nota de seguridad: No confiamos en el monto enviado por JS para calcular lógica interna, solo para log.
	$paypal_amount_sent = isset($_POST['paypal_amount']) ? sanitize_text_field($_POST['paypal_amount']) : '0.00';

	if (empty($user_nip) || empty($paypal_order_id)) {
		wp_send_json_error(['message' => 'Faltan datos requeridos.']);
	}

	// No permitir 2 alquileres activos
	$has_active_account = $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM " . $wpdb->prefix . "campo_cuentas WHERE nip_asignado = %s AND status = 'alquilada'",
		$user_nip
	));
	if ($has_active_account > 0) {
		wp_send_json_error(['message' => 'Ya tienes una cuenta de alquiler activa.']);
	}

	$wpdb->query('START TRANSACTION');

	// Bloquear una cuenta disponible
	$account_to_assign = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "campo_cuentas WHERE status = 'disponible' LIMIT 1 FOR UPDATE");
	if (!$account_to_assign) {
		$wpdb->query('ROLLBACK');
		wp_send_json_error(["message" => 'Lo sentimos, no hay cuentas disponibles en este momento.']);
	}

	$update_account = $wpdb->update(
		$wpdb->prefix . "campo_cuentas",
		['status' => 'alquilada', 'nip_asignado' => $user_nip],
		['id' => $account_to_assign->id]
	);

	// Generar número de orden
	function campo_rental_generate_unique_order_number_v2() {
		global $wpdb;
		do {
			$order_number = mt_rand(100000, 999999);
			$exists = $wpdb->get_var($wpdb->prepare("SELECT numero_orden FROM " . $wpdb->prefix . "campo_logs WHERE numero_orden = %s", $order_number));
		} while ($exists);
		return $order_number;
	}

	$new_order_number = campo_rental_generate_unique_order_number_v2();
	$user_ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);

	// Log simple
	$log_data = [
		'numero_orden'			=> $new_order_number,
		'numero_transaccion'	=> $paypal_order_id,
		'fecha_registro'		=> current_time('mysql'),
		'ip'					=> $user_ip,
		'terminos_condiciones'=> 'ACEPTADO',
		'tipo'					=> 'SUBASTA',
		'user_id'				=> $user_id,
		'datos'					=> 'COMPRA',
		'monto_pagado'			=> $paypal_amount_sent
	];
	$insert_log = $wpdb->insert($wpdb->prefix . "campo_logs", $log_data);

	if ($update_account === false || $insert_log === false) {
		$wpdb->query('ROLLBACK');
		wp_send_json_error(['message' => 'Ocurrió un error interno. Contacta a soporte.']);
	}

	// === Registrar transacción + emitir factura B02 (Seguridad: Calculo Servidor) ===
	$tabla_transacciones = $wpdb->prefix . 'campo_transacciones';
	$subtotal	= (float) CAMPO_RENTAL_PRICE;								// 300.00
	$pp_fee		= round(($subtotal * CAMPO_PP_FEE_PCT) + CAMPO_PP_FEE_FIXED, 2); // 5.4% + $0.30
	$total_cli = $subtotal + $pp_fee;

	// Insert transacción
	$wpdb->insert($tabla_transacciones, [
		'user_id'			=> $user_id,
		'item_id'			=> (int)$account_to_assign->id,
		'item_type'			=> 'rental_account',
		'monto'				=> $subtotal,
		'comision_pago'		=> $pp_fee,
		'metodo_pago'		=> 'PayPal',
		'referencia_externa'=> $paypal_order_id,
		'status'			=> 'completado',
		'fecha_transaccion'	=> current_time('mysql'),
		'is_seen_by_admin'	=> 0
	]);
	$transaction_id = (int) $wpdb->insert_id;

	// Emitir factura con módulo central (B02)
	$user_info	= get_userdata($user_id);
	$payer_email = $user_info ? $user_info->user_email : '';

	$invoice_info = apply_filters('campo_invoicer_emit_direct', null, [
		'txn_id'		=> $transaction_id ?: 0,
		'user_id'		=> (int)$user_id,
		'customer_email'=> $payer_email,
		'customer_name'	=> $user_info ? $user_info->display_name : '',

		'item_type'		=> 'rental_account',
		'qty'			=> 1,
		'amount_usd'	=> (float)$subtotal,
		'currency'		=> 'USD',

		'gateway_fee_usd'=> (float)$pp_fee,
		'payment_method'=> 'PayPal',
		'gateway_ref'	=> $paypal_order_id,

		'issued_at'		=> current_time('mysql'),
	]);

	// Guardar invoice_id si la columna existe
	if ($transaction_id && !empty($invoice_info['invoice_id'])) {
		$table_tx = $tabla_transacciones;
		$has_col	= $wpdb->get_results("SHOW COLUMNS FROM {$table_tx} LIKE 'invoice_id'");
		if (!empty($has_col)) {
			$wpdb->update($table_tx, ['invoice_id' => (int)$invoice_info['invoice_id']], ['id_transaccion' => $transaction_id], ['%d'], ['%d']);
		}
	}

	$wpdb->query('COMMIT');

	// === Correo de activación con plantilla Carfax + factura adjunta ===
	$user_name	= $user_info ? $user_info->display_name : 'Cliente';
	$subject	= "✅ Tu cuenta de subasta está activada";
	$ncf_txt	= (!empty($invoice_info['ncf']) ? ' y tu <strong>Factura '.$invoice_info['ncf'].'</strong>' : '');
	$today_fmt	= date_i18n(get_option('date_format'), current_time('timestamp'));

	ob_start(); ?>
	<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 20px auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
		<div style="background-color: #2c3e50; color: #ffffff; padding: 20px; text-align: center;">
			<h1 style="margin: 0; font-size: 24px;">Campo Broker</h1>
		</div>
		<div style="padding: 30px;">
			<h2 style="color: #2c3e50; font-size: 20px;">¡Tu cuenta de subasta está activada!</h2>
			<p>Hola <?php echo esc_html($user_name); ?>, hemos activado tu alquiler de cuenta. Aquí están los detalles:</p>
			<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
				<tr style="border-bottom: 1px solid #eee;">
					<td style="padding: 10px; font-weight: bold;">Nº de Orden:</td>
					<td style="padding: 10px; font-family: monospace;"><?php echo esc_html($new_order_number); ?></td>
				</tr>
				<tr style="border-bottom: 1px solid #eee;">
					<td style="padding: 10px; font-weight: bold;">Concepto:</td>
					<td style="padding: 10px;">Alquiler de Cuenta de Subasta</td>
				</tr>
				<tr style="border-bottom: 1px solid #eee;">
					<td style="padding: 10px; font-weight: bold;">Subtotal:</td>
					<td style="padding: 10px;">$<?php echo number_format($subtotal, 2); ?></td>
				</tr>
				<tr style="border-bottom: 1px solid #eee;">
					<td style="padding: 10px; font-weight: bold;">Comisión (5.4% + $0.30):</td>
					<td style="padding: 10px;">$<?php echo number_format($pp_fee, 2); ?></td>
				</tr>
				<tr style="border-bottom: 1px solid #eee;">
					<td style="padding: 10px; font-weight: bold;">Total:</td>
					<td style="padding: 10px;">$<?php echo number_format($total_cli, 2); ?></td>
				</tr>
				<tr style="border-bottom: 1px solid #eee;">
					<td style="padding: 10px; font-weight: bold;">Fecha:</td>
					<td style="padding: 10px;"><?php echo esc_html($today_fmt); ?></td>
				</tr>
			</table>
			<p style="text-align: center; margin-top: 30px;">
				Hemos adjuntado la <strong>Factura B02 en PDF</strong><?php echo $ncf_txt; ?> por el alquiler de la cuenta.
			</p>
			<p style="font-size:12px; color:#555; text-align:center;">
				Si tienes problemas, escribe a: <a href="mailto:Ayuda@campobroker.com">Ayuda@campobroker.com</a>
			</p>
		</div>
		<div style="background-color: #f7f9fc; padding: 15px; text-align: center; font-size: 12px; color: #777;">
			<p>&copy; <?php echo date('Y'); ?> Campo Broker.</p>
		</div>
	</div>
	<?php
	$email_body = ob_get_clean();
	$headers	= ['Content-Type: text/html; charset=UTF-8', 'From: Campo Broker <no-reply@campobroker.com>'];

	// Adjuntos: factura PDF si existe
	$attachments = [];
	if (!empty($invoice_info['pdf_url'])) {
		$invoice_path = campo_pdf_url_to_path_cb($invoice_info['pdf_url']);
		if ($invoice_path && file_exists($invoice_path)) { $attachments[] = $invoice_path; }
	}

	wp_mail($payer_email, $subject, $email_body, $headers, $attachments);

	wp_send_json_success(['message' => '¡Pago completado! Tu cuenta está ahora activa.']);
});

/** Analizar factura (OCR) con Rate Limiting y Mejor Regex para IAAI */
add_action('wp_ajax_campo_analyze_invoice', function() {
	check_ajax_referer('campo_rental_nonce', 'nonce');
	$user_id = get_current_user_id();

	// --- SEGURIDAD: Rate Limiting (15 intentos cada 10 minutos) ---
	$transient_key = 'ocr_attempts_' . $user_id;
	$attempts = get_transient($transient_key);
	if ($attempts === false) {
		set_transient($transient_key, 1, 10 * 60);
	} elseif ($attempts >= 15) {
		wp_send_json_error(['message' => 'Has excedido el límite de intentos de lectura. Por favor intenta manualmente o espera 10 minutos.']);
	} else {
		set_transient($transient_key, $attempts + 1, 10 * 60);
	}

	if (!isset($_FILES['invoice_file'])) {
		wp_send_json_error(['message' => 'No se recibió ningún archivo.']);
	}

	$file = $_FILES['invoice_file'];
	
	// --- SEGURIDAD: Validación de Archivo Real (Magic Bytes) ---
	if (!campo_security_validate_file($file['tmp_name'])) {
		wp_send_json_error(['message' => 'El archivo subido no es válido o está corrupto.']);
	}

	try {
		if (filesize($file['tmp_name']) == 0) {
			throw new Exception("El archivo subido está vacío.");
		}

		$full_text = '';
		$data_found = false;
		$extracted_data = [];

		if ($file['type'] === 'application/pdf' && class_exists('\\Smalot\\PdfParser\\Parser')) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pdf = $parser->parseFile($file['tmp_name']);
				$full_text = $pdf->getText();
				$extracted_data = campo_extract_data_from_text($full_text);
				$data_found = !empty($extracted_data['vin']) && !empty($extracted_data['amount']) && !empty($extracted_data['source']);
			} catch (Exception $e) {
				$data_found = false;
			}
		}

		if (!$data_found) {
			$content = campo_get_image_content_from_file($file);
			if (empty($content)) {
				throw new Exception("No se pudo leer ni convertir el archivo a imagen.");
			}
			$full_text = campo_enviar_a_google_vision($content);
			$extracted_data = campo_extract_data_from_text($full_text);
		}

		// VALIDACION DE MONTO MAYOR A CERO
		if (empty($extracted_data['vin']) || empty($extracted_data['amount']) || (float)$extracted_data['amount'] <= 0.0 || empty($extracted_data['source'])) {
			wp_send_json_error(['message' => 'No pudimos leer todos los datos, o el monto de la factura no es válido ($0.00 o negativo). Intenta subir una imagen más clara o ingresa los datos manualmente.']);
		}

		$vin_data = campo_get_vehicle_details_from_vin_v3($extracted_data['vin']);
		$extracted_data['vehicle_name'] = $vin_data ? trim($vin_data['year'] . ' ' . $vin_data['make'] . ' ' . $vin_data['model']) : 'No detectado';

		wp_send_json_success($extracted_data);

	} catch (Exception $e) {
		wp_send_json_error(['message' => 'Error al procesar el documento: ' . $e->getMessage()]);
	}
});

/** Pre-alerta (SEGURIDAD: Recálculo de fees en servidor) */
add_action('wp_ajax_campo_procesar_pre_alerta', function() {
	check_ajax_referer('campo_rental_nonce', 'nonce');
	if (!is_user_logged_in()) { wp_send_json_error(['message' => 'Autenticación requerida.'], 403); }

	global $wpdb;
	$user_id	= get_current_user_id();
	$user_nip	= sanitize_text_field(get_user_meta($user_id, 'nip', true));

	// Datos del vehículo
	$vin				= isset($_POST['vin']) ? sanitize_text_field($_POST['vin']) : '';
	$monto_factura		= isset($_POST['amount']) ? floatval($_POST['amount']) : 0.0;
	$fuente				= isset($_POST['source']) ? sanitize_text_field($_POST['source']) : '';
	$nombre_vehiculo	= isset($_POST['vehicle_name']) ? sanitize_text_field($_POST['vehicle_name']) : '';
	$metodo_prealerta	= isset($_POST['info_method']) ? sanitize_text_field($_POST['info_method']) : 'desconocido';
	$trabajara_taller	= isset($_POST['trabajara_taller']) ? sanitize_text_field($_POST['trabajara_taller']) : '';
	
	// Datos de Pago
	$paypal_order_id	= isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
	
	// Radios extra
	$estado_especial	= isset($_POST['estado_especial']) ? sanitize_text_field($_POST['estado_especial']) : '';
	$finalidad_vehiculo = isset($_POST['finalidad_vehiculo']) ? sanitize_text_field($_POST['finalidad_vehiculo']) : '';

	if ($trabajara_taller === 'si') {
		$finalidad_vehiculo = 'exportar';
	}

	$id_taller_seleccionado = null;
	$direccion_final = '';

	if (empty($vin) || empty($fuente) || empty($paypal_order_id) || empty($user_nip)) {
		wp_send_json_error(['message' => 'Faltan datos esenciales de la pre-alerta.']);
	}

	// VALIDACIÓN ADICIONAL DE MONTO MAYOR A CERO
	if ($monto_factura <= 0.0) {
		wp_send_json_error(['message' => 'El monto de la factura debe ser mayor a cero.']);
	}

	// Validar VIN único
	$tabla_vehiculos = $wpdb->prefix . 'campo_vehiculos';
	$vin_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabla_vehiculos} WHERE vin = %s", $vin));
	if ($vin_exists > 0) {
		wp_send_json_error(['message' => 'Este VIN ya ha sido registrado.']);
	}

	// --- SEGURIDAD: Recálculo de Total en Servidor ---
	// No confiamos en $_POST['paypal_amount'] para validar lógica interna, aunque podemos guardarlo como referencia.
	$base_fee = (float) CAMPO_PREALERTA_FEE; // 114.50
	$extra_estado = ($estado_especial === 'si') ? 45.00 : 0.00;
	$extra_uso	= ($finalidad_vehiculo === 'uso_usa') ? 50.00 : 0.00;
	
	$subtotal_server = $base_fee + $extra_estado + $extra_uso;
	// La comisión que cobramos en la pasarela:
	$comision_server = round(($subtotal_server * CAMPO_PP_FEE_PCT) + CAMPO_PP_FEE_FIXED, 2);
	$total_esperado	= $subtotal_server + $comision_server;
	
	// El monto que guardaremos como "pago de servicio" en transacciones (podemos guardar el total pagado)
	$monto_transaccion = $total_esperado;

	// Lógica de Talleres / Dirección
	$tabla_talleres = $wpdb->prefix . 'campo_talleres';
	if ($trabajara_taller === 'si') {
		$id_taller_seleccionado = isset($_POST['taller_id']) ? intval($_POST['taller_id']) : 0;
		if (empty($id_taller_seleccionado)) { wp_send_json_error(['message' => 'Seleccione un taller.']); }
		$taller_info = $wpdb->get_row($wpdb->prepare("SELECT direccion FROM {$tabla_talleres} WHERE id_taller = %d AND status = 'activo'", $id_taller_seleccionado));
		if (!$taller_info) { wp_send_json_error(['message' => 'Taller inválido.']); }
		$direccion_final = sanitize_text_field($taller_info->direccion);
	} else {
		$direccion_final = isset($_POST['shipping_address']) ? sanitize_textarea_field($_POST['shipping_address']) : '';
		if (empty($direccion_final)) { wp_send_json_error(['message' => 'Falta dirección de envío.']); }
	}

	// Cuenta
	$tabla_cuentas = $wpdb->prefix . 'campo_cuentas';
	$cuenta_alquiler = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$tabla_cuentas} WHERE nip_asignado = %s", $user_nip));
	if (!$cuenta_alquiler) { wp_send_json_error(['message' => 'No se encontró la cuenta de alquiler activa.']); }
	$cuenta_alquiler_id = $cuenta_alquiler->id;

	$wpdb->query('START TRANSACTION');

	$insert_vehiculo = $wpdb->insert($tabla_vehiculos, [
		'user_id'			=> $user_id,
		'nip_original'		=> $user_nip,
		'cuenta_alquiler_id'=> $cuenta_alquiler_id,
		'vin'				=> $vin,
		'vehiculo_nombre'	=> $nombre_vehiculo,
		'monto_factura'		=> $monto_factura,
		'direccion_titulo'	=> $direccion_final,
		'subasta_origen'	=> $fuente,
		'metodo_prealerta'	=> $metodo_prealerta,
		'estado_especial'	=> $estado_especial,
		'finalidad_vehiculo'=> $finalidad_vehiculo,
		'fecha_prealerta'	=> current_time('mysql'),
	]);

	if (!$insert_vehiculo) {
		$wpdb->query('ROLLBACK');
		wp_send_json_error(['message' => 'Error Crítico DB.']);
	}

	$nuevo_vehiculo_id = $wpdb->insert_id;

	if ($trabajara_taller === 'si' && !empty($id_taller_seleccionado)) {
		$wpdb->insert($wpdb->prefix . 'campo_vehiculo_taller', [
			'vehiculo_id'		=> $nuevo_vehiculo_id,
			'user_id'			=> $user_id,
			'nip'				=> $user_nip,
			'vin'				=> $vin,
			'lote'				=> null,
			'taller_id'			=> $id_taller_seleccionado,
			'status_taller'		=> 'prealerta_taller',
			'fecha_creacion'	=> current_time('mysql')
		]);
	}

	$tabla_transacciones = $wpdb->prefix . 'campo_transacciones';
	$wpdb->insert($tabla_transacciones, [
		'user_id'			=> $user_id,
		'monto'				=> $monto_transaccion,
		'metodo_pago'		=> 'PayPal',
		'referencia_externa'=> $paypal_order_id,
		'status'			=> 'completado',
		'item_id'			=> $nuevo_vehiculo_id,
		'item_type'			=> 'prealerta_fee',
		'fecha_transaccion'	=> current_time('mysql'),
		'is_seen_by_admin'	=> 0
	]);

	// Liberar cuenta
	$cuenta_liberada = $wpdb->update($tabla_cuentas,
		['status' => 'disponible', 'nip_asignado' => null],
		['id' => $cuenta_alquiler_id]
	);

	if ($cuenta_liberada === false) {
		$wpdb->query('ROLLBACK');
		wp_send_json_error(['message' => 'Error al liberar cuenta.']);
	}

	$wpdb->query('COMMIT');
	wp_send_json_success(['message' => '¡Pre-alerta completada!']);
});

/** Abonos a vehículo (SEGURIDAD: Recálculo de fees) */
add_action('wp_ajax_campo_procesar_pago_vehiculo', function() {
	if (!is_user_logged_in()) { wp_send_json_error(['message' => 'Autenticación requerida.'], 403); }
	check_ajax_referer('campo_rental_nonce', 'nonce');

	global $wpdb;
	$user_id = get_current_user_id();
	$tabla_transacciones = $wpdb->prefix . 'campo_transacciones';

	$vehiculo_id	= isset($_POST['vehiculo_id']) ? intval($_POST['vehiculo_id']) : 0;
	$monto_subtotal= isset($_POST['monto_subtotal']) ? floatval($_POST['monto_subtotal']) : 0.0;
	$metodo_pago	= isset($_POST['metodo_pago']) ? sanitize_text_field($_POST['metodo_pago']) : '';

	if (empty($vehiculo_id) || $monto_subtotal <= 0 || empty($metodo_pago)) {
		wp_send_json_error(['message' => 'Faltan datos o el monto es inválido.']);
	}

	// --- SEGURIDAD: Recálculo de Comisión en Servidor ---
	$comision_server = 0.00;
	if ($metodo_pago === 'PayPal') {
		$comision_server = ($monto_subtotal * CAMPO_PP_FEE_PCT) + CAMPO_PP_FEE_FIXED;
	}
	
	$data_to_insert = [
		'user_id'			=> $user_id,
		'metodo_pago'		=> $metodo_pago,
		'item_id'			=> $vehiculo_id,
		'item_type'			=> 'vehiculo',
		'fecha_transaccion'	=> current_time('mysql'),
		'is_seen_by_admin'	=> 0,
		'monto'				=> $monto_subtotal,
		'comision_pago'		=> $comision_server
	];

	if ($metodo_pago === 'PayPal') {
		$paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
		if (empty($paypal_order_id)) wp_send_json_error(['message' => 'Falta ID de PayPal.']);
		
		$data_to_insert['referencia_externa']= $paypal_order_id;
		$data_to_insert['status']			= 'completado';
		$data_to_insert['nota_admin']		= 'Abono PayPal. Total cobrado: $' . number_format($monto_subtotal + $comision_server, 2);

	} elseif ($metodo_pago === 'Transferencia Bancaria') {
		if (!isset($_FILES['comprobante_pago']) || $_FILES['comprobante_pago']['error'] !== UPLOAD_ERR_OK) {
			wp_send_json_error(['message' => 'Falta comprobante.']);
		}
		
		// Validación MIME
		if (!campo_security_validate_file($_FILES['comprobante_pago']['tmp_name'])) {
			wp_send_json_error(['message' => 'Archivo inválido. Solo JPG/PNG/PDF.']);
		}

		require_once(ABSPATH . 'wp-admin/includes/file.php');
		$upload_overrides = ['test_form' => false];
		$movefile = wp_handle_upload($_FILES['comprobante_pago'], $upload_overrides);

		if ($movefile && !isset($movefile['error'])) {
			$data_to_insert['referencia_externa'] = $movefile['url'];
			$data_to_insert['status']			= 'pendiente';
			$data_to_insert['nota_admin']			= 'Pendiente de verificación.';
		} else {
			wp_send_json_error(['message' => 'Error al subir archivo.']);
		}
	}

	$result = $wpdb->insert($tabla_transacciones, $data_to_insert);

	// === Correo de acuse ===
	if ($result) {
		$user_info = get_userdata($user_id);
		$payer_email = $user_info ? $user_info->user_email : '';
		$user_name	= $user_info ? $user_info->display_name : 'Cliente';
		// ... (Código de correo sin cambios) ...
		$tabla_vehiculos = $wpdb->prefix . 'campo_vehiculos';
		$veh = $wpdb->get_row($wpdb->prepare("SELECT vehiculo_nombre, vin FROM {$tabla_vehiculos} WHERE id = %d AND user_id = %d", $vehiculo_id, $user_id));
		$vehiculo_nombre = $veh ? $veh->vehiculo_nombre : 'Vehículo';
		$vin_txt = $veh ? $veh->vin : '';
		$today_fmt	= date_i18n(get_option('date_format'), current_time('timestamp'));
		$subject	= "✅ Hemos recibido tu pago — En revisión";
		ob_start(); ?>
		<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 20px auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
			<div style="background-color: #2c3e50; color: #ffffff; padding: 20px; text-align: center;">
				<h1 style="margin: 0; font-size: 24px;">Campo Broker</h1>
			</div>
			<div style="padding: 30px;">
				<h2 style="color: #2c3e50; font-size: 20px;">Pago recibido — en revisión</h2>
				<p>Hola <?php echo esc_html($user_name); ?>, hemos recibido tu pago de <strong>$<?php echo number_format($monto_subtotal, 2); ?></strong> para el vehículo <strong><?php echo esc_html($vehiculo_nombre); ?></strong> (VIN: <?php echo esc_html($vin_txt); ?>).</p>
				<p>Será revisado y aplicado a tu cuenta en breve.</p>
			</div>
		</div>
		<?php
		$email_body = ob_get_clean();
		$headers	= ['Content-Type: text/html; charset=UTF-8', 'From: Campo Broker <no-reply@campobroker.com>'];
		if (!empty($payer_email)) {
			wp_mail($payer_email, $subject, $email_body, $headers);
		}
		wp_send_json_success(['message' => '¡Pago registrado! En revisión.']);
	} else {
		wp_send_json_error(['message' => 'Error DB.']);
	}
});

/** NUEVO: Obtener documentos por VIN */
add_action('wp_ajax_campo_get_vehicle_docs', function(){
	if (!is_user_logged_in()) { wp_send_json_error(['message' => 'Autenticación requerida.'], 403); }
	check_ajax_referer('campo_rental_nonce', 'nonce');

	global $wpdb;
	$user_id = get_current_user_id();
	$vin = isset($_POST['vin']) ? sanitize_text_field($_POST['vin']) : '';
	if (empty($vin)) { wp_send_json_error(['message' => 'VIN requerido']); }

	$tabla_vehiculos = $wpdb->prefix . 'campo_vehiculos';
	$row = $wpdb->get_row($wpdb->prepare(
		"SELECT factura_final_url, titulo_url FROM {$tabla_vehiculos} WHERE vin = %s AND user_id = %d LIMIT 1",
		$vin, $user_id
	));

	if (!$row) {
		wp_send_json_success(['factura' => '', 'titulo' => '']);	
	} else {
		wp_send_json_success([
			'factura' => esc_url_raw((string)$row->factura_final_url),
			'titulo'	=> esc_url_raw((string)$row->titulo_url)
		]);
	}
});

// --- ajustes visuales ---
add_action('wp_head', function () {
	echo '<style>
		.pac-container { z-index: 9999 !important; }
		#pre_alert_modal .modal-box, #rental_payment_modal .modal-box, #pagar_vehiculo_modal .modal-box {
			width: min(96vw, 1100px); border-radius: 14px; overflow-x: hidden;
		}
		.cb-tip{display:inline-flex;align-items:center;justify-content:center;width:1rem;height:1rem;margin-left:.35rem}
		.cb-tip svg{width:1rem;height:1rem;opacity:.65;transition:opacity .15s ease}
		.cb-tip:hover svg,.cb-tip:focus svg{opacity:1}
		.cb-scroll{max-height:14rem;overflow:auto}
		.tooltip:before, .tooltip:after { opacity: 0 !important; }
		.tooltip:hover:before, .tooltip:hover:after { opacity: 1 !important; }
		.tooltip:before { background: #111827 !important; color: #fff !important; border: 0 !important; }
		.veh-cards-list { display: flex; flex-direction: column; gap: 16px; }
		.veh-card { border: 1px solid var(--b3, #e5e7eb); border-radius: 12px; background: var(--b1, #fff); box-shadow: 0 2px 8px rgba(0,0,0,.04); }
		.veh-card-body { padding: 16px; }
		.veh-card-grid { display: grid; grid-template-columns: 1.2fr 1fr 0.9fr 0.9fr; gap: 16px; align-items: start; }
		@media (max-width: 900px){ .veh-card-grid { grid-template-columns: 1fr; } }
		.veh-section-title { font-weight: 700; font-size: .9rem; opacity: .8; margin-bottom: .35rem; }
		.veh-actions .btn + .btn { margin-left: .35rem; }
		.gestion-vehiculos-table thead { display:none; }
	</style>';
});

function campo_get_image_content_from_file($file) {
	$file_path = $file['tmp_name'];
	if ($file['type'] !== 'application/pdf') {
		return file_get_contents($file_path);
	}
	$content = null;
	if (extension_loaded('imagick') && class_exists('Imagick')) {
		try {
			$imagick = new Imagick();
			$imagick->setResolution(300, 300);
			$imagick->readImage($file_path . '[0]');
			$imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
			$imagick->setImageBackgroundColor('white');
			$imagick = $imagick->flattenImages();
			$imagick->setImageFormat('png');
			$content = $imagick->getImageBlob();
			$imagick->clear();
			$imagick->destroy();
			if (!empty($content) && strlen($content) > 1000) { return $content; }
		} catch (Exception $e) { $content = null; }
	}
	return $content;
}

function campo_enviar_a_google_vision($content) {
	$vision_class = '\\Google\\Cloud\\Vision\\V1\\Client\\ImageAnnotatorClient';
	if (!class_exists($vision_class)) { throw new Exception("Librería Google Vision no instalada."); }
	if (!file_exists(GOOGLE_APPLICATION_CREDENTIALS)) { throw new Exception('Faltan credenciales JSON.'); }

	$imageAnnotator = new $vision_class(['credentials' => GOOGLE_APPLICATION_CREDENTIALS]);
	$image = new \Google\Cloud\Vision\V1\Image();
	$image->setContent($content);
	$feature = new \Google\Cloud\Vision\V1\Feature();
	$feature->setType(\Google\Cloud\Vision\V1\Feature\Type::DOCUMENT_TEXT_DETECTION);
	$single_request = new \Google\Cloud\Vision\V1\AnnotateImageRequest();
	$single_request->setImage($image);
	$single_request->setFeatures([$feature]);
	$batch_request = new \Google\Cloud\Vision\V1\BatchAnnotateImagesRequest();
	$batch_request->setRequests([$single_request]);
	$response = $imageAnnotator->batchAnnotateImages($batch_request);
	$annotations = $response->getResponses()[0];
	$imageAnnotator->close();

	if ($annotations->hasError()) {
		throw new Exception('API Error: ' . $annotations->getError()->getMessage());
	}
	return $annotations->getFullTextAnnotation()->getText();
}

/**
 * EXTRACTOR DATA CORREGIDO PARA IAAI 2025 (Fix $0.00 issue)
 * Maneja formato "CSV vertical" con comillas.
 */
function campo_extract_data_from_text($full_text) {
	// 1. Limpiar comillas dobles para simplificar el regex
	$clean_text = str_replace('"', '', (string)$full_text);
	// 2. Normalizar espacios y saltos de línea
	$clean_text = preg_replace(["/\r\n|\r|\n/u", "/[ \t]+/u"], ["\n", " "], $clean_text);
	
	// Texto original normalizado (por si acaso)
	$raw_text = preg_replace(["/\r\n|\r|\n/u", "/[ \t]+/u"], ["\n", " "], (string)$full_text);

	$extracted_data = [
		'vin'	=> null,
		'amount'=> null,
		'source'=> null
	];

	// Detectar Fuente
	if (preg_match('/\bCopart\b/i', $clean_text)) {
		$extracted_data['source'] = 'Copart';
	} elseif (preg_match('/Insurance\s+Auto\s+Auctions|\bIAAI\b|Buyer\s+Invoice\s+Receipt/i', $clean_text)) {
		$extracted_data['source'] = 'IAAI';
	}

	// Detectar VIN (3 estrategias)
	if (empty($extracted_data['vin']) && preg_match('/Stock\s*#\s*VIN\s*Description.*?\n([^\n]+)/i', $clean_text, $m)) {
		if (preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/', $m[1], $m2)) {
			$extracted_data['vin'] = strtoupper($m2[1]);
		}
	}
	if (empty($extracted_data['vin']) && preg_match('/\bVIN\b[^A-HJ-NPR-Z0-9]{0,8}([A-HJ-NPR-Z0-9]{17})/i', $clean_text, $m)) {
		$extracted_data['vin'] = strtoupper($m[1]);
	}
	if (empty($extracted_data['vin']) && preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/', $clean_text, $m)) {
		$extracted_data['vin'] = strtoupper($m[1]);
	}

	// Helper para buscar montos
	$findAmount = function($text_to_search, array $patterns) {
		foreach ($patterns as $p) {
			if (preg_match($p, $text_to_search, $m)) {
				$num = preg_replace('/[^\d.]/', '', $m[1]);
				if ($num !== '') {
					// VALIDACION: El monto extraído debe ser > 0
					if ((float)$num > 0.0) {
						return number_format((float)$num, 2, '.', '');
					}
				}
			}
		}
		return null;
	};

	// --- Lógica Mejorada para Montos ---
	
	if ($extracted_data['source'] === 'IAAI') {
		// Estrategia específica para el formato de IAAI compartido
		$extracted_data['amount'] = $findAmount($clean_text, [
			// 1. Patrón exacto de la tabla vertical (más limpio)
			'/Total\s+Amount\s+Due\s*[\n\r,]+\s*\$([\d,]+\.\d{2})/i',
			// 2. Patrón de Balance Due (usado en la factura vieja, pero se mantiene)
			'/Balance\s+Due\s*[\n\r,]+\s*\$([\d,]+\.\d{2})/i',
			// 3. Patrón para el formato "10,420.00$" o "$10,420.00" después de Total/Balance
			'/(?:Total\s+Amount\s+Due|Total\s+Due|Balance\s+Due).*?([\d,]+\.\d{2}\s*\$|\$\s*[\d,]+\.\d{2})/i',
			// 4. Patrón que captura el monto al final de la linea que contiene "Total Amount Due" o "Total"
			'/(?:Total\s+Amount\s+Due|Total\s+Due)\s*\s*.*?(?:\$)?\s*([\d,]+\.\d{2})/i'
		]);
		
		// Fallback: Si limpiando comillas falló, intentamos con el texto crudo (raw)
		if (empty($extracted_data['amount'])) {
			 $extracted_data['amount'] = $findAmount($raw_text, [
				 // "Total Amount Due" ... "$10,420.00"
				 '/"Total\s+Amount\s+Due"\s*[\n\r,]+\s*"\$([\d,]+\.\d{2})"/i',
			 ]);
		}

	} elseif ($extracted_data['source'] === 'Copart') {
		$extracted_data['amount'] = $findAmount($clean_text, [
			// Copart: busca "Net Due" y el monto
			'/Net\s+(?:Amount\s+)?Due[^\d$]*([$]?\s*[\d,]+(?:\.\d{1,2})?)/i',
			// Copart: Fallback a Total Due
			'/Total\s+Due[^\d$]*([$]?\s*[\d,]+(?:\.\d{1,2})?)/i',
		]);
	}

	// Fallback General (Búsqueda desesperada del número más alto formateado como moneda)
	// Solo si no se encontró un monto específico y es mayor a 0.
	if (empty($extracted_data['amount']) && preg_match_all('/(?:USD|US\$|\$)\s*([\d]{1,3}(?:,\d{3})*(?:\.\d{1,2})?)/i', $clean_text, $all)) {
		$max = 0.0;
		foreach ($all[1] as $n) {
			$val = (float) str_replace(',', '', $n);
			if ($val > $max) $max = $val;
		}
		// REGLA: Si el monto general es > 0, lo usamos.
		if ($max > 0) {
			$extracted_data['amount'] = number_format($max, 2, '.', '');
		}
	}
	
	// VALIDACIÓN FINAL: Si el monto extraído es <= 0.00 (ej. $0.00 por error), lo forzamos a null.
	if (!empty($extracted_data['amount']) && (float)$extracted_data['amount'] <= 0.0) {
		$extracted_data['amount'] = null;
	}

	return $extracted_data;
}

function campo_get_vehicle_details_from_vin_v3($vin) {
	if (empty($vin)) return null;
	$url = "https://vpic.nhtsa.dot.gov/api/vehicles/decodevin/{$vin}?format=json";
	$response = wp_remote_get($url, ['timeout' => 15]);
	if (is_wp_error($response)) { return null; }
	$body = wp_remote_retrieve_body($response);
	$data = json_decode($body, true);
	if (isset($data['Results'])) {
		$details = [];
		foreach ($data['Results'] as $result) {
			if($result['Value'] !== null && $result['Value'] !== '') {
				$details[$result['Variable']] = $result['Value'];
			}
		}
		if (isset($details['Make']) && isset($details['Model']) && isset($details['Model Year'])) {
			return [
				'make'	=> ucwords(strtolower($details['Make'])),
				'model' => ucwords(strtolower($details['Model'])),
				'year'	=> $details['Model Year']
			];
		}
	}
	return null;
}

add_action('wp_ajax_campo_validate_vin', function() {
	check_ajax_referer('campo_rental_nonce', 'nonce');
	$vin = isset($_POST['vin']) ? sanitize_text_field($_POST['vin']) : '';
	if (strlen($vin) !== 17) {
		wp_send_json_error(['message' => 'VIN inválido.']);
	}
	$data = campo_get_vehicle_details_from_vin_v3($vin);
	if ($data) {
		wp_send_json_success($data);
	} else {
		wp_send_json_error(['message' => 'No se pudo decodificar el VIN.']);
	}
});

// --- 3. DEFINICIÓN DEL SHORTCODE ---
add_shortcode('campo_cuentas_alquiler_ui', 'campo_cuentas_alquiler_ui_shortcode');
function campo_cuentas_alquiler_ui_shortcode() {

	// === PayPal SDK ===
	$paypal_client_id = '';
	if (defined('CAMPO_PAYPAL_USE_SANDBOX') && CAMPO_PAYPAL_USE_SANDBOX) {
		if (defined('CAMPO_PAYPAL_SANDBOX_CLIENT_ID')) { $paypal_client_id = CAMPO_PAYPAL_SANDBOX_CLIENT_ID; }
	} else {
		if (defined('CAMPO_PAYPAL_LIVE_CLIENT_ID')) { $paypal_client_id = CAMPO_PAYPAL_LIVE_CLIENT_ID; }
	}

	wp_register_script('campo-rental-runtime', '', ['jquery'], null, true);
	wp_enqueue_script('campo-rental-runtime');

	if (!empty($paypal_client_id)) {
		wp_enqueue_script(
			'paypal-sdk',
			'https://www.paypal.com/sdk/js?client-id=' . esc_attr($paypal_client_id) . '&currency=' . CAMPO_RENTAL_CURRENCY,
			[],
			null,
			true
		);
		wp_script_add_data('campo-rental-runtime', 'data', '');
		wp_scripts()->registered['campo-rental-runtime']->deps[] = 'paypal-sdk';
	} else {
		add_action('wp_footer', function () {
			echo "<script>console.warn('CAMPO: Falta CAMPO_PAYPAL_*_CLIENT_ID. PayPal SDK no se cargará.');</script>";
		}, 5);
	}

	wp_localize_script('campo-rental-runtime', 'campo_rental_vars', [
		'ajax_url'					=> admin_url('admin-ajax.php'),
		'nonce'						=> wp_create_nonce('campo_rental_nonce'),
		'rental_price'				=> CAMPO_RENTAL_PRICE,
		'prealerta_fee'				=> CAMPO_PREALERTA_FEE,
		'processing_fee_pct'		=> CAMPO_PP_FEE_PCT * 100,
		'processing_fee_fixed'		=> CAMPO_PP_FEE_FIXED
	]);

	ob_start();

	if (!is_user_logged_in()) {
		echo '<div data-theme="light" class="p-4 text-center text-orange-700 bg-orange-100 border border-orange-200 rounded-lg"><h3 class="font-bold">Acceso Requerido</h3><p>Por favor, <a href="' . esc_url(wp_login_url(get_permalink())) . '" class="link link-primary">inicia sesión</a> para gestionar tus cuentas de alquiler.</p></div>';
		return ob_get_clean();
	}

	global $wpdb;
	$user_id = get_current_user_id();
	$user_nip = sanitize_text_field(get_user_meta($user_id, 'nip', true));

	$active_rental_account = null;
	if (!empty($user_nip)) {
		$active_rental_account = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "campo_cuentas WHERE nip_asignado = %s AND status = 'alquilada'", $user_nip));
	}
	$active_rentals = [];
	if ($active_rental_account) {
		$rental_date = $wpdb->get_var($wpdb->prepare("SELECT MAX(fecha_registro) FROM " . $wpdb->prefix . "campo_logs WHERE tipo = 'SUBASTA' AND datos = 'COMPRA' AND user_id = %d", $user_id));
		$active_rental_account->fecha_alquiler = $rental_date;
		$active_rentals[] = $active_rental_account;
	}

	$cuentas_disponibles = $wpdb->get_var("SELECT COUNT(*) FROM " . $wpdb->prefix . "campo_cuentas WHERE status = 'disponible'");
	$can_rent = !$active_rental_account && $cuentas_disponibles > 0;

	$tabla_vehiculos = $wpdb->prefix . 'campo_vehiculos';
	$tabla_transacciones = $wpdb->prefix . 'campo_transacciones';

	$items_per_page = 4;
	$current_page = isset($_GET['vehicle_page']) ? max(1, intval($_GET['vehicle_page'])) : 1;
	$date_filter = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : '90';
	$offset = ($current_page - 1) * $items_per_page;

	$date_where_clause = '';
	switch ($date_filter) {
		case '30': $date_where_clause = " AND fecha_prealerta >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; break;
		case '60': $date_where_clause = " AND fecha_prealerta >= DATE_SUB(NOW(), INTERVAL 60 DAY)"; break;
		case '90': $date_where_clause = " AND fecha_prealerta >= DATE_SUB(NOW(), INTERVAL 90 DAY)"; break;
		case 'all': default: $date_where_clause = ''; break;
	}

	$total_vehiculos = $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$tabla_vehiculos} WHERE user_id = %d AND status_vehiculo != 'entregado'{$date_where_clause}",
		$user_id
	));
	$total_pages = $total_vehiculos > 0 ? ceil($total_vehiculos / $items_per_page) : 1;

	$vehiculos_gestion = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM {$tabla_vehiculos} WHERE user_id = %d AND status_vehiculo != 'entregado'{$date_where_clause} ORDER BY fecha_prealerta DESC LIMIT %d, %d",
		$user_id, $offset, $items_per_page
	));

	foreach ($vehiculos_gestion as $vehiculo) {
		$pagos_completados = $wpdb->get_var($wpdb->prepare(
			"SELECT SUM(monto) FROM {$tabla_transacciones} WHERE item_id = %d AND item_type = 'vehiculo' AND status = 'completado'",
			$vehiculo->id
		));
		$total_deuda = $vehiculo->monto_factura + $vehiculo->late_fee + $vehiculo->storage_fee;
		$vehiculo->monto_pendiente = $total_deuda - floatval($pagos_completados);
	}
	?>
	<div data-theme="light" class="p-2 space-y-6 font-sans campo-rental-container">

		<div class="text-sm breadcrumbs">
			<ul>
				<li><a href="<?php echo esc_url(CAMPO_DASHBOARD_URL); ?>">Dashboard</a></li>
				<li>Cuentas de Subasta</li>
			</ul>
		</div>

		<div class="card bg-base-100 shadow-xl">
			<div class="card-body">
				<h2 class="card-title">Tus Cuentas de Alquiler</h2>
				<div class="overflow-x-auto">
					<table class="table w-full">
						<thead><tr><th>Estado</th><th>Límites</th><th>Cuenta</th><th>Fecha de Alquiler</th><th class="text-center">Acciones</th></tr></thead>
						<tbody>
							<?php if (empty($active_rentals)): ?>
								<tr>
									<td colspan="5" class="text-center py-8">
										<?php if ($can_rent): ?>
											<button class="btn btn-primary btn-wide" onclick="rental_payment_modal.showModal()">
												<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
												Alquilar Nueva Cuenta
											</button>
										<?php else: ?>
											<span class="text-base-content/60">No tienes alquileres activos o no hay cuentas disponibles.</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php else: foreach ($active_rentals as $rental): ?>
								<tr>
									<td><div class="flex items-center gap-2"><div class="badge badge-success badge-xs" title="Activo"></div>Activo</div></td>
									<td>
										<?php if (isset($rental->limite) && is_numeric($rental->limite)): ?>
											<?php if ($rental->limite <= 300): ?>
												<div class="flex items-center gap-2">
													<span class="badge badge-warning badge-xs"></span> Pendiente
													<div class="tooltip" data-tip="Puede usar sus credenciales, pero debe esperar de 1 a 9 horas hábiles para poder subastar.">
														<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="w-4 h-4 stroke-current"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
													</div>
												</div>
											<?php else: $limite_calculado = $rental->limite * 10; ?>
												<div class="flex items-center gap-2"><span class="badge badge-success badge-xs"></span> Límite de compra $<?php echo esc_html(number_format($limite_calculado, 0, '.', ',')); ?></div>
											<?php endif; ?>
										<?php else: ?><span class="text-xs opacity-50">No definido</span><?php endif; ?>
									</td>
									<td><div class="font-bold">NIP: <?php echo esc_html($rental->nip_asignado); ?></div><div class="text-sm opacity-70">Acceso a Subasta</div></td>
									<td><?php if (!empty($rental->fecha_alquiler)) { echo date_i18n('d/m/Y', strtotime($rental->fecha_alquiler)); } else { echo 'N/A'; } ?></td>
									<td class="text-center space-x-1">
										<button class="btn btn-sm btn-outline btn-info" onclick="fetchAndShowCredentials()">Credenciales</button>
										<button class="btn btn-sm btn-outline" onclick="rules_modal.showModal()">Reglas</button>
										<button class="btn btn-sm btn-outline btn-accent" onclick="abrirPreAlertaConAutocompletado()">Pre-alerta</button>
									</td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<div class="card bg-base-100 shadow-xl mt-8">
			<div class="card-body">
				<div class="flex flex-col sm:flex-row justify-between items-center gap-4 mt-2 mb-4">
					<h2 class="card-title">Gestión de Vehículos Comprados</h2>
					<div class="dropdown dropdown-end">
						<div tabindex="0" role="button" class="btn btn-sm m-1">
							Filtrar:
							<span class="font-bold">
							<?php
								$filters = ['30' => 'Últimos 30 Días', '60' => 'Últimos 60 Días', '90' => 'Últimos 90 Días', 'all' => 'Ver Todos'];
								echo esc_html($filters[$date_filter]);
							?>
							</span>
							<svg width="12px" height="12px" class="h-2 w-2 fill-current opacity-60 inline-block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2048 2048"><path d="M1799 349l242 241-1017 1017L7 590l242-241 775 775 775-775z"></path></svg>
						</div>
						<ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-52">
							<?php
							$base_url_filters = remove_query_arg('vehicle_page');
							foreach ($filters as $key => $label) {
								echo '<li><a href="' . esc_url(add_query_arg('date_filter', $key, $base_url_filters)) . '">' . esc_html($label) . '</a></li>';
							}
							?>
						</ul>
					</div>
				</div>

				<div class="veh-cards-list">
					<?php if (empty($vehiculos_gestion)): ?>
						<div class="text-center py-8 text-base-content/60">No se encontraron vehículos con el filtro seleccionado.</div>
					<?php else: foreach($vehiculos_gestion as $v): ?>
						<div class="veh-card">
							<div class="veh-card-body">
								<div class="veh-card-grid">
									<div>
										<div class="veh-section-title">Vehículo</div>
										<div class="font-bold"><?php echo esc_html($v->vehiculo_nombre); ?></div>
										<div class="text-sm opacity-70">VIN: <?php echo esc_html($v->vin); ?></div>
										<div class="text-sm opacity-70">Fecha: <?php echo date_i18n('d/m/Y', strtotime($v->fecha_prealerta)); ?></div>
									</div>

									<div>
										<div class="veh-section-title">Detalles Financieros</div>
										<p>Factura: <span class="font-semibold">$<?php echo number_format($v->monto_factura, 2); ?></span></p>
										<p>Late Fee: <span class="font-semibold text-red-600">$<?php echo number_format($v->late_fee, 2); ?></span></p>
										<p>Storage: <span class="font-semibold text-red-600">$<?php echo number_format($v->storage_fee, 2); ?></span></p>
										<p class="font-bold mt-2 border-t pt-1">Pendiente:
											<span class="text-lg text-red-600">$<?php echo number_format($v->monto_pendiente, 2); ?></span>
										</p>
									</div>

									<div>
										<div class="veh-section-title">Estado</div>
										<?php
											$pagos_pendientes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabla_transacciones} WHERE item_id = %d AND item_type = 'vehiculo' AND status = 'pendiente'", $v->id));
											if ($v->monto_pendiente <= 0.01) { $status_pago_luz = 'bg-green-500'; $status_pago_texto = 'Pagado'; }
											elseif ($pagos_pendientes > 0) { $status_pago_luz = 'bg-yellow-400'; $status_pago_texto = 'Verificando Pago'; }
											else { $status_pago_luz = 'bg-orange-500'; $status_pago_texto = 'Pendiente de Pago'; }
										?>
										<div class="flex items-center gap-2 mt-1">
											<div class="badge <?php echo esc_attr($status_pago_luz); ?> badge-xs" title="<?php echo esc_attr($status_pago_texto); ?>"></div>
											<span><?php echo esc_html($status_pago_texto); ?></span>
										</div>
									</div>

									<div class="veh-actions">
										<div class="veh-section-title">Acciones</div>
										<button class="btn btn-sm btn-outline ver-documentos-btn"
												data-vin="<?php echo esc_attr($v->vin); ?>">
											Ver Documentos
										</button>
										<?php if ($v->monto_pendiente > 0): ?>
											<button class="btn btn-sm btn-primary mt-2 pagar-vehiculo-btn"
													data-vehiculo-id="<?php echo esc_attr($v->id); ?>"
													data-vin="<?php echo esc_attr($v->vin); ?>"
													data-monto-pendiente="<?php echo esc_attr($v->monto_pendiente); ?>">
												PAGAR
											</button>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>

				<div class="mt-6 flex justify-center">
					<div class="join">
						<?php $base_url_pagination = add_query_arg('date_filter', $date_filter); ?>
						<a href="<?php echo esc_url(add_query_arg('vehicle_page', $current_page - 1, $base_url_pagination)); ?>" class="join-item btn <?php if ($current_page <= 1) echo 'btn-disabled'; ?>">«</a>

						<?php for ($i = 1; $i <= $total_pages; $i++): ?>
							<a href="<?php echo esc_url(add_query_arg('vehicle_page', $i, $base_url_pagination)); ?>" class="join-item btn <?php if ($i == $current_page) echo 'btn-primary'; ?>">
								<?php echo $i; ?>
							</a>
						<?php endfor; ?>

						<a href="<?php echo esc_url(add_query_arg('vehicle_page', $current_page + 1, $base_url_pagination)); ?>" class="join-item btn <?php if ($current_page >= $total_pages) echo 'btn-disabled'; ?>">»</a>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
		if(!document.getElementById("daisyui-cdn")){
			const e=document.createElement("link");
			e.id="daisyui-cdn",e.href="https://cdn.jsdelivr.net/npm/daisyui@4.10.1/dist/full.min.css",e.rel="stylesheet",document.head.appendChild(e);
			const t=document.createElement("script");
			t.id="tailwindcss-cdn",t.src="https://cdn.tailwindcss.com",document.head.appendChild(t)
		}
	</script>
	<?php
	add_action('wp_footer', 'campo_rental_modals_footer_full_v33', 100);
	return ob_get_clean();
}

function campo_rental_modals_footer_full_v33() {
	if (did_action('campo_rental_modals_footer_printed') > 0) return;

	global $wpdb;
	$tabla_talleres = $wpdb->prefix . 'campo_talleres';
	$talleres = $wpdb->get_results($wpdb->prepare("SELECT id_taller, nombre FROM {$tabla_talleres} WHERE status = %s ORDER BY nombre ASC", 'activo'));
	?>
	<div id="campo-modal-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-[90]" style="display: none;"></div>

	<dialog id="credentials_modal" class="modal">
		<div class="modal-box" style="z-index: 100;">
			<h3 class="font-bold text-lg">Tus Credenciales de Subasta</h3>
			<div id="credentials-content" class="py-4"></div>
			<div class="modal-action"><form method="dialog"><button class="btn">Cerrar</button></form></div>
		</div>
	</dialog>

	<dialog id="rules_modal" class="modal">
		<div class="modal-box w-11/12 max-w-2xl" style="z-index: 100;">
			<h3 class="font-bold text-lg">Reglas de la Cuenta de Alquiler</h3>
			<div class="py-4 prose max-w-none">
				<?php echo do_shortcode('[reglas_copart]'); ?>
			</div>
			<div class="modal-action"><form method="dialog"><button class="btn">Entendido</button></form></div>
		</div>
	</dialog>

	<dialog id="rental_payment_modal" class="modal">
		<div class="modal-box w-11/12 max-w-4xl" style="z-index: 100%;">
			<form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">✕</button></form>
			<h3 class="font-bold text-lg text-center">Alquilar una Cuenta de Subasta</h3>

			<div id="rental-wizard" class="space-y-4 p-4">
				<ul class="steps w-full">
					<li id="rental-step-li-1" class="step step-primary">Términos</li>
					<li id="rental-step-li-2" class="step">Reglas</li>
					<li id="rental-step-li-3" class="step">Aviso</li>
					<li id="rental-step-li-4" class="step">Pago</li>
				</ul>

				<div id="rental-step-panel-1" class="step-panel">
					<h4 class="text-md font-semibold mb-2">Paso 1: Términos y Condiciones</h4>
					<div class="form-control p-4 border rounded-lg bg-base-200">
						<div class="prose max-w-none bg-base-100 p-3 rounded border cb-scroll mb-3">
							<?php echo do_shortcode('[cb_terminos]'); ?>
						</div>
						<label class="label cursor-pointer justify-start gap-3">
							<input type="checkbox" id="terms-check" class="checkbox checkbox-primary" />
							<span class="label-text">He leído y acepto los Términos y Condiciones.</span>
						</label>
					</div>
				</div>

				<div id="rental-step-panel-2" class="step-panel hidden">
					<h4 class="text-md font-semibold mb-2">Paso 2: Reconocer Reglas</h4>
					<div class="form-control p-4 border rounded-lg bg-base-200">
						<div class="prose max-w-none bg-base-100 p-3 rounded border cb-scroll mb-3">
							<?php echo do_shortcode('[reglas_copart]'); ?>
						</div>
						<label class="label cursor-pointer justify-start gap-3">
							<input type="checkbox" id="rules-check" class="checkbox checkbox-primary" />
							<span class="label-text">He leído y acepto las Reglas de la cuenta de alquiler.</span>
						</label>
					</div>
				</div>

				<div id="rental-step-panel-3" class="step-panel hidden">
					<h4 class="text-md font-semibold mb-2">Paso 3: Aviso Importante de Pago</h4>
					<div role="alert" class="alert alert-warning">
						<div>
							<h3 class="font-bold">¡Atención!</h3>
							<div class="text-xs">
								El depósito es de $300, reembolsable si no se compra en 36 horas.
								Los pagos de vehículos ganados **deben hacerse a Campo Broker mediante el panel de control luego de realizar la Pre-Alerta**.
								Pagar directamente a la subasta (Copart/IAAI) no se recomienda, y está bajo tu propio riesgo, ya que puede causar multas innecesarias y perder las garantias que otorga Campo Broker S.R.L, debido a un comunicado oficial por parte de las subastas diciendo que **dejarán de aceptar pagos de terceros**.
							</div>
						</div>
					</div>
				</div>

				<div id="rental-step-panel-4" class="step-panel hidden">
					<div class="font-sans max-w-4xl mx-auto p-4 md:p-8 bg-gray-50 rounded-lg">
						<div class="grid grid-cols-1 md:grid-cols-2 gap-8">
							<div class="flex flex-col space-y-4">
								<h2 class="text-xl font-bold text-gray-800">Elige tu método de pago</h2>
								<label class="p-4 border rounded-lg hover:border-blue-500 cursor-pointer has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
									<input type="radio" name="payment_method_rental" class="hidden" value="paypal" checked>
									<div class="flex items-center justify-between">
										<span class="font-semibold">PayPal</span>
										<img src="https://www.paypalobjects.com/webstatic/mktg/logo-center/PP_Acceptance_Marks_for_LogoCenter_266x142.png" alt="PayPal Logo" class="h-8">
									</div>
								</label>
							</div>

							<div class="bg-white p-6 rounded-lg shadow-inner">
								<h3 class="text-lg font-bold text-gray-800 border-b pb-4">Resumen del pedido</h3>
								<div class="space-y-3 mt-4 text-gray-600">
									<p class="flex justify-between"><span>Tarifa de Alquiler</span><span class="font-semibold" id="rental-subtotal"></span></p>
									<p class="flex justify-between">
										<span>Comisión de procesamiento (<span id="rental-fee-pct">5.4</span>% + $<span id="rental-fee-fx">0.30</span>)</span>
										<span id="rental-fee-amount" class="font-semibold"></span>
									</p>
									<div class="border-t pt-4 mt-4">
										<p class="flex justify-between text-lg font-bold text-gray-900"><span>Pagar en USD</span><span id="rental-total-amount"></span></p>
									</div>
								</div>
								<div id="rental-paypal-button-container" class="mt-6 min-h-[50px]"></div>
								<div id="rental-processing-spinner" class="text-center p-4 hidden">
									<p>Procesando...</p><span class="loading loading-infinity loading-lg"></span>
								</div>
								<div class="flex items-center justify-center space-x-4 mt-4 text-gray-400"><span class="text-xs">✔️ SSL</span><span class="text-xs">✔️ PCI DSS</span><span class="text-xs">✔️ Pagos Seguros</span></div>
							</div>
						</div>
					</div>
				</div>

				<div class="modal-action mt-6">
					<button id="rental-wizard-back-btn" class="btn">Atrás</button>
					<button id="rental-wizard-next-btn" class="btn btn-primary">Siguiente</button>
				</div>
			</div>
		</div>
	</dialog>

	<dialog id="alert_modal" class="modal">
		<div class="modal-box" style="z-index: 100%;">
			<div id="alert_modal_content"></div>
			<div class="modal-action"><form method="dialog"><button class="btn btn-primary" id="alert_modal_close_btn">Entendido</button></form></div>
		</div>
	</dialog>

	<dialog id="pre_alert_modal" class="modal">
		<div class="modal-box w-11/12 max-w-4xl" style="z-index: 100%;">
			<form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">✕</button></form>
			<h3 class="font-bold text-lg text-center">Pre-Alerta de Vehículo Comprado</h3>

			<div id="pre-alert-wizard" class="space-y-4 p-4">
				<ul class="steps w-full">
					<li id="pa-step-li-1" class="step step-primary">Aviso</li>
					<li id="pa-step-li-2" class="step">Datos</li>
					<li id="pa-step-li-3" class="step">Detalles</li>
					<li id="pa-step-li-4" class="step">Confirmar</li>
					<li id="pa-step-li-5" class="step">Pago</li>
				</ul>

				<div id="pa-step-panel-1" class="pa-step-panel">
					<div role="alert" class="alert alert-info mt-4">
						<h3 class="font-bold">Aviso Importante</h3>
						<div class="text-sm">Está a punto de iniciar el proceso de pago para un vehículo que ya ha ganado en una subasta. Al continuar, confirma que ha finalizado sus compras con la cuenta de alquiler actual y su acceso será desactivado. Para realizar nuevas compras, deberá alquilar una nueva cuenta.</div>
					</div>
				</div>

				<div id="pa-step-panel-2" class="pa-step-panel hidden">
					<div class="divider">Información para el Título</div>
					<div class="form-control">
						<label class="label"><span class="label-text">¿Enviará el título a un taller autorizado con fines de exportación?</span></label>
						<div class="flex gap-4">
							<label class="label cursor-pointer"><input type="radio" name="trabajara_taller" value="si" class="radio radio-primary" required>&nbsp;Sí</label>
							<label class="label cursor-pointer"><input type="radio" name="trabajara_taller" value="no" class="radio radio-primary" required>&nbsp;No</label>
						</div>
					</div>
					<div id="pa-taller-section" class="form-control w-full hidden mt-4">
						<label class="label"><span class="label-text">Seleccione el Taller Autorizado</span></label>
						<select id="pa_taller_id" name="taller_id" class="select select-bordered w-full">
							<option value="">-- Seleccione un taller --</option>
							<?php if (!empty($talleres)): foreach ($talleres as $taller): ?>
								<option value="<?php echo esc_attr($taller->id_taller); ?>"><?php echo esc_html($taller->nombre); ?></option>
							<?php endforeach; endif; ?>
						</select>
					</div>
					<div id="pa-direccion-manual-section" class="hidden mt-4 space-y-3">
						<div class="form-control w-full">
							<label class="label"><span class="label-text">¿A qué dirección (USA) se enviará el título?</span></label>
							<input type="text" id="pa_shipping_address" name="shipping_address" placeholder="Comience a escribir la dirección en USA..." class="input input-bordered w-full" />
						</div>
					</div>

					<div class="divider">Estado donde ganó la subasta</div>
					<div class="form-control">
						<label class="label"><span class="label-text">¿El vehículo fue ganado en FL, NY, NJ, NC, OH u OR?</span></label>
						<div class="flex gap-4 items-center">
							<label class="label cursor-pointer gap-2">
								<input type="radio" name="estado_especial" value="si" class="radio radio-primary" required>
								<span>Sí <span class="text-xs opacity-70">(+ US$45)</span></span>
							</label>
							<label class="label cursor-pointer gap-2">
								<input type="radio" name="estado_especial" value="no" class="radio radio-primary" required>
								<span>No</span>
							</label>
						</div>
					</div>

					<div class="divider">Finalidad del vehículo</div>
					<div class="form-control">
						<label class="label"><span class="label-text">¿Cuál será la finalidad del vehículo?</span></label>
						<div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center">
							<label class="label cursor-pointer gap-2">
								<input type="radio" name="finalidad_vehiculo" value="exportar" class="radio radio-primary" required>
								<span>Exportarlo</span>
							</label>
							<label class="label cursor-pointer gap-2">
								<input type="radio" name="finalidad_vehiculo" value="uso_usa" class="radio radio-primary" required>
								<span>Uso en territorio EE.UU. <span class="text-xs opacity-70">(+ US$50)</span></span>
							</label>
						</div>
					</div>

					<div class="hidden">
						<input type="radio" name="info_method" value="upload" checked />
						<input type="radio" name="info_method" value="manual" />
					</div>
				</div>

				<div id="pa-step-panel-3" class="pa-step-panel hidden">
					<div id="pa-ajax-loader" class="text-center hidden"><p>Procesando factura...</p><span class="loading loading-lg loading-spinner"></span></div>
					<div id="pa-form-content">
						<div id="pa-upload-section">
							<label class="label"><span class="label-text">Subir Factura (JPG, PNG y PDF)</span></label>
							<input type="file" id="pa_invoice_file" class="file-input file-input-bordered w-full" accept=".jpg,.jpeg,.png,.pdf" />
							<div class="mt-2 p-2 bg-yellow-100 text-yellow-800 text-sm rounded flex items-center gap-2">
								<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
								<span>
									Si no sabe cuál es la factura que debe adjuntar,
									<a href="#" target="_blank" class="underline font-bold text-black">Descargue el instructivo</a>.
								</span>
							</div>
							
							<div id="pa-manual-fallback-container" class="hidden mt-4 text-center">
								<p class="text-sm text-error mb-2">No pudimos leer tu factura automáticamente tras varios intentos.</p>
								<button type="button" id="btn-switch-manual" class="btn btn-sm btn-outline btn-warning">
									Llenar los datos manualmente
								</button>
							</div>
						</div>

						<div id="pa-manual-section" class="hidden space-y-3">
							<div class="alert alert-warning text-sm shadow-lg mb-4">
								<span>Estás ingresando los datos manualmente. Asegúrate de escribir todo exactamente como aparece en la factura.</span>
							</div>
							<div class="form-control w-full"><label class="label"><span class="label-text">VIN (Del vehículo ganado con su cuenta)</span></label><input type="text" id="pa_vin" placeholder="Ingrese el VIN de 17 dígitos" class="input input-bordered w-full" maxlength="17" /></div>
							<div id="pa-vehicle-info" class="text-sm p-2 bg-base-200 rounded-md min-h-[2.5rem] flex items-center"></div>
							<div class="form-control w-full"><label class="label"><span class="label-text">Monto Total de la Factura a Pagar en dólares (USD)</span></label><input type="number" id="pa_total_amount" placeholder="Ej: 12520.00"
	class="input input-bordered w-full"
	inputmode="decimal" min="0.01" step="0.01"
	pattern="^\d+(\.\d{1,2})?$"
	onkeydown="return !['e','E','+','-'].includes(event.key);"
	oninput="this.value=this.value.replace(/[^0-9.]/g,'');if(this.value.startsWith('.'))this.value='0'+this.value;const p=this.value.split('.');if(p.length>2){this.value=p.shift()+'.'+p.join('');}if(p[1]&&p[1].length>2){this.value=p[0]+'.'+p[1].slice(0,2);}"/></div>
							<div class="flex gap-4">
								<label class="label">Subasta:</label>
								<div class="form-control"><label class="label cursor-pointer gap-2"><span class="label-text">Copart</span><input type="radio" name="auction_source" value="Copart" class="radio radio-primary" /></label></div>
								<div class="form-control"><label class="label cursor-pointer gap-2"><span class="label-text">IAAI</span><input type="radio" name="auction_source" value="IAAI" class="radio radio-primary" /></label></div>
							</div>
						</div>
					</div>
				</div>

				<div id="pa-step-panel-4" class="pa-step-panel hidden">
					<h4 class="font-semibold text-center mb-4">Por favor, confirme los datos</h4>
					<div id="pa-confirmation-details" class="p-4 bg-base-200 rounded-lg text-sm space-y-2"></div>
				</div>

				<div id="pa-step-panel-5" class="pa-step-panel hidden">
					<div class="font-sans max-w-4xl mx-auto p-4 md:p-8 bg-gray-50 rounded-lg">
						<div class="grid grid-cols-1 md:grid-cols-[1fr_420px] gap-8">
							<div class="flex flex-col space-y-4">
								<h2 class="text-xl font-bold text-gray-800">Elige tu método de pago</h2>
								<label class="p-4 border rounded-lg hover:border-blue-500 cursor-pointer has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
									<input type="radio" name="payment_method_prealerta" class="hidden" value="paypal" checked>
									<div class="flex items-center justify-between">
										<span class="font-semibold">PayPal</span>
										<img src="https://www.paypalobjects.com/webstatic/mktg/logo-center/PP_Acceptance_Marks_for_LogoCenter_266x142.png" alt="PayPal Logo" class="h-8">
									</div>
								</label>
							</div>
							<div class="bg-white p-6 rounded-lg shadow-inner">
								<h3 class="text-lg font-bold text-gray-800 border-b pb-4">Resumen del pedido</h3>
								<div class="space-y-3 mt-4 text-gray-600">

									<p class="flex justify-between">
										<span class="flex items-center">
											Tarifa de Pre-Alerta
											<span tabindex="0" class="tooltip tooltip-top cb-tip" data-tip="Este cargo cubre los gastos de transferencia local e internacional, envío de títulos e impuestos locales e internacionales relacionados con su pago.">
												<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 8h.01M12 12v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
											</span>
										</span>
										<span class="font-semibold" id="pre-alerta-subtotal"></span>
									</p>

									<p id="pre-alerta-extra-estado-row" class="flex justify-between hidden">
										<span class="flex items-center">
											Cargo por estado
											<span tabindex="0" class="tooltip tooltip-top cb-tip" data-tip="Aplica si el vehículo fue ganado en FL, NY, NJ, NC, OH u OR, ya que el título debe enviarse obligatoriamente ante el DMV para solicitar un nuevo titulo (no emitido a nombre del usuario).">
												<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 8h.01M12 12v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
											</span>
										</span>
										<span class="font-semibold" id="pre-alerta-extra-estado"></span>
									</p>

									<p id="pre-alerta-extra-uso-row" class="flex justify-between hidden">
										<span class="flex items-center">
											Nuevo Título
											<span tabindex="0" class="tooltip tooltip-top cb-tip" data-tip="Emisión de un nuevo título a nombre del usuario para uso dentro de Estados Unidos (venta o uso personal).">
												<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 8h.01M12 12v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
											</span>
										</span>
										<span class="font-semibold" id="pre-alerta-extra-uso"></span>
									</p>

									<p class="flex justify-between">
										<span>Comisión de procesamiento (5.4% + $0.30)</span>
										<span id="pre-alerta-fee-amount" class="font-semibold"></span>
									</p>
									<div class="border-t pt-4 mt-4"><p class="flex justify-between text-lg font-bold text-gray-900"><span>Pagar en USD</span><span id="pre-alerta-total-amount"></span></p></div>
								</div>
								<div id="pre-alerta-paypal-button-container" class="mt-6 min-h-[50px]"></div>
								<div id="pre-alerta-processing-spinner" class="text-center p-4 hidden"><p>Procesando pago y registrando vehículo...</p><span class="loading loading-infinity loading-lg"></span></div>
								<div class="flex items-center justify-center space-x-4 mt-4 text-gray-400"><span class="text-xs">✔️ SSL</span><span class="text-xs">✔️ PCI DSS</span><span class="text-xs">✔️ Pagos Seguros</span></div>
							</div>
						</div>
					</div>
				</div>

				<div class="modal-action mt-6">
					<button id="pa-wizard-back-btn" class="btn">Atrás</button>
					<button id="pa-wizard-next-btn" class="btn btn-primary">Siguiente</button>
				</div>
			</div>
		</div>
	</dialog>

	<dialog id="ver_documentos_modal" class="modal">
		<div class="modal-box" style="z-index: 100%;">
			<form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">✕</button></form>
			<h3 class="font-bold text-lg">Documentos para VIN: <span id="docs-vin-cliente"></span></h3>
			<div id="lista-docs-cliente" class="py-4">
				</div>
			<div class="modal-action"><form method="dialog"><button class="btn">Cerrar</button></form></div>
		</div>
	</dialog>

	<dialog id="pagar_vehiculo_modal" class="modal">
		<div class="modal-box w-11/12 max-w-4xl" style="z-index: 100%;">
			<form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">✕</button></form>
			<h3 class="font-bold text-lg text-center mb-4">Realizar Abono a Vehículo</h3>

			<form id="form-pago-vehiculo" class="font-sans bg-gray-50 rounded-lg">
				<input type="hidden" id="pago-vehiculo-id" name="vehiculo_id">
				<div class="grid grid-cols-1 md:grid-cols-2 gap-8 p-4 md:p-8">
					<div class="flex flex-col space-y-4">
						<h2 class="text-xl font-bold text-gray-800">Elige tu método de pago</h2>
						<p class="text-sm">VIN: <span id="pago-vehiculo-vin" class="font-mono"></span><br>Monto Pendiente Total: <strong id="pago-vehiculo-pendiente" class="text-red-500"></strong></p>
						<div class="form-control">
							<label class="label"><span class="label-text font-semibold">Monto a Pagar (USD)</span></label>
							<input type="number" id="pago-vehiculo-monto" name="monto" class="input input-bordered w-full" step="0.01" placeholder="0.00" required>
						</div>

						<div id="paypal-option-container">
							<label class="p-4 border rounded-lg hover:border-blue-500 cursor-pointer has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500 flex items-center justify-between" id="paypal-label">
								<span class="font-semibold">PayPal</span>
								<img src="https://www.paypalobjects.com/webstatic/mktg/logo-center/PP_Acceptance_Marks_for_LogoCenter_266x142.png" alt="PayPal Logo" class="h-8">
								<input type="radio" name="metodo_pago" value="PayPal" class="hidden">
							</label>
							<div id="paypal-limit-msg" class="text-xs text-red-600 mt-1 hidden">Pagos mayores a $700.00 solo por transferencia.</div>
						</div>

						<label class="p-4 border rounded-lg hover:border-blue-500 cursor-pointer has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
							<input type="radio" name="metodo_pago" value="Transferencia Bancaria" class="hidden">
							<div class="flex items-center justify-between"><span class="font-semibold">Transferencia Bancaria</span><span>🏦</span></div>
						</label>

						<div id="pago-vehiculo-transferencia-info" class="hidden space-y-3 p-4 border rounded-lg bg-base-200">
							<p class="font-semibold">Por favor, realice la transferencia a la siguiente cuenta:</p>
							<div>
								<p><strong>Banco:</strong> Banreservas</p>
								<p><strong>Número de Cuenta:</strong> 9608450112</p>
								<p><strong>Nombre del Beneficiario:</strong> Campo Broker SRL</p>
								<p><strong>Código SWIFT (transferencias internacionales):</strong> BRRDDOSD</p>

								<button type="button" id="btn-transfer-detalles" class="btn btn-xs btn-outline mt-2">
									VER MÁS DETALLES
								</button>

								<div id="transfer-detalles-extra" class="mt-2 hidden">
									<p><strong>RNC / TAX ID:</strong> 133-30707-3</p>
									<p><strong>Moneda:</strong> USD</p>
									<p><strong>Tipo de cuenta:</strong> Ahorro</p>
									<p><strong>Dirección del beneficiario:</strong> AVENIDA CAMINO REAL ESQ. CALLE NAVIDAD ESTE #11, CIUDAD JUAN BOSCH, SANTO DOMINGO ESTE, REPÚBLICA DOMINICANA</p>
									<p><strong>Teléfono:</strong> +1 (809) 431-2000</p>
									<p><strong>Dirección del banco:</strong> AVENIDA WINSTON CHURCHILL ESQUINA PORFIRIO HERRERA, TORRE BANRESERVAS, SANTO DOMINGO, REPÚBLICA DOMINICANA</p>
								</div>
							</div>
							<div class="form-control w-full">
								<label class="label"><span class="label-text">Subir Comprobante de Pago</span></label>
								<input type="file" id="comprobante_pago" name="comprobante_pago" class="file-input file-input-bordered file-input-sm w-full" accept=".jpg,.jpeg,.png,.pdf" required>
							</div>
						</div>
					</div>

					<div class="bg-white p-6 rounded-lg shadow-inner">
						<h3 class="text-lg font-bold text-gray-800 border-b pb-4">Resumen del pedido</h3>
						<div class="space-y-3 mt-4 text-gray-600">
							<p class="flex justify-between"><span>Subtotal</span><span class="font-semibold" id="pago-vehiculo-subtotal">$0.00</span></p>
							<p class="flex justify-between"><span>Comisión de procesamiento (5.4% + $0.30)</span><span id="pago-vehiculo-fee" class="font-semibold">$0.00</span></p>
							<div class="border-t pt-4 mt-4"><p class="flex justify-between text-lg font-bold text-gray-900"><span>Pagar en USD</span><span id="pago-vehiculo-total">$0.00</span></p></div>
						</div>
						<div id="pago-vehiculo-paypal-container" class="mt-6 min-h-[50px]"></div>
						<button type="submit" id="pago-vehiculo-transferencia-submit" class="btn btn-primary w-full mt-6 hidden">Enviar Comprobante</button>
						<div id="pago-vehiculo-spinner" class="text-center p-4 hidden"><p>Procesando...</p><span class="loading loading-infinity loading-lg"></span></div>
						<div class="flex items-center justify-center space-x-4 mt-4 text-gray-400"><span class="text-xs">✔️ SSL</span><span class="text-xs">✔️ PCI DSS</span><span class="text-xs">✔️ Pagos Seguros</span></div>
					</div>
				</div>
			</form>
		</div>
	</dialog>

	<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo defined('CAMPO_MAPS_API_KEY') ? esc_attr(CAMPO_MAPS_API_KEY) : ''; ?>&libraries=places" async defer></script>

	<script id="campo-rental-script">
	function fetchAndShowCredentials(){
		const modal = document.getElementById('credentials_modal');
		const content = document.getElementById('credentials-content');
		content.innerHTML = '<p>Cargando...</p><div class="text-center p-4"><span class="loading loading-dots loading-lg"></span></div>';
		modal.showModal();
		jQuery.ajax({
			url: campo_rental_vars.ajax_url,
			type: "POST",
			data: { action: "campo_get_rental_credentials", nonce: campo_rental_vars.nonce },
			success: function(response) {
				if (response.success) {
					const data = response.data;
					content.innerHTML = `<p>Hola ${data.nombre}, aquí están tus datos:</p>
					<div class="my-2 p-2 bg-base-200 rounded break-words"><strong>Email:</strong> ${data.email}</div>
					<div class="my-2 p-2 bg-base-200 rounded break-words"><strong>Contraseña:</strong> ${data.password}</div>
					<p class="text-xs text-opacity-70 mt-3"><strong>Importante:</strong> Las contraseñas pueden cambiar. Revisa siempre aquí.</p>`;
				} else {
					content.innerHTML = `<div class="alert alert-error"><span>Error: ${response.data.message}</span></div>`;
				}
			},
			error: function() { content.innerHTML = '<div class="alert alert-error"><span>Error de servidor.</span></div>'; }
		});
	}

	function initializeAddressAutocomplete() {
		const input = document.getElementById('pa_shipping_address');
		if (!input || typeof google === 'undefined' || typeof google.maps === 'undefined') { return; }
		if (input.dataset.autocompleteInitialized === 'true') return;
		const autocomplete = new google.maps.places.Autocomplete(input, { types: ['address'], componentRestrictions: { country: ['us', 'ca', 'do'] } });
		autocomplete.addListener('place_changed', function () {
			input.dispatchEvent(new Event('input', { bubbles: true }));
		});
		input.dataset.autocompleteInitialized = 'true';
	}

	function abrirPreAlertaConAutocompletado() {
		const modal = document.getElementById('pre_alert_modal');
		const overlay = document.getElementById('campo-modal-overlay');
		overlay.style.display = 'block';
		modal.show(); // se mantiene como en tu flujo actual
		setTimeout(() => { initializeAddressAutocomplete(); }, 300);
	}

	jQuery(document).ready(function($) {
		const feePct = (Number(campo_rental_vars.processing_fee_pct) || 5.4) / 100; // 5.4%
		const feeFixed = (typeof campo_rental_vars.processing_fee_fixed !== 'undefined') ? Number(campo_rental_vars.processing_fee_fixed) : 0.30; // $0.30

		const showAlert = (message, type = 'error', reload = false) => {
			const alertModal = document.getElementById('alert_modal');
			$('#alert_modal_content').html(`<h3 class="font-bold text-lg text-${type}">${type === 'error' ? 'Error' : 'Aviso'}</h3><p class="py-4">${message}</p>`);
			$('#alert_modal_close_btn').off('click').on('click', () => { if (reload) { location.reload(); } });
			alertModal.showModal();
		};

		// === Ver Documentos (usa DB: factura_final_url y titulo_url) ===
		$('.ver-documentos-btn').on('click', function() {
			const vin = $(this).data('vin');
			$('#docs-vin-cliente').text(vin);
			const lista = $('#lista-docs-cliente'); lista.html('<div class="text-center p-4"><span class="loading loading-dots loading-lg"></span></div>');
			$.post(campo_rental_vars.ajax_url, {
				action: 'campo_get_vehicle_docs',
				nonce: campo_rental_vars.nonce,
				vin: vin
			}).done(function(resp){
				const factura = (resp.success && resp.data.factura) ? resp.data.factura : '';
				const titulo	= (resp.success && resp.data.titulo) ? resp.data.titulo : '';
				const mk = (label, url) => `
					<div class="p-3 border rounded-lg mb-2 flex items-center justify-between">
						<div><strong>${label}</strong><div class="text-xs opacity-70">${url ? 'Disponible' : 'No disponible'}</div></div>
						${url ? `<a class="btn btn-sm btn-outline" target="_blank" href="${url}">Ver</a>` : '<button class="btn btn-sm" disabled>—</button>'}
					</div>`;
				lista.html(mk('Factura Subasta', factura) + mk('Título', titulo));
			}).fail(function(){
				lista.html('<div class="alert alert-error">No se pudieron cargar los documentos.</div>');
			});
			document.getElementById('ver_documentos_modal').showModal();
		});

		let paypalButtonsVehiculo;

		function updateVehiclePaymentSummary() {
			const subtotal = parseFloat($('#pago-vehiculo-monto').val()) || 0;
			const paypalRadio = $('input[name="metodo_pago"][value="PayPal"]');
			const paypalLabel = $('#paypal-label');
			const paypalLimitMsg = $('#paypal-limit-msg');

			if (subtotal > 700) {
				paypalRadio.prop('disabled', true);
				paypalLabel.addClass('opacity-50 cursor-not-allowed');
				paypalLimitMsg.removeClass('hidden');
				if ($('input[name="metodo_pago"]:checked').val() === 'PayPal') {
					$('input[name="metodo_pago"][value="Transferencia Bancaria"]').prop('checked', true).trigger('change'); return;
				}
			} else {
				paypalRadio.prop('disabled', false);
				paypalLabel.removeClass('opacity-50 cursor-not-allowed');
				paypalLimitMsg.addClass('hidden');
			}

			const method = $('input[name="metodo_pago"]:checked').val();
			let fee = 0;
			if (method === 'PayPal') { fee = (subtotal * feePct) + feeFixed; }
			const total = subtotal + fee;

			$('#pago-vehiculo-subtotal').text(`$${subtotal.toFixed(2)}`);
			$('#pago-vehiculo-fee').text(`$${fee.toFixed(2)}`);
			$('#pago-vehiculo-total').text(`$${total.toFixed(2)}`);

			renderPayPalButtons();
		}

		// NUEVO: toggle VER MÁS DETALLES de transferencia
		$('#btn-transfer-detalles').on('click', function() {
			const extra = $('#transfer-detalles-extra');
			const isHidden = extra.hasClass('hidden');
			extra.toggleClass('hidden');
			$(this).text(isHidden ? 'OCULTAR DETALLES' : 'VER MÁS DETALLES');
		});

		$('.pagar-vehiculo-btn').on('click', function() {
			const vehiculoId = $(this).data('vehiculo-id');
			const vin = $(this).data('vin');
			const montoPendiente = parseFloat($(this).data('monto-pendiente'));

			$('#pago-vehiculo-id').val(vehiculoId);
			$('#pago-vehiculo-vin').text(vin);
			$('#pago-vehiculo-pendiente').text(`$${montoPendiente.toFixed(2)}`);

			// Reiniciar el formulario y volver a asignar valores clave
			$('#form-pago-vehiculo')[0].reset();
			$('#pago-vehiculo-id').val(vehiculoId);
			
			// Llenar el campo "Monto a pagar" automáticamente con el monto pendiente
			$('#pago-vehiculo-monto')
				.val(montoPendiente.toFixed(2))
				.attr('max', montoPendiente.toFixed(2));

			$('input[name="metodo_pago"][value="Transferencia Bancaria"]').prop('checked', true);

			updateVehiclePaymentSummary();
			$('input[name="metodo_pago"]').trigger('change');
			document.getElementById('pagar_vehiculo_modal').showModal();
		});

		$('#pago-vehiculo-monto').on('input', updateVehiclePaymentSummary);
		$('input[name="metodo_pago"]').on('change', function() {
			const method = $(this).val();
			$('#pago-vehiculo-paypal-container').toggle(method === 'PayPal');
			$('#pago-vehiculo-transferencia-info').toggle(method === 'Transferencia Bancaria');
			$('#pago-vehiculo-transferencia-submit').toggle(method === 'Transferencia Bancaria');
			updateVehiclePaymentSummary();
		});

		function renderPayPalButtons() {
			const container = '#pago-vehiculo-paypal-container';
			const method = $('input[name="metodo_pago"]:checked').val();

			if (paypalButtonsVehiculo) { paypalButtonsVehiculo.close(); }
			$(container).empty();

			if(method !== 'PayPal' || ($('#pago-vehiculo-monto').val() > 700)) return;
			if (typeof paypal === 'undefined') {
				$(container).html('<div class="alert alert-error">No se pudo cargar PayPal. Verifique su conexión o intente más tarde.</div>');
				console.error('PayPal SDK no cargado');
				return;
			}

			paypalButtonsVehiculo = paypal.Buttons({
				createOrder: function(data, actions) {
					const subtotal = parseFloat($('#pago-vehiculo-monto').val()) || 0;
					if (subtotal <= 0 || subtotal > 700) {
						showAlert('Monto para PayPal debe ser entre $0.01 y $700.00.');
						return Promise.reject();
					}
					const fee = (subtotal * feePct) + feeFixed;
					const total = subtotal + fee;
					return actions.order.create({
						purchase_units: [{
							description: `Abono al vehículo VIN: ${$('#pago-vehiculo-vin').text()}`,
							amount: { value: total.toFixed(2) }
						}]
					});
				},
				onApprove: function(data, actions) {
					$('#pago-vehiculo-paypal-container, #pago-vehiculo-transferencia-submit').hide();
					$('#pago-vehiculo-spinner').show();
					return actions.order.capture().then(function(details) {
						const subtotal = parseFloat($('#pago-vehiculo-monto').val()) || 0;
						const total = parseFloat(details.purchase_units[0].amount.value);
						const paymentData = {
							action: 'campo_procesar_pago_vehiculo',
							nonce: campo_rental_vars.nonce,
							vehiculo_id: $('#pago-vehiculo-id').val(),
							monto_subtotal: subtotal,
							monto_total: total,
							metodo_pago: 'PayPal',
							paypal_order_id: details.id
						};
						$.post(campo_rental_vars.ajax_url, paymentData)
							.done(response => {
								document.getElementById('pagar_vehiculo_modal').close();
								if (response.success) { showAlert(response.data.message, 'success', true); }
								else { showAlert(response.data.message); }
							})
							.fail(() => showAlert('Error de comunicación con el servidor.'))
							.always(() => $('#pago-vehiculo-spinner').hide());
					});
				}
			});
			paypalButtonsVehiculo.render(container);
		}

		$('#form-pago-vehiculo').on('submit', function(e) {
			e.preventDefault();
			if ($('input[name="metodo_pago"]:checked').val() !== 'Transferencia Bancaria') return;

			const monto = parseFloat($('#pago-vehiculo-monto').val());
			if (isNaN(monto) || monto <= 0) { showAlert('Por favor, ingrese un monto válido.'); return; }
			if ($('#comprobante_pago')[0].files.length === 0) { showAlert('Por favor, suba el comprobante de pago.'); return; }

			const formData = new FormData(this);
			formData.append('action', 'campo_procesar_pago_vehiculo');
			formData.append('nonce', campo_rental_vars.nonce);
			formData.append('monto_subtotal', monto);
			formData.append('monto_total', monto);

			$('#pago-vehiculo-transferencia-info, #pago-vehiculo-transferencia-submit').hide();
			$('#pago-vehiculo-spinner').show();

			$.ajax({
				url: campo_rental_vars.ajax_url,
				type: 'POST',
				data: formData, processData: false, contentType: false,
				success: function(response) {
					document.getElementById('pagar_vehiculo_modal').close();
					if (response.success) { showAlert(response.data.message, 'success', true); }
					else { showAlert(response.data.message); }
				},
				error: function() { showAlert('Error de comunicación al enviar el formulario.'); },
				complete: function() {
					$('#pago-vehiculo-spinner').hide();
					$('#pago-vehiculo-transferencia-info, #pago-vehiculo-transferencia-submit').show();
				}
			});
		});

		// ===== Alquiler - Wizard (comisión 5.4% + $0.30) =====
		(function setupRentalWizard() {
			let currentStep = 1;
			const wizard = $('#rental-wizard'); if (wizard.length === 0) return;
			const nextBtn = wizard.find('#rental-wizard-next-btn'), backBtn = wizard.find('#rental-wizard-back-btn');

			const update = () => {
				wizard.find('.steps .step').removeClass('step-primary');
				for (let i = 1; i <= currentStep; i++) wizard.find('#rental-step-li-' + i).addClass('step-primary');
				wizard.find('.step-panel').addClass('hidden');
				wizard.find('#rental-step-panel-' + currentStep).removeClass('hidden');
				backBtn.toggle(currentStep > 1);
				nextBtn.toggle(currentStep < 4);
				validate();
			};

			const validate = () => {
				let isValid = false;
				if (currentStep === 1) isValid = $('#terms-check').is(':checked');
				else if (currentStep === 2) isValid = $('#rules-check').is(':checked');
				else if (currentStep === 3) isValid = true;
				nextBtn.prop('disabled', !isValid);
			};

			wizard.find('input[type="checkbox"]').on('change', validate);

			nextBtn.on('click', () => {
				if (currentStep < 4) {
					currentStep++; update();
					if (currentStep === 4) initializeRentalPayPal();
				}
			});

			backBtn.on('click', () => { if (currentStep > 1) { currentStep--; update(); } });
			$('#rental_payment_modal').on('close', () => { currentStep = 1; wizard.find('input[type="checkbox"]').prop('checked', false); update(); });

			let paypalButtonsRendered = false;

			function initializeRentalPayPal() {
				if (paypalButtonsRendered) return;
				paypalButtonsRendered = true;

				const subtotal = parseFloat(campo_rental_vars.rental_price);
				const fee = (subtotal * feePct) + feeFixed;
				const total = subtotal + fee;

				$('#rental-fee-pct').text((feePct*100).toFixed(1));
				$('#rental-fee-fx').text(feeFixed.toFixed(2));
				$('#rental-subtotal').text(`$${subtotal.toFixed(2)}`);
				$('#rental-fee-amount').text(`$${fee.toFixed(2)}`);
				$('#rental-total-amount').text(`$${total.toFixed(2)}`);
				$('#rental-paypal-button-container').empty();

				if (typeof paypal === 'undefined') {
					$('#rental-paypal-button-container').html('<div class="alert alert-error">No se pudo cargar PayPal. Verifique su configuración.</div>');
					console.error('PayPal SDK no cargado');
					return;
				}

				paypal.Buttons({
					createOrder: (data, actions) =>
						actions.order.create({
							purchase_units: [{ description: 'Alquiler de Cuenta de Subasta', amount: { value: total.toFixed(2) }}]
						}),
					onApprove: (data, actions) => {
						$('#rental-paypal-button-container').hide();
						$('#rental-processing-spinner').show();
						return actions.order.capture().then(details => {
							$.ajax({
								url: campo_rental_vars.ajax_url,
								type: "POST",
								data: {
									action: 'campo_process_rental_payment',
									nonce: campo_rental_vars.nonce,
									paypal_order_id: details.id,
									paypal_amount: details.purchase_units[0].amount.value
								},
								success: function(response) {
									document.getElementById('rental_payment_modal').close();
									if (response.success) { showAlert(response.data.message, 'success', true); }
									else { showAlert(response.data.message); }
								},
								error: function(){
									document.getElementById('rental_payment_modal').close();
									showAlert('Ocurrió un error con el servidor.');
								}
							});
						});
					}
				}).render('#rental-paypal-button-container');
			}

			update();
		})();

		// ===== Pre-Alerta - Wizard (comisión 5.4% + $0.30) =====
		(function setupPreAlertWizard() {
			let currentStep = 1; const totalSteps = 5;
			let uploadFailures = 0; // Contador de intentos fallidos
			
			const wizard = $('#pre-alert-wizard'); if(wizard.length === 0) return;

			const nextBtn = wizard.find('#pa-wizard-next-btn'), backBtn = wizard.find('#pa-wizard-back-btn');
			let paypalPreAlertaRendered = false;

			const radiosTaller = wizard.find('input[name="trabajara_taller"]');
			const tallerSection = $('#pa-taller-section');
			const direccionManualSection = $('#pa-direccion-manual-section');
			const tallerSelect = $('#pa_taller_id');
			const shippingAddressInput = $('#pa_shipping_address');

			const radiosEstadoEspecial = wizard.find('input[name="estado_especial"]');
			const radiosFinalidad = wizard.find('input[name="finalidad_vehiculo"]');
			const radioExportar = wizard.find('input[name="finalidad_vehiculo"][value="exportar"]');
			const radioUsoUSA = wizard.find('input[name="finalidad_vehiculo"][value="uso_usa"]');
			const btnSwitchManual = $('#btn-switch-manual');

			function enforceFinalidadByTaller(selection){
				if (selection === 'si') {
					radioExportar.prop('checked', true);
					radiosFinalidad.prop('disabled', true); // bloquear edición
				} else {
					radiosFinalidad.prop('disabled', false);
					radiosFinalidad.prop('checked', false);
				}
				validateStep();
			}

			radiosTaller.on('change', function() {
				const selection = $(this).val();
				tallerSection.toggle(selection === 'si');
				direccionManualSection.toggle(selection === 'no');
				tallerSelect.prop('required', selection === 'si');
				shippingAddressInput.prop('required', selection === 'no');
				if (selection === 'no') { setTimeout(() => { initializeAddressAutocomplete(); }, 100); }
				enforceFinalidadByTaller(selection);
			});

			// Forzar cambio a modo manual cuando el usuario lo pide tras 3 fallos
			btnSwitchManual.on('click', function() {
				$('input[name="info_method"][value="manual"]').prop('checked', true).trigger('change');
			});

			function computePrealertTotals(){
				const base = parseFloat(campo_rental_vars.prealerta_fee) || 0;
				const extraEstado = ($('input[name="estado_especial"]:checked').val()==='si') ? 45 : 0;
				const extraUso	= ($('input[name="finalidad_vehiculo"]:checked').val()==='uso_usa') ? 50 : 0;
				const subtotal	= base + extraEstado + extraUso;
				const fee		= (subtotal * feePct) + feeFixed; // 5.4% + $0.30
				const total		= subtotal + fee;
				return {base, extraEstado, extraUso, subtotal, fee, total};
			}

			function initializePreAlertaPayPal() {
				const {base, extraEstado, extraUso, fee, total} = computePrealertTotals();

				$('#pre-alerta-subtotal').text(`$${base.toFixed(2)}`);
				if (extraEstado>0){ $('#pre-alerta-extra-estado').text(`$${extraEstado.toFixed(2)}`); $('#pre-alerta-extra-estado-row').removeClass('hidden'); } else { $('#pre-alerta-extra-estado-row').addClass('hidden'); }
				if (extraUso>0){ $('#pre-alerta-extra-uso').text(`$${extraUso.toFixed(2)}`); $('#pre-alerta-extra-uso-row').removeClass('hidden'); } else { $('#pre-alerta-extra-uso-row').addClass('hidden'); }
				$('#pre-alerta-fee-amount').text(`$${fee.toFixed(2)}`);
				$('#pre-alerta-total-amount').text(`$${total.toFixed(2)}`);

				$('#pre-alerta-paypal-button-container').empty();
				if (typeof paypal === 'undefined') {
					$('#pre-alerta-paypal-button-container').html('<div class="alert alert-error">No se pudo cargar PayPal. Verifique su configuración.</div>');
					console.error('PayPal SDK no cargado');
					return;
				}

				paypalPreAlertaRendered = true;
				paypal.Buttons({
					createOrder: (data, actions) => actions.order.create({
						purchase_units: [{ description: 'Tarifa de Servicio Pre-Alerta de Vehículo', amount: { value: total.toFixed(2), currency_code: 'USD' } }]
					}),
					onApprove: (data, actions) => {
						$('#pre-alerta-paypal-button-container').hide();
						$('#pre-alerta-processing-spinner').show();
						backBtn.prop('disabled', true);
						return actions.order.capture().then(details => {
							let preAlertaData = {
								action: 'campo_procesar_pre_alerta',
								nonce: campo_rental_vars.nonce,
								trabajara_taller: $('input[name="trabajara_taller"]:checked').val(),
								taller_id: $('#pa_taller_id').val(),
								shipping_address: $('#pa_shipping_address').val(),
								info_method: $('input[name="info_method"]:checked').val(),
								estado_especial: $('input[name="estado_especial"]:checked').val(),
								finalidad_vehiculo: $('input[name="finalidad_vehiculo"]:checked').val(),
								paypal_order_id: details.id,
								paypal_amount: (details.purchase_units[0].payments && details.purchase_units[0].payments.captures && details.purchase_units[0].payments.captures[0] ? details.purchase_units[0].payments.captures[0].amount.value : total.toFixed(2))
							};

							const infoMethod = preAlertaData.info_method;
							if (infoMethod === 'upload') {
								const visionResults = $('#pa-confirmation-details').data('vision-results') || {};
								$.extend(preAlertaData, {
									vin: visionResults.vin, amount: visionResults.amount,
									source: visionResults.source, vehicle_name: visionResults.vehicle_name
								});
							} else {
								$.extend(preAlertaData, {
									vin: $("#pa_vin").val(), amount: $("#pa_total_amount").val(),
									source: $('input[name="auction_source"]:checked').val(),
									vehicle_name: $('#pa-vehicle-info').data('vehicle-name') || "No verificado"
								});
							}

							$.ajax({
								url: campo_rental_vars.ajax_url,
								type: 'POST', data: preAlertaData,
								success: function(response) {
									document.getElementById('pre_alert_modal').close();
									if (response.success) { showAlert(response.data.message, 'success', true); }
									else { showAlert(response.data.message); }
								},
								error: function() { showAlert('Error de comunicación al procesar la pre-alerta.'); },
								complete: function() { $('#pre-alerta-processing-spinner').hide(); }
							});
						});
					}
				}).render('#pre-alerta-paypal-button-container');
			}

			const updateUI = () => {
				wizard.find('.steps .step').removeClass('step-primary');
				for (let i = 1; i <= currentStep; i++) wizard.find('#pa-step-li-' + i).addClass('step-primary');
				wizard.find('.pa-step-panel').addClass('hidden');
				wizard.find('#pa-step-panel-' + currentStep).removeClass('hidden');
				backBtn.toggle(currentStep > 1 && currentStep < totalSteps);
				nextBtn.toggle(currentStep < totalSteps);
				validateStep();
				if (currentStep === totalSteps) { initializePreAlertaPayPal(); } else { paypalPreAlertaRendered = false; }
			};

			const validateStep = () => {
				let isValid = false;
				switch (currentStep){
					case 1: isValid = true; break;
					case 2:
						const tallerChoice = wizard.find('input[name="trabajara_taller"]:checked').val();
						let tallerValid = false;
						if (tallerChoice === 'si') { tallerValid = $('#pa_taller_id').val() !== ''; }
						else if (tallerChoice === 'no') { tallerValid = ($('#pa_shipping_address').val().trim().length > 5); }
						
						// info_method siempre está checked por defecto en HTML oculto o visible
						const infoMethodChosen = wizard.find('input[name="info_method"]:checked').length > 0;
						const estadoChosen = wizard.find('input[name="estado_especial"]:checked').length > 0;
						const finChosen = wizard.find('input[name="finalidad_vehiculo"]:checked').length > 0;
						isValid = tallerValid && infoMethodChosen && estadoChosen && finChosen;
						break;
					case 3:
						const method = wizard.find('input[name="info_method"]:checked').val();
						if (method === 'upload') {
							isValid = $('#pa-confirmation-details').data('vision-results') ? true : ($('#pa_invoice_file').get(0).files.length > 0);
						} else if (method === 'manual') {
							const vin = $('#pa_vin').val();
							const amount = parseFloat($('#pa_total_amount').val());
							// VALIDACIÓN MONTO MANUAL: debe ser > 0.00
							isValid = vin && vin.trim().length === 17 && !isNaN(amount) && amount > 0.00 && wizard.find('input[name="auction_source"]:checked').length > 0;
						}
						break;
					case 4: isValid = true; break;
				}
				nextBtn.prop('disabled', !isValid);
			};

			const showConditionalSections = () => {
				const method = $('input[name="info_method"]:checked').val();
				$('#pa-upload-section').toggle(method === 'upload');
				$('#pa-manual-section').toggle(method === 'manual');
				$('#pa-confirmation-details').removeData('vision-results');
				wizard.find('#pa_invoice_file').val('');
				validateStep();
			};

			const updateConfirmationDetails = () => {
				if (currentStep !== 4) return;
				let addressHTML = '';
				const tallerChoice = $('input[name="trabajara_taller"]:checked').val();
				if (tallerChoice === 'si') {
					addressHTML = `<p><strong>Título enviado a Taller:</strong> ${$('#pa_taller_id option:selected').text()}</p>`;
				} else {
					addressHTML = `<p><strong>Dirección de Envío:</strong> ${$('#pa_shipping_address').val()}</p>`;
				}
				const estadoTxt = $('input[name="estado_especial"]:checked').val()==='si' ? 'Sí (FL/NY/NJ/NC/OH/OR)' : 'No';
				const finTxt = $('input[name="finalidad_vehiculo"]:checked').val()==='uso_usa' ? 'Uso en EE.UU.' : 'Exportarlo';

				let detailsHTML = `<p><strong>Estado especial:</strong> ${estadoTxt}</p><p><strong>Finalidad del vehículo:</strong> ${finTxt}</p><div class="divider my-1"></div>`;
				if ($('input[name="info_method"]:checked').val() === 'upload') {
					const visionResults = $('#pa-confirmation-details').data('vision-results');
					if(visionResults) {
						detailsHTML += `<p><strong>Vehículo:</strong> ${visionResults.vehicle_name}</p>
											<p><strong>VIN:</strong> ${visionResults.vin}</p>
											<p><strong>Monto Factura:</strong> $${visionResults.amount}</p>
											<p><strong>Subasta:</strong> ${visionResults.source}</p>`;
					} else {
						detailsHTML += `<p class="text-error">Los datos no pudieron ser procesados.</p>`;
					}
				} else {
					const vehicle = $('#pa-vehicle-info').data('vehicle-name') || "No verificado";
					detailsHTML += `<p><strong>Vehículo:</strong> ${vehicle}</p>
										<p><strong>VIN:</strong> ${$("#pa_vin").val()}</p>
										<p><strong>Monto Factura:</strong> $${$("#pa_total_amount").val()}</p>
										<p><strong>Subasta:</strong> ${$('input[name="auction_source"]:checked').val() || "No seleccionada"}</p>`;
				}
				$('#pa-confirmation-details').html(addressHTML + detailsHTML);
			};

			nextBtn.on('click', function() {
				if ($(this).is(':disabled')) return;
				if (currentStep === 3 && $('input[name="info_method"]:checked').val() === 'upload' && !$('#pa-confirmation-details').data('vision-results')) {
					const fileInput = $('#pa_invoice_file')[0];
					if (!fileInput || fileInput.files.length === 0) { showAlert('Por favor, sube la factura.'); return; }
					
					const formData = new FormData();
					formData.append('invoice_file', fileInput.files[0]);
					formData.append('action', 'campo_analyze_invoice');
					formData.append('nonce', campo_rental_vars.nonce);
					
					$('#pa-form-content').addClass('hidden'); $('#pa-ajax-loader').removeClass('hidden');
					nextBtn.prop('disabled', true); backBtn.prop('disabled', true);
					
					$.ajax({
						url: campo_rental_vars.ajax_url,
						type: 'POST', data: formData, processData: false, contentType: false,
						success: (response) => {
							if (response.success) {
								$('#pa-confirmation-details').data('vision-results', response.data);
								currentStep++; updateConfirmationDetails(); updateUI();
							} else {	
								showAlert(response.data.message);
								uploadFailures++;
								if(uploadFailures >= 3){
									$('#pa-manual-fallback-container').removeClass('hidden');
								}
							}
						},
						error: () => { showAlert('Error de comunicación al analizar la factura.'); },
						complete: () => {
							$('#pa-form-content').removeClass('hidden'); $('#pa-ajax-loader').addClass('hidden');
							backBtn.prop('disabled', false); validateStep();
						}
					});
				} else if (currentStep < totalSteps) {
					currentStep++; updateConfirmationDetails(); updateUI();
				}
			});

			backBtn.on('click', () => {
				if (currentStep > 1) {
					currentStep--;
					$('#pa-confirmation-details').removeData('vision-results');
					wizard.find('#pa_invoice_file').val('');
					updateUI();
				}
			});

			wizard.find('input, select, #pa_invoice_file').on('input change', function(){
				validateStep();
				if (currentStep === totalSteps) { initializePreAlertaPayPal(); }
			});
			wizard.find('input[name="info_method"]').on('change', showConditionalSections);

			$("#pa_vin").on("input", function() {
				const vin = $(this).val(), infoDiv = $("#pa-vehicle-info");
				infoDiv.html("").removeData("vehicle-name");
				validateStep();
				if (vin.length === 17) {
					infoDiv.html("<span class='text-info'>Verificando VIN...</span>");
					$.ajax({
						url: campo_rental_vars.ajax_url,
						type: 'POST',
						data: { action: 'campo_validate_vin', nonce: campo_rental_vars.nonce, vin: vin },
						success: function(response) {
							if (response.success) {
								const name = `${response.data.year} ${response.data.make} ${response.data.model}`;
								infoDiv.html(`<span class='text-success'>Vehículo: ${name}</span>`).data('vehicle-name', name);
							} else {
								infoDiv.html(`<span class='text-error'>${response.data.message}</span>`);
							}
							validateStep();
						},
						error: function() { infoDiv.html("<span class='text-error'>Error de conexión.</span>"); validateStep(); }
					});
				}
			});

			document.getElementById('pre_alert_modal').addEventListener('close', () => {
				document.getElementById('campo-modal-overlay').style.display = 'none';
				currentStep = 1;
				uploadFailures = 0; // Resetear contador
				$('#pa-manual-fallback-container').addClass('hidden');
				// Resetear a Upload por defecto
				$('input[name="info_method"][value="upload"]').prop('checked', true);
				
				wizard.find("input[type='text'], input[type='file'], select").val('');
				wizard.find("input[type='radio'][name='trabajara_taller']").prop('checked', false);
				wizard.find("input[type='radio'][name='estado_especial']").prop('checked', false);
				wizard.find("input[type='radio'][name='finalidad_vehiculo']").prop('checked', false).prop('disabled', false);
				
				$('#pa-confirmation-details').removeData('vision-results');
				showConditionalSections(); updateUI();
			});

			updateUI();
		})();
	});
	</script>
	<?php
	did_action('campo_rental_modals_footer_printed');
}
