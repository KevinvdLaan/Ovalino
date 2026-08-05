<?php
/**
 * Plugin Name: OV Halte Importer
 * Description: Upload CHB-, PassengerStopAssignment- en NeTEx-bestanden en toon automatisch lijnen en bestemmingen per halte via shortcode.
 * Version: 1.3.7
 * Author: Kevin van der Laan
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

class OV_Halte_Importer {
	const VERSION = '1.3.7';
	const OPTION_IMPORT_INFO = 'ovhi_import_info';
	const OPTION_LIVE_DELAY_SETTINGS = 'ovhi_live_delay_settings';
	const OPTION_LIVE_DELAY_LAST_SYNC = 'ovhi_live_delay_last_sync';
	const DELAY_RETENTION_SECONDS = 3600; // automatische cleanup voor realtime vertragingen
	const NONCE_ACTION = 'ovhi_upload_datasets';
	const NONCE_REALTIME_IMPORT = 'ovhi_realtime_import';
	const NONCE_SAVE_LIVE_SETTINGS = 'ovhi_save_live_delay_settings';
	const FRONTEND_STYLE = 'ovhi-frontend';
	const FALLBACK_COLOR = '#861121';
	private static $runtime_table_prefix = '';

	public static function init() {
		register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
		add_action('admin_menu', array(__CLASS__, 'admin_menu'));
		add_action('admin_post_ovhi_upload', array(__CLASS__, 'handle_upload'));
		add_action('admin_post_ovhi_realtime_upload', array(__CLASS__, 'handle_realtime_upload'));
		add_action('admin_post_ovhi_save_live_settings', array(__CLASS__, 'handle_live_settings_save'));
		add_action('admin_post_ovhi_manual_sync', array(__CLASS__, 'handle_manual_sync'));
		add_action('template_redirect', array(__CLASS__, 'maybe_sync_remote_realtime_delays'));
		add_action('ovhi_cron_sync_realtime_delays', array(__CLASS__, 'fetch_remote_realtime_delays'));
		add_action('init', array(__CLASS__, 'handle_async_sync_request'));
		add_filter('cron_schedules', array(__CLASS__, 'add_cron_intervals'));
		add_shortcode('ov_halte', array(__CLASS__, 'render_shortcode_safely'));
	}

	public static function activate() {
		self::create_tables();
		self::migrate_tables();
	}

	private static function table($suffix) {
		return self::table_for_prefix($suffix, self::$runtime_table_prefix);
	}

	private static function table_for_prefix($suffix, $prefix = '') {
		global $wpdb;
		$prefix = sanitize_key((string) $prefix);
		$prefix = $prefix !== '' ? $prefix . '_' : '';
		return $wpdb->prefix . 'ovhi_' . $prefix . $suffix;
	}

	private static function set_runtime_table_prefix($prefix) {
		self::$runtime_table_prefix = sanitize_key((string) $prefix);
	}

	private static function clear_runtime_table_prefix() {
		self::$runtime_table_prefix = '';
	}

	private static function encode_import_blob(array $data) {
		$json = function_exists('wp_json_encode')
			? wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			: json_encode($data);

		return is_string($json) ? $json : '[]';
	}

	private static function decode_import_blob($blob) {
		if (!is_string($blob) || trim($blob) === '') {
			return array();
		}

		$data = json_decode($blob, true);
		return is_array($data) ? $data : array();
	}

	private static function get_table_suffixes() {
		return array('stopplaces', 'quays', 'scheduled_stops', 'assignments', 'lines', 'stop_lines', 'stop_line_patterns', 'availability', 'journeys', 'stop_offsets', 'notices', 'notice_assignments');
	}

	private static function drop_tables_for_prefix($prefix) {
		global $wpdb;

		foreach (self::get_table_suffixes() as $suffix) {
			$table_name = self::table_for_prefix($suffix, $prefix);
			$wpdb->query('DROP TABLE IF EXISTS ' . $table_name);
		}
	}

	private static function table_exists($table_name) {
		global $wpdb;

		$like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($table_name) : addcslashes($table_name, '_%\\');
		$sql = $wpdb->prepare('SHOW TABLES LIKE %s', $like);

		return !empty($wpdb->get_var($sql));
	}

	private static function swap_tables_from_prefix($source_prefix) {
		global $wpdb;

		$source_prefix = sanitize_key((string) $source_prefix);
		if ($source_prefix === '') {
			throw new RuntimeException('Tijdelijke importprefix ontbreekt.');
		}

		$backup_prefix = 'backup_' . wp_generate_password(10, false, false);
		$rename_pairs = array();

		foreach (self::get_table_suffixes() as $suffix) {
			$source_table = self::table_for_prefix($suffix, $source_prefix);
			$live_table = self::table_for_prefix($suffix);

			if (!self::table_exists($source_table)) {
				throw new RuntimeException('Tijdelijke importtabel ontbreekt: ' . $suffix);
			}

			if (self::table_exists($live_table)) {
				$backup_table = self::table_for_prefix($suffix, $backup_prefix);
				$rename_pairs[] = $live_table . ' TO ' . $backup_table;
			}

			$rename_pairs[] = $source_table . ' TO ' . $live_table;
		}

		$result = $wpdb->query('RENAME TABLE ' . implode(', ', $rename_pairs));
		if ($result === false || $wpdb->last_error !== '') {
			throw new RuntimeException('Kon importtabellen niet omzetten: ' . $wpdb->last_error);
		}

		self::drop_tables_for_prefix($backup_prefix);
	}

	private static function cleanup_import_workspace($temp_dir = '', $stage_prefix = '') {
		self::clear_runtime_table_prefix();
		if ($stage_prefix !== '') {
			self::drop_tables_for_prefix($stage_prefix);
		}
		if ($temp_dir !== '' && is_dir($temp_dir)) {
			self::delete_directory($temp_dir);
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = "CREATE TABLE " . self::table('stopplaces') . " (
			stopplace_code varchar(50) NOT NULL,
			stopplace_name varchar(255) NOT NULL default '',
			town varchar(255) NOT NULL default '',
			latitude decimal(9, 6) NOT NULL default 0,
			longitude decimal(9, 6) NOT NULL default 0,
			PRIMARY KEY  (stopplace_code)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('quays') . " (
			quay_code varchar(50) NOT NULL,
			stopplace_code varchar(50) NOT NULL default '',
			quay_name varchar(255) NOT NULL default '',
			latitude decimal(9, 6) NOT NULL default 0,
			longitude decimal(9, 6) NOT NULL default 0,
			wheelchair_access varchar(20) NOT NULL default 'unknown',
			step_free_access varchar(20) NOT NULL default 'unknown',
			PRIMARY KEY  (quay_code),
			KEY stopplace_code (stopplace_code)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('scheduled_stops') . " (
			scheduled_stop_point_ref varchar(100) NOT NULL,
			user_stop_code varchar(50) NOT NULL default '',
			stop_name varchar(255) NOT NULL default '',
			stop_area_ref varchar(100) NOT NULL default '',
			PRIMARY KEY  (scheduled_stop_point_ref),
			KEY user_stop_code (user_stop_code)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('assignments') . " (
			scheduled_stop_point_ref varchar(100) NOT NULL,
			quay_code varchar(50) NOT NULL default '',
			PRIMARY KEY  (scheduled_stop_point_ref),
			KEY quay_code (quay_code)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('lines') . " (
			line_ref varchar(100) NOT NULL,
			public_code varchar(50) NOT NULL default '',
			line_name varchar(255) NOT NULL default '',
			colour varchar(7) NOT NULL default '',
			text_colour varchar(7) NOT NULL default '',
			PRIMARY KEY  (line_ref),
			KEY public_code (public_code)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('stop_lines') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quay_code varchar(50) NOT NULL default '',
			line_ref varchar(100) NOT NULL default '',
			direction_type varchar(20) NOT NULL default '',
			destination varchar(255) NOT NULL default '',
			PRIMARY KEY  (id),
			UNIQUE KEY quay_line_direction_destination (quay_code, line_ref, direction_type, destination),
			KEY quay_code (quay_code),
			KEY line_ref (line_ref)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('stop_line_patterns') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quay_code varchar(50) NOT NULL default '',
			line_ref varchar(100) NOT NULL default '',
			direction_type varchar(20) NOT NULL default '',
			destination_raw varchar(255) NOT NULL default '',
			destination_display varchar(255) NOT NULL default '',
			destination_hash char(32) NOT NULL default '',
			service_journey_pattern_ref varchar(100) NOT NULL default '',
			PRIMARY KEY  (id),
			UNIQUE KEY pattern_quay_destination (destination_hash, service_journey_pattern_ref, quay_code),
			KEY quay_line_direction (quay_code, line_ref, direction_type),
			KEY service_journey_pattern_ref (service_journey_pattern_ref)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('availability') . " (
			availability_ref varchar(100) NOT NULL,
			from_date date NOT NULL,
			to_date date NOT NULL,
			valid_day_bits longtext NOT NULL,
			PRIMARY KEY  (availability_ref),
			KEY date_range (from_date, to_date)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('journeys') . " (
			journey_signature varchar(64) NOT NULL,
			journey_ref varchar(120) NOT NULL default '',
			journey_number varchar(20) NOT NULL default '',
			service_journey_pattern_ref varchar(100) NOT NULL default '',
			time_demand_type_ref varchar(100) NOT NULL default '',
			availability_ref varchar(100) NOT NULL default '',
			availability_from_date date NOT NULL default '1970-01-01',
			departure_seconds int(11) NOT NULL default 0,
			PRIMARY KEY  (journey_signature),
			KEY journey_ref (journey_ref),
			KEY journey_number (journey_number),
			KEY pattern_time (service_journey_pattern_ref, time_demand_type_ref),
			KEY availability_ref (availability_ref),
			KEY availability_from_date (availability_from_date),
			KEY departure_seconds (departure_seconds)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('stop_offsets') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_journey_pattern_ref varchar(100) NOT NULL default '',
			time_demand_type_ref varchar(100) NOT NULL default '',
			scheduled_stop_point_ref varchar(100) NOT NULL default '',
			line_ref varchar(100) NOT NULL default '',
			direction_type varchar(20) NOT NULL default '',
			offset_seconds int(11) NOT NULL default 0,
			stop_order int(11) NOT NULL default 0,
			for_boarding tinyint(1) NOT NULL default 1,
			for_alighting tinyint(1) NOT NULL default 1,
			PRIMARY KEY  (id),
			UNIQUE KEY pattern_time_stop (service_journey_pattern_ref, time_demand_type_ref, scheduled_stop_point_ref),
			KEY stop_line_direction (scheduled_stop_point_ref, line_ref, direction_type),
			KEY pattern_time (service_journey_pattern_ref, time_demand_type_ref),
			KEY line_ref_dir (line_ref, direction_type)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('realtime_delays') . " (
			journey_ref varchar(120) NOT NULL default '',
			stop_code varchar(100) NOT NULL default '',
			delay_seconds int(11) NOT NULL default 0,
			is_cancelled tinyint(1) NOT NULL default 0,
			expected_time datetime NULL default NULL,
			updated_at datetime NOT NULL default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (journey_ref, stop_code),
			KEY stop_code (stop_code)
		) $charset;";
		
		$sql[] = "CREATE TABLE " . self::table('notices') . " (
			notice_id varchar(100) NOT NULL,
			notice_text text NOT NULL,
			PRIMARY KEY  (notice_id)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table('notice_assignments') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			notice_ref varchar(100) NOT NULL default '',
			noticed_object_ref varchar(120) NOT NULL default '',
			name_of_ref_class varchar(50) NOT NULL default '',
			PRIMARY KEY  (id),
			UNIQUE KEY notice_object (notice_ref, noticed_object_ref),
			KEY noticed_object_ref (noticed_object_ref)
		) $charset;";


		foreach ($sql as $statement) {
			dbDelta($statement);
		}
	}

	private static function migrate_tables() {
		global $wpdb;

		// Migrate stopplaces table
		$table_stopplaces = self::table('stopplaces');
		$columns = $wpdb->get_results("DESCRIBE $table_stopplaces");
		$column_names = wp_list_pluck($columns, 'Field');

		if (!in_array('latitude', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table_stopplaces ADD COLUMN latitude decimal(9, 6) NOT NULL default 0 AFTER town");
		}
		if (!in_array('longitude', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table_stopplaces ADD COLUMN longitude decimal(9, 6) NOT NULL default 0 AFTER latitude");
		}

		// Migrate quays table
		$table_quays = self::table('quays');
		$columns = $wpdb->get_results("DESCRIBE $table_quays");
		$column_names = wp_list_pluck($columns, 'Field');

		if (!in_array('latitude', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table_quays ADD COLUMN latitude decimal(9, 6) NOT NULL default 0 AFTER quay_name");
		}
		if (!in_array('longitude', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table_quays ADD COLUMN longitude decimal(9, 6) NOT NULL default 0 AFTER latitude");
		}
		if (!in_array('wheelchair_access', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table_quays ADD COLUMN wheelchair_access varchar(20) NOT NULL default 'unknown' AFTER longitude");
		}
		if (!in_array('step_free_access', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table_quays ADD COLUMN step_free_access varchar(20) NOT NULL default 'unknown' AFTER wheelchair_access");
		}

		// Migrate stop_offsets table indexes
		$table_stop_offsets = self::table('stop_offsets');
		$indexes = $wpdb->get_results("SHOW INDEX FROM $table_stop_offsets");
		$index_names = wp_list_pluck($indexes, 'Key_name');
		if (!in_array('line_ref_dir', $index_names, true)) {
			$wpdb->query("ALTER TABLE $table_stop_offsets ADD INDEX line_ref_dir (line_ref, direction_type)");
		}

		// Migrate journeys table: add journey_number, gevuld uit het NeTEx
		// <PrivateCode type="JourneyNumber"> veld op ServiceJourney-niveau.
		// Dit vervangt het gokken naar het ritnummer op basis van de opbouw
		// van het lange journey_ref/id (wat alleen toevallig klopte voor
		// vervoerders wier ID-structuur op Qbuzz's exportformaat leek).
		$table_journeys = self::table('journeys');
		$journey_columns = $wpdb->get_results("DESCRIBE $table_journeys");
		$journey_column_names = wp_list_pluck($journey_columns, 'Field');
		if (!in_array('journey_number', $journey_column_names, true)) {
			$wpdb->query("ALTER TABLE $table_journeys ADD COLUMN journey_number varchar(20) NOT NULL default '' AFTER journey_ref");
			$wpdb->query("ALTER TABLE $table_journeys ADD INDEX journey_number (journey_number)");
		}

		// Migrate realtime_delays table: add expected_time column if missing
		$table_realtime = self::table('realtime_delays');
		$realtime_columns = $wpdb->get_results("DESCRIBE $table_realtime");
		$realtime_column_names = wp_list_pluck($realtime_columns, 'Field');
		if (!in_array('expected_time', $realtime_column_names, true)) {
			$wpdb->query("ALTER TABLE $table_realtime ADD COLUMN expected_time datetime NULL default NULL AFTER is_cancelled");
		}
	}

	public static function admin_menu() {
		// Registreer het hoofdmenu 'Ovalino' in de backend zijbalk
		add_menu_page(
			'Ovalino',
			'Ovalino',
			'manage_options',
			'ovalino-menu',
			array(__CLASS__, 'render_admin_page'),
			'dashicons-transport',
			30
		);

		// Voeg OV Halte Importer toe als eerste submenu pagina
		add_submenu_page(
			'ovalino-menu',
			'OV Halte Importer',
			'OV Halte Importer',
			'manage_options',
			'ov-halte-importer',
			array(__CLASS__, 'render_admin_page')
		);

		remove_submenu_page('ovalino-menu', 'ovalino-menu');
	}

	public static function render_admin_page() {
		global $wpdb;

		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om deze pagina te bekijken.', 'ovhi'));
		}

		self::create_tables();
		self::migrate_tables();
		$info = get_option(self::OPTION_IMPORT_INFO, array());
		$debug_counts = self::get_debug_counts();
		$notice = isset($_GET['ovhi_notice']) ? sanitize_text_field(wp_unslash($_GET['ovhi_notice'])) : '';
		$message = isset($_GET['ovhi_message']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['ovhi_message']))) : '';
		$logo_url = plugins_url('ovalinologo.png', __FILE__);
		?>
		<div class="wrap">
			<div style="display:flex; align-items:center; gap: 20px; margin-bottom: 20px; margin-top: 10px;">
				<img src="<?php echo esc_url($logo_url); ?>" alt="Ovalino Logo" style="height: 50px; width: auto;" />
				<h1 style="margin:0;">OV Halte Importer</h1>
			</div>

			<?php if ($notice === 'success') : ?>
				<div class="notice notice-success"><p>Import voltooid. Oude datasets zijn volledig verwijderd en vervangen.</p></div>
			<?php elseif ($notice === 'error') : ?>
				<div class="notice notice-error"><p><?php echo $message ? esc_html($message) : 'De import is mislukt. Controleer de bestanden en probeer opnieuw.'; ?></p></div>
			<?php elseif ($notice === 'live_settings_saved') : ?>
				<div class="notice notice-success"><p>Ovalino Live-instellingen opgeslagen.</p></div>
			<?php elseif ($notice === 'manual_sync_complete') : ?>
				<div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
			<?php endif; ?>

			<p>Upload hier de drie benodigde bronbestanden. Bij elke nieuwe import worden oude datasetbestanden direct verwijderd.</p>
			<p>Het NeTEx-veld accepteert meerdere bestanden tegelijk.</p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="ovhi_upload" />
				<?php wp_nonce_field(self::NONCE_ACTION); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ovhi_chb_file">ExportCHB</label></th>
						<td><input type="file" id="ovhi_chb_file" name="ovhi_chb_file" accept=".xml,.gz,.xml.gz" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ovhi_psa_file">PassengerStopAssignmentExportCHB</label></th>
						<td><input type="file" id="ovhi_psa_file" name="ovhi_psa_file" accept=".xml,.gz,.xml.gz" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ovhi_netex_file">NeTEx</label></th>
						<td><input type="file" id="ovhi_netex_file" name="ovhi_netex_file[]" accept=".xml,.gz,.xml.gz" multiple required /></td>
					</tr>
				</table>
				<?php submit_button('Datasets uploaden en importeren'); ?>
			</form>

			<?php if (!empty($info)) : ?>
				<h2>Laatste import</h2>
				<table class="widefat striped" style="max-width: 900px;">
					<tbody>
						<tr>
							<td><strong>Datum</strong></td>
							<td><?php echo !empty($info['imported_at']) ? esc_html($info['imported_at']) : '-'; ?></td>
						</tr>
						<tr>
							<td><strong>StopPlaces</strong></td>
							<td><?php echo isset($info['counts']['stopplaces']) ? esc_html((string) $info['counts']['stopplaces']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Quays</strong></td>
							<td><?php echo isset($info['counts']['quays']) ? esc_html((string) $info['counts']['quays']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Lijnen</strong></td>
							<td><?php echo isset($info['counts']['lines']) ? esc_html((string) $info['counts']['lines']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Halte-lijn combinaties</strong></td>
							<td><?php echo isset($info['counts']['stop_lines']) ? esc_html((string) $info['counts']['stop_lines']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Halte-lijn patronen</strong></td>
							<td><?php echo isset($info['counts']['stop_line_patterns']) ? esc_html((string) $info['counts']['stop_line_patterns']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Ritten</strong></td>
							<td><?php echo isset($info['counts']['journeys']) ? esc_html((string) $info['counts']['journeys']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Notices (Voetnoten)</strong></td>
							<td><?php echo isset($info['counts']['notices']) ? esc_html((string) $info['counts']['notices']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Notice-koppelingen</strong></td>
							<td><?php echo isset($info['counts']['notice_assignments']) ? esc_html((string) $info['counts']['notice_assignments']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Halte-tijd offsets</strong></td>
							<td><?php echo isset($info['counts']['stop_offsets']) ? esc_html((string) $info['counts']['stop_offsets']) : '0'; ?></td>
						</tr>
						<tr>
							<td><strong>Bestanden</strong></td>
							<td>
								<?php
								if (!empty($info['files']) && is_array($info['files'])) {
									echo esc_html(implode(', ', $info['files']));
								} else {
									echo '-';
								}
								?>
							</td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Debug</h2>
			<table class="widefat striped" style="max-width: 900px;">
				<tbody>
					<tr>
						<td><strong>Assignments</strong></td>
						<td><?php echo esc_html((string) $debug_counts['assignments']); ?></td>
					</tr>
					<tr>
						<td><strong>Scheduled stops</strong></td>
						<td><?php echo esc_html((string) $debug_counts['scheduled_stops']); ?></td>
					</tr>
					<tr>
						<td><strong>Stop lines</strong></td>
						<td><?php echo esc_html((string) $debug_counts['stop_lines']); ?></td>
					</tr>
					<tr>
						<td><strong>Stop line patterns</strong></td>
						<td><?php echo esc_html((string) $debug_counts['stop_line_patterns']); ?></td>
					</tr>
					<tr>
						<td><strong>Availability</strong></td>
						<td><?php echo esc_html((string) $debug_counts['availability']); ?></td>
					</tr>
					<tr>
						<td><strong>Journeys</strong></td>
						<td><?php echo esc_html((string) $debug_counts['journeys']); ?></td>
					</tr>
					<tr>
						<td><strong>Notices</strong></td>
						<td><?php echo esc_html((string) $debug_counts['notices']); ?></td>
					</tr>
					<tr>
						<td><strong>Notice assignments</strong></td>
						<td><?php echo esc_html((string) $debug_counts['notice_assignments']); ?></td>
					</tr>
					<tr>
						<td><strong>Stop offsets</strong></td>
						<td><?php echo esc_html((string) $debug_counts['stop_offsets']); ?></td>
					</tr>
					<tr>
						<td><strong>Realtime vertragingen</strong></td>
						<td><?php echo esc_html((string) $debug_counts['realtime_delays']); ?></td>
					</tr>
				</tbody>
			</table>

			<h2>Ovalino Live / automatische vertragingen</h2>
			<?php $live_settings = self::get_live_delay_settings(); ?>
			<p>Configureer hier de automatische WordPress-koppeling naar je Ovalino Live VPS. Wanneer deze optie ingeschakeld is, controleert WordPress de VPS op elke frontendweergave (maximaal eenmaal per ingestelde interval) en slaat vertragingen op in de realtimevertragingstabel.</p>
			<p><strong>Opmerking:</strong> realtime vertragingen ouder dan 1 uur worden automatisch uit de tabel verwijderd.</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="ovhi_save_live_settings" />
				<?php wp_nonce_field(self::NONCE_SAVE_LIVE_SETTINGS); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ovhi_live_enabled">Inschakelen</label></th>
						<td><input type="checkbox" id="ovhi_live_enabled" name="ovhi_live_enabled" value="1" <?php checked($live_settings['enabled'], true); ?> /> Activeer automatische synchronisatie met Ovalino Live</td>
					</tr>
					<tr>
						<th scope="row"><label for="ovhi_live_endpoint">Ovalino Live endpoint</label></th>
						<td><input type="url" id="ovhi_live_endpoint" name="ovhi_live_endpoint" class="regular-text" value="<?php echo esc_attr($live_settings['endpoint']); ?>" placeholder="https://ovalino-live.example.com/api/delays" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ovhi_live_auth_token">Authenticatietoken</label></th>
						<td><input type="text" id="ovhi_live_auth_token" name="ovhi_live_auth_token" class="regular-text" value="<?php echo esc_attr($live_settings['auth_token']); ?>" placeholder="Optioneel" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ovhi_live_timeout_seconds">Timeout (seconden)</label></th>
						<td><input type="number" id="ovhi_live_timeout_seconds" name="ovhi_live_timeout_seconds" min="1" max="30" value="<?php echo esc_attr($live_settings['timeout_seconds']); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ovhi_live_poll_interval_seconds">Synchronisatie-interval</label></th>
						<td><input type="number" id="ovhi_live_poll_interval_seconds" name="ovhi_live_poll_interval_seconds" min="10" max="3600" value="<?php echo esc_attr($live_settings['poll_interval_seconds']); ?>" /> seconden (maximaal 1 HTTP-oproep per interval)</td>
					</tr>
					<tr>
						<th scope="row"><label for="ovhi_infoplus_cancel_codes">InfoPlus annuleringscodes</label></th>
						<td>
							<input type="text" id="ovhi_infoplus_cancel_codes" name="ovhi_infoplus_cancel_codes" class="regular-text" value="<?php echo esc_attr(implode(', ', (array) $live_settings['infoplus_cancel_codes'])); ?>" />
							<p class="description">Komma-gescheiden lijst met numerieke InfoPlus-statuscodes die als annulering moeten worden geïnterpreteerd (bv. 25,32,39,44). Deze instellingen worden ook naar de daemon geschreven.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Laatste synchronisatie</th>
						<td>
							<?php echo self::get_live_delay_last_sync() ? esc_html(date_i18n(get_option('date_format') . ' H:i:s', self::get_live_delay_last_sync())) : 'Nog niet gesynchroniseerd'; ?>
						</td>
					</tr>
				</table>
				<?php submit_button('Live instellingen opslaan'); ?>
			</form>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: -10px; margin-bottom: 20px;">
				<input type="hidden" name="action" value="ovhi_manual_sync" />
				<?php wp_nonce_field('ovhi_manual_sync_action'); ?>
				<button type="submit" class="button button-secondary">Nu synchroniseren (Handmatig testen)</button>
			</form>

			<hr />

			<h2>Realtime vertragingen</h2>
			<p>Importeer actuele vertragingen en rituitval uit een JSON- of CSV-bestand in de volgende vorm: <code>[{"journey_ref":"...","stop_code":"...","delay_seconds":120,"is_cancelled":false}, ...]</code>.</p>
			<p>Gebruik deze upload wanneer een externe converter of module de NDOV-gegevens beschikbaar maakt als bruikbare ritvertragingen voor Ovalino.</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="ovhi_realtime_upload" />
				<?php wp_nonce_field(self::NONCE_REALTIME_IMPORT); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ovhi_realtime_file">Realtime vertragingen (JSON of CSV)</label></th>
						<td><input type="file" id="ovhi_realtime_file" name="ovhi_realtime_file" accept=".json,.csv" required /></td>
					</tr>
				</table>
				<?php submit_button('Realtime vertragingen importeren'); ?>
			</form>

			<?php if (!empty($debug_counts['realtime_delays'])) : ?>
				<p><strong>Realtime vertragingen opgeslagen:</strong> <?php echo esc_html((string) $debug_counts['realtime_delays']); ?></p>
			<?php endif; ?>

			<h2>Shortcode</h2>
			<p>Gebruik bijvoorbeeld:</p>
			<code>[ov_halte stopplace="NL:S:10006870"]</code>
			<p>Je kunt ook een specifieke haltepaal gebruiken:</p>
			<code>[ov_halte quay="NL:Q:10006870"]</code>
			<p>Of meerdere richtingcodes in één shortcode combineren:</p>
			<code>[ov_halte user_stops="10006870,10006880"]</code>
			<p>Combineer met een NS-stationscode (uit stations.dat, bijv. <code>gn</code> voor Groningen) om de komende zeven treinen te tonen:</p>
			<code>[ov_halte stopplace="NL:S:10006870" station="gn"]</code>
			<p>Optioneel kun je zelf een algemene vertreklink meegeven:</p>
			<code>[ov_halte stopplace="NL:S:10006870" departures_url="https://voorbeeld.nl/vertrektijden"]</code>
			<p>Optioneel: link naar de treindienstregeling (plugin OV Trein Dienstregeling):</p>
			<code>[ov_halte station="gn" train_schedule_url="https://voorbeeld.nl/treindienstregeling/"]</code>
		</div>
		<?php
	}

	public static function handle_upload() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om dit uit te voeren.', 'ovhi'));
		}

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		check_admin_referer(self::NONCE_ACTION);

		$required_files = array(
			'ovhi_chb_file'   => 'chb',
			'ovhi_psa_file'   => 'psa',
			'ovhi_netex_file' => 'netex',
		);

		foreach ($required_files as $field => $label) {
			$uploaded_files = self::get_uploaded_files($field);
			if (empty($uploaded_files)) {
				self::redirect_admin('error', 'Bestand ontbreekt: ' . $label);
			}
			if ($label !== 'netex' && count($uploaded_files) > 1) {
				self::redirect_admin('error', 'Meerdere bestanden niet toegestaan voor ' . $label);
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp_dir = trailingslashit(self::dataset_dir()) . 'tmp-' . gmdate('YmdHis') . '-' . wp_generate_password(8, false, false);
		if (!wp_mkdir_p($temp_dir) && !is_dir($temp_dir)) {
			wp_die(esc_html__('Kon tijdelijke uploadmap niet aanmaken.', 'ovhi'));
		}
		$stage_prefix = 'stage_' . wp_generate_password(10, false, false);

		$saved_files = array(
			'chb' => '',
			'psa' => '',
			'netex' => array(),
		);

		try {
			foreach ($required_files as $field => $label) {
				$uploaded_files = self::get_uploaded_files($field);
				if (empty($uploaded_files)) {
					throw new RuntimeException('Bestand ontbreekt: ' . $label);
				}
				if ($label !== 'netex' && count($uploaded_files) > 1) {
					throw new RuntimeException('Meerdere bestanden niet toegestaan voor ' . $label);
				}

				foreach ($uploaded_files as $index => $file) {
					$filename = sanitize_file_name($file['name']);
					if ($label === 'netex' && count($uploaded_files) > 1) {
						$filename = ($index + 1) . '-' . $filename;
					}
					$destination = trailingslashit($temp_dir) . $filename;

					if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
						throw new RuntimeException('Ongeldig uploadbestand: ' . $filename);
					}

					if (!move_uploaded_file($file['tmp_name'], $destination)) {
						throw new RuntimeException('Upload mislukt voor ' . $filename);
					}

					if ($label === 'netex') {
						$saved_files['netex'][] = $destination;
					} else {
						$saved_files[$label] = $destination;
					}
				}
			}

			self::set_runtime_table_prefix($stage_prefix);
			self::drop_tables_for_prefix($stage_prefix);
			self::create_tables();

			$counts = array(
				'stopplaces' => 0,
				'quays' => 0,
				'lines' => 0,
				'stop_lines' => 0,
				'stop_line_patterns' => 0,
				'journeys' => 0,
				'stop_offsets' => 0,
			);

			self::import_psa($saved_files['psa']);
			$netex_counts = self::import_netex($saved_files['netex']);
			$required_quays = self::get_assigned_quay_codes();
			$counts = array_merge($counts, self::import_chb($saved_files['chb'], $required_quays));
			$counts['lines'] = $netex_counts['lines'];
			$counts['stop_lines'] = $netex_counts['stop_lines'];
			$counts['stop_line_patterns'] = $netex_counts['stop_line_patterns'];
			$counts['journeys'] = $netex_counts['journeys'];
			$counts['stop_offsets'] = $netex_counts['stop_offsets'];
			$counts['notices'] = $netex_counts['notices'];
			$counts['notice_assignments'] = $netex_counts['notice_assignments'];

			self::clear_runtime_table_prefix();
			self::swap_tables_from_prefix($stage_prefix);

			update_option(
				self::OPTION_IMPORT_INFO,
				array(
					'imported_at' => current_time('mysql'),
					'files'       => array_merge(
						array(wp_basename($saved_files['chb']), wp_basename($saved_files['psa'])),
						array_map('wp_basename', $saved_files['netex'])
					),
					'counts'      => $counts,
				),
				false
			);

			if (is_dir($temp_dir)) {
				self::delete_directory($temp_dir);
			}
		} catch (Throwable $exception) {
			self::cleanup_import_workspace($temp_dir, $stage_prefix);
			self::redirect_admin('error', $exception->getMessage());
		}

		self::redirect_admin('success');
	}

	public static function handle_realtime_upload() {
		if (!current_user_can('manage_options')) {
		wp_die(esc_html__('Je hebt geen rechten om dit uit te voeren.', 'ovhi'));
		}

		check_admin_referer(self::NONCE_REALTIME_IMPORT);

		if (empty($_FILES['ovhi_realtime_file']) || empty($_FILES['ovhi_realtime_file']['tmp_name'])) {
		self::redirect_admin('error', 'Geen bestand geüpload voor realtime vertragingen.');
		}

		$file = $_FILES['ovhi_realtime_file'];
		if (!is_uploaded_file($file['tmp_name'])) {
		self::redirect_admin('error', 'Ongeldig uploadbestand voor realtime vertragingen.');
		}

		$path = $file['tmp_name'];
		$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

		try {
		if ($extension === 'json') {
			$entries = self::parse_realtime_delay_json_file($path);
		} elseif ($extension === 'csv') {
			$entries = self::parse_realtime_delay_csv_file($path);
		} else {
			self::redirect_admin('error', 'Ondersteund bestandstype voor realtimevertragingen is alleen JSON of CSV.');
		}

		if (empty($entries)) {
			self::redirect_admin('error', 'Er zijn geen vertragingrecords gevonden in het bestand.');
		}

		$count = self::upsert_realtime_delay_entries($entries);
		self::redirect_admin('success', 'Realtime vertragingen geïmporteerd: ' . $count);
		} catch (Throwable $exception) {
		self::redirect_admin('error', $exception->getMessage());
		}
	}

	private static function parse_realtime_delay_json_file($path) {
		$content = file_get_contents($path);
		if ($content === false) {
		throw new RuntimeException('Kon realtimebestand niet lezen.');
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
		throw new RuntimeException('Ongeldig JSON-formaat voor realtimevertragingen.');
		}

		return self::parse_realtime_delay_entries(self::extract_realtime_items($data));
	}

	private static function extract_realtime_items(array $data) {
		if (empty($data)) {
		return array();
		}

		// Handle common envelope keys used by various APIs.
		$container_keys = array('data', 'results', 'entries', 'delays', 'items', 'records');
		foreach ($container_keys as $key) {
		if (isset($data[$key]) && is_array($data[$key])) {
			return self::extract_realtime_items($data[$key]);
		}
		}

		// If the current layer already contains an indexed collection of rows,
		// return it directly.
		if (array_values($data) === $data) {
			foreach ($data as $item) {
				if (is_array($item)) {
					return $data;
				}
			}
		}

		// If the current layer looks like a single realtime record object, return
		// it as a single-item array so it can still be parsed.
		$journey_fields = array('journey_ref', 'journeyRef', 'journey', 'journey_id', 'journeyId', 'trip_id', 'tripId', 'trip', 'tripRef', 'tripref', 'train_id', 'trainId', 'trainid', 'ritid');
		$stop_code_fields = array('stop_code', 'stopCode', 'stop', 'stop_id', 'stopId', 'station_code', 'stationCode', 'halte_code', 'haltecode', 'quay_code', 'quayCode', 'user_stop_code', 'userStopCode', 'stop_point_ref', 'stopPointRef', 'stopPointCode', 'stop_point_code', 'stopplace_code');
		if (self::get_realtime_field_value($data, $journey_fields) !== null && self::get_realtime_field_value($data, $stop_code_fields) !== null) {
			return array($data);
		}

		// Otherwise search deeper for the first array of records.
		foreach ($data as $value) {
		if (is_array($value)) {
			$items = self::extract_realtime_items($value);
			if (!empty($items)) {
				return $items;
			}
		}
		}

		return array();
	}

	private static function get_realtime_field_value($item, array $field_names) {
		$result = self::get_realtime_field_and_key($item, $field_names);
		return $result['value'];
	}

	private static function get_realtime_field_and_key($item, array $field_names) {
		$result = array('key' => null, 'value' => null);
		if (!is_array($item)) {
		return $result;
		}

		foreach ($field_names as $field_name) {
			if (array_key_exists($field_name, $item) && $item[$field_name] !== null && $item[$field_name] !== '') {
				return array('key' => $field_name, 'value' => $item[$field_name]);
			}
		}

		foreach ($item as $key => $value) {
			if (!is_string($key)) {
				continue;
			}
			foreach ($field_names as $field_name) {
				if (strcasecmp($key, $field_name) === 0 && $value !== null && $value !== '') {
					return array('key' => $key, 'value' => $value);
				}
			}
		}

		foreach ($item as $value) {
			if (is_array($value)) {
				$nested = self::get_realtime_field_and_key($value, $field_names);
				if ($nested['value'] !== null) {
					return $nested;
				}
			}
		}

		return $result;
	}

	private static function parse_realtime_delay_csv_file($path) {
		$handle = fopen($path, 'r');
		if ($handle === false) {
		throw new RuntimeException('Kon realtimebestand niet openen.');
		}

		$rows = array();
		$headers = array();
		while (($row = fgetcsv($handle)) !== false) {
		if (empty($headers)) {
			$headers = array_map('trim', $row);
			continue;
		}
		if (count($row) !== count($headers)) {
			continue;
		}
		$rows[] = array_combine($headers, $row);
		}
		fclose($handle);

		return self::parse_realtime_delay_entries($rows);
	}

	private static function parse_realtime_delay_entries(array $items) {
		$entries = array();
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			$journey_ref = '';
			$stop_code = '';
			$delay_seconds = 0;
			$is_cancelled = false;

			$field_candidates = array(
				'journey_ref' => array('journey_ref', 'journeyRef', 'journey', 'journey_id', 'journeyId', 'trip_id', 'tripId', 'trip', 'tripRef', 'tripref', 'train_id', 'trainId', 'trainid', 'ritid', 'journeyNumber', 'journey_number', 'vehicleJourneyRef', 'serviceJourneyRef'),
				'stop_code' => array('stop_code', 'stopCode', 'stop', 'stop_id', 'stopId', 'station_code', 'stationCode', 'halte_code', 'haltecode', 'quay_code', 'quayCode', 'user_stop_code', 'userStopCode', 'stop_point_ref', 'stopPointRef', 'stopPointCode', 'stop_point_code', 'stopplace_code', 'userStopCode', 'station', 'stationCode'),
				'delay_seconds' => array('delay_seconds', 'delaySeconds', 'delay', 'delay_minutes', 'delayMinutes', 'delayInSeconds', 'delay_in_seconds', 'lateness', 'lateness_seconds', 'vertrekvertraging', 'vertrekVertraging', 'vertraging'),
				'is_cancelled' => array('is_cancelled', 'isCancelled', 'cancelled', 'canceled', 'isCanceled', 'status', 'train_status', 'trip_status', 'tripStatus', 'reis_status', 'cancelReason', 'cancel_reason', 'cancellation', 'cancellationStatus', 'cancellation_reason'),
			);

			$journey_ref_value = self::get_realtime_field_value($item, $field_candidates['journey_ref']);
			if ($journey_ref_value !== null && trim((string) $journey_ref_value) !== '') {
				$journey_ref = trim((string) $journey_ref_value);
			}

			$stop_code_value = self::get_realtime_field_value($item, $field_candidates['stop_code']);
			if ($stop_code_value !== null && trim((string) $stop_code_value) !== '') {
				$stop_code = trim((string) $stop_code_value);
			}

			$delay_field = self::get_realtime_field_and_key($item, $field_candidates['delay_seconds']);
			if ($delay_field['value'] !== null && trim((string) $delay_field['value']) !== '') {
				$delay_seconds = self::normalize_delay_value($delay_field['value']);
				if ($delay_field['key'] !== null && stripos($delay_field['key'], 'minute') !== false) {
					$delay_seconds = $delay_seconds * 60;
				}
			}

			$expected_time = '';
			$expected_time_fields = array(
				'expected_time',
				'expectedTime',
				'expectedDepartureTime',
				'expectedArrivalTime',
				'expected_departure_time',
				'expected_arrival_time',
				'expectedDepartureTimestamp',
				'expectedArrivalTimestamp',
				'plannedDepartureTime',
				'plannedArrivalTime',
				'planned_departure_time',
				'planned_arrival_time',
				'scheduledDepartureTime',
				'scheduledArrivalTime',
				'scheduled_departure_time',
				'scheduled_arrival_time',
				'actualDepartureTime',
				'actualArrivalTime',
				'realTimeDeparture',
				'realTimeArrival',
				'real_time',
				'targetDepartureTime',
				'targetArrivalTime',
				'target_departure_time',
				'target_arrival_time',
				'estimatedTime',
				'estimated_time',
				'expected',
				'time',
				'tijd',
			);
			$expected_value = self::get_realtime_field_value($item, $expected_time_fields);
			if ($expected_value !== null && trim((string) $expected_value) !== '') {
				$raw = trim((string) $expected_value);
				if (preg_match('/^\d{10}(?:\d{3})?$/', $raw)) {
					$timestamp = (int) $raw;
					if (strlen($raw) === 13) {
						$timestamp = (int) round($timestamp / 1000);
					}
					if ($timestamp > 0) {
						$expected_time = date('Y-m-d H:i:s', $timestamp);
					}
				} else {
					$ts = strtotime($raw);
					if ($ts !== false && $ts > 0) {
						$expected_time = date('Y-m-d H:i:s', $ts);
					} elseif (preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $raw)) {
						$today = date('Y-m-d');
						$expected_time = $today . ' ' . $raw . (strlen($raw) === 5 ? ':00' : '');
					}
				}
			}

			$live_settings = self::get_live_delay_settings();
			$info_cancel_codes = array();
			if (isset($live_settings['infoplus_cancel_codes']) && is_array($live_settings['infoplus_cancel_codes'])) {
				foreach ($live_settings['infoplus_cancel_codes'] as $c) {
					if (is_numeric($c)) {
						$info_cancel_codes[] = (int) $c;
					}
				}
			}
			if (empty($info_cancel_codes)) {
				$info_cancel_codes = array(25, 32, 34, 39, 44);
			}

			$is_cancel_field = null;
			foreach ($field_candidates['is_cancelled'] as $field) {
				$cancel_value = self::get_realtime_field_value($item, array($field));
				if ($cancel_value !== null) {
					$raw_val = trim((string) $cancel_value);
					if ($raw_val !== '' && is_numeric($raw_val)) {
						$code = (int) $raw_val;
						if (in_array($field, array('is_cancelled', 'isCancelled', 'cancelled', 'canceled', 'isCanceled'), true)) {
							$is_cancelled = self::normalize_boolean_value($raw_val);
						} elseif (in_array($code, $info_cancel_codes, true)) {
							$is_cancelled = true;
						}
					} else {
						$is_cancelled = self::normalize_boolean_value($raw_val);
					}
					break;
				}
			}

			// If no explicit cancellation field matched, search any text field for cancel tokens.
			if (!$is_cancelled && self::detect_cancel_in_text_recursive($item)) {
				$is_cancelled = true;
			}

			if ($journey_ref !== '' && $stop_code !== '') {
				$alt_journey_refs = array($journey_ref);
				if (strpos($journey_ref, ':') !== false) {
					$parts = explode(':', $journey_ref);
					$last_part = end($parts);
					if ($last_part !== '' && $last_part !== $journey_ref) {
						$alt_journey_refs[] = $last_part;
					}
				}

				$alt_stop_codes = array($stop_code);
				if (preg_match('/^NL:Q:(\d+)$/i', $stop_code, $m)) {
					$alt_stop_codes[] = $m[1];
				} elseif (preg_match('/^\d+$/', $stop_code)) {
					$alt_stop_codes[] = 'NL:Q:' . $stop_code;
				}

				foreach ($alt_journey_refs as $j_ref) {
					foreach ($alt_stop_codes as $s_code) {
						$entries[] = array(
							'journey_ref'   => $j_ref,
							'stop_code'     => $s_code,
							'delay_seconds' => $delay_seconds,
							'is_cancelled'  => $is_cancelled ? 1 : 0,
							'expected_time' => $expected_time,
						);
					}
				}
			}
		}

		return $entries;
	}

	private static function normalize_delay_value($value) {
		if (is_numeric($value)) {
			return (int) round((float) $value);
		}

		if (is_string($value)) {
			$clean = trim(strtolower($value));
			if ($clean === '') {
				return 0;
			}

			if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $clean)) {
				return self::time_to_seconds($clean);
			}

			if (preg_match('/^pt(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $clean, $matches)) {
				$hours = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
				$minutes = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
				$seconds = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0;
				return $hours * HOUR_IN_SECONDS + $minutes * MINUTE_IN_SECONDS + $seconds;
			}

			$clean = trim(str_replace(array('+', 'min', 'minutes', 'mins'), '', $clean));
			if ($clean === '') {
				return 0;
			}
			return (int) round((float) $clean);
		}

		return 0;
	}

	private static function normalize_boolean_value($value) {
		if (is_bool($value)) {
			return $value;
		}

		if (is_numeric($value)) {
			return ((int) $value) !== 0;
		}

		if (is_string($value)) {
			$value = strtolower(trim($value));
			if ($value === '') {
				return false;
			}

			$truthy_values = array('1', 'true', 'yes', 'y', 'ja', 'oui');
			if (in_array($value, $truthy_values, true)) {
				return true;
			}

			$cancel_tokens = array(
				'cancelled',
				'canceled',
				'vervallen',
				'geannuleerd',
				'annulering',
				'rijdt niet',
				'rijdtniet',
				'rijdetniet',
				'niet gereden',
				'nietgereden',
				'nietopgevoerd',
				'notdriving',
				'deleted',
				'cancel',
			);
			// Use word-boundary regex matching so tokens inside other words/numbers
			// do not accidentally trigger a cancellation.
			foreach ($cancel_tokens as $token) {
				$pattern = '/\b' . preg_quote($token, '/') . '\b/i';
				if (preg_match($pattern, $value) === 1) {
					return true;
				}
			}

			return false;
		}

		return false;
	}


	/**
	 * Detect cancellation tokens in free-form text without treating numeric
	 * values like "5" as boolean true. Useful for scanning arbitrary feed
	 * fields where numbers (delays) may otherwise be misinterpreted as
	 * cancellation flags.
	 */
	private static function detect_cancel_in_text($text) {
		if (!is_string($text) || trim($text) === '') {
			return false;
		}
		$value = strtolower(trim($text));
		$cancel_tokens = array('cancelled','canceled','vervallen','geannuleerd','annulering','rijdt niet','rijdtniet','rijdetniet','niet gereden','nietgereden','nietopgevoerd','notdriving','deleted','cancel');
		foreach ($cancel_tokens as $token) {
			$pattern = '/\b' . preg_quote($token, '/') . '\b/i';
			if (preg_match($pattern, $value) === 1) {
				return true;
			}
		}
		return false;
	}


	private static function detect_cancel_in_text_recursive($item) {
		if (is_string($item)) {
			return self::detect_cancel_in_text($item);
		}
		if (!is_array($item)) {
			return false;
		}
		foreach ($item as $value) {
			if (self::detect_cancel_in_text_recursive($value)) {
				return true;
			}
		}
		return false;
	}

	private static function cleanup_expired_realtime_delay_entries() {
		global $wpdb;

		$table = self::table('realtime_delays');
		$result = $wpdb->query($wpdb->prepare(
			'DELETE FROM ' . $table . ' WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)',
			self::DELAY_RETENTION_SECONDS
		));
		if ($result === false) {
			error_log('OVHI cleanup error: ' . $wpdb->last_error);
		}
	}

	private static function upsert_realtime_delay_entries(array $rows) {
		global $wpdb;

		if (empty($rows)) {
			self::cleanup_expired_realtime_delay_entries();
			return 0;
		}

		/*
		 * The live API identifies a journey by its public, short number (for
		 * example EBS:1025). NeTEx uses the full ServiceJourney id in the
		 * timetable. Store an additional record using that exact id so every
		 * frontend can keep using its normal, exact journey lookup.
		 */
		try {
			$rows = self::add_scheduled_journey_delay_entries($rows);
		} catch (Throwable $exception) {
			// A failed timetable lookup must never block the raw live API records.
			error_log('OVHI journey matching error: ' . $exception->getMessage());
		}
		$unique_rows = array();
		foreach ($rows as $row) {
			$journey_ref = isset($row['journey_ref']) ? trim((string) $row['journey_ref']) : '';
			$stop_code = isset($row['stop_code']) ? trim((string) $row['stop_code']) : '';
			$journey_ref = substr($journey_ref, 0, 120);
			$stop_code = substr($stop_code, 0, 100);
			if ($journey_ref === '' || $stop_code === '') {
				continue;
			}
			$row['journey_ref'] = $journey_ref;
			$row['stop_code'] = $stop_code;
			$unique_rows[$journey_ref . "\0" . $stop_code] = $row;
		}
		$rows = array_values($unique_rows);
		if (empty($rows)) {
			self::cleanup_expired_realtime_delay_entries();
			return 0;
		}

		$table = self::table('realtime_delays');
		$chunks = array_chunk($rows, 250);

		foreach ($chunks as $chunk) {
			$values = array();
			$queries = array();
			foreach ($chunk as $row) {
				// Do not persist expected_time (unreliable). Store only journey_ref, stop_code, delay_seconds and is_cancelled.
				$queries[] = "(%s, %s, %d, %d, NOW())";
				$values[] = isset($row['journey_ref']) ? $row['journey_ref'] : '';
				$values[] = isset($row['stop_code']) ? $row['stop_code'] : '';
				$values[] = isset($row['delay_seconds']) ? (int) $row['delay_seconds'] : 0;
				$values[] = isset($row['is_cancelled']) ? (int) $row['is_cancelled'] : 0;
			}

			$sql = 'INSERT INTO ' . $table . ' (journey_ref, stop_code, delay_seconds, is_cancelled, updated_at) VALUES ' . implode(', ', $queries) . ' ON DUPLICATE KEY UPDATE delay_seconds = VALUES(delay_seconds), is_cancelled = VALUES(is_cancelled), updated_at = NOW()';
			$result = $wpdb->query($wpdb->prepare($sql, $values));

			if ($result === false) {
				throw new RuntimeException('Kon realtimevertragingen niet opslaan: ' . $wpdb->last_error);
			}
		}

		self::cleanup_expired_realtime_delay_entries();
		return count($rows);
	}

	private static function add_scheduled_journey_delay_entries(array $rows) {
		global $wpdb;

		$journey_numbers = array();
		$stop_codes = array();
		$rows_by_key = array();

		foreach ($rows as $row) {
			$journey_ref = isset($row['journey_ref']) ? trim((string) $row['journey_ref']) : '';
			$stop_code = isset($row['stop_code']) ? trim((string) $row['stop_code']) : '';
			if ($journey_ref === '' || $stop_code === '') {
				continue;
			}

			$journey_number = self::get_realtime_journey_number($journey_ref);
			if ($journey_number === '') {
				continue;
			}
			$journey_numbers[$journey_number] = true;

			foreach (self::get_realtime_stop_code_variants($stop_code) as $variant) {
				$stop_codes[$variant] = true;
				$rows_by_key[$journey_number . "\0" . $variant] = $row;
			}
		}

		if (empty($journey_numbers) || empty($stop_codes)) {
			return $rows;
		}

		$journey_numbers = array_keys($journey_numbers);
		$stop_codes = array_keys($stop_codes);

		/*
		 * Veiligheidsgrenzen: voorkom dat één synchronisatieronde onbegrensd
		 * geheugen kan opeisen, ongeacht hoe groot de binnenkomende live-feed
		 * is of hoeveel dienstregelingsregels er (per ongeluk, door hergebruikte
		 * journey-nummers tussen vervoerders) matchen. Zonder deze grenzen kan
		 * de JOIN hieronder een combinatorische explosie van rijen opleveren
		 * en PHP's memory_limit fataal doen vastlopen (zie OVHI-incident 23/07).
		 */
		$max_journey_chunk_size = 100;
		$max_stop_chunk_size = 200;
		$max_rows_per_query = 5000;
		$max_total_matches = 20000;

		$journey_chunks = array_chunk($journey_numbers, $max_journey_chunk_size);
		$stop_chunks = array_chunk($stop_codes, $max_stop_chunk_size);

		$total_matches = 0;

		foreach ($journey_chunks as $journey_chunk) {
			foreach ($stop_chunks as $stop_chunk) {
				if ($total_matches >= $max_total_matches) {
					error_log('OVHI: matching-limiet van ' . $max_total_matches . ' bereikt, resterende combinaties overgeslagen om geheugenuitputting te voorkomen.');
					break 2;
				}

				$journey_placeholders = implode(',', array_fill(0, count($journey_chunk), '%s'));
				$stop_placeholders = implode(',', array_fill(0, count($stop_chunk), '%s'));

				/*
				 * Matcht bij voorkeur op het echte journey_number (uit het
				 * NeTEx <PrivateCode type="JourneyNumber">-veld op
				 * ServiceJourney-niveau - hetzelfde veld als "JourneyNumber"
				 * in de live KV78turbo-feed). Dit werkt voor elke vervoerder,
				 * ongeacht hoe die zijn ServiceJourney-id/journey_ref opbouwt.
				 *
				 * Voor rijen die (nog) geen journey_number hebben - bv. data
				 * die is geïmporteerd vóór deze wijziging, of een exporteur
				 * die dit veld niet vult - valt de query terug op de oude
				 * heuristiek die de laatste twee streepjes-delen uit het
				 * journey_ref/id haalt. Die heuristiek klopt toevallig voor
				 * Qbuzz's ID-opbouw (...:<blok>-<ritnr>-<variant>) maar niet
				 * per se voor andere vervoerders (zoals EBS: ...:<lijn>-
				 * <blok>-<ritnr>, waar de heuristiek "<blok>" ipv "<ritnr>"
				 * teruggaf) - vandaar dat Qbuzz altijd al matchte en EBS niet.
				 */
				$query = '
					SELECT DISTINCT
						j.journey_ref,
						st.user_stop_code,
						COALESCE(NULLIF(j.journey_number, \'\'), SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(j.journey_ref, CHAR(58), -1), CHAR(45), -2), CHAR(45), 1)) AS journey_number
					FROM ' . self::table('journeys') . ' j
					INNER JOIN ' . self::table('stop_offsets') . ' so
						ON so.service_journey_pattern_ref = j.service_journey_pattern_ref
						AND so.time_demand_type_ref = j.time_demand_type_ref
					INNER JOIN ' . self::table('scheduled_stops') . ' st
						ON st.scheduled_stop_point_ref = so.scheduled_stop_point_ref
					WHERE COALESCE(NULLIF(j.journey_number, \'\'), SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(j.journey_ref, CHAR(58), -1), CHAR(45), -2), CHAR(45), 1)) IN (' . $journey_placeholders . ')
						AND st.user_stop_code IN (' . $stop_placeholders . ')
					LIMIT ' . (int) $max_rows_per_query;

				$params = array_merge($journey_chunk, $stop_chunk);
				$matches = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

				if ($matches === null) {
					error_log('OVHI matching-query mislukt: ' . $wpdb->last_error);
					continue;
				}

				if (count($matches) >= $max_rows_per_query) {
					error_log('OVHI: matching-query raakte de LIMIT van ' . $max_rows_per_query . ' rijen voor dit segment; sommige matches kunnen ontbreken. Overweeg de matching te verfijnen (bv. op vervoerder) als dit vaak voorkomt.');
				}

				foreach ($matches as $match) {
					$journey_number = isset($match['journey_number']) ? (string) $match['journey_number'] : '';
					$stop_code = isset($match['user_stop_code']) ? (string) $match['user_stop_code'] : '';
					$key = $journey_number . "\0" . $stop_code;
					if ($journey_number === '' || $stop_code === '' || empty($match['journey_ref']) || !isset($rows_by_key[$key])) {
						continue;
					}

					$source = $rows_by_key[$key];
					$source['journey_ref'] = (string) $match['journey_ref'];
					$source['stop_code'] = $stop_code;
					$rows[] = $source;

					// The map uses the quay code from the API, while the stop timetable
					// uses user_stop_code. Preserve both exact stop-code representations.
					foreach (self::get_realtime_stop_code_variants(isset($rows_by_key[$key]['stop_code']) ? $rows_by_key[$key]['stop_code'] : '') as $source_stop_code) {
						$source['stop_code'] = $source_stop_code;
						$rows[] = $source;
					}

					$total_matches++;
				}
			}
		}

		return $rows;
	}

	private static function get_realtime_journey_number($journey_ref) {
		$journey_ref = trim((string) $journey_ref);
		if ($journey_ref === '') {
			return '';
		}

		$parts = explode(':', $journey_ref);
		return trim((string) end($parts));
	}

	private static function get_realtime_journey_ref_variants($journey_ref) {
		$journey_ref = trim((string) $journey_ref);
		if ($journey_ref === '') {
			return array();
		}

		$variants = array($journey_ref);
		// If the journey_ref is namespaced (e.g. NL:...:longref), include the short last part
		if (strpos($journey_ref, ':') !== false) {
			$parts = explode(':', $journey_ref);
			$short_ref = trim((string) end($parts));
			if ($short_ref !== '' && $short_ref !== $journey_ref) {
				$variants[] = $short_ref;
			}
		}

		// If the ref contains only digits, include variants without leading zeros
		if (preg_match('/^\d+$/', $journey_ref)) {
			$no_leading = ltrim($journey_ref, '0');
			if ($no_leading !== '' && $no_leading !== $journey_ref) {
				$variants[] = $no_leading;
			}
			// Special-case: NS sometimes prefixes cancelled or special journey numbers with '300' (e.g. 3003617)
			// Include a variant with the '300' prefix stripped so that matching still works
			if (preg_match('/^300(\d{3,})$/', $journey_ref, $m)) {
				$variants[] = $m[1];
			}
		}

		// Also, if the short_ref (from namespaced ref) is numeric, apply the same numeric rules
		if (isset($short_ref) && preg_match('/^\d+$/', $short_ref)) {
			$no_leading = ltrim($short_ref, '0');
			if ($no_leading !== '' && $no_leading !== $short_ref) {
				$variants[] = $no_leading;
			}
			if (preg_match('/^300(\d{3,})$/', $short_ref, $m2)) {
				$variants[] = $m2[1];
			}
		}

		return array_values(array_unique($variants));
	}

	private static function get_realtime_stop_code_variants($stop_code) {
		$stop_code = trim((string) $stop_code);
		if ($stop_code === '') {
			return array();
		}
		if (preg_match('/^NL:Q:(\d+)$/i', $stop_code, $matches)) {
			return array($stop_code, $matches[1]);
		}
		if (preg_match('/^\d+$/', $stop_code)) {
			return array($stop_code, 'NL:Q:' . $stop_code);
		}
		return array($stop_code);
	}
	private static function redirect_admin($notice, $message = '') {
		$url = add_query_arg(
		array(
			'page' => 'ov-halte-importer',
			'ovhi_notice' => $notice,
			'ovhi_message' => rawurlencode($message),
		),
		admin_url('admin.php')
		);
		wp_safe_redirect($url);
		exit;
	}

	private static function clear_tables() {
		global $wpdb;
		$tables = array('notice_assignments', 'notices', 'stop_offsets', 'journeys', 'availability', 'stop_line_patterns', 'stop_lines', 'lines', 'assignments', 'scheduled_stops', 'quays', 'stopplaces');
		foreach ($tables as $table) {
			$wpdb->query('DELETE FROM ' . self::table($table));
		}
	}

	private static function bulk_upsert($table_suffix, array $fields, array $rows, array $update_fields = array()) {
		global $wpdb;
		if (empty($rows)) {
			return;
		}

		$table = self::table($table_suffix);
		$chunks = array_chunk($rows, 250);
		$update_fields = !empty($update_fields) ? $update_fields : $fields;

		foreach ($chunks as $chunk) {
			$queries = array();
			$values = array();

			foreach ($chunk as $row) {
				$placeholders = array();
				foreach ($fields as $field) {
					$placeholders[] = '%s';
					$values[] = isset($row[$field]) ? $row[$field] : '';
				}
				$queries[] = '(' . implode(',', $placeholders) . ')';
			}

			$fields_sql = implode(',', array_map('sanitize_key', $fields));
			$queries_sql = implode(',', $queries);

			$updates = array();
			foreach ($update_fields as $field) {
				$sanitized = sanitize_key($field);
				$updates[] = "{$sanitized} = VALUES({$sanitized})";
			}
			$updates_sql = implode(',', $updates);

			$sql = "INSERT INTO {$table} ({$fields_sql}) VALUES {$queries_sql} ON DUPLICATE KEY UPDATE {$updates_sql}";
			$wpdb->query($wpdb->prepare($sql, $values));
		}
	}

	private static function flush_bulk_rows($table_suffix, array $fields, array &$rows, array $update_fields = array()) {
		if (empty($rows)) {
			return;
		}

		self::bulk_upsert($table_suffix, $fields, $rows, $update_fields);
		$rows = array();
	}

	private static function bulk_upsert_journeys(array $rows) {
		global $wpdb;
		if (empty($rows)) {
			return;
		}

		$table = self::table('journeys');
		$chunks = array_chunk($rows, 250);

		foreach ($chunks as $chunk) {
			$queries = array();
			$values = array();

			foreach ($chunk as $row) {
				$queries[] = '(%s,%s,%s,%s,%s,%s,%s,%s)';
				$values[] = isset($row['journey_ref']) ? $row['journey_ref'] : '';
				$values[] = isset($row['journey_number']) ? $row['journey_number'] : '';
				$values[] = isset($row['journey_signature']) ? $row['journey_signature'] : '';
				$values[] = isset($row['service_journey_pattern_ref']) ? $row['service_journey_pattern_ref'] : '';
				$values[] = isset($row['time_demand_type_ref']) ? $row['time_demand_type_ref'] : '';
				$values[] = isset($row['availability_ref']) ? $row['availability_ref'] : '';
				$values[] = isset($row['availability_from_date']) ? $row['availability_from_date'] : '1970-01-01';
				$values[] = isset($row['departure_seconds']) ? $row['departure_seconds'] : 0;
			}

			$sql = "
				INSERT INTO {$table}
					(journey_ref, journey_number, journey_signature, service_journey_pattern_ref, time_demand_type_ref, availability_ref, availability_from_date, departure_seconds)
				VALUES " . implode(',', $queries) . "
				ON DUPLICATE KEY UPDATE
					journey_ref = IF(VALUES(availability_from_date) >= availability_from_date, VALUES(journey_ref), journey_ref),
					journey_number = IF(VALUES(availability_from_date) >= availability_from_date, VALUES(journey_number), journey_number),
					journey_signature = IF(VALUES(availability_from_date) >= availability_from_date, VALUES(journey_signature), journey_signature),
					service_journey_pattern_ref = IF(VALUES(availability_from_date) >= availability_from_date, VALUES(service_journey_pattern_ref), service_journey_pattern_ref),
					time_demand_type_ref = IF(VALUES(availability_from_date) >= availability_from_date, VALUES(time_demand_type_ref), time_demand_type_ref),
					availability_ref = IF(VALUES(availability_from_date) >= availability_from_date, VALUES(availability_ref), availability_ref),
					availability_from_date = GREATEST(availability_from_date, VALUES(availability_from_date)),
					departure_seconds = IF(VALUES(availability_from_date) >= availability_from_date, VALUES(departure_seconds), departure_seconds)
			";

			$wpdb->query($wpdb->prepare($sql, $values));
		}
	}

	private static function bulk_upsert_availability(array $rows) {
		global $wpdb;
		if (empty($rows)) {
			return;
		}

		$table = self::table('availability');
		$chunks = array_chunk($rows, 250);

		foreach ($chunks as $chunk) {
			$queries = array();
			$values = array();

			foreach ($chunk as $row) {
				$queries[] = '(%s,%s,%s,%s)';
				$values[] = isset($row['availability_ref']) ? $row['availability_ref'] : '';
				$values[] = isset($row['from_date']) ? $row['from_date'] : '1970-01-01';
				$values[] = isset($row['to_date']) ? $row['to_date'] : '1970-01-01';
				$values[] = isset($row['valid_day_bits']) ? $row['valid_day_bits'] : '';
			}

			$sql = "
				INSERT INTO {$table}
					(availability_ref, from_date, to_date, valid_day_bits)
				VALUES " . implode(',', $queries) . "
				ON DUPLICATE KEY UPDATE
					from_date = IF(VALUES(from_date) >= from_date, VALUES(from_date), from_date),
					to_date = IF(VALUES(from_date) >= from_date, VALUES(to_date), to_date),
					valid_day_bits = IF(VALUES(from_date) >= from_date, VALUES(valid_day_bits), valid_day_bits)
			";

			$wpdb->query($wpdb->prepare($sql, $values));
		}
	}
	private static function dataset_dir() {
		$upload = wp_upload_dir();
		return trailingslashit($upload['basedir']) . 'ov-halte-importer';
	}

	private static function delete_directory($directory) {
		if (!is_dir($directory)) {
			return;
		}

		$items = array_diff(scandir($directory), array('.', '..'));
		foreach ($items as $item) {
			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if (is_dir($path)) {
				self::delete_directory($path);
			} elseif (file_exists($path)) {
				unlink($path);
			}
		}
		rmdir($directory);
	}

	private static function xml_reader($file) {
		if (!class_exists('XMLReader')) {
			throw new Exception('XMLReader is niet beschikbaar op deze server.');
		}

		if (!file_exists($file)) {
			throw new Exception('Bestand niet gevonden: ' . wp_basename($file));
		}

		$uri = self::is_gzip($file) ? 'compress.zlib://' . $file : $file;
		$reader = new XMLReader();
		$flags = 0;
		if (!$reader->open($uri, null, $flags)) {
			throw new Exception('Kon XML-bestand niet openen: ' . wp_basename($file));
		}
		return $reader;
	}

	private static function is_gzip($file) {
		return strtolower(substr($file, -3)) === '.gz';
	}

	private static function import_chb($file, array $required_quays = array()) {
		$reader = self::xml_reader($file);
		$stopplace_count = 0;
		$quay_count = 0;

		$stopplace_rows = array();
		$quay_rows = array();

		while ($reader->read()) {
			// Hoofdletterongevoelige check op de localName vanwege eventuele 'ns1:' namespaces
			if ($reader->nodeType !== XMLReader::ELEMENT || strtolower($reader->localName) !== 'stopplace') {
				continue;
			}

			$stopplace = self::parse_chb_stopplace($reader);
			if (empty($stopplace['stopplace_code'])) {
				continue;
			}

			$stopplace_rows[] = array(
				'stopplace_code' => $stopplace['stopplace_code'],
				'stopplace_name' => $stopplace['stopplace_name'],
				'town' => $stopplace['town'],
				'latitude' => $stopplace['latitude'],
				'longitude' => $stopplace['longitude'],
			);
			$stopplace_count++;

			if (!empty($stopplace['quays'])) {
				foreach ($stopplace['quays'] as $quay) {
					if (empty($quay['quay_code'])) {
						continue;
					}

					$quay_rows[] = array(
						'quay_code' => $quay['quay_code'],
						'stopplace_code' => $stopplace['stopplace_code'],
						'quay_name' => $quay['quay_name'],
						'latitude' => $quay['latitude'],
						'longitude' => $quay['longitude'],
					);
					$quay_count++;
				}
			}
		}

    $reader->close();
    
    // Opslaan in database via bulk upsert
    self::bulk_upsert('stopplaces', array('stopplace_code', 'stopplace_name', 'town', 'latitude', 'longitude'), $stopplace_rows);
    self::bulk_upsert('quays', array('quay_code', 'stopplace_code', 'quay_name', 'latitude', 'longitude', 'wheelchair_access', 'step_free_access'), $quay_rows);
    
    return array(
        'stopplaces' => $stopplace_count,
        'quays' => $quay_count,
    );
}

	private static function rd_to_wgs84($x, $y) {
		$x = (float) $x;
		$y = (float) $y;
		if ($x == 0.0 && $y == 0.0) {
			return array('lat' => 0.0, 'lon' => 0.0);
		}
		$dx = ($x - 155000) / 100000;
		$dy = ($y - 463000) / 100000;
		
		$lat = 52.15517 + (3235.65389 * $dy - 32.58297 * pow($dx, 2) - 0.24750 * pow($dy, 2) - 0.84978 * pow($dx, 2) * $dy - 0.06550 * pow($dy, 3)) / 3600;
		$lon = 5.387206 + (5260.52916 * $dx + 105.94684 * $dx * $dy + 2.45656 * $dx * pow($dy, 2) - 0.81885 * pow($dx, 3)) / 3600;
		
		return array(
			'lat' => round($lat, 6),
			'lon' => round($lon, 6),
		);
	}

	private static function parse_chb_stopplace(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'stopplace_code' => '',
			'stopplace_name' => '',
			'town' => '',
			'latitude' => 0.0,
			'longitude' => 0.0,
			'quays' => array(),
			'matched' => false,
		);
		$rd_x = 0.0;
		$rd_y = 0.0;

    while ($reader->read()) {
        $localName = strtolower($reader->localName);

        // Sluiting van de stopplace
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $localName === 'stopplace') {
            break;
        }

        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        switch ($localName) {
            case 'stopplacecode':
                $data['stopplace_code'] = trim($reader->readString());
                break;
            case 'publicname':
                $data['stopplace_name'] = trim($reader->readString());
                break;
            case 'town':
                $data['town'] = trim($reader->readString());
                break;
            case 'latitude':
                $data['latitude'] = (float) trim($reader->readString());
                break;
            case 'longitude':
                $data['longitude'] = (float) trim($reader->readString());
                break;
            case 'rd-x':
                $rd_x = (float) trim($reader->readString());
                break;
            case 'rd-y':
                $rd_y = (float) trim($reader->readString());
                break;
            case 'quay':
                // Open sub-parser voor de quay
                $quay = self::parse_chb_quay($reader);
                if (!empty($quay['quay_code'])) {
                    $data['quays'][] = $quay;
                }
                break;
        }
    }

    if ($rd_x > 0.0 && $rd_y > 0.0 && $data['latitude'] == 0.0) {
        $wgs = self::rd_to_wgs84($rd_x, $rd_y);
        $data['latitude'] = $wgs['lat'];
        $data['longitude'] = $wgs['lon'];
    }

    return $data;
}

	private static function parse_chb_quay(XMLReader $reader) {
    $depth = $reader->depth;
    $data = array(
        'quay_code' => '',
        'quay_name' => '',
        'latitude' => 0.0,
        'longitude' => 0.0,
        'wheelchair_access' => 'unknown',
        'step_free_access' => 'unknown',
    );
    $rd_x = 0.0;
    $rd_y = 0.0;

    while ($reader->read()) {
        $localName = strtolower($reader->localName);

        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $localName === 'quay') {
            break;
        }

        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        if ($localName === 'quaycode') {
            $data['quay_code'] = trim($reader->readString());
        } elseif ($localName === 'quayname') {
            $data['quay_name'] = trim($reader->readString());
        } elseif ($localName === 'latitude') {
            $data['latitude'] = (float) trim($reader->readString());
        } elseif ($localName === 'longitude') {
            $data['longitude'] = (float) trim($reader->readString());
        } elseif ($localName === 'rd-x') {
            $rd_x = (float) trim($reader->readString());
        } elseif ($localName === 'rd-y') {
            $rd_y = (float) trim($reader->readString());
        } elseif ($localName === 'wheelchairaccess') {
            $data['wheelchair_access'] = trim($reader->readString());
        } elseif ($localName === 'stepfreeaccess') {
            $data['step_free_access'] = trim($reader->readString());
        }
    }

    if ($rd_x > 0.0 && $rd_y > 0.0 && $data['latitude'] == 0.0) {
        $wgs = self::rd_to_wgs84($rd_x, $rd_y);
        $data['latitude'] = $wgs['lat'];
        $data['longitude'] = $wgs['lon'];
    }

    return $data;
}

	private static function get_assigned_quay_codes() {
		global $wpdb;

		$rows = $wpdb->get_col('SELECT DISTINCT quay_code FROM ' . self::table('assignments') . " WHERE quay_code <> ''");
		if (empty($rows)) {
			return array();
		}

		return array_values(array_unique(array_filter($rows)));
	}

	private static function get_live_delay_settings() {
		$defaults = array(
			'enabled' => false,
			'endpoint' => '',
			'auth_token' => '',
			'timeout_seconds' => 3,
			'poll_interval_seconds' => 60,
			// Default InfoPlus cancellation codes (integer array)
			'infoplus_cancel_codes' => array(25, 32, 34, 39, 44),
		);

		$settings = get_option(self::OPTION_LIVE_DELAY_SETTINGS, array());
		if (!is_array($settings)) {
			$settings = array();
		}

		// Ensure infoplus_cancel_codes becomes an array if stored as CSV or string
		if (isset($settings['infoplus_cancel_codes']) && is_string($settings['infoplus_cancel_codes'])) {
			$raw = trim($settings['infoplus_cancel_codes']);
			if ($raw === '') {
				$settings['infoplus_cancel_codes'] = array();
			} else {
				$parts = preg_split('/[,;\s]+/', $raw);
				$nums = array();
				foreach ($parts as $p) {
					if (is_numeric($p)) $nums[] = (int) $p;
				}
				$settings['infoplus_cancel_codes'] = $nums;
			}
		}

		return wp_parse_args($settings, $defaults);
	}

	public static function add_cron_intervals($schedules) {
		if (!is_array($schedules)) {
			$schedules = array();
		}
		if (!isset($schedules['every_minute'])) {
			$schedules['every_minute'] = array(
				'interval' => 60,
				'display'  => 'Elke minuut',
			);
		}
		return $schedules;
	}

	public static function handle_async_sync_request() {
		if (isset($_GET['ovhi_async_sync']) && !empty($_GET['ovhi_async_sync'])) {
			$token = sanitize_text_field(wp_unslash($_GET['ovhi_async_sync']));
			if (!wp_verify_nonce($token, 'ovhi_async_sync_nonce')) {
				return;
			}
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
			}
			self::fetch_remote_realtime_delays();
			exit;
		}
	}

	private static function save_live_delay_settings(array $settings) {
		$allowed = array(
			'enabled' => isset($settings['enabled']) ? boolval($settings['enabled']) : false,
			'endpoint' => isset($settings['endpoint']) ? trim($settings['endpoint']) : '',
			'auth_token' => isset($settings['auth_token']) ? trim($settings['auth_token']) : '',
			'timeout_seconds' => max(1, (int) $settings['timeout_seconds']),
			'poll_interval_seconds' => max(10, (int) $settings['poll_interval_seconds']),
			// allow passing infoplus_cancel_codes either as array or CSV string
			'infoplus_cancel_codes' => isset($settings['infoplus_cancel_codes']) ? $settings['infoplus_cancel_codes'] : array(),
		);

		// Normalize infoplus_cancel_codes to array of ints
		$codes = array();
		if (is_string($allowed['infoplus_cancel_codes'])) {
			$parts = preg_split('/[,;\s]+/', $allowed['infoplus_cancel_codes']);
			foreach ($parts as $p) {
				if (is_numeric($p)) $codes[] = (int) $p;
			}
		} elseif (is_array($allowed['infoplus_cancel_codes'])) {
			foreach ($allowed['infoplus_cancel_codes'] as $p) {
				if (is_numeric($p)) $codes[] = (int) $p;
			}
		}
		$allowed['infoplus_cancel_codes'] = array_values(array_unique($codes));

		update_option(self::OPTION_LIVE_DELAY_SETTINGS, $allowed, false);

		// Also write a JSON file next to the plugin so the daemon can read the same codes
		try {
			$plugin_dir = plugin_dir_path(__FILE__);
			$file = $plugin_dir . 'infoplus_cancel_codes.json';
			@file_put_contents($file, wp_json_encode($allowed['infoplus_cancel_codes']));
		} catch (Throwable $e) {
			// ignore file write errors but log for debugging
			error_log('OVHI: kon infoplus-codes niet naar bestand schrijven: ' . $e->getMessage());
		}

		if (function_exists('wp_clear_scheduled_hook')) {
			wp_clear_scheduled_hook('ovhi_cron_sync_realtime_delays');
			if ($allowed['enabled'] && !empty($allowed['endpoint'])) {
				if (!wp_next_scheduled('ovhi_cron_sync_realtime_delays')) {
					wp_schedule_event(time(), 'every_minute', 'ovhi_cron_sync_realtime_delays');
				}
			}
		}
	}

	private static function get_live_delay_last_sync() {
		return (int) get_option(self::OPTION_LIVE_DELAY_LAST_SYNC, 0);
	}

	private static function set_live_delay_last_sync($timestamp) {
		update_option(self::OPTION_LIVE_DELAY_LAST_SYNC, (int) $timestamp, false);
	}

	private static function should_fetch_live_delays($interval_seconds) {
		$last_sync = self::get_live_delay_last_sync();
		return (time() - $last_sync) >= max(10, (int) $interval_seconds);
	}


	public static function fetch_remote_realtime_delays() {
		$settings = self::get_live_delay_settings();
		if (empty($settings['enabled']) || empty($settings['endpoint'])) {
			return 0;
		}

		$args = array(
			'timeout' => max(5, (int) $settings['timeout_seconds']),
			'sslverify' => false, // Voorkomt SSL-handshake problemen tussen servers
			'headers' => array(
				'Accept' => 'application/json',
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			),
		);

		if (!empty($settings['auth_token'])) {
			$args['headers']['Authorization'] = 'Bearer ' . $settings['auth_token'];
		}

		$endpoint_url = $settings['endpoint'];
		if (strpos($endpoint_url, 'active_only') === false) {
			$endpoint_url = add_query_arg(array('active_only' => '1', 'limit' => '0'), $endpoint_url);
		}

		$response = wp_remote_get($endpoint_url, $args);
		if (is_wp_error($response)) {
			error_log('OVHI HTTP Error: ' . $response->get_error_message());
			return 0;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			error_log('OVHI HTTP Status Code: ' . $code);
			return 0;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if (!is_array($data)) {
			error_log('OVHI JSON Decode Error: Geen geldige JSON ontvangen');
			return 0;
		}

		$items = self::extract_realtime_items($data);
		if (empty($items) || !is_array($items)) {
			self::set_live_delay_last_sync(time());
			return 0;
		}

		$entries = self::parse_realtime_delay_entries($items);
		if (empty($entries)) {
			error_log('OVHI Parse Error: Geen matchende velden (journey_ref/stop_code) gevonden in de JSON items.');
			self::set_live_delay_last_sync(time());
			return 0;
		}

		try {
			$count = self::upsert_realtime_delay_entries($entries);
		} catch (Throwable $exception) {
			error_log('OVHI live sync error: ' . $exception->getMessage());
			self::cleanup_expired_realtime_delay_entries();
			self::set_live_delay_last_sync(time());
			return 0;
		}
		self::set_live_delay_last_sync(time());
		return $count;
	}

	private static function sync_remote_realtime_delays_if_needed() {
		static $done = false;
		if ($done) {
			return;
		}
		$done = true;

		$settings = self::get_live_delay_settings();
		if (empty($settings['enabled']) || empty($settings['endpoint'])) {
			return;
		}

		if (!self::should_fetch_live_delays($settings['poll_interval_seconds'])) {
			return;
		}

		self::set_live_delay_last_sync(time());

		$async_url = add_query_arg('ovhi_async_sync', wp_create_nonce('ovhi_async_sync_nonce'), site_url('/'));
		wp_remote_post($async_url, array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
		));
	}

	public static function maybe_sync_remote_realtime_delays() {
		if (is_admin()) {
			return;
		}

		self::sync_remote_realtime_delays_if_needed();
	}

	public static function handle_live_settings_save() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om deze instellingen op te slaan.', 'ovhi'));
		}

		check_admin_referer(self::NONCE_SAVE_LIVE_SETTINGS);

		$settings = array(
			'enabled' => isset($_POST['ovhi_live_enabled']) ? true : false,
			'endpoint' => isset($_POST['ovhi_live_endpoint']) ? sanitize_text_field(wp_unslash($_POST['ovhi_live_endpoint'])) : '',
			'auth_token' => isset($_POST['ovhi_live_auth_token']) ? sanitize_text_field(wp_unslash($_POST['ovhi_live_auth_token'])) : '',
			'timeout_seconds' => isset($_POST['ovhi_live_timeout_seconds']) ? (int) $_POST['ovhi_live_timeout_seconds'] : 3,
			'poll_interval_seconds' => isset($_POST['ovhi_live_poll_interval_seconds']) ? (int) $_POST['ovhi_live_poll_interval_seconds'] : 60,
			'infoplus_cancel_codes' => isset($_POST['ovhi_infoplus_cancel_codes']) ? sanitize_text_field(wp_unslash($_POST['ovhi_infoplus_cancel_codes'])) : '',
		);

		self::save_live_delay_settings($settings);

		$redirect = add_query_arg(
			array(
				'page' => 'ov-halte-importer',
				'ovhi_notice' => 'live_settings_saved',
			),
			admin_url('admin.php')
		);
		wp_safe_redirect($redirect);
		exit;
	}

	public static function handle_manual_sync() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om deze actie uit te voeren.', 'ovhi'));
		}
		check_admin_referer('ovhi_manual_sync_action');

		try {
			$count = self::fetch_remote_realtime_delays();
		} catch (Throwable $exception) {
			error_log('OVHI manual sync error: ' . $exception->getMessage());
			self::redirect_admin('error', 'Synchronisatie mislukt. Controleer het OVHI-logbestand voor de technische melding.');
		}

		$redirect = add_query_arg(
			array(
				'page' => 'ov-halte-importer',
				'ovhi_notice' => 'manual_sync_complete',
				'ovhi_message' => rawurlencode("Handmatige synchronisatie voltooid: {$count} vertraging(en) verwerkt uit Ovalino Live."),
			),
			admin_url('admin.php')
		);
		wp_safe_redirect($redirect);
		exit;
	}

	private static function get_realtime_delay_map(array $journey_refs, array $stop_codes) {
		self::sync_remote_realtime_delays_if_needed();
		global $wpdb;

		$journey_refs = array_values(array_filter(array_map('trim', array_unique($journey_refs))));
		$stop_codes = array_values(array_filter(array_map('trim', array_unique($stop_codes))));
		if (empty($journey_refs) || empty($stop_codes)) {
			return array();
		}

		$lookup_journey_refs = array();
		$lookup_to_scheduled = array();
		foreach ($journey_refs as $journey_ref) {
			foreach (self::get_realtime_journey_ref_variants($journey_ref) as $variant) {
				$lookup_journey_refs[] = $variant;
				if (!isset($lookup_to_scheduled[$variant])) {
					$lookup_to_scheduled[$variant] = array();
				}
				$lookup_to_scheduled[$variant][] = $journey_ref;
			}
		}
		$lookup_journey_refs = array_values(array_unique(array_filter($lookup_journey_refs)));
		if (empty($lookup_journey_refs)) {
			return array();
		}

		$lookup_stop_codes = array();
		foreach ($stop_codes as $stop_code) {
			$lookup_stop_codes[] = $stop_code;
			if (preg_match('/^NL:Q:(\d+)$/i', $stop_code, $matches)) {
				$lookup_stop_codes[] = $matches[1];
			} elseif (preg_match('/^\d+$/', $stop_code)) {
				$lookup_stop_codes[] = 'NL:Q:' . $stop_code;
			}
		}
		$lookup_stop_codes = array_values(array_unique($lookup_stop_codes));

		static $table_exists = null;
		if ($table_exists === null) {
			$table_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table('realtime_delays')));
		}
		if (!$table_exists) {
			return array();
		}

		$table = self::table('realtime_delays');
		$placeholders_j = implode(',', array_fill(0, count($lookup_journey_refs), '%s'));
		$placeholders_s = implode(',', array_fill(0, count($lookup_stop_codes), '%s'));
		$params = array_merge($lookup_journey_refs, $lookup_stop_codes);

		$query = 'SELECT journey_ref, stop_code, delay_seconds, is_cancelled, expected_time FROM ' . $table . ' WHERE journey_ref IN (' . $placeholders_j . ') AND stop_code IN (' . $placeholders_s . ')';
		$rows = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

		$map = array();
		foreach ((array) $rows as $row) {
			$delay = array(
				'delay_seconds' => isset($row['delay_seconds']) ? (int) $row['delay_seconds'] : 0,
				'is_cancelled'  => !empty($row['is_cancelled']),
				'expected_time' => isset($row['expected_time']) ? $row['expected_time'] : null,
			);
			$stored_key = $row['journey_ref'] . '|' . $row['stop_code'];
			$map[$stored_key] = $delay;

			$stop_code_variants = array($row['stop_code']);
			if (preg_match('/^NL:Q:(\d+)$/i', $row['stop_code'], $matches)) {
				$stop_code_variants[] = $matches[1];
			} elseif (preg_match('/^(\d+)$/', $row['stop_code'], $matches)) {
				$stop_code_variants[] = 'NL:Q:' . $matches[1];
			}

			foreach (array_values(array_unique($stop_code_variants)) as $stop_code_variant) {
				$key = $row['journey_ref'] . '|' . $stop_code_variant;
				if (!isset($map[$key])) {
					$map[$key] = $delay;
				}
			}

			if (isset($lookup_to_scheduled[$row['journey_ref']])) {
				foreach (array_values(array_unique($lookup_to_scheduled[$row['journey_ref']])) as $scheduled_ref) {
					foreach ($stop_code_variants as $stop_code_variant) {
						$alternate_key = $scheduled_ref . '|' . $stop_code_variant;
						if (!isset($map[$alternate_key])) {
							$map[$alternate_key] = $delay;
						}
					}
				}
			}
		}
		return $map;
	}

	private static function get_realtime_delay($journey_ref, $stop_code) {
		self::sync_remote_realtime_delays_if_needed();
		global $wpdb;

		$journey_ref = trim((string) $journey_ref);
		$stop_code = trim((string) $stop_code);
		if ($journey_ref === '' || $stop_code === '') {
			return array('delay_seconds' => 0, 'is_cancelled' => false);
		}

		static $cache = array();
		$cache_key = $journey_ref . '|' . $stop_code;
		if (isset($cache[$cache_key])) {
			return $cache[$cache_key];
		}

		static $table_exists = null;
		if ($table_exists === null) {
			$table_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table('realtime_delays')));
		}
		if (!$table_exists) {
			$cache[$cache_key] = array('delay_seconds' => 0, 'is_cancelled' => false);
			return $cache[$cache_key];
		}

		$table = self::table('realtime_delays');
		$journey_ref_variants = self::get_realtime_journey_ref_variants($journey_ref);
		$lookup_stop_codes = array($stop_code);
		if (preg_match('/^NL:Q:(\d+)$/i', $stop_code, $matches)) {
			$lookup_stop_codes[] = $matches[1];
		} elseif (preg_match('/^(\d+)$/', $stop_code, $matches)) {
			$lookup_stop_codes[] = 'NL:Q:' . $matches[1];
		}
		$lookup_stop_codes = array_values(array_unique($lookup_stop_codes));

		$placeholders_j = implode(',', array_fill(0, count($journey_ref_variants), '%s'));
		$placeholders_s = implode(',', array_fill(0, count($lookup_stop_codes), '%s'));
		// Prefer an exact match on both journey_ref and stop_code. Order by the
		// preferred journey_ref variants first, then by the preferred stop_code
		// variants so the query returns the most relevant realtime row for the
		// specific stop. Fall back to any variant if an exact match is not
		// available.
		$query = 'SELECT journey_ref, stop_code, delay_seconds, is_cancelled FROM ' . $table . ' WHERE journey_ref IN (' . $placeholders_j . ') AND stop_code IN (' . $placeholders_s . ') ORDER BY FIELD(journey_ref, ' . $placeholders_j . '), FIELD(stop_code, ' . $placeholders_s . ') LIMIT 1';
		// Parameters: journey variants, stop variants, then repeated for the two FIELD() ordering calls
		$params = array_merge($journey_ref_variants, $lookup_stop_codes, $journey_ref_variants, $lookup_stop_codes);
		$row = $wpdb->get_row($wpdb->prepare($query, $params), ARRAY_A);

		// Ensure the matched realtime row actually applies to the queried stop.
		// If the stored row's stop_code does not match any of the lookup stop_code
		// variants for this query, ignore the row entirely to avoid showing
		// "Rijdt niet" at a stop where the cancellation applies elsewhere on
		// the route.
		if (!empty($row)) {
			$stored_stop = isset($row['stop_code']) ? (string) $row['stop_code'] : '';
			$stored_variants = self::get_realtime_stop_code_variants($stored_stop);
			$match_found = false;
			foreach ($stored_variants as $sv) {
			    if (in_array($sv, $lookup_stop_codes, true)) {
			        $match_found = true;
			        break;
			    }
			}
			if (!$match_found) {
			    // Treat as no row found for this particular stop
			    $row = null;
			}
		}

		$res = array(
			'delay_seconds' => (!empty($row) && isset($row['delay_seconds'])) ? (int) $row['delay_seconds'] : 0,
			'is_cancelled'  => (!empty($row) && !empty($row['is_cancelled'])),
		);

		$cache[$cache_key] = $res;
		return $res;
	}

	private static function get_debug_counts() {
		global $wpdb;

		$tables = array(
			'assignments' => 'assignments',
			'scheduled_stops' => 'scheduled_stops',
			'stop_lines' => 'stop_lines',
			'stop_line_patterns' => 'stop_line_patterns',
			'availability' => 'availability',
			'journeys' => 'journeys',
			'notices' => 'notices',
			'notice_assignments' => 'notice_assignments',
			'stop_offsets' => 'stop_offsets',
			'realtime_delays' => 'realtime_delays',
		);

		$counts = array();
		foreach ($tables as $key => $table_suffix) {
			$table_name = self::table($table_suffix);
			$table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
			$counts[$key] = $table_exists ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table_name) : 0;
		}

		return $counts;
	}

	private static function import_psa($file) {
    $reader = self::xml_reader($file);
    $assignment_rows = array();

    while ($reader->read()) {
        // Zorg voor hoofdletterongevoelige check op de elementnaam
        if ($reader->nodeType !== XMLReader::ELEMENT || strtolower($reader->localName) !== 'quay') {
            // Als het bestand toch PassengerStopAssignment gebruikt, vangen we dat hier op:
            if (strtolower($reader->localName) !== 'passengerstopassignment') {
                continue;
            }
        }

        $assignment = self::parse_assignment($reader);
        if (empty($assignment['scheduled_stop_point_ref']) || empty($assignment['quay_code'])) {
            continue;
        }

        $assignment_rows[] = $assignment;
    }

    $reader->close();
    self::bulk_upsert('assignments', array('scheduled_stop_point_ref', 'quay_code'), $assignment_rows);
}

private static function parse_assignment(XMLReader $reader) {
    $depth = $reader->depth;
    $data = array(
        'scheduled_stop_point_ref' => '',
        'quay_code' => '',
    );

    while ($reader->read()) {
        $localName = strtolower($reader->localName);

        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && ($localName === 'quay' || $localName === 'passengerstopassignment')) {
            break;
        }

        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        if ($localName === 'scheduledstoppointref') {
            $data['scheduled_stop_point_ref'] = trim((string) $reader->getAttribute('ref'));
        } elseif ($localName === 'stopplacecode' && empty($data['scheduled_stop_point_ref'])) {
            $data['scheduled_stop_point_ref'] = trim($reader->readString());
        } elseif ($localName === 'quayref') {
            $data['quay_code'] = self::normalize_quay_code((string) $reader->getAttribute('ref'));
        } elseif ($localName === 'quaycode') {
            $data['quay_code'] = self::normalize_quay_code($reader->readString());
        }
    }

    return $data;
}

	private static function import_netex($files) {
		$files = is_array($files) ? array_values($files) : array($files);

		$counts = array(
			'quays' => 0,
			'lines' => 0,
			'stop_lines' => 0,
			'stop_line_patterns' => 0,
			'journeys' => 0,
			'stop_offsets' => 0,
			'notices' => 0,
			'notice_assignments' => 0,
		);

		foreach ($files as $index => $file) {
			// Geef elk bestand een unieke prefix voor availability_refs, zodat twee bestanden
			// met dezelfde ref-namen nooit elkaars availability-bits overschrijven.
			$file_prefix = 'f' . $index . '_';
			$file_counts = self::import_netex_file($file, $file_prefix);
			foreach ($counts as $key => $value) {
				if (isset($file_counts[$key])) {
					$counts[$key] += (int) $file_counts[$key];
				}
			}
			unset($file_counts);
			if (function_exists('gc_collect_cycles')) {
				gc_collect_cycles();
			}
		}

		return $counts;
	}

	private static function import_netex_file($file, $file_prefix = '') {
		global $wpdb;
		$lines = array();
		$quays = array();
		$scheduled_stops = array();
		$assignments = array();
		$destination_displays = array();
		$route_to_line = array();
		$stop_lines = array();
		$stop_line_patterns = array();
		$patterns = array();
		$availability_conditions = array();
		$time_demand_types = array();
		$notices = array();
		$notice_assignments = array();

		$reader = self::xml_reader($file);

			while ($reader->read()) {
				if ($reader->nodeType !== XMLReader::ELEMENT) {
					continue;
				}

				switch ($reader->localName) {
					case 'Notice':
						$notice = self::parse_netex_notice($reader);
						if (!empty($notice['notice_id'])) {
							$notices[$notice['notice_id']] = $notice;
						}
						break;

					case 'NoticeAssignment':
						$notice_assignment = self::parse_netex_notice_assignment($reader);
						if (!empty($notice_assignment['notice_ref']) && !empty($notice_assignment['noticed_object_ref'])) {
							$notice_assignments[] = $notice_assignment;
						}
						break;

					case 'Line':
						$line = self::parse_netex_line($reader);
						if (!empty($line['line_ref'])) {
							$lines[$line['line_ref']] = $line;
						}
						break;

					case 'DestinationDisplay':
						$destination = self::parse_destination_display($reader);
						if (!empty($destination['ref']) && $destination['text'] !== '') {
							$destination_displays[$destination['ref']] = $destination['text'];
						}
						break;

					case 'ScheduledStopPoint':
						$scheduled_stop = self::parse_scheduled_stop($reader);
						if (!empty($scheduled_stop['scheduled_stop_point_ref'])) {
							$scheduled_stops[$scheduled_stop['scheduled_stop_point_ref']] = $scheduled_stop;
						}
						break;

					case 'PassengerStopAssignment':
						$assignment = self::parse_assignment($reader);
						if (!empty($assignment['scheduled_stop_point_ref']) && !empty($assignment['quay_code'])) {
							$assignments[$assignment['scheduled_stop_point_ref']] = $assignment['quay_code'];
						}
						break;

					case 'Route':
						$route = self::parse_route($reader);
						if (!empty($route['route_ref']) && !empty($route['line_ref'])) {
							$route_to_line[$route['route_ref']] = $route['line_ref'];
						}
						break;

					case 'AvailabilityCondition':
						$availability = self::parse_availability_condition($reader);
						if (!empty($availability['availability_ref'])) {
							// Prefix de ref zodat elk bestand unieke availability-sleutels heeft
							$availability['availability_ref'] = $file_prefix . $availability['availability_ref'];
							$availability_conditions[$availability['availability_ref']] = $availability;
						}
						break;

			case 'TimeDemandType':
				$time_demand_type = self::parse_time_demand_type($reader);
				if (!empty($time_demand_type['time_demand_type_ref'])) {
					$time_demand_types[$time_demand_type['time_demand_type_ref']] = array(
						'time_demand_type_ref' => $time_demand_type['time_demand_type_ref'],
						'run_times_blob' => self::encode_import_blob(isset($time_demand_type['run_times']) ? $time_demand_type['run_times'] : array()),
						'wait_times_blob' => self::encode_import_blob(isset($time_demand_type['wait_times']) ? $time_demand_type['wait_times'] : array()),
					);
				}
				unset($time_demand_type);
				break;

			case 'ServiceJourneyPattern':
				$pattern = self::parse_service_journey_pattern_raw($reader);
				if (!empty($pattern['pattern_ref'])) {
					$patterns[$pattern['pattern_ref']] = array(
						'pattern_ref' => $pattern['pattern_ref'],
						'route_ref' => $pattern['route_ref'],
						'direction_type' => $pattern['direction_type'],
						'pattern_destination_ref' => $pattern['pattern_destination_ref'],
						'stop_points_blob' => self::encode_import_blob(isset($pattern['stop_points']) ? $pattern['stop_points'] : array()),
					);
				}
				unset($pattern);
				break;

					case 'ServiceJourney':
						// Journeys worden in een tweede, lichtere pass verwerkt.
						break;
				}
			}

			$reader->close();

		$assigned_quays = array();
		$assignment_rows = $wpdb->get_results('SELECT scheduled_stop_point_ref, quay_code FROM ' . self::table('assignments'), ARRAY_A);
		foreach ($assignment_rows as $assignment_row) {
			if (!empty($assignment_row['scheduled_stop_point_ref']) && !empty($assignment_row['quay_code'])) {
				$assigned_quays[$assignment_row['scheduled_stop_point_ref']] = $assignment_row['quay_code'];
			}
		}

		// Maak quays aan vanuit scheduled_stops met assignments
		foreach ($scheduled_stops as $scheduled_stop) {
			if (!empty($scheduled_stop['scheduled_stop_point_ref']) && isset($assigned_quays[$scheduled_stop['scheduled_stop_point_ref']])) {
				$quay_code = $assigned_quays[$scheduled_stop['scheduled_stop_point_ref']];
				if (!isset($quays[$quay_code])) {
					$quays[$quay_code] = array(
						'quay_code' => $quay_code,
						'stopplace_code' => '',
						'quay_name' => $scheduled_stop['stop_name'],
						'latitude' => 0,
						'longitude' => 0,
						'wheelchair_access' => 'unknown',
						'step_free_access' => 'unknown',
					);
				}
			}
		}

		foreach ($patterns as $pattern) {
			if (empty($pattern['route_ref']) || !isset($route_to_line[$pattern['route_ref']])) {
				continue;
			}

			$line_ref = $route_to_line[$pattern['route_ref']];
			$stop_points = isset($pattern['stop_points']) ? $pattern['stop_points'] : array();
			if (empty($stop_points) && !empty($pattern['stop_points_blob'])) {
				$stop_points = self::decode_import_blob($pattern['stop_points_blob']);
			}
			foreach ($stop_points as $point) {
				if (empty($point['scheduled_stop_point_ref']) || empty($point['for_boarding'])) {
					continue;
				}

				$quay_code = '';
				if (isset($assigned_quays[$point['scheduled_stop_point_ref']])) {
					$quay_code = $assigned_quays[$point['scheduled_stop_point_ref']];
				} elseif (isset($assignments[$point['scheduled_stop_point_ref']])) {
					$quay_code = $assignments[$point['scheduled_stop_point_ref']];
				}
				if ($quay_code === '') {
					continue;
				}

				$destination_ref = $point['destination_ref'] ?: $pattern['pattern_destination_ref'];
				$destination = '';
				if ($destination_ref !== '' && isset($destination_displays[$destination_ref])) {
					$destination = $destination_displays[$destination_ref];
				}
				$destination_display = self::clean_destination_text($destination);

				$key = $quay_code . '|' . $line_ref . '|' . $pattern['direction_type'] . '|' . $destination_display;
				if (!isset($stop_lines[$key])) {
					$stop_lines[$key] = array(
						'quay_code' => $quay_code,
						'line_ref' => $line_ref,
						'direction_type' => $pattern['direction_type'],
						'destination' => $destination_display,
					);
				}

				$pattern_key = $quay_code . '|' . $line_ref . '|' . $pattern['direction_type'] . '|' . $pattern['pattern_ref'] . '|' . md5($destination);
				if (!isset($stop_line_patterns[$pattern_key])) {
					$stop_line_patterns[$pattern_key] = array(
						'quay_code' => $quay_code,
						'line_ref' => $line_ref,
						'direction_type' => $pattern['direction_type'],
						'destination_raw' => $destination,
						'destination_display' => $destination_display,
						'destination_hash' => md5($destination),
						'service_journey_pattern_ref' => $pattern['pattern_ref'],
					);
				}
			}
		}

		$availability_rows = array_values($availability_conditions);
		$journey_rows = array();
		$journey_count = 0;
		$used_pattern_time_pairs = array();

		$reader = self::xml_reader($file);
		while ($reader->read()) {
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'ServiceJourney') {
				continue;
			}

			$journey = self::parse_service_journey($reader);
			if (empty($journey['journey_ref']) || empty($journey['service_journey_pattern_ref']) || empty($journey['time_demand_type_ref']) || empty($journey['availability_ref'])) {
				continue;
			}
			// Pas dezelfde prefix toe op de availability_ref van de journey zodat hij
			// overeenkomt met de geprefixte sleutel in $availability_conditions.
			$journey['availability_ref'] = $file_prefix . $journey['availability_ref'];
			if (!isset($patterns[$journey['service_journey_pattern_ref']]) || !isset($time_demand_types[$journey['time_demand_type_ref']]) || !isset($availability_conditions[$journey['availability_ref']])) {
				continue;
			}

			$pattern = $patterns[$journey['service_journey_pattern_ref']];
			if (empty($pattern['route_ref']) || !isset($route_to_line[$pattern['route_ref']])) {
				continue;
			}

			$availability = $availability_conditions[$journey['availability_ref']];
			$line_ref = $route_to_line[$pattern['route_ref']];
			$direction_type = isset($pattern['direction_type']) ? (string) $pattern['direction_type'] : '';
			$valid_day_bits = isset($availability['valid_day_bits']) ? (string) $availability['valid_day_bits'] : '';
			$from_date = isset($availability['from_date']) ? (string) $availability['from_date'] : '1970-01-01';
			$signature = md5($line_ref . "\0" . $direction_type . "\0" . (string) (int) $journey['departure_seconds'] . "\0" . $valid_day_bits . "\0" . $from_date);

			$journey['journey_signature'] = $signature;
			$journey['availability_from_date'] = $from_date;
			$journey_rows[] = $journey;
			$journey_count++;

			$used_pattern_time_pairs[$journey['service_journey_pattern_ref'] . '|' . $journey['time_demand_type_ref']] = true;

			if (count($journey_rows) >= 100) {
				self::bulk_upsert_journeys($journey_rows);
				$journey_rows = array();
			}
		}
		$reader->close();
		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		if (!empty($journey_rows)) {
			self::bulk_upsert_journeys($journey_rows);
			$journey_rows = array();
		}

		$stop_offset_rows = array();
		foreach (array_keys($used_pattern_time_pairs) as $pair_key) {
			$pair = explode('|', $pair_key, 2);
			$pattern_ref = isset($pair[0]) ? $pair[0] : '';
			$time_demand_type_ref = isset($pair[1]) ? $pair[1] : '';
			if ($pattern_ref === '' || $time_demand_type_ref === '' || !isset($patterns[$pattern_ref]) || !isset($time_demand_types[$time_demand_type_ref])) {
				continue;
			}

			$pattern = $patterns[$pattern_ref];
			if (empty($pattern['route_ref']) || !isset($route_to_line[$pattern['route_ref']])) {
				continue;
			}

			$time_demand_type = $time_demand_types[$time_demand_type_ref];
			$offset_rows = self::build_stop_offsets(
				$pattern,
				$time_demand_type,
				$route_to_line[$pattern['route_ref']]
			);
			foreach ($offset_rows as $offset_row) {
				$stop_offset_rows[] = $offset_row;
				if (count($stop_offset_rows) >= 50) {
					self::bulk_upsert(
						'stop_offsets',
						array('service_journey_pattern_ref', 'time_demand_type_ref', 'scheduled_stop_point_ref', 'line_ref', 'direction_type', 'offset_seconds', 'stop_order', 'for_boarding', 'for_alighting'),
						$stop_offset_rows,
						array('offset_seconds', 'stop_order', 'for_boarding', 'for_alighting')
					);
					$stop_offset_rows = array();
				}
			}
		}

		if (!empty($stop_offset_rows)) {
			self::bulk_upsert(
				'stop_offsets',
				array('service_journey_pattern_ref', 'time_demand_type_ref', 'scheduled_stop_point_ref', 'line_ref', 'direction_type', 'offset_seconds', 'stop_order', 'for_boarding', 'for_alighting'),
				$stop_offset_rows,
				array('offset_seconds', 'stop_order', 'for_boarding', 'for_alighting')
			);
			$stop_offset_rows = array();
		}

		$count_quays = count($quays);
		$count_lines = count($lines);
		$count_stop_lines = count($stop_lines);
		$count_stop_line_patterns = count($stop_line_patterns);
		$count_notices = count($notices);
		$count_notice_assignments = count($notice_assignments);

		unset($patterns);
		unset($time_demand_types);
		unset($route_to_line);
		unset($used_pattern_time_pairs);

		self::bulk_upsert(
			'lines',
			array('line_ref', 'public_code', 'line_name', 'colour', 'text_colour'),
			array_values($lines)
		);
		unset($lines);

		// Voeg quays toe die uit NeTEx zijn geïmporteerd
		if (!empty($quays)) {
			self::bulk_upsert(
				'quays',
				array('quay_code', 'stopplace_code', 'quay_name', 'latitude', 'longitude', 'wheelchair_access', 'step_free_access'),
				array_values($quays)
			);
		}
		unset($quays);

		self::bulk_upsert(
			'scheduled_stops',
			array('scheduled_stop_point_ref', 'user_stop_code', 'stop_name', 'stop_area_ref'),
			array_values($scheduled_stops)
		);
		unset($scheduled_stops);

		$assignment_rows = array();
		foreach ($assignments as $scheduled_stop_point_ref => $quay_code) {
			$assignment_rows[] = array(
				'scheduled_stop_point_ref' => $scheduled_stop_point_ref,
				'quay_code' => $quay_code,
			);
		}
		unset($assignments);
		self::bulk_upsert(
			'assignments',
			array('scheduled_stop_point_ref', 'quay_code'),
			$assignment_rows
		);
		unset($assignment_rows);

		$stop_line_rows = array_values($stop_lines);
		unset($stop_lines);
		self::bulk_upsert(
			'stop_lines',
			array('quay_code', 'line_ref', 'direction_type', 'destination'),
			$stop_line_rows
		);
		unset($stop_line_rows);

		$stop_line_pattern_rows = array_values($stop_line_patterns);
		unset($stop_line_patterns);
		self::bulk_upsert(
			'stop_line_patterns',
			array('quay_code', 'line_ref', 'direction_type', 'destination_raw', 'destination_display', 'destination_hash', 'service_journey_pattern_ref'),
			$stop_line_pattern_rows
		);
		unset($stop_line_pattern_rows);

		self::bulk_upsert_availability($availability_rows);
		unset($availability_conditions);
		unset($availability_rows);

		self::bulk_upsert_journeys($journey_rows);
		unset($journey_rows);

		self::bulk_upsert(
			'stop_offsets',
			array('service_journey_pattern_ref', 'time_demand_type_ref', 'scheduled_stop_point_ref', 'line_ref', 'direction_type', 'offset_seconds', 'stop_order', 'for_boarding', 'for_alighting'),
			$stop_offset_rows,
			array('offset_seconds', 'stop_order', 'for_boarding', 'for_alighting')
		);
		$count_stop_offsets = count($stop_offset_rows);
		unset($stop_offset_rows);

		self::bulk_upsert(
			'notices',
			array('notice_id', 'notice_text'),
			array_values($notices)
		);
		unset($notices);

		self::bulk_upsert(
			'notice_assignments',
			array('notice_ref', 'noticed_object_ref', 'name_of_ref_class'),
			$notice_assignments
		);
		unset($notice_assignments);

		return array(
			'quays' => $count_quays,
			'lines' => $count_lines,
			'stop_lines' => $count_stop_lines,
			'stop_line_patterns' => $count_stop_line_patterns,
			'journeys' => $journey_count,
			'stop_offsets' => $count_stop_offsets,
			'notices' => $count_notices,
			'notice_assignments' => $count_notice_assignments,
		);
	}

	private static function parse_netex_line(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'line_ref' => (string) $reader->getAttribute('id'),
			'public_code' => '',
			'line_name' => '',
			'colour' => '',
			'text_colour' => '',
		);
		$in_presentation = false;

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'Line') {
				break;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Presentation') {
				$in_presentation = true;
				continue;
			}

			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'Presentation') {
				$in_presentation = false;
				continue;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'Name' && $data['line_name'] === '') {
				$data['line_name'] = trim($reader->readString());
			} elseif ($reader->localName === 'PublicCode' && $data['public_code'] === '') {
				$data['public_code'] = trim($reader->readString());
			} elseif ($in_presentation && $reader->localName === 'Colour') {
				$data['colour'] = self::sanitize_hex($reader->readString());
			} elseif ($in_presentation && $reader->localName === 'TextColour') {
				$data['text_colour'] = self::sanitize_hex($reader->readString());
			}
		}

		return $data;
	}

	private static function parse_netex_notice(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'notice_id' => (string) $reader->getAttribute('id'),
			'notice_text' => '',
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'Notice') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'Text' && $data['notice_text'] === '') {
				$data['notice_text'] = trim($reader->readString());
			}
		}

		return $data;
	}

	private static function parse_netex_notice_assignment(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'notice_ref' => '',
			'noticed_object_ref' => '',
			'name_of_ref_class' => '',
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'NoticeAssignment') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'NoticeRef') {
				$data['notice_ref'] = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'NoticedObjectRef') {
				$data['noticed_object_ref'] = trim((string) $reader->getAttribute('ref'));
				$data['name_of_ref_class'] = trim((string) $reader->getAttribute('nameOfRefClass'));
			}
		}

		return $data;
	}

	private static function parse_destination_display(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'ref' => (string) $reader->getAttribute('id'),
			'text' => '',
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'DestinationDisplay') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if (($reader->localName === 'FrontText' || $reader->localName === 'Name') && $data['text'] === '') {
				$data['text'] = trim($reader->readString());
			} elseif ($reader->localName === 'SideText' && $data['text'] === '') {
				$data['text'] = trim($reader->readString());
			}
		}

		return $data;
	}

	private static function parse_scheduled_stop(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'scheduled_stop_point_ref' => (string) $reader->getAttribute('id'),
			'user_stop_code' => '',
			'stop_name' => '',
			'stop_area_ref' => '',
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'ScheduledStopPoint') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'Name' && $data['stop_name'] === '') {
				$data['stop_name'] = trim($reader->readString());
			} elseif ($reader->localName === 'PrivateCode' && $reader->getAttribute('type') === 'UserStopCode') {
				$data['user_stop_code'] = trim($reader->readString());
			} elseif ($reader->localName === 'StopAreaRef') {
				$data['stop_area_ref'] = trim((string) $reader->getAttribute('ref'));
			}
		}

		return $data;
	}

	private static function parse_route(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'route_ref' => (string) $reader->getAttribute('id'),
			'line_ref' => '',
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'Route') {
				break;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'LineRef') {
				$data['line_ref'] = trim((string) $reader->getAttribute('ref'));
			}
		}

		return $data;
	}

	private static function parse_availability_condition(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'availability_ref' => (string) $reader->getAttribute('id'),
			'from_date' => '',
			'to_date' => '',
			'valid_day_bits' => '',
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'AvailabilityCondition') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'FromDate') {
				$data['from_date'] = self::date_part($reader->readString());
			} elseif ($reader->localName === 'ToDate') {
				$data['to_date'] = self::date_part($reader->readString());
			} elseif ($reader->localName === 'ValidDayBits') {
				$data['valid_day_bits'] = preg_replace('/[^01]/', '', $reader->readString());
			}
		}

		if ($data['from_date'] === '') {
			$data['from_date'] = '1970-01-01';
		}
		if ($data['to_date'] === '') {
			$data['to_date'] = $data['from_date'];
		}

		return $data;
	}

	private static function parse_time_demand_type(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'time_demand_type_ref' => (string) $reader->getAttribute('id'),
			'run_times' => array(),
			'wait_times' => array(),
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'TimeDemandType') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'JourneyRunTime') {
				$run_time = self::parse_journey_run_time($reader);
				if (!empty($run_time['timing_link_ref'])) {
					$data['run_times'][$run_time['timing_link_ref']] = $run_time['seconds'];
				}
			} elseif ($reader->localName === 'JourneyWaitTime') {
				$wait_time = self::parse_journey_wait_time($reader);
				if (!empty($wait_time['scheduled_stop_point_ref'])) {
					$data['wait_times'][$wait_time['scheduled_stop_point_ref']] = $wait_time['seconds'];
				}
			}
		}

		return $data;
	}

	private static function parse_journey_run_time(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'timing_link_ref' => '',
			'seconds' => 0,
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'JourneyRunTime') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'TimingLinkRef') {
				$data['timing_link_ref'] = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'RunTime') {
				$data['seconds'] = self::duration_to_seconds($reader->readString());
			}
		}

		return $data;
	}

	private static function parse_journey_wait_time(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'scheduled_stop_point_ref' => '',
			'seconds' => 0,
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'JourneyWaitTime') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'ScheduledStopPointRef') {
				$data['scheduled_stop_point_ref'] = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'WaitTime') {
				$data['seconds'] = self::duration_to_seconds($reader->readString());
			}
		}

		return $data;
	}

	private static function parse_service_journey(XMLReader $reader) {
		$depth = $reader->depth;
		$data = array(
			'journey_ref' => (string) $reader->getAttribute('id'),
			// Het korte, publieke ritnummer (bv. "7615"). BISON/NeTEx-exports
			// zetten dit in <privateCodes><PrivateCode type="JourneyNumber">
			// binnen het ServiceJourney-element - zo levert EBS het ook aan
			// (geverifieerd in de meegestuurde export). Dit is dezelfde naam
			// als het "JourneyNumber"-veld in de live KV78turbo-feed, dus dit
			// is de bedoelde, robuuste sleutel om op te matchen - in plaats
			// van te gokken op de opbouw van het lange journey_ref/id.
			'journey_number' => '',
			'service_journey_pattern_ref' => '',
			'time_demand_type_ref' => '',
			'availability_ref' => '',
			'departure_seconds' => 0,
		);

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'ServiceJourney') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'AvailabilityConditionRef' && $data['availability_ref'] === '') {
				$data['availability_ref'] = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'DepartureTime') {
				$data['departure_seconds'] = self::time_to_seconds($reader->readString());
			} elseif ($reader->localName === 'ServiceJourneyPatternRef') {
				$data['service_journey_pattern_ref'] = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'TimeDemandTypeRef') {
				$data['time_demand_type_ref'] = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'PrivateCode' && $data['journey_number'] === '') {
				$code_type = trim((string) $reader->getAttribute('type'));
				$code_value = trim((string) $reader->readString());
				if ($code_type === 'JourneyNumber' && $code_value !== '') {
					$data['journey_number'] = $code_value;
				}
			}
		}

		return $data;
	}

	private static function build_stop_offsets(array $pattern, array $time_demand_type, $line_ref) {
		$rows = array();
		$offset = 0;
		$index = 0;
		$run_times = isset($time_demand_type['run_times']) ? $time_demand_type['run_times'] : array();
		$wait_times = isset($time_demand_type['wait_times']) ? $time_demand_type['wait_times'] : array();
		$time_demand_type_ref = isset($time_demand_type['time_demand_type_ref']) ? $time_demand_type['time_demand_type_ref'] : '';
		if (empty($run_times) && !empty($time_demand_type['run_times_blob'])) {
			$run_times = self::decode_import_blob($time_demand_type['run_times_blob']);
		}
		if (empty($wait_times) && !empty($time_demand_type['wait_times_blob'])) {
			$wait_times = self::decode_import_blob($time_demand_type['wait_times_blob']);
		}
		$stop_points = isset($pattern['stop_points']) ? $pattern['stop_points'] : array();
		if (empty($stop_points) && !empty($pattern['stop_points_blob'])) {
			$stop_points = self::decode_import_blob($pattern['stop_points_blob']);
		}

		if (empty($pattern['pattern_ref']) || $time_demand_type_ref === '' || empty($stop_points)) {
			return $rows;
		}

		foreach ($stop_points as $point) {
			$scheduled_stop_point_ref = isset($point['scheduled_stop_point_ref']) ? $point['scheduled_stop_point_ref'] : '';
			if ($index > 0 && $scheduled_stop_point_ref !== '' && isset($wait_times[$scheduled_stop_point_ref])) {
				$offset += (int) $wait_times[$scheduled_stop_point_ref];
			}

			$for_boarding = !empty($point['for_boarding']) ? 1 : 0;
			$for_alighting = !empty($point['for_alighting']) ? 1 : 0;
			$order = !empty($point['order']) ? (int) $point['order'] : ($index + 1);

			if ($scheduled_stop_point_ref !== '' && ($for_boarding || $for_alighting)) {
				$rows[] = array(
					'service_journey_pattern_ref' => $pattern['pattern_ref'],
					'time_demand_type_ref' => $time_demand_type_ref,
					'scheduled_stop_point_ref' => $scheduled_stop_point_ref,
					'line_ref' => $line_ref,
					'direction_type' => isset($pattern['direction_type']) ? $pattern['direction_type'] : '',
					'offset_seconds' => $offset,
					'stop_order' => $order,
					'for_boarding' => $for_boarding,
					'for_alighting' => $for_alighting,
				);
			}

			$onward_timing_link_ref = isset($point['onward_timing_link_ref']) ? $point['onward_timing_link_ref'] : '';
			if ($onward_timing_link_ref !== '' && isset($run_times[$onward_timing_link_ref])) {
				$offset += (int) $run_times[$onward_timing_link_ref];
			}
			$index++;
		}

		return $rows;
	}

	private static function parse_service_journey_pattern(XMLReader $reader, array $route_to_line, array $destination_displays) {
		$depth = $reader->depth;
		$pattern_ref = (string) $reader->getAttribute('id');
		$route_ref = '';
		$pattern_destination_ref = '';
		$direction_type = '';
		$rows = array();
		$points = array();

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'ServiceJourneyPattern') {
				break;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'RouteRef') {
				$route_ref = trim((string) $reader->getAttribute('ref'));
				continue;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'DirectionType') {
				$direction_type = trim($reader->readString());
				continue;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'DestinationDisplayRef' && $pattern_destination_ref === '') {
				$pattern_destination_ref = trim((string) $reader->getAttribute('ref'));
				continue;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'StopPointInJourneyPattern') {
				$point = self::parse_stop_point_in_journey_pattern($reader, $route_ref, $pattern_destination_ref, $route_to_line, $destination_displays);
				if (!empty($point)) {
					if (!empty($point['for_boarding'])) {
						$rows[] = array(
							'scheduled_stop_point_ref' => $point['scheduled_stop_point_ref'],
							'line_ref' => $point['line_ref'],
							'direction_type' => $direction_type,
							'destination' => $point['destination'],
						);
					}
					$point['direction_type'] = $direction_type;
					$points[] = $point;
				}
			}
		}

		return array(
			'pattern_ref' => $pattern_ref,
			'route_ref' => $route_ref,
			'direction_type' => $direction_type,
			'stop_lines' => $rows,
			'points' => $points,
		);
	}

	private static function parse_service_journey_pattern_raw(XMLReader $reader) {
		$depth = $reader->depth;
		$pattern_ref = (string) $reader->getAttribute('id');
		$route_ref = '';
		$pattern_destination_ref = '';
		$direction_type = '';
		$stop_points = array();

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'ServiceJourneyPattern') {
				break;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'RouteRef') {
				$route_ref = trim((string) $reader->getAttribute('ref'));
				continue;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'DirectionType') {
				$direction_type = trim($reader->readString());
				continue;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'DestinationDisplayRef' && $pattern_destination_ref === '') {
				$pattern_destination_ref = trim((string) $reader->getAttribute('ref'));
				continue;
			}

			if ($reader->nodeType === XMLReader::ELEMENT && in_array($reader->localName, array('StopPointInJourneyPattern', 'TimingPointInJourneyPattern'), true)) {
				$stop_points[] = self::parse_stop_point_in_journey_pattern_raw($reader);
			}
		}

		return array(
			'pattern_ref' => $pattern_ref,
			'route_ref' => $route_ref,
			'direction_type' => $direction_type,
			'pattern_destination_ref' => $pattern_destination_ref,
			'stop_points' => $stop_points,
		);
	}

	private static function parse_stop_point_in_journey_pattern_raw(XMLReader $reader) {
		$depth = $reader->depth;
		$element_name = $reader->localName;
		$scheduled_stop_point_ref = '';
		$destination_ref = '';
		$for_boarding = true;
		$for_alighting = true;
		$onward_timing_link_ref = '';
		$order = (int) $reader->getAttribute('order');

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === $element_name) {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'ScheduledStopPointRef') {
				$scheduled_stop_point_ref = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'DestinationDisplayRef') {
				$destination_ref = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'OnwardTimingLinkRef') {
				$onward_timing_link_ref = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'ForBoarding') {
				$for_boarding = strtolower(trim($reader->readString())) !== 'false';
			} elseif ($reader->localName === 'ForAlighting') {
				$for_alighting = strtolower(trim($reader->readString())) !== 'false';
			}
		}

		return array(
			'scheduled_stop_point_ref' => $scheduled_stop_point_ref,
			'destination_ref' => $destination_ref,
			'for_boarding' => $for_boarding,
			'for_alighting' => $for_alighting,
			'onward_timing_link_ref' => $onward_timing_link_ref,
			'order' => $order,
		);
	}

	private static function parse_stop_point_in_journey_pattern(XMLReader $reader, $route_ref, $pattern_destination_ref, array $route_to_line, array $destination_displays) {
		$depth = $reader->depth;
		$scheduled_stop_point_ref = '';
		$destination_ref = '';
		$for_boarding = true;
		$onward_timing_link_ref = '';

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'StopPointInJourneyPattern') {
				break;
			}

			if ($reader->nodeType !== XMLReader::ELEMENT) {
				continue;
			}

			if ($reader->localName === 'ScheduledStopPointRef') {
				$scheduled_stop_point_ref = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'DestinationDisplayRef') {
				$destination_ref = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'OnwardTimingLinkRef') {
				$onward_timing_link_ref = trim((string) $reader->getAttribute('ref'));
			} elseif ($reader->localName === 'ForBoarding') {
				$for_boarding = strtolower(trim($reader->readString())) !== 'false';
			}
		}

		if ($scheduled_stop_point_ref === '' || $route_ref === '' || empty($route_to_line[$route_ref])) {
			return array();
		}

		$line_ref = $route_to_line[$route_ref];
		$destination_display_ref = $destination_ref ? $destination_ref : $pattern_destination_ref;
		$destination = '';
		if ($destination_display_ref !== '' && isset($destination_displays[$destination_display_ref])) {
			$destination = $destination_displays[$destination_display_ref];
		}

		return array(
			'scheduled_stop_point_ref' => $scheduled_stop_point_ref,
			'line_ref' => $line_ref,
			'destination' => $destination,
			'for_boarding' => $for_boarding,
			'onward_timing_link_ref' => $onward_timing_link_ref,
		);
	}

	private static function normalize_quay_code($value) {

	$value = trim((string) $value);

	if ($value === '') {
		return '';
	}

	// Reeds correcte Q-code
	if (preg_match('/^[A-Z]{2}:Q:/', $value)) {
		return $value;
	}

	// NL:CHB:Quay:12345 -> NL:Q:12345
	if (preg_match('/^([A-Z]{2}):CHB:Quay:(.+)$/', $value, $matches)) {
		return $matches[1] . ':Q:' . $matches[2];
	}

	// Oude variant zonder landcode
	if (preg_match('/^CHB:Quay:(.+)$/', $value, $matches)) {
		return 'NL:Q:' . $matches[1];
	}

	return $value;
}

	private static function date_part($value) {
		$value = trim((string) $value);
		if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
			return $matches[1];
		}
		return '';
	}

	private static function clean_destination_text($value) {
		$value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
		$value = trim(preg_replace('/\s+/', ' ', $value));
		if ($value === '') {
			return '';
		}

		$cleaned = preg_replace('/\s+via(?:\s+.*)?$/i', '', $value);
		$cleaned = trim((string) $cleaned);

		return $cleaned !== '' ? $cleaned : $value;
	}

	private static function duration_to_seconds($value) {
		$value = strtoupper(trim((string) $value));
		if ($value === '') {
			return 0;
		}

		if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $value, $matches)) {
			return 0;
		}

		$days = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
		$hours = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
		$minutes = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0;
		$seconds = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0;

		return ($days * DAY_IN_SECONDS) + ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS) + $seconds;
	}

	private static function time_to_seconds($value) {
		$value = trim((string) $value);
		if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $matches)) {
			return 0;
		}

		$hours = (int) $matches[1];
		$minutes = (int) $matches[2];
		$seconds = isset($matches[3]) ? (int) $matches[3] : 0;

		return ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS) + $seconds;
	}

	private static function sanitize_hex($value) {
		$value = strtoupper(trim((string) $value));
		$value = ltrim($value, '#');
		if (!preg_match('/^[0-9A-F]{6}$/', $value)) {
			return '';
		}
		return '#' . $value;
	}

	public static function render_shortcode_safely($atts) {
		try {
			return self::render_shortcode($atts);
		} catch (Throwable $exception) {
			error_log('OVHI shortcode error: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
			return '<p class="ovhi-error">Actuele vertrektijden zijn tijdelijk niet beschikbaar.</p>';
		}
	}

	public static function render_shortcode($atts) {
		global $wpdb;

		$atts = shortcode_atts(
			array(
				'stopplace' => '',
				'stopplaces' => '',
				'quay' => '',
				'quays' => '',
				'user_stop' => '',
				'user_stops' => '',
				'station' => '',
				'stations' => '',
				'departures_url' => '',
				'schedule_url' => '',
				'train_schedule_url' => '',
			),
			$atts,
			'ov_halte'
		);
		$schedule_base_url = trim((string) $atts['schedule_url']);
		if ($schedule_base_url === '') {
			$schedule_base_url = home_url('/dienstregeling/');
		}
		$train_schedule_base_url = trim((string) $atts['train_schedule_url']);
		if ($train_schedule_base_url === '') {
			$train_schedule_base_url = home_url('/treindienstregeling/');
		}

		$station_codes = array_merge(
			self::split_codes($atts['station']),
			self::split_codes($atts['stations'])
		);
		$station_codes = array_values(
			array_unique(
				array_filter(
					array_map(
						function ($code) {
							return strtolower(trim((string) $code));
						},
						$station_codes
					)
				)
			)
		);

		$train_items = self::find_train_departure_items($station_codes, $train_schedule_base_url, 7);

		$quay_codes = array();
		$quay_inputs = array_merge(
			self::split_codes($atts['quay']),
			self::split_codes($atts['quays'])
		);
		foreach ($quay_inputs as $quay_input) {
			$quay_codes[] = self::normalize_quay_code($quay_input);
		}

		$stopplace_inputs = array_merge(
			self::split_codes($atts['stopplace']),
			self::split_codes($atts['stopplaces'])
		);
		foreach ($stopplace_inputs as $stopplace_input) {
			$normalized_stopplace = self::normalize_stopplace_code($stopplace_input);
			$found_quays = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT quay_code FROM ' . self::table('quays') . ' WHERE stopplace_code = %s',
					$normalized_stopplace
				)
			);
			if (!empty($found_quays)) {
				$quay_codes = array_merge($quay_codes, $found_quays);
			}
		}

		$user_stop_inputs = array_merge(
			self::split_codes($atts['user_stop']),
			self::split_codes($atts['user_stops'])
		);
		foreach ($user_stop_inputs as $user_stop_input) {
			$user_stop_code = sanitize_text_field($user_stop_input);
			$scheduled_stop_refs = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT scheduled_stop_point_ref FROM ' . self::table('scheduled_stops') . ' WHERE user_stop_code = %s',
					$user_stop_code
				)
			);
			if (empty($scheduled_stop_refs)) {
				continue;
			}

			foreach ($scheduled_stop_refs as $scheduled_stop_ref) {
				$quay_code = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT quay_code FROM ' . self::table('assignments') . ' WHERE scheduled_stop_point_ref = %s LIMIT 1',
						$scheduled_stop_ref
					)
				);
				if ($quay_code) {
					$quay_codes[] = $quay_code;
				}
			}
		}

		$quay_codes = array_values(array_unique(array_filter($quay_codes)));

		$items = array();
		if (!empty($quay_codes)) {
		$placeholders = implode(',', array_fill(0, count($quay_codes), '%s'));
		$scheduled_stop_refs = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT scheduled_stop_point_ref FROM ' . self::table('assignments') . " WHERE quay_code IN ($placeholders)",
				$quay_codes
			)
		);
		$scheduled_stop_refs = array_values(array_unique(array_filter($scheduled_stop_refs)));

		$query = "
			SELECT sl.quay_code, sl.line_ref, sl.direction_type, sl.destination, l.public_code, l.line_name, l.colour, l.text_colour
			FROM " . self::table('stop_lines') . " sl
			INNER JOIN " . self::table('lines') . " l ON l.line_ref = sl.line_ref
			WHERE sl.quay_code IN ($placeholders)
		";

		$rows = $wpdb->get_results($wpdb->prepare($query, $quay_codes), ARRAY_A);
		if (!empty($rows)) {
		foreach ($rows as $row) {
			$key = $row['line_ref'] . '|' . $row['direction_type'];
			if (!isset($items[$key])) {
				$items[$key] = array(
					'line_ref' => $row['line_ref'],
					'public_code' => $row['public_code'],
					'line_name' => $row['line_name'],
					'direction_type' => $row['direction_type'],
					'colour' => $row['colour'],
					'text_colour' => $row['text_colour'],
					'destinations' => array(),
				);
			}
			$row_destination = self::clean_destination_text($row['destination']);
			if (!isset($items[$key]['destinations'][$row_destination])) {
				$items[$key]['destinations'][$row_destination] = 0;
			}
			$items[$key]['destinations'][$row_destination]++;
		}

		foreach ($items as $key => $item) {
			$fallback_destination = self::pick_primary_destination($item['destinations'], $item['line_name']);
			$items[$key]['destination'] = self::pick_operational_day_destination(
				$quay_codes,
				$item['line_ref'],
				$item['direction_type'],
				$fallback_destination
			);
			$items[$key]['departures'] = self::find_next_departures(
				$scheduled_stop_refs,
				$item['line_ref'],
				$item['direction_type'],
				2
			);
			$items[$key]['schedule_url'] = self::build_schedule_url(
				$schedule_base_url,
				$item['line_ref'],
				$item['direction_type']
			);
		}

				$items = array_values($items);
				usort($items, array(__CLASS__, 'sort_line_items'));
			}
		}

		if (empty($train_items) && empty($items)) {
			return '';
		}

		self::enqueue_frontend_style();

		ob_start();
		?>
		<div class="ovhi-list">
			<?php if (!empty($train_items)) : ?>
				<div class="ovhi-heading">Eerstvolgende geplande treinen</div>
				<?php echo self::render_departure_items($train_items); ?>
			<?php endif; ?>
			<?php if (!empty($items)) : ?>
				<div class="ovhi-heading">Eerstvolgende geplande bussen</div>
				<?php echo self::render_departure_items($items); ?>
			<?php endif; ?>
			<?php if (!empty($atts['departures_url'])) : ?>
				<p class="ovhi-link-spacer"></p>
				<div class="ovhi-item ovhi-item-link">
					<a class="ovhi-departures-link" href="<?php echo esc_url($atts['departures_url']); ?>">Actuele vertrektijden</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function find_train_departure_items(array $station_codes, $train_schedule_base_url, $limit = 7) {
		if (empty($station_codes) || !class_exists('OV_Trein_Dienstregeling') || !OV_Trein_Dienstregeling::is_data_available()) {
			return array();
		}

		$items = array();
		$limit = max(1, (int) $limit);
		foreach ($station_codes as $station_code) {
			$station_items = OV_Trein_Dienstregeling::find_next_departures_at_station(
				$station_code,
				$limit,
				$train_schedule_base_url
			);
			foreach ($station_items as $item) {
				$items[] = $item;
				if (count($items) >= $limit) {
					return $items;
				}
			}
		}

		return $items;
	}

	private static function render_departure_items(array $items) {
		ob_start();
		foreach ($items as $item) {
			$background = !empty($item['colour']) ? $item['colour'] : self::FALLBACK_COLOR;
			$text_color = self::resolve_text_colour($background, isset($item['text_colour']) ? $item['text_colour'] : '');
			$destination = !empty($item['destination']) ? $item['destination'] : (isset($item['line_name']) ? $item['line_name'] : '');
			$departure_text = !empty($item['departures']) ? ': ' . implode(' - ', $item['departures']) : '';
			$schedule_url = !empty($item['schedule_url']) ? $item['schedule_url'] : '';
			?>
			<div class="ovhi-item">
				<a class="ovhi-badge" href="<?php echo esc_url($schedule_url); ?>" style="background-color: <?php echo esc_attr($background); ?>; color: <?php echo esc_attr($text_color); ?>;">
					<?php echo esc_html($item['public_code']); ?>
				</a>
				<span class="ovhi-content">
					<a class="ovhi-destination" href="<?php echo esc_url($schedule_url); ?>"><?php echo esc_html($destination) . $departure_text; ?></a>
				</span>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	public static function sort_line_items($a, $b) {
		$a_numeric = is_numeric($a['public_code']);
		$b_numeric = is_numeric($b['public_code']);

		if ($a_numeric && $b_numeric) {
			$compare = (int) $a['public_code'] - (int) $b['public_code'];
			if ($compare !== 0) {
				return $compare;
			}
		} else {
			$compare = strnatcasecmp($a['public_code'], $b['public_code']);
			if ($compare !== 0) {
				return $compare;
			}
		}

		return strcasecmp($a['destination'], $b['destination']);
	}

	private static function pick_primary_destination(array $destinations, $fallback = '') {
		if (empty($destinations)) {
			return (string) $fallback;
		}

		arsort($destinations, SORT_NUMERIC);
		$top_count = (int) reset($destinations);
		$candidates = array();

		foreach ($destinations as $destination => $count) {
			if ((int) $count !== $top_count) {
				break;
			}
			$candidates[] = (string) $destination;
		}

		usort($candidates, 'strcasecmp');
		$chosen = reset($candidates);

		if ($chosen === false || $chosen === '') {
			return (string) $fallback;
		}

		return $chosen;
	}

	private static function pick_operational_day_destination(array $quay_codes, $line_ref, $direction_type, $fallback = '') {
		global $wpdb;

		$quay_codes = array_values(array_unique(array_filter($quay_codes)));
		$line_ref = (string) $line_ref;
		$direction_type = (string) $direction_type;
		$fallback = self::clean_destination_text($fallback);

		if (empty($quay_codes) || $line_ref === '') {
			return $fallback;
		}

		$window = self::current_operational_window();
		$placeholders = implode(',', array_fill(0, count($quay_codes), '%s'));
		$params = $quay_codes;
		$params[] = $line_ref;
		$params[] = $direction_type;
		$params[] = $window['start_date'];
		$params[] = $window['end_date'];

		$query = "
			SELECT DISTINCT slp.destination_display, so.offset_seconds, j.journey_ref, j.departure_seconds, a.from_date, a.to_date, a.valid_day_bits
			FROM " . self::table('stop_line_patterns') . " slp
			INNER JOIN " . self::table('assignments') . " ass ON ass.quay_code = slp.quay_code
			INNER JOIN " . self::table('stop_offsets') . " so
				ON so.service_journey_pattern_ref = slp.service_journey_pattern_ref
				AND so.scheduled_stop_point_ref = ass.scheduled_stop_point_ref
				AND so.line_ref = slp.line_ref
				AND so.direction_type = slp.direction_type
			INNER JOIN " . self::table('journeys') . " j
				ON j.service_journey_pattern_ref = so.service_journey_pattern_ref
				AND j.time_demand_type_ref = so.time_demand_type_ref
			INNER JOIN " . self::table('availability') . " a ON a.availability_ref = j.availability_ref
			WHERE slp.quay_code IN ($placeholders)
				AND slp.line_ref = %s
				AND slp.direction_type = %s
				AND a.to_date >= %s
				AND a.from_date <= %s
		";

		$rows = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
		if (empty($rows)) {
			return $fallback;
		}

		// Alleen de huidige OV-servicedag controleren om te voorkomen dat de +1 dag-correctie
		// voor ritten ná middernacht (total_seconds < 5u) dubbel wordt toegepast
		// wanneer ook de volgende servicedag in de lus zou worden meegenomen.
		$service_date = $window['start_date'];

		// Eerst filteren op geldigheid voor de huidige servicedag, pas dáárna dedupliceren.
		// Zo niet, dan kan een rit die vandaag echt rijdt worden weggegooid omdat een
		// heel ander dagpatroon (bijv. een losse zaterdag-uitzondering met een latere
		// from_date) toevallig op hetzelfde kloktijdstip vertrekt.
		$valid_rows = array();
		foreach ($rows as $row) {
			if ((string) $row['from_date'] > $service_date || (string) $row['to_date'] < $service_date) {
				continue;
			}
			if (!self::availability_matches_date($row, $service_date)) {
				continue;
			}
			$valid_rows[] = $row;
		}

		if (empty($valid_rows)) {
			return $fallback;
		}

		// Filter duplicates: keep only the newest version of journeys with the same departure time at this stop
		$groups = array();
		foreach ($valid_rows as $row) {
			$total_seconds = (int) $row['departure_seconds'] + (int) $row['offset_seconds'];
			$groups[$total_seconds][] = $row;
		}
		$rows = array();
		foreach ($groups as $total_seconds => $group) {
			if (count($group) > 1) {
				usort($group, function ($a, $b) {
					return strcmp($b['from_date'], $a['from_date']);
				});
			}
			$rows[] = $group[0];
		}

		$counts = array();
		$seen = array();
		foreach ($rows as $row) {
			$destination = self::clean_destination_text($row['destination_display']);
			if ($destination === '') {
				continue;
			}

			$date = new DateTimeImmutable($service_date . ' 00:00:00', $window['timezone']);
			$total_seconds = (int) $row['departure_seconds'] + (int) $row['offset_seconds'];
			$candidate = $date->modify('+' . $total_seconds . ' seconds');
			if ($total_seconds < 5 * HOUR_IN_SECONDS) {
				$candidate = $candidate->modify('+1 day');
			}
			if ($candidate >= $window['start'] && $candidate < $window['end']) {
				$seen_key = $destination . '|' . $row['journey_ref'] . '|' . $candidate->getTimestamp();
				if (!isset($seen[$seen_key])) {
					$seen[$seen_key] = true;
					if (!isset($counts[$destination])) {
						$counts[$destination] = 0;
					}
					$counts[$destination]++;
				}
			}
		}

		if (empty($counts)) {
			return $fallback;
		}

		arsort($counts, SORT_NUMERIC);
		$top_count = (int) reset($counts);
		$candidates = array();
		foreach ($counts as $destination => $count) {
			if ((int) $count !== $top_count) {
				break;
			}
			$candidates[] = (string) $destination;
		}

		if ($fallback !== '' && in_array($fallback, $candidates, true)) {
			return $fallback;
		}

		usort($candidates, 'strcasecmp');
		$chosen = reset($candidates);

		return $chosen === false ? $fallback : $chosen;
	}

	private static function current_operational_window() {
		$timezone = wp_timezone();
		$now = new DateTimeImmutable('now', $timezone);
		$today_at_five = new DateTimeImmutable($now->format('Y-m-d') . ' 05:00:00', $timezone);
		$start = $now < $today_at_five ? $today_at_five->modify('-1 day') : $today_at_five;
		$end = $start->modify('+1 day');

		return array(
			'timezone' => $timezone,
			'now' => $now,
			'start' => $start,
			'end' => $end,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
		);
	}

	private static function build_schedule_url($base_url, $line_ref, $direction_type) {
		$base_url = trim((string) $base_url);
		if ($base_url === '') {
			return '';
		}

		$window = self::current_operational_window();
		return add_query_arg(
			array(
				'ovld_direction' => (string) $direction_type,
				'ovld_line' => (string) $line_ref,
				'ovld_variant' => $window['start_date'],
			),
			$base_url
		);
	}

	private static function find_next_departures(array $scheduled_stop_refs, $line_ref, $direction_type, $limit = 2) {
		global $wpdb;

		$scheduled_stop_refs = array_values(array_unique(array_filter($scheduled_stop_refs)));
		$line_ref = (string) $line_ref;
		$direction_type = (string) $direction_type;
		$limit = max(1, (int) $limit);

		if (empty($scheduled_stop_refs) || $line_ref === '') {
			return array();
		}

		$window = self::current_operational_window();
		$placeholders = implode(',', array_fill(0, count($scheduled_stop_refs), '%s'));

		$params = $scheduled_stop_refs;
		$params[] = $line_ref;
		$params[] = $direction_type;
		$params[] = $window['start_date'];
		$params[] = $window['end_date'];

		$query = "
			SELECT DISTINCT so.offset_seconds, j.departure_seconds, j.journey_ref, st.user_stop_code, a.from_date, a.to_date, a.valid_day_bits
			FROM " . self::table('stop_offsets') . " so
			INNER JOIN " . self::table('journeys') . " j
				ON j.service_journey_pattern_ref = so.service_journey_pattern_ref
				AND j.time_demand_type_ref = so.time_demand_type_ref
			INNER JOIN " . self::table('scheduled_stops') . " st ON st.scheduled_stop_point_ref = so.scheduled_stop_point_ref
			INNER JOIN " . self::table('availability') . " a ON a.availability_ref = j.availability_ref
			WHERE so.scheduled_stop_point_ref IN ($placeholders)
				AND so.line_ref = %s
				AND so.direction_type = %s
				AND so.for_boarding = 1
				AND a.to_date >= %s
				AND a.from_date <= %s
		";

		$rows = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
		if (empty($rows)) {
			return array();
		}

		// Alleen de huidige OV-servicedag controleren om te voorkomen dat de +1 dag-correctie
		// voor ritten ná middernacht (total_seconds < 5u) dubbel wordt toegepast
		// wanneer ook de volgende servicedag in de lus zou worden meegenomen.
		$service_date = $window['start_date'];

		// Eerst filteren op geldigheid voor de huidige servicedag, pas dáárna dedupliceren.
		// Zo niet, dan kan een rit die vandaag echt rijdt worden weggegooid omdat een
		// heel ander dagpatroon (bijv. een losse zaterdag-uitzondering met een latere
		// from_date) toevallig op hetzelfde kloktijdstip vertrekt.
		$valid_rows = array();
		foreach ($rows as $row) {
			if ((string) $row['from_date'] > $service_date || (string) $row['to_date'] < $service_date) {
				continue;
			}
			if (!self::availability_matches_date($row, $service_date)) {
				continue;
			}
			$valid_rows[] = $row;
		}

		if (empty($valid_rows)) {
			return array();
		}

		// Fetch realtime delays in ONE bulk query instead of N+1
		$journey_refs = array();
		$stop_codes = array();
		foreach ($valid_rows as $row) {
			if (!empty($row['journey_ref'])) {
				$journey_refs[] = (string) $row['journey_ref'];
			}
			if (!empty($row['user_stop_code'])) {
				$stop_codes[] = (string) $row['user_stop_code'];
			}
		}
		$delay_map = self::get_realtime_delay_map($journey_refs, $stop_codes);

		// Filter duplicates: keep only the newest version of journeys with the same departure time at this stop.
		$groups = array();
		foreach ($valid_rows as $row) {
			$journey_ref = isset($row['journey_ref']) ? (string) $row['journey_ref'] : '';
			$stop_code = isset($row['user_stop_code']) ? (string) $row['user_stop_code'] : '';
			
			$map_key = $journey_ref . '|' . $stop_code;
			$delay = isset($delay_map[$map_key]) ? $delay_map[$map_key] : array('delay_seconds' => 0, 'is_cancelled' => false);
			
			if (empty($delay['delay_seconds']) && empty($delay['is_cancelled']) && preg_match('/^[0-9]+$/', $stop_code)) {
				$alt_key = $journey_ref . '|NL:Q:' . $stop_code;
				$delay = isset($delay_map[$alt_key]) ? $delay_map[$alt_key] : array('delay_seconds' => 0, 'is_cancelled' => false);
			}

			$row['delay_seconds'] = (is_array($delay) && isset($delay['delay_seconds'])) ? (int) $delay['delay_seconds'] : 0;
			$row['is_cancelled'] = (is_array($delay) && !empty($delay['is_cancelled']));
			$row['expected_time'] = (is_array($delay) && isset($delay['expected_time'])) ? $delay['expected_time'] : null;
			$total_seconds = (int) $row['departure_seconds'] + (int) $row['offset_seconds'];
			$groups[$total_seconds][] = $row;
		}

		$rows = array();
		foreach ($groups as $total_seconds => $group) {
			if (count($group) > 1) {
				usort($group, function ($a, $b) {
					return strcmp($b['from_date'], $a['from_date']);
				});
			}
			$rows[] = $group[0];
		}

		$candidates = array();
		foreach ($rows as $row) {
			$date = new DateTimeImmutable($service_date . ' 00:00:00', $window['timezone']);
			$total_seconds = (int) $row['departure_seconds'] + (int) $row['offset_seconds'];
			$planned_candidate = $date->modify('+' . $total_seconds . ' seconds');
			if ($total_seconds < 5 * HOUR_IN_SECONDS) {
				$planned_candidate = $planned_candidate->modify('+1 day');
			}

			$realtime_candidate = $planned_candidate;
			if (!empty($row['delay_seconds'])) {
				$offset = (int) $row['delay_seconds'];
				$realtime_candidate = $planned_candidate->modify(($offset >= 0 ? '+' : '-') . abs($offset) . ' seconds');
			}

			// Blijf zichtbaar tot de geplande tijd is verstreken (bij voorloop) of tot de
			// vertraagde tijd is verstreken (bij vertraging): gebruik steeds de laatste van de twee.
			$visibility_candidate = $realtime_candidate > $planned_candidate ? $realtime_candidate : $planned_candidate;

			if ($visibility_candidate > $window['now'] && $realtime_candidate >= $window['start'] && $realtime_candidate < $window['end']) {
				$planned_time_str = $planned_candidate->format('H:i');
				$formatted = self::format_time_with_delay($planned_time_str, $row['delay_seconds'], $row['is_cancelled'], isset($row['expected_time']) ? $row['expected_time'] : '');
				$candidates[$realtime_candidate->getTimestamp()] = $formatted;
			}
		}

		if (empty($candidates)) {
			return array();
		}

		ksort($candidates, SORT_NUMERIC);
		return array_slice(array_values($candidates), 0, $limit);
	}

	private static function format_time_with_delay($time_str, $delay_seconds = 0, $is_cancelled = false, $expected_time = '') {
		$time_html = esc_html((string) $time_str);
		if ($is_cancelled) {
			return '<span style="color:#d00;font-weight:700;">Rijdt niet</span>';
		}
		// prefer explicit expected_time from realtime if provided
		if (!empty($expected_time)) {
			try {
				// Interpret stored expected_time as UTC (no tz info) and convert to
				// the site timezone for display. This avoids mismatches when the
				// daemon writes a naive datetime in server-local time.
				$site_tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
				$utc = new DateTimeZone('UTC');
				// First try strict Y-m-d H:i:s format as UTC
				$dt = DateTime::createFromFormat('Y-m-d H:i:s', $expected_time, $utc);
				if ($dt instanceof DateTime) {
					$dt->setTimezone($site_tz);
					return $dt->format('H:i');
				}
				// Fallback: attempt to parse with DateTime assuming UTC
				$dt2 = new DateTime($expected_time, $utc);
				if ($dt2 instanceof DateTime) {
					$dt2->setTimezone($site_tz);
					return $dt2->format('H:i');
				}
			} catch (Throwable $e) {
				// parsing failed — ignore and fall back to delay display
			}
		}

		if (empty($delay_seconds)) {
			return $time_html;
		}

		$sign = (int) $delay_seconds > 0 ? '+' : '-';
		$minutes = (int) floor((abs((int) $delay_seconds) + 59) / 60);
		$color = (int) $delay_seconds > 0 ? '#d00' : '#0a0';
		return $time_html . ' <span style="color:' . esc_attr($color) . ';font-weight:700;">' . esc_html($sign . $minutes) . '</span>';
	}

	private static function availability_matches_date(array $availability, $service_date) {
		$from_date = isset($availability['from_date']) ? (string) $availability['from_date'] : '';
		$to_date = isset($availability['to_date']) ? (string) $availability['to_date'] : '';
		$bits = isset($availability['valid_day_bits']) ? (string) $availability['valid_day_bits'] : '';

		if ($from_date === '' || $to_date === '' || $service_date < $from_date || $service_date > $to_date) {
			return false;
		}

		if ($bits === '') {
			return true;
		}

		$start = new DateTimeImmutable($from_date . ' 00:00:00');
		$date = new DateTimeImmutable($service_date . ' 00:00:00');
		$index = (int) $start->diff($date)->format('%a');

		return $index >= 0 && $index < strlen($bits) && $bits[$index] === '1';
	}

	private static function resolve_text_colour($background, $preferred) {
		if ($preferred) {
			return $preferred;
		}

		$background = ltrim($background, '#');
		if (strlen($background) !== 6) {
			return '#FFFFFF';
		}

		$r = hexdec(substr($background, 0, 2));
		$g = hexdec(substr($background, 2, 2));
		$b = hexdec(substr($background, 4, 2));
		$luminance = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

		return $luminance >= 150 ? '#000000' : '#FFFFFF';
	}

	private static function split_codes($raw) {
		$raw = trim((string) $raw);
		if ($raw === '') {
			return array();
		}

		$parts = preg_split('/[\s,;|]+/', $raw);
		if (!is_array($parts)) {
			return array();
		}

		$parts = array_map('sanitize_text_field', $parts);
		return array_values(array_unique(array_filter($parts)));
	}

	private static function normalize_stopplace_code($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		if (strpos($value, 'NL:S:') === 0) {
			return $value;
		}
		if (preg_match('/^\d+$/', $value)) {
			return 'NL:S:' . $value;
		}
		return $value;
	}

	private static function enqueue_frontend_style() {
		static $done = false;
		if ($done) {
			return;
		}
		$done = true;

		wp_register_style(self::FRONTEND_STYLE, false, array(), self::VERSION);
		wp_enqueue_style(self::FRONTEND_STYLE);
		wp_add_inline_style(
			self::FRONTEND_STYLE,
			'.ovhi-list{display:block;max-width:100%;}
			.ovhi-heading{font-family:circularstd-bold,sans-serif;font-size:18px;line-height:1.35;color:#861121;margin:0 0 10px;}
			.ovhi-item{display:flex;align-items:center;gap:10px;min-width:0;max-width:100%;width:100%;margin:0 0 10px;}
			.ovhi-badge{width:32px;height:32px;min-width:32px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-family:circularstd-bold,sans-serif;font-size:14px;line-height:1;text-align:center;box-sizing:border-box;padding:0 4px;text-decoration:none;}
			.ovhi-content{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;min-width:0;}
			.ovhi-destination{font-family:circularstd-bold,sans-serif;font-size:14px;line-height:1.35;color:#861121;min-width:0;word-break:break-word;text-decoration:none;}
			.ovhi-departures-link{font-family:circularstd-bold,sans-serif;font-size:16px;line-height:1.35;color:#861121;min-width:0;word-break:break-word;text-decoration:none;}
			.ovhi-item-link{width:100%;}
			.ovhi-link-spacer{margin:0 0 14px;}
			@media (max-width: 640px){.ovhi-item{align-items:flex-start;}.ovhi-content{display:block;}}'
		);
	}

	private static function get_uploaded_files($field) {
		if (!isset($_FILES[$field])) {
			return array();
		}

		$file = $_FILES[$field];
		if (!is_array($file['name'])) {
			return array($file);
		}

		$files = array();
		foreach ($file['name'] as $index => $name) {
			if (empty($name) || empty($file['tmp_name'][$index])) {
				continue;
			}
			$files[] = array(
				'name'     => $name,
				'type'     => isset($file['type'][$index]) ? $file['type'][$index] : '',
				'tmp_name' => isset($file['tmp_name'][$index]) ? $file['tmp_name'][$index] : '',
				'error'    => isset($file['error'][$index]) ? $file['error'][$index] : UPLOAD_ERR_NO_FILE,
				'size'     => isset($file['size'][$index]) ? $file['size'][$index] : 0,
			);
		}

		return $files;
	}
}

OV_Halte_Importer::init();