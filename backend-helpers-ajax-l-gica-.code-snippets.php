<?php

/**
 * BACKEND (helpers + AJAX + lógica)
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
		wp_send_json_error(['message' => 'Error al procesar el documento: ' . $e->getMessage() ]);
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
			$data_to_insert['nota_admin']		= 'Pendiente de verificación.';
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
				'make	'=> ucwords(strtolower($details['Make'])),
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
