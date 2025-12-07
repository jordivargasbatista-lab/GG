<?php

/**
 * FRONTEND (shortcode + HTML + JS)
 */
// --- 3. DEFINICIÓN DEL SHORTCODE (UI PREMIUM v2.0) ---
add_shortcode('campo_cuentas_alquiler_ui', 'campo_cuentas_alquiler_ui_shortcode');
function campo_cuentas_alquiler_ui_shortcode() {

	// === PayPal SDK & Enqueue (LÓGICA INTACTA) ===
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

	// --- ESTILOS CSS INYECTADOS PARA DETALLES FINOS ---
	?>
	<style>
		/* Fuente moderna si no la carga el tema */
		@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
		
		.campo-dashboard-wrapper { font-family: 'Inter', sans-serif; background-color: #F3F4F6; }
		.glass-panel { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.5); }
		.hero-gradient { background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); }
		.btn-modern { transition: all 0.2s ease; transform: translateY(0); }
		.btn-modern:active { transform: translateY(2px); }
		.card-hover:hover { transform: translateY(-4px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); }
		.text-shadow-sm { text-shadow: 0 1px 2px rgba(0,0,0,0.1); }
		
		/* Scrollbar fina */
		.custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
		.custom-scroll::-webkit-scrollbar-track { background: #f1f1f1; }
		.custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
		.custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

		/* Animaciones suaves */
		@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
		.animate-enter { animation: fadeIn 0.4s ease-out forwards; }
	</style>
	<?php

	// --- ACCESO RESTRINGIDO (LOGIN) ---
	if (!is_user_logged_in()) {
		echo '
		<div class="campo-dashboard-wrapper min-h-[400px] flex items-center justify-center p-4 rounded-xl">
			<div class="bg-white max-w-md w-full rounded-2xl shadow-xl overflow-hidden text-center p-8 animate-enter">
				<div class="bg-indigo-50 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6 text-indigo-600">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-10 h-10"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
				</div>
				<h3 class="text-2xl font-bold text-gray-900 mb-2">Acceso Exclusivo</h3>
				<p class="text-gray-500 mb-8">Inicia sesión para acceder a tu panel de subastas y gestionar tus vehículos.</p>
				<a href="' . esc_url(wp_login_url(get_permalink())) . '" class="block w-full py-3 px-4 bg-gray-900 text-white rounded-xl font-semibold hover:bg-gray-800 transition-colors shadow-lg shadow-gray-900/20">Iniciar Sesión</a>
			</div>
		</div>';
		return ob_get_clean();
	}

	global $wpdb;
	$user_id = get_current_user_id();
	$user_nip = sanitize_text_field(get_user_meta($user_id, 'nip', true));

	// --- LÓGICA DE DATOS (NO TOCAR) ---
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
	$items_per_page = 6; // Aumentado ligeramente para mejor grid
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
	$total_vehiculos = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabla_vehiculos} WHERE user_id = %d AND status_vehiculo != 'entregado'{$date_where_clause}", $user_id));
	$total_pages = $total_vehiculos > 0 ? ceil($total_vehiculos / $items_per_page) : 1;
	$vehiculos_gestion = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tabla_vehiculos} WHERE user_id = %d AND status_vehiculo != 'entregado'{$date_where_clause} ORDER BY fecha_prealerta DESC LIMIT %d, %d", $user_id, $offset, $items_per_page));

	foreach ($vehiculos_gestion as $vehiculo) {
		$pagos_completados = $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM {$tabla_transacciones} WHERE item_id = %d AND item_type = 'vehiculo' AND status = 'completado'", $vehiculo->id));
		$total_deuda = $vehiculo->monto_factura + $vehiculo->late_fee + $vehiculo->storage_fee;
		$vehiculo->monto_pendiente = $total_deuda - floatval($pagos_completados);
	}
	?>

	<!-- === MAIN CONTAINER === -->
	<div class="campo-dashboard-wrapper w-full text-gray-800 p-2 md:p-6 rounded-3xl" data-theme="light">
		
		<!-- 1. HEADER MODERNO -->
		<header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4 animate-enter">
			<div>
				<h1 class="text-3xl font-bold text-gray-900 tracking-tight">Panel de Subastas</h1>
				<p class="text-sm text-gray-500 mt-1 flex items-center gap-2">
					<span class="inline-block w-2 h-2 rounded-full bg-green-500"></span> Sistema Operativo
				</p>
			</div>
			
			<?php if (!empty($active_rentals)): ?>
				<div class="flex items-center gap-3 bg-white px-4 py-2 rounded-full shadow-sm border border-gray-100">
					<div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-xs">
						<?php 
							$u = wp_get_current_user();
							echo strtoupper(substr($u->display_name, 0, 2)); 
						?>
					</div>
					<div class="text-xs">
						<p class="font-bold text-gray-900">Sesión Activa</p>
						<p class="text-gray-400">Usuario Verificado</p>
					</div>
				</div>
			<?php endif; ?>
		</header>

		<!-- 2. HERO SECTION: CUENTA ACTIVA (DARK CARD) -->
		<section class="mb-12 animate-enter" style="animation-delay: 0.1s;">
			<?php if (empty($active_rentals)): ?>
				<!-- ESTADO EMPTY: CTA -->
				<div class="bg-white rounded-3xl p-8 md:p-12 text-center shadow-xl border border-gray-100 relative overflow-hidden group">
					<div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-white opacity-50"></div>
					<div class="relative z-10 max-w-lg mx-auto">
						<div class="w-20 h-20 bg-white rounded-2xl shadow-lg flex items-center justify-center mx-auto mb-6 text-indigo-600 transform group-hover:scale-110 transition-transform duration-300">
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-10 h-10"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z" /></svg>
						</div>
						<h2 class="text-3xl font-bold text-gray-900 mb-4">Empieza a Subastar</h2>
						<p class="text-gray-500 mb-8 text-lg">Accede a las mejores subastas de USA (Copart & IAAI) alquilando una cuenta verificada al instante.</p>
						
						<?php if ($can_rent): ?>
							<button onclick="rental_payment_modal.showModal()" class="btn-modern inline-flex items-center justify-center px-8 py-4 bg-gray-900 text-white text-lg font-bold rounded-xl shadow-xl shadow-indigo-500/20 hover:bg-gray-800">
								Alquilar Cuenta ($<?php echo esc_html(CAMPO_RENTAL_PRICE); ?>)
								<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
							</button>
						<?php else: ?>
							<div class="inline-block px-6 py-3 bg-orange-100 text-orange-800 rounded-lg font-medium">
								⚠️ No hay cuentas disponibles por el momento.
							</div>
						<?php endif; ?>
					</div>
				</div>

			<?php else: foreach ($active_rentals as $rental): ?>
				<!-- ESTADO ACTIVO: DARK CARD DASHBOARD -->
				<div class="hero-gradient rounded-3xl p-6 md:p-10 shadow-2xl text-white relative overflow-hidden">
					<!-- Círculos decorativos de fondo -->
					<div class="absolute top-0 right-0 -mr-20 -mt-20 w-80 h-80 bg-blue-500 rounded-full mix-blend-overlay filter blur-3xl opacity-20"></div>
					<div class="absolute bottom-0 left-0 -ml-20 -mb-20 w-80 h-80 bg-indigo-500 rounded-full mix-blend-overlay filter blur-3xl opacity-20"></div>

					<div class="relative z-10 flex flex-col lg:flex-row justify-between gap-10">
						<!-- Info Principal -->
						<div class="flex-1">
							<div class="flex items-center gap-3 mb-6">
								<span class="px-3 py-1 bg-green-500/20 border border-green-500/30 text-green-300 rounded-full text-xs font-bold tracking-wide uppercase flex items-center gap-2">
									<span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span> Activa
								</span>
								<span class="text-gray-400 text-xs">ID Cuenta: #<?php echo $rental->id; ?></span>
							</div>

							<div class="grid grid-cols-1 sm:grid-cols-2 gap-8">
								<div>
									<p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-1">Tu NIP de Subasta</p>
									<div class="flex items-center gap-3">
										<span class="text-4xl font-bold text-white tracking-tight"><?php echo esc_html($rental->nip_asignado); ?></span>
										<button class="text-gray-400 hover:text-white transition-colors" onclick="navigator.clipboard.writeText('<?php echo esc_attr($rental->nip_asignado); ?>')" title="Copiar NIP">
											<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
										</button>
									</div>
								</div>
								
								<div>
									<p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-1">Poder de Compra</p>
									<?php if (isset($rental->limite) && is_numeric($rental->limite)): ?>
										<?php if ($rental->limite <= 300): ?>
											<div class="flex items-center gap-2 text-yellow-400 font-bold text-xl">
												<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
												<span>Verificando...</span>
											</div>
											<p class="text-[10px] text-gray-400 mt-1">Activación en 1-9 hrs hábiles</p>
										<?php else: $limite_calculado = $rental->limite * 10; ?>
											<span class="text-3xl font-bold text-green-400">$<?php echo esc_html(number_format($limite_calculado, 0, '.', ',')); ?></span>
										<?php endif; ?>
									<?php else: ?>
										<span class="text-gray-500">N/A</span>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<!-- Panel de Acciones (Derecha) -->
						<div class="w-full lg:w-72 flex flex-col gap-3">
							<button class="btn-modern w-full py-3 px-4 bg-white text-gray-900 rounded-xl font-bold hover:bg-gray-100 flex items-center justify-center gap-2 shadow-lg" onclick="abrirPreAlertaConAutocompletado()">
								<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
								Hacer Pre-Alerta
							</button>
							
							<div class="grid grid-cols-2 gap-3">
								<button class="btn-modern py-3 px-4 bg-gray-800 text-white rounded-xl text-sm font-semibold hover:bg-gray-700 border border-gray-700" onclick="fetchAndShowCredentials()">
									Credenciales
								</button>
								<button class="btn-modern py-3 px-4 bg-transparent text-gray-300 rounded-xl text-sm font-semibold hover:text-white border border-gray-700 hover:border-gray-500" onclick="rules_modal.showModal()">
									Reglas
								</button>
							</div>
							
							<div class="mt-2 text-center">
								<p class="text-[10px] text-gray-400">Renovación: <?php echo date_i18n('d M, Y', strtotime($rental->fecha_alquiler)); ?></p>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; endif; ?>
		</section>

		<!-- 3. LISTADO DE VEHÍCULOS (GRID CARDS) -->
		<section class="animate-enter" style="animation-delay: 0.2s;">
			<div class="flex flex-col sm:flex-row justify-between items-end sm:items-center mb-6 gap-4 border-b border-gray-200 pb-4">
				<h3 class="text-xl font-bold text-gray-900">Vehículos en Gestión</h3>
				
				<!-- Filtro Dropdown Estilizado -->
				<div class="dropdown dropdown-end">
					<div tabindex="0" role="button" class="btn btn-sm h-10 px-4 bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 rounded-lg shadow-sm flex items-center gap-2">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>
						<span class="font-medium">
							<?php $filters = ['30' => 'Últimos 30 días', '60' => 'Últimos 60 días', '90' => 'Últimos 90 días', 'all' => 'Todo el historial']; echo esc_html($filters[$date_filter]); ?>
						</span>
						<svg class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
					</div>
					<ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow-xl bg-white rounded-xl w-52 mt-2 border border-gray-100">
						<?php
						$base_url_filters = remove_query_arg('vehicle_page');
						foreach ($filters as $key => $label) {
							$active = ($date_filter === $key) ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-gray-600';
							echo '<li><a href="' . esc_url(add_query_arg('date_filter', $key, $base_url_filters)) . '" class="rounded-lg '.$active.'">' . esc_html($label) . '</a></li>';
						}
						?>
					</ul>
				</div>
			</div>

			<!-- GRID CONTENT -->
			<?php if (empty($vehiculos_gestion)): ?>
				<div class="text-center py-16 bg-white rounded-3xl border border-dashed border-gray-200">
					<div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4 text-gray-400">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
					</div>
					<h3 class="text-lg font-bold text-gray-900">Sin actividad reciente</h3>
					<p class="text-gray-500 text-sm mt-1">No hay vehículos registrados en este periodo.</p>
				</div>
			<?php else: ?>
				<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
					<?php foreach($vehiculos_gestion as $v): ?>
						<div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 card-hover flex flex-col justify-between h-full relative">
							<!-- Indicador de estado (borde superior de color) -->
							<?php $statusColor = ($v->monto_pendiente > 0) ? 'bg-red-500' : 'bg-green-500'; ?>
							<div class="absolute top-0 left-6 right-6 h-1 <?php echo $statusColor; ?> rounded-b-lg opacity-80"></div>

							<div>
								<!-- Header Card -->
								<div class="flex justify-between items-start mb-4 mt-2">
									<div class="w-10 h-10 rounded-lg bg-gray-50 text-gray-700 font-bold flex items-center justify-center text-lg border border-gray-100">
										<?php echo strtoupper(substr($v->vehiculo_nombre, 0, 1)); ?>
									</div>
									<?php
										$pagos_pendientes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabla_transacciones} WHERE item_id = %d AND item_type = 'vehiculo' AND status = 'pendiente'", $v->id));
										if ($v->monto_pendiente <= 0.01) { 
											echo '<span class="px-2 py-1 bg-green-50 text-green-700 border border-green-100 rounded-md text-[10px] font-bold uppercase tracking-wide">Pagado</span>';
										} elseif ($pagos_pendientes > 0) { 
											echo '<span class="px-2 py-1 bg-yellow-50 text-yellow-700 border border-yellow-100 rounded-md text-[10px] font-bold uppercase tracking-wide flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-yellow-500 animate-pulse"></span> Verificando</span>';
										} else { 
											echo '<span class="px-2 py-1 bg-red-50 text-red-700 border border-red-100 rounded-md text-[10px] font-bold uppercase tracking-wide">Pendiente</span>';
										}
									?>
								</div>

								<h4 class="text-lg font-bold text-gray-900 leading-tight mb-1 truncate" title="<?php echo esc_attr($v->vehiculo_nombre); ?>">
									<?php echo esc_html($v->vehiculo_nombre); ?>
								</h4>
								<div class="flex items-center gap-2 mb-4">
									<code class="text-xs text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded font-mono select-all"><?php echo esc_html($v->vin); ?></code>
								</div>

								<!-- Stats Grid -->
								<div class="grid grid-cols-2 gap-y-2 text-sm text-gray-600 mb-6 bg-gray-50 p-3 rounded-xl border border-gray-100">
									<div class="text-xs text-gray-400">Factura</div>
									<div class="text-right font-medium">$<?php echo number_format($v->monto_factura, 2); ?></div>
									
									<div class="text-xs text-gray-400">Fees</div>
									<div class="text-right font-medium text-red-400">
										<?php $fees = $v->late_fee + $v->storage_fee; echo $fees > 0 ? '+$'.number_format($fees,0) : '$0'; ?>
									</div>
									
									<div class="col-span-2 border-t border-gray-200 my-1"></div>
									
									<div class="font-bold text-gray-900">Pendiente</div>
									<div class="text-right font-bold <?php echo $v->monto_pendiente > 0 ? 'text-red-600' : 'text-green-600'; ?>">
										$<?php echo number_format($v->monto_pendiente, 2); ?>
									</div>
								</div>
							</div>

							<!-- Actions Footer -->
							<div class="grid grid-cols-2 gap-3">
								<button class="btn-modern w-full py-2 rounded-lg border border-gray-200 text-gray-600 text-xs font-bold hover:bg-gray-50 hover:text-gray-900 ver-documentos-btn"
									data-vin="<?php echo esc_attr($v->vin); ?>">
									Documentos
								</button>
								
								<?php if ($v->monto_pendiente > 0): ?>
									<button class="btn-modern w-full py-2 rounded-lg bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700 shadow-md shadow-indigo-200 pagar-vehiculo-btn"
										data-vehiculo-id="<?php echo esc_attr($v->id); ?>"
										data-vin="<?php echo esc_attr($v->vin); ?>"
										data-monto-pendiente="<?php echo esc_attr($v->monto_pendiente); ?>">
										Pagar Ahora
									</button>
								<?php else: ?>
									<button disabled class="w-full py-2 rounded-lg bg-gray-100 text-gray-400 text-xs font-bold cursor-not-allowed">
										Completado
									</button>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Paginación Estilizada -->
			<?php if ($total_pages > 1): ?>
				<div class="mt-10 flex justify-center">
					<div class="inline-flex bg-white rounded-xl shadow-sm border border-gray-100 p-1">
						<?php $base_url_pagination = add_query_arg('date_filter', $date_filter); ?>
						
						<a href="<?php echo esc_url(add_query_arg('vehicle_page', max(1, $current_page - 1), $base_url_pagination)); ?>" 
						   class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-50 transition-colors <?php if($current_page<=1) echo 'opacity-50 pointer-events-none'; ?>">
							«
						</a>
						
						<?php for ($i = 1; $i <= $total_pages; $i++): ?>
							<a href="<?php echo esc_url(add_query_arg('vehicle_page', $i, $base_url_pagination)); ?>" 
							   class="w-8 h-8 flex items-center justify-center rounded-lg text-sm font-bold transition-all <?php if ($i == $current_page) echo 'bg-indigo-600 text-white shadow-md'; else echo 'text-gray-600 hover:bg-gray-50'; ?>">
								<?php echo $i; ?>
							</a>
						<?php endfor; ?>
						
						<a href="<?php echo esc_url(add_query_arg('vehicle_page', min($total_pages, $current_page + 1), $base_url_pagination)); ?>" 
						   class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-50 transition-colors <?php if($current_page>=$total_pages) echo 'opacity-50 pointer-events-none'; ?>">
							»
						</a>
					</div>
				</div>
			<?php endif; ?>
		</section>

	</div>

	<!-- === DEPENDENCIAS === -->
	<script>
		if(!document.getElementById("daisyui-cdn")){
			const e=document.createElement("link");e.id="daisyui-cdn",e.href="https://cdn.jsdelivr.net/npm/daisyui@4.10.1/dist/full.min.css",e.rel="stylesheet",document.head.appendChild(e);
			const t=document.createElement("script");t.id="tailwindcss-cdn",t.src="https://cdn.tailwindcss.com",document.head.appendChild(t);
		}
	</script>

	<?php
	// Llamada al footer con los Modals rediseñados
	add_action('wp_footer', 'campo_rental_modals_footer_full_v33', 100);
	return ob_get_clean();
}

/**
 * FOOTER CON MODALS REDISEÑADOS (GLASS & CLEAN)
 */
function campo_rental_modals_footer_full_v33() {
	if (did_action('campo_rental_modals_footer_printed') > 0) return;

	global $wpdb;
	$tabla_talleres = $wpdb->prefix . 'campo_talleres';
	$talleres = $wpdb->get_results($wpdb->prepare("SELECT id_taller, nombre FROM {$tabla_talleres} WHERE status = %s ORDER BY nombre ASC", 'activo'));
	?>
	<!-- Overlay con Glass Blur -->
	<div id="campo-modal-overlay" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-[90]" style="display: none; transition: opacity 0.3s;"></div>

	<!-- 1. MODAL CREDENCIALES -->
	<dialog id="credentials_modal" class="modal">
		<div class="modal-box bg-white rounded-3xl shadow-2xl p-0 overflow-hidden max-w-md transform scale-100 transition-all duration-200">
			<div class="bg-gray-900 p-6 flex justify-between items-center text-white">
				<h3 class="font-bold text-lg flex items-center gap-2"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" /></svg> Acceso Seguro</h3>
				<form method="dialog"><button class="btn btn-sm btn-circle btn-ghost text-white/70 hover:bg-white/10">✕</button></form>
			</div>
			<div id="credentials-content" class="p-8 space-y-4 bg-gray-50"></div>
		</div>
	</dialog>

	<!-- 2. MODAL REGLAS -->
	<dialog id="rules_modal" class="modal">
		<div class="modal-box w-11/12 max-w-3xl bg-white rounded-3xl p-0 shadow-2xl">
			<div class="px-8 py-6 border-b border-gray-100 flex justify-between items-center">
				<h3 class="font-bold text-xl text-gray-800">Reglas y Normativas</h3>
				<form method="dialog"><button class="btn btn-sm btn-circle btn-ghost text-gray-400">✕</button></form>
			</div>
			<div class="p-8 prose prose-indigo max-w-none text-sm text-gray-600 custom-scroll h-[60vh] overflow-y-auto">
				<?php echo do_shortcode('[reglas_copart]'); ?>
			</div>
			<div class="p-6 bg-gray-50 flex justify-end">
				<form method="dialog"><button class="px-6 py-3 bg-gray-900 text-white font-bold rounded-xl hover:bg-gray-800 shadow-lg">Entendido</button></form>
			</div>
		</div>
	</dialog>

	<!-- 3. MODAL ALQUILER (WIZARD REDISEÑADO) -->
	<dialog id="rental_payment_modal" class="modal">
		<div class="modal-box w-11/12 max-w-6xl bg-white rounded-3xl p-0 overflow-hidden shadow-2xl h-[90vh] md:h-auto md:max-h-[85vh] flex flex-col md:flex-row">
			
			<!-- Sidebar Dark -->
			<div class="bg-gray-900 text-white w-full md:w-80 p-8 flex flex-col justify-between shrink-0">
				<div>
					<h3 class="text-2xl font-bold mb-2">Alquiler</h3>
					<p class="text-gray-400 text-sm mb-10">Completa los pasos para activar tu cuenta.</p>
					
					<ul class="steps steps-vertical w-full text-sm">
						<li id="rental-step-li-1" class="step step-primary font-medium" data-content="✓">Términos</li>
						<li id="rental-step-li-2" class="step font-medium" data-content="2">Reglas</li>
						<li id="rental-step-li-3" class="step font-medium" data-content="3">Confirmación</li>
						<li id="rental-step-li-4" class="step font-medium" data-content="$">Pago Seguro</li>
					</ul>
				</div>
				<div class="text-xs text-gray-500 mt-8">
					<p class="mb-1">🔒 SSL Encrypted</p>
					<p>&copy; Campo Broker</p>
				</div>
			</div>

			<!-- Main Content -->
			<div id="rental-wizard" class="flex-1 p-8 md:p-12 overflow-y-auto custom-scroll relative bg-white">
				<form method="dialog" class="absolute top-6 right-6 z-20"><button class="btn btn-sm btn-circle btn-ghost text-gray-400">✕</button></form>

				<!-- Steps Content -->
				<div class="max-w-3xl mx-auto pt-4">
					
					<!-- Step 1 -->
					<div id="rental-step-panel-1" class="step-panel animate-enter">
						<h4 class="text-2xl font-bold text-gray-900 mb-6">Términos y Condiciones</h4>
						<div class="bg-gray-50 rounded-2xl p-6 border border-gray-200 h-64 overflow-y-auto custom-scroll mb-6 text-sm text-gray-600 shadow-inner">
							<?php echo do_shortcode('[cb_terminos]'); ?>
						</div>
						<label class="flex items-center gap-4 p-4 border border-gray-200 rounded-xl cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition-all">
							<input type="checkbox" id="terms-check" class="checkbox checkbox-primary" />
							<span class="font-medium text-gray-800">He leído y acepto los términos legales.</span>
						</label>
					</div>

					<!-- Step 2 -->
					<div id="rental-step-panel-2" class="step-panel hidden animate-enter">
						<h4 class="text-2xl font-bold text-gray-900 mb-6">Reglas Operativas</h4>
						<div class="bg-gray-50 rounded-2xl p-6 border border-gray-200 h-64 overflow-y-auto custom-scroll mb-6 text-sm text-gray-600 shadow-inner">
							<?php echo do_shortcode('[reglas_copart]'); ?>
						</div>
						<label class="flex items-center gap-4 p-4 border border-gray-200 rounded-xl cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition-all">
							<input type="checkbox" id="rules-check" class="checkbox checkbox-primary" />
							<span class="font-medium text-gray-800">Comprendo las reglas de la subasta.</span>
						</label>
					</div>

					<!-- Step 3 -->
					<div id="rental-step-panel-3" class="step-panel hidden animate-enter">
						<h4 class="text-2xl font-bold text-gray-900 mb-6">Aviso Importante</h4>
						<div class="bg-orange-50 border border-orange-100 rounded-2xl p-8 flex gap-6 items-start">
							<div class="w-12 h-12 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center shrink-0 text-xl font-bold">!</div>
							<div>
								<h5 class="text-lg font-bold text-orange-800 mb-2">Sobre los pagos a la Subasta</h5>
								<p class="text-orange-900/80 leading-relaxed text-sm">
									El depósito de $300 es reembolsable si no realizas compras en 36h. 
									<br><br>
									<strong>CRÍTICO:</strong> Si ganas un vehículo, <span class="underline decoration-2 decoration-orange-400">debes pagarlo a través de este dashboard</span>. Pagar directamente a Copart/IAAI invalida nuestra garantía y soporte.
								</p>
							</div>
						</div>
					</div>

					<!-- Step 4: Payment -->
					<div id="rental-step-panel-4" class="step-panel hidden animate-enter">
						<h4 class="text-2xl font-bold text-gray-900 mb-8">Checkout Seguro</h4>
						<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
							<!-- Resumen -->
							<div class="bg-gray-50 p-6 rounded-2xl border border-gray-200">
								<h5 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4">Resumen del Pedido</h5>
								<div class="space-y-3 text-sm text-gray-600">
									<div class="flex justify-between"><span>Alquiler Cuenta</span><span class="font-mono text-gray-900" id="rental-subtotal">$0.00</span></div>
									<div class="flex justify-between">
										<span>Processing Fee (<span id="rental-fee-pct">0</span>%)</span>
										<span class="font-mono text-gray-900" id="rental-fee-amount">$0.00</span>
									</div>
									<div class="border-t border-gray-200 pt-4 mt-2 flex justify-between items-center">
										<span class="font-bold text-gray-900 text-lg">Total</span>
										<span class="font-bold text-2xl text-indigo-600" id="rental-total-amount">$0.00</span>
									</div>
								</div>
							</div>

							<!-- Gateway -->
							<div class="space-y-4">
								<div class="p-4 border-2 border-indigo-600 bg-indigo-50/50 rounded-xl flex items-center justify-between">
									<span class="font-bold text-indigo-900 flex items-center gap-2">
										<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
										Pago Digital
									</span>
									<img src="https://www.paypalobjects.com/webstatic/mktg/logo-center/PP_Acceptance_Marks_for_LogoCenter_266x142.png" alt="PayPal" class="h-6">
								</div>
								
								<div id="rental-paypal-button-container" class="mt-4"></div>
								
								<div id="rental-processing-spinner" class="hidden text-center py-8">
									<span class="loading loading-ring loading-lg text-indigo-600"></span>
									<p class="text-sm font-semibold text-gray-600 mt-2">Procesando transacción...</p>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Nav Buttons -->
				<div class="mt-10 flex justify-between items-center pt-6 border-t border-gray-100">
					<button id="rental-wizard-back-btn" class="px-6 py-2.5 rounded-xl border border-gray-200 text-gray-600 font-bold hover:bg-gray-50 transition-colors hidden">Atrás</button>
					<button id="rental-wizard-next-btn" class="px-8 py-3 rounded-xl bg-indigo-600 text-white font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition-all transform hover:-translate-y-1">Continuar</button>
				</div>
			</div>
		</div>
	</dialog>

	<!-- 4. ALERT MODAL (MINIMALIST) -->
	<dialog id="alert_modal" class="modal">
		<div class="modal-box bg-white rounded-2xl shadow-2xl p-8 text-center max-w-sm">
			<div id="alert_modal_content"></div>
			<div class="mt-6">
				<form method="dialog"><button class="w-full py-3 bg-gray-900 text-white font-bold rounded-xl hover:bg-black" id="alert_modal_close_btn">Entendido</button></form>
			</div>
		</div>
	</dialog>

	<!-- 5. PRE-ALERTA MODAL (WIZARD PREMIUM) -->
	<dialog id="pre_alert_modal" class="modal">
		<div class="modal-box w-11/12 max-w-6xl bg-white rounded-3xl p-0 overflow-hidden shadow-2xl h-[90vh] md:h-auto flex flex-col md:flex-row">
			
			<!-- Sidebar con Gradiente -->
			<div class="bg-gradient-to-b from-indigo-900 to-indigo-800 text-white w-full md:w-72 p-8 shrink-0 relative overflow-hidden">
				<div class="relative z-10">
					<h3 class="text-2xl font-bold mb-1">Pre-Alerta</h3>
					<p class="text-indigo-200 text-xs mb-8 uppercase tracking-widest font-bold">Vehículo Ganado</p>
					
					<ul class="steps steps-vertical w-full text-sm">
						<li id="pa-step-li-1" class="step step-secondary font-medium text-white" data-content="1">Logística</li>
						<li id="pa-step-li-2" class="step font-medium text-indigo-200" data-content="2">Detalles</li>
						<li id="pa-step-li-3" class="step font-medium text-indigo-200" data-content="3">Factura</li>
						<li id="pa-step-li-4" class="step font-medium text-indigo-200" data-content="4">Confirmar</li>
						<li id="pa-step-li-5" class="step font-medium text-indigo-200" data-content="$">Pago</li>
					</ul>
				</div>
				<!-- Decoración fondo -->
				<div class="absolute bottom-0 right-0 -mr-16 -mb-16 w-64 h-64 bg-white opacity-5 rounded-full blur-2xl"></div>
			</div>

			<!-- Main Form Content -->
			<div id="pre-alert-wizard" class="flex-1 p-8 md:p-12 overflow-y-auto custom-scroll relative">
				<form method="dialog" class="absolute top-6 right-6 z-20"><button class="btn btn-sm btn-circle btn-ghost text-gray-400">✕</button></form>

				<!-- Steps Container -->
				<div class="max-w-3xl mx-auto pt-2">

					<!-- Step 1: Aviso & Logística -->
					<div id="pa-step-panel-1" class="pa-step-panel animate-enter">
						<div class="mb-8 p-4 bg-blue-50 border border-blue-100 rounded-xl flex gap-4">
							<div class="text-blue-500"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
							<div>
								<h4 class="font-bold text-blue-900 text-sm">Importante</h4>
								<p class="text-xs text-blue-800/80">Este proceso cierra tu ciclo de compra actual. Ten a mano la factura final de la subasta.</p>
							</div>
						</div>

						<h4 class="text-xl font-bold text-gray-900 mb-6 border-b pb-2">Destino del Título</h4>
						<div class="space-y-6">
							<div class="form-control">
								<label class="font-bold text-gray-700 mb-3 block">¿Enviar a Taller Autorizado (Exportación)?</label>
								<div class="grid grid-cols-2 gap-4">
									<label class="cursor-pointer border-2 border-gray-100 rounded-xl p-4 hover:border-indigo-500 hover:bg-indigo-50 transition-all has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
										<input type="radio" name="trabajara_taller" value="si" class="hidden" required>
										<span class="font-bold text-gray-800 block">Sí, usar taller</span>
										<span class="text-xs text-gray-500">Para clientes internacionales</span>
									</label>
									<label class="cursor-pointer border-2 border-gray-100 rounded-xl p-4 hover:border-indigo-500 hover:bg-indigo-50 transition-all has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
										<input type="radio" name="trabajara_taller" value="no" class="hidden" required>
										<span class="font-bold text-gray-800 block">No, dirección propia</span>
										<span class="text-xs text-gray-500">Envío dentro de USA</span>
									</label>
								</div>
							</div>

							<!-- Dinámicos -->
							<div id="pa-taller-section" class="hidden animate-enter">
								<label class="text-sm font-bold text-gray-500 uppercase tracking-wide mb-2 block">Selecciona el Taller</label>
								<select id="pa_taller_id" name="taller_id" class="select select-bordered w-full rounded-xl bg-gray-50 focus:bg-white h-12">
									<option value="">-- Elige un taller de la lista --</option>
									<?php if (!empty($talleres)): foreach ($talleres as $taller): ?>
										<option value="<?php echo esc_attr($taller->id_taller); ?>"><?php echo esc_html($taller->nombre); ?></option>
									<?php endforeach; endif; ?>
								</select>
							</div>
							<div id="pa-direccion-manual-section" class="hidden animate-enter">
								<label class="text-sm font-bold text-gray-500 uppercase tracking-wide mb-2 block">Dirección de Envío (USA)</label>
								<input type="text" id="pa_shipping_address" name="shipping_address" placeholder="Ej: 1234 Main St, Miami, FL..." class="input input-bordered w-full rounded-xl bg-gray-50 focus:bg-white h-12" />
							</div>
						</div>
					</div>

					<!-- Step 2: Detalles Adicionales -->
					<div id="pa-step-panel-2" class="pa-step-panel hidden animate-enter">
						<h4 class="text-xl font-bold text-gray-900 mb-6 border-b pb-2">Detalles del Vehículo</h4>
						<div class="space-y-6">
							<div>
								<p class="font-bold text-gray-700 mb-3">¿Vehículo ganado en estados especiales?</p>
								<p class="text-xs text-gray-400 mb-3">FL, NY, NJ, NC, OH u OR requieren trámites extra.</p>
								<div class="flex gap-4">
									<label class="flex items-center gap-3 px-4 py-3 border rounded-xl cursor-pointer hover:bg-gray-50 w-full has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
										<input type="radio" name="estado_especial" value="si" class="radio radio-primary radio-sm" required>
										<span class="text-sm font-bold">Sí <span class="text-orange-500 ml-1">(+$45)</span></span>
									</label>
									<label class="flex items-center gap-3 px-4 py-3 border rounded-xl cursor-pointer hover:bg-gray-50 w-full has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
										<input type="radio" name="estado_especial" value="no" class="radio radio-primary radio-sm" required>
										<span class="text-sm font-bold">No</span>
									</label>
								</div>
							</div>
							
							<div>
								<p class="font-bold text-gray-700 mb-3">Finalidad</p>
								<div class="flex gap-4">
									<label class="flex items-center gap-3 px-4 py-3 border rounded-xl cursor-pointer hover:bg-gray-50 w-full has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
										<input type="radio" name="finalidad_vehiculo" value="exportar" class="radio radio-primary radio-sm" required>
										<span class="text-sm font-bold">Exportación</span>
									</label>
									<label class="flex items-center gap-3 px-4 py-3 border rounded-xl cursor-pointer hover:bg-gray-50 w-full has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
										<input type="radio" name="finalidad_vehiculo" value="uso_usa" class="radio radio-primary radio-sm" required>
										<span class="text-sm font-bold">Uso USA <span class="text-orange-500 ml-1">(+$50)</span></span>
									</label>
								</div>
							</div>
						</div>
						<!-- Hidden inputs for logic -->
						<div class="hidden">
							<input type="radio" name="info_method" value="upload" checked />
							<input type="radio" name="info_method" value="manual" />
						</div>
					</div>

					<!-- Step 3: Factura (Upload) -->
					<div id="pa-step-panel-3" class="pa-step-panel hidden animate-enter">
						<div id="pa-ajax-loader" class="hidden flex flex-col items-center justify-center py-12">
							<span class="loading loading-bars loading-lg text-indigo-600"></span>
							<p class="mt-4 font-bold text-indigo-900 animate-pulse">Analizando documento con IA...</p>
							<p class="text-xs text-indigo-400">Extrayendo VIN y Monto Total</p>
						</div>

						<div id="pa-form-content">
							<div id="pa-upload-section" class="border-2 border-dashed border-gray-300 rounded-3xl p-10 text-center hover:border-indigo-400 hover:bg-indigo-50/30 transition-all group cursor-pointer relative bg-gray-50">
								<div class="w-16 h-16 bg-white rounded-full shadow-md flex items-center justify-center mx-auto mb-4 text-indigo-500 group-hover:scale-110 transition-transform">
									<svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
								</div>
								<h3 class="text-lg font-bold text-gray-900">Sube la Factura Final</h3>
								<p class="text-sm text-gray-500 mb-6">PDF, PNG o JPG (Max 5MB)</p>
								<input type="file" id="pa_invoice_file" class="file-input file-input-bordered file-input-primary w-full max-w-xs rounded-full" accept=".jpg,.jpeg,.png,.pdf" />
								
								<div id="pa-manual-fallback-container" class="hidden mt-8 pt-6 border-t border-gray-200">
									<p class="text-xs text-red-500 mb-2 font-bold">¿Fallo en la lectura?</p>
									<button type="button" id="btn-switch-manual" class="btn btn-sm btn-outline btn-warning rounded-lg">Llenar datos manualmente</button>
								</div>
							</div>

							<!-- Manual Form Fallback -->
							<div id="pa-manual-section" class="hidden mt-6 bg-white p-6 rounded-2xl border border-gray-200 shadow-lg">
								<div class="flex items-center gap-2 text-orange-600 text-xs font-bold mb-4 uppercase tracking-wide">
									<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
									Ingreso Manual
								</div>
								
								<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
									<div class="form-control">
										<label class="label-text font-bold mb-1">VIN (17 Dígitos)</label>
										<input type="text" id="pa_vin" class="input input-bordered rounded-xl font-mono uppercase bg-gray-50 focus:bg-white" maxlength="17" placeholder="Ej: 1G1..." />
										<div id="pa-vehicle-info" class="text-xs mt-2 min-h-[1.5rem]"></div>
									</div>
									<div class="form-control">
										<label class="label-text font-bold mb-1">Total a Pagar (USD)</label>
										<input type="number" id="pa_total_amount" class="input input-bordered rounded-xl font-mono bg-gray-50 focus:bg-white" placeholder="0.00" step="0.01" />
									</div>
									<div class="form-control col-span-2">
										<label class="label-text font-bold mb-2">Fuente</label>
										<div class="flex gap-4">
											<label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50"><span class="font-bold text-sm">Copart</span><input type="radio" name="auction_source" value="Copart" class="radio radio-xs radio-primary" /></label>
											<label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50"><span class="font-bold text-sm">IAAI</span><input type="radio" name="auction_source" value="IAAI" class="radio radio-xs radio-primary" /></label>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Step 4: Confirmación -->
					<div id="pa-step-panel-4" class="pa-step-panel hidden animate-enter">
						<h4 class="text-xl font-bold text-gray-900 mb-6 text-center">Revisión Final</h4>
						<div id="pa-confirmation-details" class="bg-gray-50 p-8 rounded-3xl border border-gray-200 text-sm space-y-4 font-mono text-gray-700 shadow-inner max-w-lg mx-auto">
							<!-- JS Renders here -->
						</div>
					</div>

					<!-- Step 5: Pago -->
					<div id="pa-step-panel-5" class="pa-step-panel hidden animate-enter">
						<h4 class="text-2xl font-bold text-gray-900 mb-8">Checkout</h4>
						<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
							<div class="order-2 lg:order-1">
								<h5 class="font-bold text-gray-500 text-xs uppercase tracking-wide mb-3">Método de Pago</h5>
								<div id="pre-alerta-paypal-button-container"></div>
								<div id="pre-alerta-processing-spinner" class="hidden text-center py-6">
									<span class="loading loading-dots loading-lg text-indigo-600"></span>
									<p class="text-sm font-bold text-gray-500 mt-2">Registrando...</p>
								</div>
							</div>
							
							<div class="order-1 lg:order-2 bg-gray-50 p-6 rounded-2xl border border-gray-200 h-fit">
								<h5 class="font-bold text-gray-500 text-xs uppercase tracking-wide mb-4 border-b pb-2">Desglose Pre-Alerta</h5>
								<div class="space-y-2 text-sm text-gray-600">
									<div class="flex justify-between"><span>Tarifa Servicio</span><span class="font-bold" id="pre-alerta-subtotal">$0.00</span></div>
									<div id="pre-alerta-extra-estado-row" class="hidden flex justify-between text-orange-600"><span>Extra Estado</span><span class="font-bold" id="pre-alerta-extra-estado">$0.00</span></div>
									<div id="pre-alerta-extra-uso-row" class="hidden flex justify-between text-orange-600"><span>Extra Uso USA</span><span class="font-bold" id="pre-alerta-extra-uso">$0.00</span></div>
									<div class="flex justify-between text-xs text-gray-400 pt-2"><span>Fees Gateway</span><span id="pre-alerta-fee-amount">$0.00</span></div>
									<div class="flex justify-between text-xl font-bold text-indigo-900 pt-4 border-t border-gray-200 mt-2">
										<span>Total</span>
										<span id="pre-alerta-total-amount">$0.00</span>
									</div>
								</div>
							</div>
						</div>
					</div>

				</div>

				<!-- Wizard Footer -->
				<div class="mt-12 flex justify-between pt-6 border-t border-gray-100">
					<button id="pa-wizard-back-btn" class="px-6 py-2 rounded-xl text-gray-500 font-bold hover:bg-gray-50 hover:text-gray-900 transition-colors">Atrás</button>
					<button id="pa-wizard-next-btn" class="px-8 py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition-all transform hover:-translate-y-1">Continuar</button>
				</div>
			</div>
		</div>
	</dialog>

	<!-- 6. MODAL DOCUMENTOS (MINIMAL) -->
	<dialog id="ver_documentos_modal" class="modal">
		<div class="modal-box bg-white rounded-3xl p-8 shadow-2xl max-w-md">
			<form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-4 top-4 text-gray-400">✕</button></form>
			<h3 class="font-bold text-xl text-gray-900 mb-1">Archivos del Vehículo</h3>
			<p class="text-xs text-gray-400 font-mono mb-6" id="docs-vin-cliente"></p>
			<div id="lista-docs-cliente" class="space-y-3"></div>
		</div>
	</dialog>

	<!-- 7. MODAL PAGO VEHÍCULO (ABONO) - ESTILO PAYMENT GATEWAY -->
	<dialog id="pagar_vehiculo_modal" class="modal">
		<div class="modal-box w-11/12 max-w-4xl bg-white rounded-3xl p-0 overflow-hidden shadow-2xl">
			<div class="bg-gray-900 p-6 flex justify-between items-center text-white">
				<h3 class="font-bold text-lg">Realizar Abono</h3>
				<form method="dialog"><button class="btn btn-sm btn-circle btn-ghost text-white/50 hover:bg-white/10">✕</button></form>
			</div>
			
			<div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-10">
				<form id="form-pago-vehiculo" class="space-y-6">
					<input type="hidden" id="pago-vehiculo-id" name="vehiculo_id">
					
					<div>
						<label class="block text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">Vehículo a Pagar</label>
						<div class="text-sm font-bold text-gray-800 font-mono" id="pago-vehiculo-vin"></div>
						<div class="text-xs text-red-500 font-bold mt-1">Pendiente: <span id="pago-vehiculo-pendiente"></span></div>
					</div>

					<div class="form-control">
						<label class="label-text font-bold text-gray-700 mb-2 block">Monto a Abonar (USD)</label>
						<div class="relative">
							<span class="absolute left-4 top-3.5 text-gray-400 font-bold">$</span>
							<input type="number" id="pago-vehiculo-monto" name="monto" class="input input-bordered w-full pl-8 h-12 rounded-xl text-lg font-bold text-gray-900 bg-gray-50 focus:bg-white" step="0.01" placeholder="0.00" required>
						</div>
					</div>

					<div class="space-y-3">
						<label class="label-text font-bold text-gray-700 block">Método</label>
						
						<!-- PayPal Selector -->
						<div id="paypal-option-container">
							<label class="flex items-center justify-between p-4 border border-gray-200 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition-all has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 has-[:checked]:shadow-md" id="paypal-label">
								<div class="flex items-center gap-3">
									<input type="radio" name="metodo_pago" value="PayPal" class="radio radio-primary radio-sm">
									<span class="font-bold text-gray-700">PayPal</span>
								</div>
								<img src="https://www.paypalobjects.com/webstatic/mktg/logo-center/PP_Acceptance_Marks_for_LogoCenter_266x142.png" class="h-5">
							</label>
							<p id="paypal-limit-msg" class="text-[10px] text-red-500 mt-1 hidden font-bold pl-2">Límite $700.00 por PayPal</p>
						</div>

						<!-- Bank Selector -->
						<label class="flex items-center justify-between p-4 border border-gray-200 rounded-xl cursor-pointer hover:border-gray-400 hover:bg-gray-50 transition-all has-[:checked]:border-gray-800 has-[:checked]:bg-gray-100 has-[:checked]:shadow-md">
							<div class="flex items-center gap-3">
								<input type="radio" name="metodo_pago" value="Transferencia Bancaria" class="radio radio-primary radio-sm">
								<span class="font-bold text-gray-700">Transferencia Bancaria</span>
							</div>
							<span class="text-xl">🏦</span>
						</label>

						<!-- Info Transferencia -->
						<div id="pago-vehiculo-transferencia-info" class="hidden mt-4 p-5 bg-gray-50 rounded-xl border border-gray-200 text-sm">
							<div class="flex justify-between items-start mb-2">
								<span class="font-bold text-gray-900">Banreservas (USD)</span>
								<button type="button" class="text-xs text-indigo-600 font-bold underline" onclick="navigator.clipboard.writeText('9608450112')">Copiar</button>
							</div>
							<p class="font-mono text-lg text-gray-800 mb-1">9608450112</p>
							<p class="text-xs text-gray-500">Campo Broker SRL</p>
							
							<div class="mt-4">
								<label class="block text-xs font-bold text-gray-700 mb-2">Subir Comprobante</label>
								<input type="file" id="comprobante_pago" name="comprobante_pago" class="file-input file-input-bordered file-input-sm w-full rounded-lg" accept="image/*,.pdf">
							</div>
						</div>
					</div>
				</form>

				<!-- Summary Right -->
				<div class="bg-gray-50 p-6 rounded-2xl h-fit border border-gray-100">
					<h4 class="font-bold text-gray-400 text-xs uppercase tracking-wide mb-4 border-b pb-2">Desglose de Pago</h4>
					<div class="space-y-3 text-sm text-gray-600">
						<div class="flex justify-between"><span>Capital</span><span class="font-bold" id="pago-vehiculo-subtotal">$0.00</span></div>
						<div class="flex justify-between"><span>Comisión</span><span class="font-bold" id="pago-vehiculo-fee">$0.00</span></div>
						<div class="pt-4 mt-2 border-t border-gray-200 flex justify-between text-xl font-bold text-gray-900">
							<span>Total</span>
							<span id="pago-vehiculo-total">$0.00</span>
						</div>
					</div>
					
					<div class="mt-8">
						<div id="pago-vehiculo-paypal-container"></div>
						<button type="submit" form="form-pago-vehiculo" id="pago-vehiculo-transferencia-submit" class="w-full py-3 bg-gray-900 text-white font-bold rounded-xl hover:bg-black hidden shadow-lg">Confirmar Transferencia</button>
						<div id="pago-vehiculo-spinner" class="hidden text-center py-4">
							<span class="loading loading-spinner text-indigo-600"></span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</dialog>

	<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo defined('CAMPO_MAPS_API_KEY') ? esc_attr(CAMPO_MAPS_API_KEY) : ''; ?>&libraries=places" async defer></script>

	<!-- SCRIPT COMPLETO (Copiar y pegar intacto del bloque anterior) -->
	<script id="campo-rental-script">
	function fetchAndShowCredentials(){
		const modal = document.getElementById('credentials_modal');
		const content = document.getElementById('credentials-content');
		content.innerHTML = '<div class="text-center py-8"><span class="loading loading-spinner loading-lg text-primary"></span></div>';
		modal.showModal();
		jQuery.ajax({
			url: campo_rental_vars.ajax_url, type: "POST", data: { action: "campo_get_rental_credentials", nonce: campo_rental_vars.nonce },
			success: function(response) {
				if (response.success) {
					const data = response.data;
					content.innerHTML = `
						<div class="space-y-4">
							<div><label class="text-xs font-bold text-gray-400 uppercase">Usuario</label><div class="flex gap-2"><input type="text" readonly value="${data.email}" class="input input-bordered w-full h-10 bg-white" /><button class="btn btn-sm btn-square" onclick="navigator.clipboard.writeText('${data.email}')">📋</button></div></div>
							<div><label class="text-xs font-bold text-gray-400 uppercase">Contraseña</label><div class="flex gap-2"><input type="text" readonly value="${data.password}" class="input input-bordered w-full h-10 bg-white" /><button class="btn btn-sm btn-square" onclick="navigator.clipboard.writeText('${data.password}')">📋</button></div></div>
						</div>`;
				} else { content.innerHTML = `<div class="text-red-500 font-bold text-center">${response.data.message}</div>`; }
			}, error: function() { content.innerHTML = '<div class="text-red-500">Error servidor.</div>'; }
		});
	}

	function initializeAddressAutocomplete() {
		const input = document.getElementById('pa_shipping_address');
		if (!input || typeof google === 'undefined' || typeof google.maps === 'undefined') return;
		if (input.dataset.autocompleteInitialized === 'true') return;
		const autocomplete = new google.maps.places.Autocomplete(input, { types: ['address'], componentRestrictions: { country: ['us', 'ca', 'do'] } });
		autocomplete.addListener('place_changed', () => input.dispatchEvent(new Event('input', { bubbles: true })));
		input.dataset.autocompleteInitialized = 'true';
	}

	function abrirPreAlertaConAutocompletado() {
		const modal = document.getElementById('pre_alert_modal');
		document.getElementById('campo-modal-overlay').style.display = 'block';
		modal.show(); setTimeout(() => initializeAddressAutocomplete(), 300);
	}

	jQuery(document).ready(function($) {
		const feePct = (Number(campo_rental_vars.processing_fee_pct) || 5.4) / 100;
		const feeFixed = (typeof campo_rental_vars.processing_fee_fixed !== 'undefined') ? Number(campo_rental_vars.processing_fee_fixed) : 0.30;

		const showAlert = (message, type = 'error', reload = false) => {
			const alertModal = document.getElementById('alert_modal');
			$('#alert_modal_content').html(`<h3 class="font-bold text-lg text-${type === 'error' ? 'red-600' : 'indigo-600'}">${type === 'error' ? 'Error' : 'Notificación'}</h3><p class="py-4 text-gray-600 font-medium">${message}</p>`);
			$('#alert_modal_close_btn').off('click').on('click', () => { if (reload) location.reload(); });
			alertModal.showModal();
		};

		// Ver Documentos
		$('.ver-documentos-btn').on('click', function() {
			const vin = $(this).data('vin'); $('#docs-vin-cliente').text(vin);
			const lista = $('#lista-docs-cliente'); lista.html('<div class="text-center p-4"><span class="loading loading-spinner text-indigo-600"></span></div>');
			$.post(campo_rental_vars.ajax_url, { action: 'campo_get_vehicle_docs', nonce: campo_rental_vars.nonce, vin: vin }).done(function(resp){
				const mk = (l, u) => `<div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-100"><div class="font-bold text-sm text-gray-700">${l}</div>${u ? `<a class="btn btn-xs btn-primary rounded-lg" target="_blank" href="${u}">Ver PDF</a>` : '<span class="text-xs text-gray-400">No disponible</span>'}</div>`;
				lista.html(mk('Factura Subasta', (resp.success && resp.data.factura) ? resp.data.factura : '') + mk('Título Vehículo', (resp.success && resp.data.titulo) ? resp.data.titulo : ''));
			}).fail(() => lista.html('<div class="text-red-500 text-xs">Error de carga.</div>'));
			document.getElementById('ver_documentos_modal').showModal();
		});

		let paypalButtonsVehiculo;
		function updateVehiclePaymentSummary() {
			const subtotal = parseFloat($('#pago-vehiculo-monto').val()) || 0;
			const paypalRadio = $('input[name="metodo_pago"][value="PayPal"]');
			const paypalLimitMsg = $('#paypal-limit-msg');
			if (subtotal > 700) {
				paypalRadio.prop('disabled', true).parent().addClass('opacity-50 grayscale');
				paypalLimitMsg.removeClass('hidden');
				if ($('input[name="metodo_pago"]:checked').val() === 'PayPal') $('input[name="metodo_pago"][value="Transferencia Bancaria"]').prop('checked', true).trigger('change');
			} else {
				paypalRadio.prop('disabled', false).parent().removeClass('opacity-50 grayscale');
				paypalLimitMsg.addClass('hidden');
			}
			const method = $('input[name="metodo_pago"]:checked').val();
			let fee = (method === 'PayPal') ? (subtotal * feePct) + feeFixed : 0;
			$('#pago-vehiculo-subtotal').text(`$${subtotal.toFixed(2)}`);
			$('#pago-vehiculo-fee').text(`$${fee.toFixed(2)}`);
			$('#pago-vehiculo-total').text(`$${(subtotal + fee).toFixed(2)}`);
			renderPayPalButtons();
		}

		$(document).on('click', '.pagar-vehiculo-btn', function() {
			const vid = $(this).data('vehiculo-id'); const vin = $(this).data('vin'); const m = parseFloat($(this).data('monto-pendiente'));
			$('#pago-vehiculo-id').val(vid); $('#pago-vehiculo-vin').text(vin); $('#pago-vehiculo-pendiente').text(`$${m.toFixed(2)}`);
			$('#form-pago-vehiculo')[0].reset(); $('#pago-vehiculo-id').val(vid);
			$('#pago-vehiculo-monto').val(m.toFixed(2)).attr('max', m.toFixed(2));
			$('input[name="metodo_pago"][value="Transferencia Bancaria"]').prop('checked', true).trigger('change');
			document.getElementById('pagar_vehiculo_modal').showModal();
		});

		$('#pago-vehiculo-monto').on('input', updateVehiclePaymentSummary);
		$('input[name="metodo_pago"]').on('change', function() {
			const m = $(this).val();
			$('#pago-vehiculo-paypal-container').toggle(m === 'PayPal');
			$('#pago-vehiculo-transferencia-info').toggle(m === 'Transferencia Bancaria');
			$('#pago-vehiculo-transferencia-submit').toggle(m === 'Transferencia Bancaria');
			updateVehiclePaymentSummary();
		});

		function renderPayPalButtons() {
			const container = '#pago-vehiculo-paypal-container';
			const method = $('input[name="metodo_pago"]:checked').val();
			if (paypalButtonsVehiculo) paypalButtonsVehiculo.close();
			$(container).empty();
			if(method !== 'PayPal' || ($('#pago-vehiculo-monto').val() > 700)) return;
			if (typeof paypal === 'undefined') return;

			paypalButtonsVehiculo = paypal.Buttons({
				style: { layout: 'horizontal', color: 'blue', shape: 'rect', label: 'pay', height: 48 },
				createOrder: (d, a) => {
					const s = parseFloat($('#pago-vehiculo-monto').val()) || 0;
					const t = s + (s * feePct) + feeFixed;
					return a.order.create({ purchase_units: [{ description: `Abono VIN: ${$('#pago-vehiculo-vin').text()}`, amount: { value: t.toFixed(2) } }] });
				},
				onApprove: (d, a) => {
					$('#pago-vehiculo-paypal-container, #pago-vehiculo-transferencia-submit').hide(); $('#pago-vehiculo-spinner').removeClass('hidden');
					return a.order.capture().then(det => {
						const s = parseFloat($('#pago-vehiculo-monto').val()) || 0;
						$.post(campo_rental_vars.ajax_url, {
							action: 'campo_procesar_pago_vehiculo', nonce: campo_rental_vars.nonce,
							vehiculo_id: $('#pago-vehiculo-id').val(), monto_subtotal: s, monto_total: parseFloat(det.purchase_units[0].amount.value),
							metodo_pago: 'PayPal', paypal_order_id: det.id
						}, r => {
							document.getElementById('pagar_vehiculo_modal').close();
							showAlert(r.success ? r.data.message : r.data.message, r.success ? 'success' : 'error', r.success);
						}).always(() => $('#pago-vehiculo-spinner').addClass('hidden'));
					});
				}
			});
			paypalButtonsVehiculo.render(container);
		}

		$('#form-pago-vehiculo').on('submit', function(e) {
			e.preventDefault();
			const m = parseFloat($('#pago-vehiculo-monto').val());
			if (isNaN(m) || m <= 0 || $('#comprobante_pago')[0].files.length === 0) { showAlert('Verifique monto y comprobante.'); return; }
			const fd = new FormData(this);
			fd.append('action', 'campo_procesar_pago_vehiculo'); fd.append('nonce', campo_rental_vars.nonce);
			fd.append('monto_subtotal', m); fd.append('monto_total', m);
			$('#pago-vehiculo-transferencia-submit').hide(); $('#pago-vehiculo-spinner').removeClass('hidden');
			$.ajax({
				url: campo_rental_vars.ajax_url, type: 'POST', data: fd, processData: false, contentType: false,
				success: r => { document.getElementById('pagar_vehiculo_modal').close(); showAlert(r.success ? r.data.message : r.data.message, r.success ? 'success' : 'error', r.success); },
				complete: () => { $('#pago-vehiculo-spinner').addClass('hidden'); $('#pago-vehiculo-transferencia-submit').show(); }
			});
		});

		// Wizards Logic (Rental + PreAlerta) - Igual que original pero adaptado a clases visuales nuevas si es necesario
		(function setupRentalWizard() {
			let step = 1;
			const wiz = $('#rental-wizard'); if (!wiz.length) return;
			const next = wiz.find('#rental-wizard-next-btn'), back = wiz.find('#rental-wizard-back-btn');
			const update = () => {
				wiz.find('.steps .step').removeClass('step-primary text-gray-900').addClass('text-gray-400');
				for(let i=1;i<=step;i++) wiz.find(`#rental-step-li-${i}`).addClass('step-primary text-gray-900');
				wiz.find('.step-panel').addClass('hidden'); wiz.find(`#rental-step-panel-${step}`).removeClass('hidden');
				back.toggle(step>1); next.toggle(step<4); validate();
			};
			const validate = () => {
				let v = false;
				if(step===1) v = $('#terms-check').is(':checked');
				else if(step===2) v = $('#rules-check').is(':checked');
				else if(step===3) v = true;
				next.prop('disabled', !v).toggleClass('opacity-50 cursor-not-allowed', !v);
			};
			wiz.find('input[type="checkbox"]').on('change', validate);
			next.on('click', () => { if(step<4){ step++; update(); if(step===4) initRentalPP(); }});
			back.on('click', () => { if(step>1){ step--; update(); }});
			$('#rental_payment_modal').on('close', () => { step=1; wiz.find('input[type="checkbox"]').prop('checked',false); update(); });

			let ppRendered = false;
			function initRentalPP() {
				if(ppRendered) return; ppRendered = true;
				const s = parseFloat(campo_rental_vars.rental_price), f = (s * feePct) + feeFixed, t = s + f;
				$('#rental-fee-pct').text((feePct*100).toFixed(1)); $('#rental-fee-amount').text(`$${f.toFixed(2)}`);
				$('#rental-subtotal').text(`$${s.toFixed(2)}`); $('#rental-total-amount').text(`$${t.toFixed(2)}`);
				$('#rental-paypal-button-container').empty();
				if (typeof paypal === 'undefined') return;
				paypal.Buttons({
					style: { layout: 'vertical', color: 'black', shape: 'rect', label: 'pay', height: 48 },
					createOrder: (d, a) => a.order.create({ purchase_units: [{ description: 'Alquiler Cuenta', amount: { value: t.toFixed(2) }}] }),
					onApprove: (d, a) => {
						$('#rental-paypal-button-container').hide(); $('#rental-processing-spinner').removeClass('hidden');
						return a.order.capture().then(det => {
							$.post(campo_rental_vars.ajax_url, {
								action: 'campo_process_rental_payment', nonce: campo_rental_vars.nonce,
								paypal_order_id: det.id, paypal_amount: det.purchase_units[0].amount.value
							}, r => {
								document.getElementById('rental_payment_modal').close();
								showAlert(r.success ? r.data.message : r.data.message, r.success ? 'success' : 'error', r.success);
							});
						});
					}
				}).render('#rental-paypal-button-container');
			}
			update();
		})();

		// PreAlerta Wizard (Compacto)
		(function setupPAWizard() {
			let step = 1; const max = 5; let fails = 0;
			const wiz = $('#pre-alert-wizard'); if (!wiz.length) return;
			const next = wiz.find('#pa-wizard-next-btn'), back = wiz.find('#pa-wizard-back-btn');
			
			// Auto Logic
			$('input[name="trabajara_taller"]').on('change', function() {
				const v = $(this).val();
				$('#pa-taller-section').toggle(v==='si'); $('#pa-direccion-manual-section').toggle(v==='no');
				if(v==='si'){ $('input[name="finalidad_vehiculo"][value="exportar"]').prop('checked',true).prop('disabled',true); }
				else { $('input[name="finalidad_vehiculo"]').prop('disabled',false).prop('checked',false); setTimeout(() => initializeAddressAutocomplete(), 100); }
				validate();
			});
			$('#btn-switch-manual').on('click', () => $('input[name="info_method"][value="manual"]').prop('checked',true).trigger('change'));

			function calc() {
				const b = parseFloat(campo_rental_vars.prealerta_fee)||0, 
					  e1 = $('input[name="estado_especial"]:checked').val()==='si' ? 45 : 0,
					  e2 = $('input[name="finalidad_vehiculo"]:checked').val()==='uso_usa' ? 50 : 0,
					  s = b+e1+e2, f = (s*feePct)+feeFixed, t = s+f;
				return {b, e1, e2, f, t};
			}
			function initPAPP() {
				const c = calc();
				$('#pre-alerta-subtotal').text(`$${c.b.toFixed(2)}`);
				$('#pre-alerta-extra-estado').text(`$${c.e1.toFixed(2)}`).parent().toggle(c.e1>0);
				$('#pre-alerta-extra-uso').text(`$${c.e2.toFixed(2)}`).parent().toggle(c.e2>0);
				$('#pre-alerta-fee-amount').text(`$${c.f.toFixed(2)}`);
				$('#pre-alerta-total-amount').text(`$${c.t.toFixed(2)}`);
				$('#pre-alerta-paypal-button-container').empty();
				if(typeof paypal === 'undefined') return;
				paypal.Buttons({
					style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'pay', height: 48 },
					createOrder: (d, a) => a.order.create({ purchase_units: [{ description: 'Pre-Alerta', amount: { value: c.t.toFixed(2) }}] }),
					onApprove: (d, a) => {
						$('#pre-alerta-paypal-button-container').hide(); $('#pre-alerta-processing-spinner').removeClass('hidden');
						return a.order.capture().then(det => {
							let d = {
								action: 'campo_procesar_pre_alerta', nonce: campo_rental_vars.nonce,
								trabajara_taller: $('input[name="trabajara_taller"]:checked').val(),
								taller_id: $('#pa_taller_id').val(), shipping_address: $('#pa_shipping_address').val(),
								info_method: $('input[name="info_method"]:checked').val(),
								estado_especial: $('input[name="estado_especial"]:checked').val(),
								finalidad_vehiculo: $('input[name="finalidad_vehiculo"]:checked').val(),
								paypal_order_id: det.id
							};
							if(d.info_method==='upload') {
								const v = $('#pa-confirmation-details').data('v');
								$.extend(d, { vin:v.vin, amount:v.amount, source:v.source, vehicle_name:v.vehicle_name });
							} else {
								$.extend(d, { vin:$('#pa_vin').val(), amount:$('#pa_total_amount').val(), source:$('input[name="auction_source"]:checked').val(), vehicle_name:$('#pa-vehicle-info').data('vn')||'Manual' });
							}
							$.post(campo_rental_vars.ajax_url, d, r => {
								document.getElementById('pre_alert_modal').close();
								showAlert(r.success ? r.data.message : r.data.message, r.success ? 'success' : 'error', r.success);
							}).always(() => $('#pre-alerta-processing-spinner').addClass('hidden'));
						});
					}
				}).render('#pre-alerta-paypal-button-container');
			}

			const update = () => {
				wiz.find('.steps .step').removeClass('step-secondary text-white font-bold').addClass('text-indigo-200');
				for(let i=1;i<=step;i++) wiz.find(`#pa-step-li-${i}`).addClass('step-secondary text-white font-bold');
				wiz.find('.pa-step-panel').addClass('hidden'); wiz.find(`#pa-step-panel-${step}`).removeClass('hidden');
				back.toggle(step>1 && step<max); next.toggle(step<max); validate();
				if(step===max) initPAPP();
			};
			const validate = () => {
				let v = false;
				if(step===1) { const t=$('input[name="trabajara_taller"]:checked').val(); v = (t==='si' && $('#pa_taller_id').val()) || (t==='no' && $('#pa_shipping_address').val().length>5); }
				else if(step===2) v = $('input[name="estado_especial"]:checked').length && $('input[name="finalidad_vehiculo"]:checked').length;
				else if(step===3) {
					const m=$('input[name="info_method"]:checked').val();
					if(m==='upload') v = !!$('#pa-confirmation-details').data('v') || $('#pa_invoice_file')[0].files.length>0;
					else v = $('#pa_vin').val().length===17 && parseFloat($('#pa_total_amount').val())>0 && $('input[name="auction_source"]:checked').length;
				} else if(step===4) v = true;
				next.prop('disabled', !v).toggleClass('opacity-50', !v);
			};
			
			// View logic
			$('input[name="info_method"]').on('change', function() {
				const m = $(this).val(); $('#pa-upload-section').toggle(m==='upload'); $('#pa-manual-section').toggle(m==='manual'); validate();
			});
			wiz.find('input, select, #pa_invoice_file').on('input change', validate);

			// Logic Next
			next.on('click', function() {
				if($(this).is(':disabled')) return;
				if(step===3 && $('input[name="info_method"]:checked').val()==='upload' && !$('#pa-confirmation-details').data('v')) {
					const fd = new FormData(); fd.append('invoice_file', $('#pa_invoice_file')[0].files[0]);
					fd.append('action', 'campo_analyze_invoice'); fd.append('nonce', campo_rental_vars.nonce);
					$('#pa-form-content').addClass('hidden'); $('#pa-ajax-loader').removeClass('hidden'); next.prop('disabled',true);
					$.ajax({
						url: campo_rental_vars.ajax_url, type: 'POST', data: fd, processData: false, contentType: false,
						success: r => {
							if(r.success) { $('#pa-confirmation-details').data('v', r.data); goNext(); }
							else { showAlert(r.data.message); fails++; if(fails>=3) $('#pa-manual-fallback-container').removeClass('hidden'); }
						},
						complete: () => { $('#pa-form-content').removeClass('hidden'); $('#pa-ajax-loader').addClass('hidden'); validate(); }
					});
				} else goNext();
			});
			const goNext = () => { 
				if(step<max) { 
					step++; update(); 
					if(step===4) {
						// Summary Render
						const m = $('input[name="info_method"]:checked').val();
						let h = `<div class="grid grid-cols-2 gap-4 mb-4 pb-4 border-b"><div><p class="text-xs text-gray-400 uppercase">Logística</p><p class="font-bold">${$('input[name="trabajara_taller"]:checked').val()==='si' ? 'Taller' : 'Directo'}</p></div><div><p class="text-xs text-gray-400 uppercase">Destino</p><p class="font-bold truncate">${$('input[name="trabajara_taller"]:checked').val()==='si' ? $('#pa_taller_id option:selected').text() : $('#pa_shipping_address').val()}</p></div></div>`;
						if(m==='upload'){ const v=$('#pa-confirmation-details').data('v'); h+=`<p class="text-xs text-gray-400 uppercase">Vehículo Detectado</p><p class="text-lg font-bold text-indigo-900">${v.vehicle_name}</p><p class="font-mono text-xs bg-gray-100 p-1 rounded inline-block mt-1">${v.vin}</p><p class="mt-2 font-bold">Factura: $${v.amount}</p>`; }
						else { h+=`<p class="text-xs text-gray-400 uppercase">Vehículo Manual</p><p class="font-mono text-xs bg-gray-100 p-1 rounded inline-block">${$('#pa_vin').val()}</p><p class="mt-2 font-bold">Factura: $${$('#pa_total_amount').val()}</p>`; }
						$('#pa-confirmation-details').html(h);
					}
				} 
			};
			back.on('click', () => { if(step>1){ step--; update(); }});
			document.getElementById('pre_alert_modal').addEventListener('close', () => $('#campo-modal-overlay').hide());
			
			// VIN lookup min
			$("#pa_vin").on("input", function() {
				const v = $(this).val(); $("#pa-vehicle-info").html("");
				if(v.length===17) {
					$("#pa-vehicle-info").html('<span class="loading loading-dots loading-xs"></span>');
					$.post(campo_rental_vars.ajax_url, {action:'campo_validate_vin', nonce:campo_rental_vars.nonce, vin:v}, r=>{
						if(r.success) $("#pa-vehicle-info").html(`<span class="text-green-600 font-bold">${r.data.year} ${r.data.make}</span>`).data('vn', `${r.data.year} ${r.data.make}`);
						else $("#pa-vehicle-info").html('<span class="text-red-400">No encontrado</span>');
						validate();
					});
				} else validate();
			});
			update();
		})();
	});
	</script>
	<?php
}
