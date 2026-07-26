<?php
/**
 * Plugin Name: OV Trein Dienstregeling
 * Description: Toon treindienstregelingen voor ritten die minimaal één keer in Groningen of Drenthe stoppen, op basis van de NS IFF-dataset.
 * Version: 1.0.6
 * Author: Kevin van der Laan
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

class OV_Trein_Dienstregeling {
	const VERSION = '1.0.6';
	const OPTION_IMPORT_INFO = 'ovtd_import_info';
	const OPTION_HIDDEN_DIRECTIONS = 'ovtd_hidden_directions';
	const OPTION_IMPORT_STATE = 'ovtd_import_state';
	const OPTION_DIRECTION_SIGNATURE = 'ovtd_direction_signature';
	const NONCE_UPLOAD = 'ovtd_upload_datasets';
	const NONCE_IMPORT = 'ovtd_import_step';
	const NONCE_SETTINGS = 'ovtd_save_settings';
	const FRONTEND_STYLE = 'ovtd-frontend';
	const FALLBACK_COLOR = '#861121';
	const DIRECTION_SIGNATURE_VERSION = 7;
	const SERVICE_DAY_START_SECONDS = 18000;
	const IMPORT_TIME_BUDGET = 18;
	const IMPORT_TRIP_BATCH = 400;

	/** Stations in Groningen/Drenthe (RD-bbox + handmatige controle). */
	const REGIONAL_STATION_CODES = array(
		'apg', 'asn', 'bdm', 'bl', 'co', 'dln', 'dz', 'dzw', 'emn', 'emnz', 'gbg', 'gerp', 'gn', 'gnn',
		'hgv', 'hgz', 'hrn', 'hrnt', 'kw', 'lp', 'mp', 'mth', 'na', 'nsch', 'sda', 'stm', 'swd', 'vdm',
		'ws', 'wsm', 'zb',
	);

	const TRAIN_TYPE_SORT = array(
		'Intercity direct' => 10,
		'Intercity' => 20,
		'Sprinter' => 30,
		'Sneltrein' => 40,
		'Stoptrein' => 50,
		'Nachttrein' => 60,
		'Eurostar' => 70,
		'ICE' => 80,
		'EuroCity' => 90,
	);

	public static function init() {
		register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
		add_action('admin_menu', array(__CLASS__, 'admin_menu'));
		add_action('admin_post_ovtd_upload', array(__CLASS__, 'handle_upload'));
		add_action('wp_ajax_ovtd_import_step', array(__CLASS__, 'ajax_import_step'));
		add_action('admin_post_ovtd_save_settings', array(__CLASS__, 'save_settings'));
		add_action('admin_post_ovtd_rebuild_meta', array(__CLASS__, 'handle_rebuild_meta'));
		add_shortcode('ov_trein_dienstregeling', array(__CLASS__, 'render_shortcode_safely'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_enqueue_assets'));
	}

	public static function admin_enqueue_assets($hook) {
		if (strpos($hook, 'ov-trein-dienstregeling') !== false) {
			wp_enqueue_media();
		}
	}

	public static function activate() {
		self::create_tables();
		self::migrate_tables();
	}

	private static function table($suffix) {
		global $wpdb;
		return $wpdb->prefix . 'ovtd_' . $suffix;
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sql = array();
		$sql[] = 'CREATE TABLE ' . self::table('stations') . " (
			station_code varchar(12) NOT NULL,
			station_name varchar(255) NOT NULL default '',
			is_regional tinyint(1) NOT NULL default 0,
			x int(11) NOT NULL default 0,
			y int(11) NOT NULL default 0,
			attributes varchar(255) NOT NULL default '',
			PRIMARY KEY (station_code)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table('directions') . " (
			direction_ref varchar(64) NOT NULL,
			series_key varchar(40) NOT NULL default '',
			direction_bucket varchar(10) NOT NULL default '',
			train_type varchar(80) NOT NULL default '',
			departure_name varchar(255) NOT NULL default '',
			destination_name varchar(255) NOT NULL default '',
			operator_code varchar(10) NOT NULL default '',
			operator_name varchar(255) NOT NULL default '',
			label varchar(255) NOT NULL default '',
			sort_key int(11) NOT NULL default 0,
			PRIMARY KEY (direction_ref),
			KEY sort_key (sort_key)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table('footnotes') . " (
			footnote_ref varchar(10) NOT NULL,
			run_bits longtext NOT NULL,
			not_run_bits longtext NOT NULL,
			PRIMARY KEY (footnote_ref)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table('journeys') . " (
			journey_ref varchar(20) NOT NULL,
			direction_ref varchar(64) NOT NULL default '',
			company_code varchar(10) NOT NULL default '',
			train_number varchar(20) NOT NULL default '',
			line_code varchar(20) NOT NULL default '',
			transport_code varchar(10) NOT NULL default '',
			footnote_ref varchar(10) NOT NULL default '',
			attributes varchar(255) NOT NULL default '',
			departure_seconds int(11) NOT NULL default 0,
			arrival_seconds int(11) NOT NULL default 0,
			PRIMARY KEY (journey_ref),
			KEY direction_ref (direction_ref),
			KEY departure_seconds (departure_seconds)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table('journey_stops') . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			journey_ref varchar(20) NOT NULL default '',
			stop_order int(11) NOT NULL default 0,
			station_code varchar(12) NOT NULL default '',
			arrival_seconds int(11) NOT NULL default -1,
			departure_seconds int(11) NOT NULL default -1,
			PRIMARY KEY (id),
			UNIQUE KEY journey_stop (journey_ref, stop_order),
			KEY station_code (station_code)
		) $charset;";

		foreach ($sql as $statement) {
			dbDelta($statement);
		}
	}

	private static function migrate_tables() {
		global $wpdb;

		$table = self::table('stations');
		$columns = $wpdb->get_results("DESCRIBE $table");
		$column_names = wp_list_pluck($columns, 'Field');

		// Add x column if it doesn't exist
		if (!in_array('x', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table ADD COLUMN x int(11) NOT NULL default 0 AFTER is_regional");
		}

		// Add y column if it doesn't exist
		if (!in_array('y', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table ADD COLUMN y int(11) NOT NULL default 0 AFTER x");
		}

		// Add attributes column if it doesn't exist
		if (!in_array('attributes', $column_names, true)) {
			$wpdb->query("ALTER TABLE $table ADD COLUMN attributes varchar(255) NOT NULL default '' AFTER y");
		}

		$table_journeys = self::table('journeys');
		$columns_journeys = $wpdb->get_results("DESCRIBE $table_journeys");
		$column_names_journeys = wp_list_pluck($columns_journeys, 'Field');

		if (!in_array('attributes', $column_names_journeys, true)) {
			$wpdb->query("ALTER TABLE $table_journeys ADD COLUMN attributes varchar(255) NOT NULL default '' AFTER footnote_ref");
		}
	}

	public static function admin_menu() {
		add_submenu_page(
			'ovalino-menu',
			'OV Trein Dienstregeling',
			'OV Trein Dienstregeling',
			'manage_options',
			'ov-trein-dienstregeling',
			array(__CLASS__, 'render_admin_page')
		);
	}

	public static function render_admin_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om deze pagina te bekijken.', 'ovtd'));
		}

		self::create_tables();
		$info = get_option(self::OPTION_IMPORT_INFO, array());
		$notice = isset($_GET['ovtd_notice']) ? sanitize_text_field(wp_unslash($_GET['ovtd_notice'])) : '';
		$message = isset($_GET['ovtd_message']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['ovtd_message']))) : '';
		$logo_url = plugins_url('ovalinologo.png', dirname(__DIR__) . '/ov-halte-importer/ov-halte-importer.php');
		$import_state = get_option(self::OPTION_IMPORT_STATE, array());
		$needs_import = !empty($import_state['file']) && empty($import_state['finished']);
		?>
		<div class="wrap">
			<div style="display:flex; align-items:center; gap: 20px; margin-bottom: 20px; margin-top: 10px;">
				<?php if (file_exists(dirname(__DIR__) . '/ov-halte-importer/ovalinologo.png')) : ?>
					<img src="<?php echo esc_url($logo_url); ?>" alt="Ovalino Logo" style="height: 50px; width: auto;" />
				<?php endif; ?>
				<h1 style="margin:0;">OV Trein Dienstregeling</h1>
			</div>
			<?php if ($notice === 'success') : ?>
				<div class="notice notice-success"><p>Dataset opgeslagen. De gefilterde import wordt nu op de achtergrond uitgevoerd.</p></div>
			<?php elseif ($notice === 'error') : ?>
				<div class="notice notice-error"><p><?php echo $message ? esc_html($message) : 'De upload of import is mislukt.'; ?></p></div>
			<?php elseif (isset($_GET['ovtd_rebuilt'])) : ?>
				<div class="notice notice-success"><p>Stamdata en richtingen zijn opnieuw opgebouwd. Stations: <?php echo esc_html((string) self::count_table('stations')); ?>, regionaal: <?php echo esc_html((string) self::count_regional_stations()); ?>, richtingen: <?php echo esc_html((string) self::count_table('directions')); ?>.</p></div>
			<?php endif; ?>

			<p>Upload de NS IFF-bestanden (.dat). Alleen ritten die minimaal één station in Groningen of Drenthe aandoen worden opgeslagen.</p>
			<p>Bij elke nieuwe import worden eerder geüploade bestanden en alle treingegevens in de database definitief verwijderd.</p>
			<p>Verplicht: <code>delivery.dat</code>, <code>stations.dat</code>, <code>company.dat</code>, <code>trnsmode.dat</code>, <code>footnote.dat</code> en <code>timetbls.dat</code> (of <code>timetbls_new.dat</code>).</p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="ovtd_upload" />
				<?php wp_nonce_field(self::NONCE_UPLOAD); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ovtd_dataset_zip">IFF-bestanden</label></th>
						<td>
							<input type="file" id="ovtd_dataset_zip" name="ovtd_dataset_zip" accept=".zip,.dat" />
							<p class="description">Eén zip met alle .dat-bestanden, of selecteer meerdere .dat-bestanden hieronder.</p>
							<input type="file" name="ovtd_dataset_files[]" accept=".dat" multiple />
						</td>
					</tr>
				</table>
				<?php submit_button('Datasets uploaden'); ?>
			</form>

			<div id="ovtd-import-progress" style="<?php echo $needs_import ? '' : 'display:none;'; ?> max-width: 900px; margin: 24px 0;">
				<h2>Import voortgang</h2>
				<p id="ovtd-import-status">Wachten op start…</p>
				<progress id="ovtd-import-bar" max="100" value="0" style="width:100%; height: 22px;"></progress>
			</div>

			<?php if (!empty($info)) : ?>
				<h2>Laatste import</h2>
				<table class="widefat striped" style="max-width: 900px;">
					<tbody>
						<tr><td><strong>Datum</strong></td><td><?php echo !empty($info['imported_at']) ? esc_html($info['imported_at']) : '-'; ?></td></tr>
						<tr><td><strong>Geldigheid</strong></td><td><?php echo esc_html(isset($info['validity']) ? $info['validity'] : '-'); ?></td></tr>
						<tr><td><strong>Stations</strong></td><td><?php echo esc_html((string) (isset($info['counts']['stations']) ? $info['counts']['stations'] : 0)); ?></td></tr>
						<tr><td><strong>Regionale stations</strong></td><td><?php echo esc_html((string) (isset($info['counts']['regional_stations']) ? $info['counts']['regional_stations'] : 0)); ?></td></tr>
						<tr><td><strong>Richtingen</strong></td><td><?php echo esc_html((string) (isset($info['counts']['directions']) ? $info['counts']['directions'] : 0)); ?></td></tr>
						<tr><td><strong>Ritten opgeslagen</strong></td><td><?php echo esc_html((string) (isset($info['counts']['journeys']) ? $info['counts']['journeys'] : 0)); ?></td></tr>
						<tr><td><strong>Ritten overgeslagen</strong></td><td><?php echo esc_html((string) (isset($info['counts']['skipped']) ? $info['counts']['skipped'] : 0)); ?></td></tr>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Shortcode</h2>
			<p>Gebruik op een WordPress-pagina: <code>[ov_trein_dienstregeling]</code></p>

			<?php if (self::is_data_available()) : ?>
				<h2>Stamdata herstellen</h2>
				<p>Als stations of richtingen ontbreken maar de ritten wel zijn opgeslagen, kun je stamdata en richtingen opnieuw opbouwen uit de geüploade .dat-bestanden (zonder opnieuw de hele dienstregeling te importeren).</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="ovtd_rebuild_meta" />
					<?php wp_nonce_field('ovtd_rebuild_meta'); ?>
					<?php submit_button('Stamdata en richtingen opnieuw opbouwen', 'secondary'); ?>
				</form>
			<?php endif; ?>

			<h2>Richtingen verbergen</h2>
			<?php self::render_settings_form(); ?>
		</div>
		<script>
		(function(){
			var wrap = document.getElementById('ovtd-import-progress');
			if (!wrap || wrap.style.display === 'none') { return; }
			var status = document.getElementById('ovtd-import-status');
			var bar = document.getElementById('ovtd-import-bar');
			function step(){
				var body = new FormData();
				body.append('action', 'ovtd_import_step');
				body.append('_ajax_nonce', '<?php echo esc_js(wp_create_nonce(self::NONCE_IMPORT)); ?>');
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); })
					.then(function(data){
						if (!data || !data.success) {
							status.textContent = (data && data.data && data.data.message) ? data.data.message : 'Import mislukt.';
							return;
						}
						var p = data.data;
						status.textContent = p.message || 'Bezig…';
						bar.value = p.percent || 0;
						if (!p.done) { step(); }
						else { status.textContent = p.message || 'Import voltooid.'; bar.value = 100; }
					})
					.catch(function(){ status.textContent = 'Import mislukt (netwerkfout).'; });
			}
			step();
		})();
		</script>
		<?php
	}

	private static function render_settings_form() {
		$directions = self::get_directions(true);
		$hidden = self::get_hidden_directions();
		if (empty($directions)) {
			echo '<p>Nog geen richtingen beschikbaar. Voer eerst een import uit.</p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="ovtd_save_settings" />
			<?php wp_nonce_field(self::NONCE_SETTINGS); ?>

			<h2>Vervoerder Logo's</h2>
			<p>Upload of kies een logo voor elke vervoerder in de database. Deze worden rechtsboven de dienstregeling getoond.</p>
			<table class="form-table" style="max-width: 900px; margin-bottom: 20px;">
				<?php
				$operators = self::get_operators();
				$logos = get_option('ovtd_operator_logos', array());
				foreach ($operators as $op) :
					$code = $op['operator_code'];
					$name = $op['operator_name'];
					$value = isset($logos[$code]) ? $logos[$code] : '';
					?>
					<tr>
						<th scope="row" style="width: 200px;"><label for="logo_<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)</label></th>
						<td>
							<div style="display: flex; align-items: center; gap: 10px;">
								<input type="text" name="operator_logos[<?php echo esc_attr($code); ?>]" id="logo_<?php echo esc_attr($code); ?>" value="<?php echo esc_url($value); ?>" class="regular-text" placeholder="https://..." />
								<button type="button" class="button ov-upload-logo-btn" data-target="logo_<?php echo esc_attr($code); ?>">Selecteer logo</button>
								<button type="button" class="button ov-clear-logo-btn" data-target="logo_<?php echo esc_attr($code); ?>">Verwijder</button>
								<img src="<?php echo esc_url($value); ?>" id="preview_logo_<?php echo esc_attr($code); ?>" style="max-height: 40px; max-width: 100px; display: <?php echo $value ? 'block' : 'none'; ?>; object-fit: contain;" />
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<hr />

			<h2>NS Reisinformatie API</h2>
			<p>Voer hier je NS API-sleutel in om actuele vertragingen en rituitval van NS-treinen te tonen.<br />
			Aanvragen kan gratis via <a href="https://apiportal.ns.nl/" target="_blank">apiportal.ns.nl</a> → abonneer op het product <strong>"NS App"</strong>.</p>
			<table class="form-table" style="max-width: 900px; margin-bottom: 20px;">
				<tr>
					<th scope="row"><label for="ovtd_ns_api_key">NS API Subscription Key</label></th>
					<td>
						<input type="text" name="ns_api_key" id="ovtd_ns_api_key"
							value="<?php echo esc_attr(get_option('ovtd_ns_api_key', '')); ?>"
							class="regular-text" placeholder="Ocp-Apim-Subscription-Key..." />
						<p class="description">Wordt veilig opgeslagen en alleen gebruikt voor server-naar-server aanroepen naar de NS API.</p>
					</td>
				</tr>
			</table>
			<hr />

			<h2>Richtingen verbergen</h2>
			<table class="widefat striped" style="max-width: 900px; margin-bottom: 20px;">
				<thead><tr><th>Verbergen</th><th>Richting</th></tr></thead>
				<tbody>
					<?php foreach ($directions as $direction) : ?>
						<tr>
							<td><input type="checkbox" name="hidden_directions[]" value="<?php echo esc_attr($direction['direction_ref']); ?>" <?php checked(in_array($direction['direction_ref'], $hidden, true)); ?> /></td>
							<td><?php echo esc_html($direction['label']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button('Instellingen opslaan'); ?>
		</form>

		<script>
		jQuery(document).ready(function($){
			$('.ov-upload-logo-btn').click(function(e) {
				e.preventDefault();
				var targetId = $(this).data('target');
				var input = $('#' + targetId);
				var preview = $('#preview_' + targetId);
				var mediaUploader = wp.media({
					title: 'Kies of upload een logo',
					button: {
						text: 'Logo gebruiken'
					},
					multiple: false
				});
				mediaUploader.on('select', function() {
					var attachment = mediaUploader.state().get('selection').first().toJSON();
					input.val(attachment.url);
					preview.attr('src', attachment.url).show();
				});
				mediaUploader.open();
			});
			$('.ov-clear-logo-btn').click(function(e) {
				e.preventDefault();
				var targetId = $(this).data('target');
				$('#' + targetId).val('');
				$('#preview_' + targetId).attr('src', '').hide();
			});
		});
		</script>
		<?php
	}

	public static function handle_rebuild_meta() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om dit uit te voeren.', 'ovtd'));
		}
		check_admin_referer('ovtd_rebuild_meta');

		try {
			self::reimport_reference_data_from_dataset();
			self::reassign_journey_direction_refs();
			self::finalize_directions();
			update_option(self::OPTION_DIRECTION_SIGNATURE, self::DIRECTION_SIGNATURE_VERSION, false);
		} catch (Exception $exception) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'ov-trein-dienstregeling',
						'ovtd_notice' => 'error',
						'ovtd_message' => rawurlencode($exception->getMessage()),
					),
					admin_url('admin.php')
				)
			);
			exit;
		}

		wp_safe_redirect(add_query_arg(array('page' => 'ov-trein-dienstregeling', 'ovtd_rebuilt' => '1'), admin_url('admin.php')));
		exit;
	}

	public static function save_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om dit uit te voeren.', 'ovtd'));
		}
		check_admin_referer(self::NONCE_SETTINGS);
		$hidden = isset($_POST['hidden_directions']) && is_array($_POST['hidden_directions']) ? wp_unslash($_POST['hidden_directions']) : array();
		$hidden = array_values(array_unique(array_filter(array_map('sanitize_text_field', $hidden))));
		update_option(self::OPTION_HIDDEN_DIRECTIONS, $hidden, false);

		$logos = isset($_POST['operator_logos']) && is_array($_POST['operator_logos']) ? wp_unslash($_POST['operator_logos']) : array();
		$sanitized_logos = array();
		foreach ($logos as $op_code => $url) {
			$sanitized_logos[sanitize_text_field($op_code)] = esc_url_raw($url);
		}
		update_option('ovtd_operator_logos', $sanitized_logos, false);

		// NS API key
		$ns_api_key = isset($_POST['ns_api_key']) ? sanitize_text_field(wp_unslash($_POST['ns_api_key'])) : '';
		update_option('ovtd_ns_api_key', $ns_api_key, false);

		wp_safe_redirect(add_query_arg(array('page' => 'ov-trein-dienstregeling', 'updated' => 'true'), admin_url('admin.php')));
		exit;
	}

	public static function handle_upload() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om dit uit te voeren.', 'ovtd'));
		}
		@set_time_limit(0);
		wp_raise_memory_limit('admin');
		check_admin_referer(self::NONCE_UPLOAD);

		require_once ABSPATH . 'wp-admin/includes/file.php';

		self::purge_all_import_data();
		wp_mkdir_p(self::dataset_dir());

		$saved = self::save_uploaded_datasets();
		if (is_wp_error($saved)) {
			self::redirect_admin('error', $saved->get_error_message());
		}

		try {
			self::create_tables();
			self::import_reference_files(self::dataset_dir());
			$timetable = self::find_timetable_file(self::dataset_dir());
			if (!$timetable) {
				throw new Exception('timetbls.dat of timetbls_new.dat ontbreekt.');
			}
			update_option(
				self::OPTION_IMPORT_STATE,
				array(
					'file' => $timetable,
					'offset' => 0,
					'finished' => false,
					'skipped' => 0,
					'stored' => 0,
					'total_trips' => 0,
					'pending_trip' => null,
				),
				false
			);
		} catch (Exception $exception) {
			self::redirect_admin('error', $exception->getMessage());
		}

		self::redirect_admin('success');
	}

	public static function ajax_import_step() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Geen rechten.'), 403);
		}
		check_ajax_referer(self::NONCE_IMPORT);

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		$state = get_option(self::OPTION_IMPORT_STATE, array());
		if (empty($state['file']) || !empty($state['finished'])) {
			wp_send_json_success(array('done' => true, 'percent' => 100, 'message' => 'Geen openstaande import.'));
		}

		try {
			$result = self::import_timetable_chunk($state);
			update_option(self::OPTION_IMPORT_STATE, $result['state'], false);

			if (!empty($result['state']['finished'])) {
				self::ensure_reference_data();
				self::reassign_journey_direction_refs();
				self::finalize_directions();
				if (self::count_table('directions') < 1 && self::count_table('journeys') > 0) {
					self::reassign_journey_direction_refs();
					self::finalize_directions();
				}
				$delivery = self::parse_delivery_header(self::dataset_dir() . '/delivery.dat');
				update_option(
					self::OPTION_IMPORT_INFO,
					array(
						'imported_at' => current_time('mysql'),
						'validity' => $delivery ? ($delivery['from'] . ' t/m ' . $delivery['to']) : '',
						'counts' => array(
							'stations' => (int) self::count_table('stations'),
							'regional_stations' => (int) self::count_regional_stations(),
							'directions' => (int) self::count_table('directions'),
							'journeys' => (int) $result['state']['stored'],
							'skipped' => (int) $result['state']['skipped'],
						),
					),
					false
				);
				update_option(self::OPTION_DIRECTION_SIGNATURE, self::DIRECTION_SIGNATURE_VERSION, false);
				delete_option(self::OPTION_IMPORT_STATE);
			}

			wp_send_json_success(
				array(
					'done' => !empty($result['state']['finished']),
					'percent' => (int) $result['percent'],
					'message' => $result['message'],
				)
			);
		} catch (Exception $exception) {
			wp_send_json_error(array('message' => $exception->getMessage()));
		}
	}

	private static function count_table($suffix) {
		global $wpdb;
		return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table($suffix));
	}

	private static function count_regional_stations() {
		global $wpdb;
		return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('stations') . ' WHERE is_regional = 1');
	}

	private static function save_uploaded_datasets() {
		$dataset_dir = self::dataset_dir();
		$required = array('delivery.dat', 'stations.dat', 'company.dat', 'trnsmode.dat', 'footnote.dat');
		$saved_files = array();

		if (!empty($_FILES['ovtd_dataset_zip']['tmp_name']) && is_uploaded_file($_FILES['ovtd_dataset_zip']['tmp_name'])) {
			$zip_path = $_FILES['ovtd_dataset_zip']['tmp_name'];
			$name = strtolower((string) $_FILES['ovtd_dataset_zip']['name']);
			if (substr($name, -4) === '.zip' && class_exists('ZipArchive')) {
				$zip = new ZipArchive();
				if ($zip->open($zip_path) !== true) {
					return new WP_Error('ovtd_zip', 'Zip-bestand kon niet worden geopend.');
				}
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$entry = $zip->getNameIndex($i);
					if (substr($entry, -1) === '/') {
						continue;
					}
					$base = strtolower(basename($entry));
					if (substr($base, -4) !== '.dat') {
						continue;
					}
					$target = trailingslashit($dataset_dir) . $base;
					copy('zip://' . $zip_path . '#' . $entry, $target);
					$saved_files[] = $base;
				}
				$zip->close();
			} elseif (substr($name, -4) === '.dat') {
				$target = trailingslashit($dataset_dir) . sanitize_file_name(basename($name));
				if (!move_uploaded_file($zip_path, $target)) {
					return new WP_Error('ovtd_upload', 'Upload mislukt.');
				}
				$saved_files[] = basename($target);
			}
		}

		if (!empty($_FILES['ovtd_dataset_files']['tmp_name']) && is_array($_FILES['ovtd_dataset_files']['tmp_name'])) {
			foreach ($_FILES['ovtd_dataset_files']['tmp_name'] as $index => $tmp) {
				if (empty($tmp) || !is_uploaded_file($tmp)) {
					continue;
				}
				$base = strtolower(sanitize_file_name((string) $_FILES['ovtd_dataset_files']['name'][$index]));
				$target = trailingslashit($dataset_dir) . $base;
				if (!move_uploaded_file($tmp, $target)) {
					return new WP_Error('ovtd_upload', 'Upload mislukt voor ' . $base);
				}
				$saved_files[] = $base;
			}
		}

		if (empty($saved_files)) {
			return new WP_Error('ovtd_upload', 'Geen .dat-bestanden ontvangen.');
		}

		foreach ($required as $file) {
			if (!file_exists(trailingslashit($dataset_dir) . $file)) {
				return new WP_Error('ovtd_missing', 'Bestand ontbreekt: ' . $file);
			}
		}
		if (!self::find_timetable_file($dataset_dir)) {
			return new WP_Error('ovtd_missing', 'timetbls.dat of timetbls_new.dat ontbreekt.');
		}

		return true;
	}

	private static function find_timetable_file($dir) {
		$new = trailingslashit($dir) . 'timetbls_new.dat';
		$old = trailingslashit($dir) . 'timetbls.dat';
		if (file_exists($new)) {
			return $new;
		}
		if (file_exists($old)) {
			return $old;
		}
		return '';
	}

	private static function import_reference_files($dir) {
		self::$station_names = array();
		self::$regional_codes = array();
		self::seed_regional_station_codes();

		$imported_stations = self::import_stations(trailingslashit($dir) . 'stations.dat');
		if ($imported_stations < 1) {
			throw new Exception('Geen stations ingelezen uit stations.dat. Controleer of het bestand in de upload zit.');
		}

		self::import_companies(trailingslashit($dir) . 'company.dat');
		self::import_trnsmodes(trailingslashit($dir) . 'trnsmode.dat');
		self::import_footnotes(trailingslashit($dir) . 'footnote.dat');
		self::import_attributes(trailingslashit($dir) . 'trnsattr.dat');
		self::import_station_attributes(trailingslashit($dir) . 'stationattributes.dat', trailingslashit($dir) . 'attributesonstation.dat');
	}

	/**
	 * Herstelt ontbrekende stamdata (bijv. als alleen de dienstregeling opnieuw is geïmporteerd).
	 */
	private static function ensure_reference_data() {
		global $wpdb;

		self::create_tables();
		$dir = trailingslashit(self::dataset_dir());

		$station_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('stations'));
		if ($station_count < 1 && file_exists($dir . 'stations.dat')) {
			self::$station_names = array();
			self::seed_regional_station_codes();
			self::import_stations($dir . 'stations.dat');
		}

		if (empty(self::$companies) && file_exists($dir . 'company.dat')) {
			self::import_companies($dir . 'company.dat');
		}
		if (empty(self::$trnsmodes) && file_exists($dir . 'trnsmode.dat')) {
			self::import_trnsmodes($dir . 'trnsmode.dat');
		}

		$footnote_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('footnotes'));
		if ($footnote_count < 1 && file_exists($dir . 'footnote.dat')) {
			self::import_footnotes($dir . 'footnote.dat');
		}

		self::load_runtime_maps();
	}

	/**
	 * Leest stations, vervoerders en voetnoten opnieuw in uit de geüploade dataset.
	 */
	private static function reimport_reference_data_from_dataset() {
		self::create_tables();
		$dir = trailingslashit(self::dataset_dir());
		if (!is_dir($dir)) {
			throw new Exception('Geen geüploade dataset gevonden. Upload eerst alle .dat-bestanden opnieuw.');
		}
		if (!file_exists($dir . 'stations.dat')) {
			throw new Exception('stations.dat ontbreekt in de uploadmap. Upload de dataset opnieuw (inclusief stations.dat).');
		}

		self::$station_names = array();
		self::$regional_codes = array();
		self::$companies = array();
		self::$trnsmodes = array();
		self::seed_regional_station_codes();

		$imported_stations = self::import_stations($dir . 'stations.dat');
		if ($imported_stations < 1) {
			throw new Exception('Geen stations ingelezen uit stations.dat. Controleer het bestand.');
		}

		if (file_exists($dir . 'company.dat')) {
			self::import_companies($dir . 'company.dat');
		}
		if (file_exists($dir . 'trnsmode.dat')) {
			self::import_trnsmodes($dir . 'trnsmode.dat');
		}
		if (file_exists($dir . 'footnote.dat')) {
			$wpdb = $GLOBALS['wpdb'];
			$wpdb->query('DELETE FROM ' . self::table('footnotes'));
			self::import_footnotes($dir . 'footnote.dat');
		}
		if (file_exists($dir . 'trnsattr.dat')) {
			self::import_attributes($dir . 'trnsattr.dat');
		}
		if (file_exists($dir . 'stationattributes.dat') && file_exists($dir . 'attributesonstation.dat')) {
			self::import_station_attributes($dir . 'stationattributes.dat', $dir . 'attributesonstation.dat');
		}

		self::load_runtime_maps();
	}

	private static function normalize_station_code($code) {
		return strtolower(trim((string) $code));
	}

	private static function seed_regional_station_codes() {
		foreach (self::REGIONAL_STATION_CODES as $code) {
			self::$regional_codes[self::normalize_station_code($code)] = true;
		}
	}

	private static function is_regional_station($station_code) {
		$station_code = self::normalize_station_code($station_code);
		if ($station_code === '') {
			return false;
		}
		if (!empty(self::$regional_codes[$station_code])) {
			return true;
		}
		if (in_array($station_code, self::REGIONAL_STATION_CODES, true)) {
			return true;
		}
		return false;
	}

	private static function import_stations($file) {
		global $wpdb;

		$regional = array_flip(array_map(array(__CLASS__, 'normalize_station_code'), self::REGIONAL_STATION_CODES));
		$table = self::table('stations');
		$imported = 0;
		$handle = self::open_dat_file($file);

		while (($line = fgets($handle)) !== false) {
			$parsed = self::parse_station_line($line);
			if (!$parsed) {
				continue;
			}

			$code = $parsed['station_code'];
			$name = $parsed['station_name'];
			$is_regional = isset($regional[$code]) ? 1 : 0;
			if (!$is_regional && $parsed['country'] === 'NL') {
				$is_regional = self::coordinates_in_region($parsed['x'], $parsed['y']) ? 1 : 0;
			}

			self::$station_names[$code] = $name;
			if ($is_regional) {
				self::$regional_codes[$code] = true;
			}

			$result = $wpdb->replace(
				$table,
				array(
					'station_code' => $code,
					'station_name' => $name,
					'is_regional' => $is_regional,
					'x' => $parsed['x'],
					'y' => $parsed['y'],
				),
				array('%s', '%s', '%d', '%d', '%d')
			);
			if ($result !== false) {
				$imported++;
			}
		}
		fclose($handle);

		return $imported;
	}

	private static function parse_station_line($line) {
		$line = rtrim($line, "\r\n");
		if ($line === '' || $line[0] === '@') {
			return null;
		}

		$parts = array_map('trim', explode(',', $line));
		if (count($parts) < 8) {
			return null;
		}

		$code = self::normalize_station_code($parts[1]);
		if ($code === '') {
			return null;
		}

		$name = trim((string) $parts[count($parts) - 1]);
		if ($name === '') {
			return null;
		}

		return array(
			'station_code' => $code,
			'station_name' => $name,
			'country' => isset($parts[4]) ? trim($parts[4]) : '',
			'x' => isset($parts[7]) ? (int) $parts[7] : 0,
			'y' => isset($parts[8]) ? (int) $parts[8] : 0,
		);
	}

	private static function load_station_names_from_file($file) {
		if (!file_exists($file)) {
			return;
		}

		$regional = array_flip(array_map(array(__CLASS__, 'normalize_station_code'), self::REGIONAL_STATION_CODES));
		$handle = self::open_dat_file($file);
		while (($line = fgets($handle)) !== false) {
			$parsed = self::parse_station_line($line);
			if (!$parsed) {
				continue;
			}
			$code = $parsed['station_code'];
			self::$station_names[$code] = $parsed['station_name'];
			if (isset($regional[$code]) || ($parsed['country'] === 'NL' && self::coordinates_in_region($parsed['x'], $parsed['y']))) {
				self::$regional_codes[$code] = true;
			}
		}
		fclose($handle);
	}

	private static function get_station_display_name($station_code) {
		$station_code = self::normalize_station_code($station_code);
		if ($station_code === '') {
			return '';
		}
		if (isset(self::$station_names[$station_code]) && self::$station_names[$station_code] !== '') {
			return self::$station_names[$station_code];
		}

		global $wpdb;
		$name = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT station_name FROM ' . self::table('stations') . ' WHERE station_code = %s',
				$station_code
			)
		);
		if ($name) {
			self::$station_names[$station_code] = $name;
			return $name;
		}

		return strtoupper($station_code);
	}

	private static function coordinates_in_region($x_raw, $y_raw) {
		$x = (int) $x_raw * 10;
		$y = (int) $y_raw * 10;
		$in_gr = ($x >= 228000 && $x <= 290000 && $y >= 548000 && $y <= 596000);
		$in_dr = ($x >= 205000 && $x <= 278000 && $y >= 512000 && $y <= 565000);
		return $in_gr || $in_dr;
	}

	private static $companies = array();
	private static $trnsmodes = array();
	private static $station_names = array();
	private static $regional_codes = array();

	private static function import_companies($file) {
		self::$companies = array();
		$handle = self::open_dat_file($file);
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if ($line === '' || $line[0] === '@') {
				continue;
			}
			$parts = array_map('trim', explode(',', $line));
			if (count($parts) < 3) {
				continue;
			}
			self::$companies[$parts[0]] = $parts[2] !== '' ? $parts[2] : $parts[1];
		}
		fclose($handle);
	}

	private static function import_trnsmodes($file) {
		self::$trnsmodes = array();
		$handle = self::open_dat_file($file);
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if ($line === '' || $line[0] === '@') {
				continue;
			}
			$parts = array_map('trim', explode(',', $line, 2));
			if (count($parts) < 2) {
				continue;
			}
			self::$trnsmodes[trim($parts[0])] = trim($parts[1]);
		}
		fclose($handle);
	}

	private static function import_attributes($file) {
		if (!file_exists($file)) {
			return;
		}
		$attributes = array();
		$handle = self::open_dat_file($file);
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if ($line === '' || $line[0] === '@') {
				continue;
			}
			$parts = array_map('trim', explode(',', $line));
			if (count($parts) < 3) {
				continue;
			}
			$attributes[$parts[0]] = $parts[2] !== '' ? $parts[2] : $parts[1];
		}
		fclose($handle);
		update_option('ovtd_train_attributes', $attributes, false);
	}

	private static function import_station_attributes($desc_file, $assignment_file) {
		global $wpdb;
		if (!file_exists($desc_file) || !file_exists($assignment_file)) {
			return;
		}

		// 1. Parse descriptions
		$descriptions = array();
		$handle = self::open_dat_file($desc_file);
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if ($line === '' || $line[0] === '@') {
				continue;
			}
			$parts = array_map('trim', explode(',', $line));
			if (count($parts) >= 2) {
				$descriptions[$parts[0]] = $parts[1];
			}
		}
		fclose($handle);
		update_option('ovtd_station_attribute_descriptions', $descriptions, false);

		// 2. Parse assignments
		$station_attrs = array();
		$current_station = '';
		$handle = self::open_dat_file($assignment_file);
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if ($line === '' || $line[0] === '@') {
				continue;
			}
			if ($line[0] === '#') {
				$current_station = self::normalize_station_code(substr($line, 1));
			} elseif ($line[0] === '-' && $current_station !== '') {
				$attr = trim(substr($line, 1));
				if (!isset($station_attrs[$current_station])) {
					$station_attrs[$current_station] = array();
				}
				$station_attrs[$current_station][] = $attr;
			}
		}
		fclose($handle);

		// 3. Update stations table
		foreach ($station_attrs as $code => $attrs) {
			$attr_string = implode(',', array_unique($attrs));
			$wpdb->update(
				self::table('stations'),
				array('attributes' => $attr_string),
				array('station_code' => $code),
				array('%s'),
				array('%s')
			);
		}
	}

	private static function import_footnotes($file) {
		$rows = array();
		$handle = self::open_dat_file($file);
		$current = '';
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if ($line === '' || $line[0] === '@') {
				continue;
			}
			if ($line[0] === '#') {
				if ($current !== '') {
					$rows[] = $current;
				}
				$current = array(
					'footnote_ref' => ltrim(substr($line, 1), '#'),
					'run_bits' => '',
					'not_run_bits' => '',
				);
				continue;
			}
			if ($current === '') {
				continue;
			}
			if ($current['run_bits'] === '') {
				$current['run_bits'] = preg_replace('/[^01]/', '', $line);
			} elseif ($current['not_run_bits'] === '') {
				$current['not_run_bits'] = preg_replace('/[^01]/', '', $line);
				$rows[] = $current;
				$current = '';
			}
			if (count($rows) >= 100) {
				self::bulk_upsert('footnotes', array('footnote_ref', 'run_bits', 'not_run_bits'), $rows);
				$rows = array();
			}
		}
		fclose($handle);
		if ($current !== '') {
			$rows[] = $current;
		}
		if (!empty($rows)) {
			self::bulk_upsert('footnotes', array('footnote_ref', 'run_bits', 'not_run_bits'), $rows);
		}
	}

	private static function load_runtime_maps() {
		global $wpdb;

		self::seed_regional_station_codes();

		$stations_file = trailingslashit(self::dataset_dir()) . 'stations.dat';
		$station_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('stations'));

		if ($station_count > 0) {
			$stations = $wpdb->get_results('SELECT station_code, station_name, is_regional FROM ' . self::table('stations'), ARRAY_A);
			foreach ($stations as $station) {
				$code = self::normalize_station_code($station['station_code']);
				self::$station_names[$code] = $station['station_name'];
				if ((int) $station['is_regional'] === 1) {
					self::$regional_codes[$code] = true;
				}
			}
		} elseif (file_exists($stations_file)) {
			self::load_station_names_from_file($stations_file);
		}

		if (empty(self::$companies) && file_exists(trailingslashit(self::dataset_dir()) . 'company.dat')) {
			self::import_companies(trailingslashit(self::dataset_dir()) . 'company.dat');
		}
		if (empty(self::$trnsmodes) && file_exists(trailingslashit(self::dataset_dir()) . 'trnsmode.dat')) {
			self::import_trnsmodes(trailingslashit(self::dataset_dir()) . 'trnsmode.dat');
		}
	}

	private static function import_timetable_chunk(array $state) {
		self::ensure_reference_data();
		$file = $state['file'];
		$offset = isset($state['offset']) ? (int) $state['offset'] : 0;
		$skipped = isset($state['skipped']) ? (int) $state['skipped'] : 0;
		$stored = isset($state['stored']) ? (int) $state['stored'] : 0;
		$processed = 0;
		$deadline = time() + self::IMPORT_TIME_BUDGET;

		$handle = fopen($file, 'rb');
		if (!$handle) {
			throw new Exception('Timetable-bestand niet leesbaar.');
		}
		if ($offset > 0) {
			fseek($handle, $offset);
		}

		$trip = !empty($state['pending_trip']) && is_array($state['pending_trip']) ? $state['pending_trip'] : null;
		$pending_line = null;

		if ($offset > 0 && !$trip) {
			while (($line = fgets($handle)) !== false) {
				$line = rtrim($line, "\r\n");
				if ($line !== '' && $line[0] === '#') {
					$pending_line = $line;
					break;
				}
			}
		}

		$size = filesize($file);
		if (!$size) {
			$size = 1;
		}

		while (!feof($handle) && time() < $deadline && $processed < self::IMPORT_TRIP_BATCH) {
			if ($pending_line !== null) {
				$line = $pending_line;
				$pending_line = null;
			} else {
				$line = fgets($handle);
				if ($line === false) {
					break;
				}
				$line = rtrim($line, "\r\n");
			}

			if ($line === '' || $line[0] === '@') {
				continue;
			}

			$type = $line[0];
			if ($type === '#') {
				if ($trip) {
					if (self::store_trip_if_regional($trip)) {
						$stored++;
					} else {
						$skipped++;
					}
					$processed++;
				}
				$trip = self::new_trip($line);
				continue;
			}
			if (!$trip) {
				continue;
			}

			switch ($type) {
				case '%':
					$trip['header'] = self::parse_trip_header($line);
					break;
				case '-':
					$trip['footnote_ref'] = self::parse_footnote_ref($line);
					break;
				case '&':
					$trip['transport_code'] = self::parse_transport_code($line);
					break;
				case '*':
					if (!isset($trip['attributes'])) {
						$trip['attributes'] = array();
					}
					$attr = self::parse_trip_attribute($line);
					if ($attr) {
						$trip['attributes'][] = $attr;
					}
					break;
				case '>':
				case '.':
				case '+':
				case ';':
				case '<':
					$stop = self::parse_stop_line($line, $type);
					if ($stop) {
						$trip['stops'][] = $stop;
					}
					break;
			}
		}

		$finished = feof($handle);

		if ($trip && $finished) {
			if (self::store_trip_if_regional($trip)) {
				$stored++;
			} else {
				$skipped++;
			}
			$processed++;
			$trip = null;
		}

		$new_offset = ftell($handle);
		fclose($handle);

		$percent = min(99, (int) floor(($new_offset / $size) * 100));
		$state['offset'] = $new_offset;
		$state['skipped'] = $skipped;
		$state['stored'] = $stored;
		$state['finished'] = $finished;
		$state['pending_trip'] = (!$finished && $trip) ? $trip : null;

		return array(
			'state' => $state,
			'percent' => $finished ? 100 : $percent,
			'message' => $finished
				? sprintf('Import voltooid: %d ritten opgeslagen, %d overgeslagen.', $stored, $skipped)
				: sprintf('Bezig… %d ritten opgeslagen (%d%%).', $stored, $percent),
		);
	}

	private static function new_trip($line) {
		return array(
			'journey_ref' => ltrim(substr($line, 1), '#'),
			'header' => array(),
			'footnote_ref' => '',
			'transport_code' => '',
			'attributes' => array(),
			'stops' => array(),
		);
	}

	private static function parse_trip_attribute($line) {
		$parts = array_map('trim', explode(',', substr($line, 1)));
		$code = isset($parts[0]) ? trim($parts[0]) : '';
		if ($code === '') {
			return null;
		}
		return array(
			'code' => $code,
			'start_order' => isset($parts[1]) ? (int) $parts[1] : 0,
			'end_order' => isset($parts[2]) ? (int) $parts[2] : 999,
		);
	}

	private static function parse_trip_header($line) {
		$body = substr($line, 1);
		$parts = array_map('trim', explode(',', $body));
		return array(
			'company_code' => isset($parts[0]) ? $parts[0] : '',
			'train_number' => isset($parts[1]) ? self::normalize_train_number($parts[1]) : '',
			'line_code' => isset($parts[5]) ? trim((string) $parts[5]) : '',
		);
	}

	/**
	 * Normalize a train number to its canonical form without leading zeros.
	 *
	 * The IFF dataset stores train numbers in a fixed-width field, so NS
	 * trains (mostly < 10000) routinely come out as "03617" while the
	 * realtime InfoPlus DVS feed reports the same train unpadded as "3617".
	 * Arriva trains are mostly >= 10000 and therefore rarely padded, which
	 * is why this mismatch showed up as "Arriva works, NS doesn't" even
	 * though it affects any operator with train numbers below 10000.
	 * Normalizing on import (and doing the same on the realtime-daemon side)
	 * makes the exact-string match in get_realtime_delays_for_journeys() /
	 * find_next_departures_at_station() work regardless of padding.
	 */
	private static function normalize_train_number($value) {
		$value = trim((string) $value);
		if ($value !== '' && ctype_digit($value)) {
			$value = (string) (int) $value;
		}
		return $value;
	}

	private static function parse_footnote_ref($line) {
		$parts = array_map('trim', explode(',', substr($line, 1)));
		return isset($parts[0]) ? $parts[0] : '';
	}

	private static function parse_transport_code($line) {
		$parts = array_map('trim', explode(',', substr($line, 1)));
		return isset($parts[0]) ? trim($parts[0]) : '';
	}

	private static function parse_stop_line($line, $type) {
		$body = substr($line, 1);
		$parts = array_map('trim', explode(',', $body));
		$code = isset($parts[0]) ? self::normalize_station_code($parts[0]) : '';
		if ($code === '') {
			return null;
		}

		$stop = array(
			'station_code' => $code,
			'arrival_seconds' => -1,
			'departure_seconds' => -1,
			'pass_through' => ($type === ';'),
		);

		if ($type === '>' && isset($parts[1])) {
			$stop['departure_seconds'] = self::iff_time_to_seconds($parts[1]);
		} elseif ($type === '<' && isset($parts[1])) {
			$stop['arrival_seconds'] = self::iff_time_to_seconds($parts[1]);
		} elseif ($type === '.' && isset($parts[1])) {
			$t = self::iff_time_to_seconds($parts[1]);
			$stop['arrival_seconds'] = $t;
			$stop['departure_seconds'] = $t;
		} elseif ($type === '+' && isset($parts[1], $parts[2])) {
			$stop['arrival_seconds'] = self::iff_time_to_seconds($parts[1]);
			$stop['departure_seconds'] = self::iff_time_to_seconds($parts[2]);
		}

		return $stop;
	}

	private static function iff_time_to_seconds($value) {
		$value = preg_replace('/\D/', '', (string) $value);
		if (strlen($value) < 3) {
			return 0;
		}
		$hours = (int) substr($value, 0, -2);
		$minutes = (int) substr($value, -2);
		return ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS);
	}

	private static function store_trip_if_regional(array $trip) {
		$stops = isset($trip['stops']) ? $trip['stops'] : array();
		if (count($stops) < 2) {
			return false;
		}

		$touches_region = false;
		foreach ($stops as $stop) {
			if (!empty($stop['pass_through'])) {
				continue;
			}
			if (self::is_regional_station($stop['station_code'])) {
				$touches_region = true;
				break;
			}
		}
		if (!$touches_region) {
			return false;
		}

		$first = $stops[0];
		$last = $stops[count($stops) - 1];
		$departure = (int) $first['departure_seconds'];
		if ($departure < 0 && (int) $first['arrival_seconds'] >= 0) {
			$departure = (int) $first['arrival_seconds'];
		}
		$arrival = (int) $last['arrival_seconds'];
		if ($arrival < 0 && (int) $last['departure_seconds'] >= 0) {
			$arrival = (int) $last['departure_seconds'];
		}
		if ($departure < 0 || $arrival < 0) {
			return false;
		}

		$company_code = isset($trip['header']['company_code']) ? $trip['header']['company_code'] : '';
		$line_code = isset($trip['header']['line_code']) ? $trip['header']['line_code'] : '';
		$operator_name = isset(self::$companies[$company_code]) ? self::$companies[$company_code] : $company_code;
		$transport_code = trim((string) $trip['transport_code']);
		$train_type = isset(self::$trnsmodes[$transport_code]) ? self::$trnsmodes[$transport_code] : $transport_code;
		$departure_code = $first['station_code'];
		$destination_code = $last['station_code'];
		$direction = self::build_direction_metadata(
			$train_type,
			$company_code,
			$operator_name,
			$departure_code,
			$destination_code,
			isset($trip['header']['train_number']) ? $trip['header']['train_number'] : '',
			$line_code
		);
		$direction_ref = $direction['direction_ref'];

		$attributes = isset($trip['attributes']) ? $trip['attributes'] : array();
		$attr_codes = array();
		foreach ($attributes as $attr) {
			$attr_codes[] = $attr['code'];
		}
		$attr_string = implode(',', array_unique($attr_codes));

		global $wpdb;
		$inserted = $wpdb->insert(
			self::table('journeys'),
			array(
				'journey_ref' => $trip['journey_ref'],
				'direction_ref' => $direction_ref,
				'company_code' => $company_code,
				'train_number' => isset($trip['header']['train_number']) ? $trip['header']['train_number'] : '',
				'line_code' => $line_code,
				'transport_code' => $transport_code,
				'footnote_ref' => $trip['footnote_ref'],
				'attributes' => $attr_string,
				'departure_seconds' => $departure,
				'arrival_seconds' => $arrival,
			),
			array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
		);
		if ($inserted === false) {
			return false;
		}

		$order = 0;
		foreach ($stops as $stop) {
			if (!empty($stop['pass_through'])) {
				continue;
			}
			$wpdb->insert(
				self::table('journey_stops'),
				array(
					'journey_ref' => $trip['journey_ref'],
					'stop_order' => $order,
					'station_code' => $stop['station_code'],
					'arrival_seconds' => (int) $stop['arrival_seconds'],
					'departure_seconds' => (int) $stop['departure_seconds'],
				),
				array('%s', '%d', '%s', '%d', '%d')
			);
			$order++;
		}

		return true;
	}

	private static function build_direction_metadata($train_type, $company_code, $operator_name, $departure_code, $destination_code, $train_number = '', $line_code = '') {
		$departure_name = self::get_station_display_name($departure_code);
		$destination_name = self::get_station_display_name($destination_code);
		$series_key = self::series_key($company_code, $train_number, $line_code);
		$direction_bucket = self::direction_bucket($train_number);
		$grouping_key = self::direction_grouping_key($company_code, $series_key, $direction_bucket);
		return array(
			'direction_ref' => md5($grouping_key),
			'series_key' => $series_key,
			'direction_bucket' => $direction_bucket,
			'train_type' => (string) $train_type,
			'departure_name' => $departure_name,
			'destination_name' => $destination_name,
			'operator_code' => (string) $company_code,
			'operator_name' => (string) $operator_name,
			'label' => trim($train_type . ' ' . $departure_name . ' richting ' . $destination_name . ' - ' . $operator_name),
		);
	}

	private static function direction_grouping_key($company_code, $series_key, $direction_bucket) {
		return (string) $company_code . '|' . (string) $series_key . '|' . (string) $direction_bucket;
	}

	private static function series_key($company_code, $train_number, $line_code = '') {
		$company_code = trim((string) $company_code);
		$line_code = strtoupper(trim((string) $line_code));
		if ($line_code !== '') {
			return $company_code . '|L|' . $line_code;
		}

		$digits = preg_replace('/\D/', '', (string) $train_number);
		if ($digits === '') {
			return $company_code . '|T|' . trim((string) $train_number);
		}

		$number = (int) $digits;
		if ($number >= 100) {
			$series = (string) (((int) floor($number / 100)) * 100);
		} elseif ($number >= 10) {
			$series = (string) (((int) floor($number / 10)) * 10);
		} else {
			$series = (string) $number;
		}

		return $company_code . '|S|' . $series;
	}

	private static function direction_bucket($train_number) {
		$digits = preg_replace('/\D/', '', (string) $train_number);
		if ($digits === '') {
			return 'unknown';
		}
		return (((int) substr($digits, -1)) % 2 === 0) ? 'even' : 'odd';
	}

	private static function compact_station_name($name) {
		$name = trim((string) $name);
		if ($name === '') {
			return '';
		}
		if (strpos($name, '/') === false) {
			return $name;
		}

		$parts = preg_split('/\s*\/\s*/', $name);
		if (!$parts || count($parts) < 2) {
			return $name;
		}

		$map = array(
			'Groningen' => 'Gron.',
			'Leeuwarden' => "L'ward.",
			'Den Haag Centraal' => 'Den Haag CS',
			'Den Haag HS' => 'Den Haag HS',
			'Den Haag Laan v NOI' => 'Den Haag LvNOI',
			'Rotterdam Centraal' => 'Rdam CS',
			'Amsterdam Centraal' => 'A dam CS',
			'Amsterdam Zuid' => 'A dam Zuid',
			'Schiphol Airport' => 'Schiphol',
			'Utrecht Centraal' => 'Utrecht CS',
			'Almere Centrum' => 'Almere C',
			'Lelystad Centrum' => 'Lelystad C',
		);

		foreach ($parts as $index => $part) {
			$part = trim((string) $part);
			$parts[$index] = isset($map[$part]) ? $map[$part] : $part;
		}

		return implode('/', $parts);
	}

	private static function select_significant_terminals(array $counts, $max_items = 2, $threshold_ratio = 0.5) {
		$counts = array_filter($counts, function ($count) {
			return (int) $count > 0;
		});
		if (empty($counts)) {
			return array();
		}

		arsort($counts, SORT_NUMERIC);
		$max_count = max($counts);
		$selected = array();
		foreach ($counts as $name => $count) {
			if (count($selected) >= $max_items) {
				break;
			}
			if ((int) $count < max(1, (int) ceil($max_count * $threshold_ratio))) {
				continue;
			}
			$selected[] = (string) $name;
		}

		if (empty($selected)) {
			$selected[] = (string) key($counts);
		}

		return $selected;
	}

	private static function reassign_journey_direction_refs() {
		global $wpdb;

		$journeys = $wpdb->get_results('SELECT journey_ref, company_code, train_number, line_code, transport_code FROM ' . self::table('journeys'), ARRAY_A);
		if (empty($journeys)) {
			return;
		}

		$stops = $wpdb->get_results(
			'SELECT journey_ref, station_code, stop_order FROM ' . self::table('journey_stops') . ' ORDER BY journey_ref ASC, stop_order ASC',
			ARRAY_A
		);
		if (empty($stops)) {
			return;
		}

		$endpoints = array();
		foreach ($stops as $stop) {
			$journey_ref = (string) $stop['journey_ref'];
			if (!isset($endpoints[$journey_ref])) {
				$endpoints[$journey_ref] = array(
					'first' => (string) $stop['station_code'],
					'last' => (string) $stop['station_code'],
				);
				continue;
			}
			$endpoints[$journey_ref]['last'] = (string) $stop['station_code'];
		}

		foreach ($journeys as $journey) {
			$journey_ref = (string) $journey['journey_ref'];
			if (empty($endpoints[$journey_ref]['first']) || empty($endpoints[$journey_ref]['last'])) {
				continue;
			}

			$company_code = isset($journey['company_code']) ? (string) $journey['company_code'] : '';
			$operator_name = isset(self::$companies[$company_code]) ? self::$companies[$company_code] : $company_code;
			$train_number = isset($journey['train_number']) ? (string) $journey['train_number'] : '';
			$line_code = isset($journey['line_code']) ? (string) $journey['line_code'] : '';
			$transport_code = trim((string) $journey['transport_code']);
			$train_type = isset(self::$trnsmodes[$transport_code]) ? self::$trnsmodes[$transport_code] : $transport_code;
			$direction = self::build_direction_metadata(
				$train_type,
				$company_code,
				$operator_name,
				$endpoints[$journey_ref]['first'],
				$endpoints[$journey_ref]['last'],
				$train_number,
				$line_code
			);

			$wpdb->update(
				self::table('journeys'),
				array('direction_ref' => $direction['direction_ref']),
				array('journey_ref' => $journey_ref),
				array('%s'),
				array('%s')
			);
		}
	}

	private static function finalize_directions() {
		global $wpdb;
		$wpdb->query('DELETE FROM ' . self::table('directions'));
		self::load_runtime_maps();

		$direction_refs = $wpdb->get_col('SELECT DISTINCT direction_ref FROM ' . self::table('journeys'));
		$insert_rows = array();
		foreach ($direction_refs as $direction_ref) {
			$samples = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table('journeys') . ' WHERE direction_ref = %s ORDER BY departure_seconds ASC',
					$direction_ref
				),
				ARRAY_A
			);
			if (empty($samples)) {
				continue;
			}
			$departure_names = array();
			$journey_sequences = array();
			$journey_stop_counts = array();
			foreach ($samples as $family_journey) {
				$journey_stops = $wpdb->get_results(
					$wpdb->prepare(
						'
						SELECT station_code, stop_order
						FROM ' . self::table('journey_stops') . '
						WHERE journey_ref = %s
						ORDER BY stop_order ASC
						',
						$family_journey['journey_ref']
					),
					ARRAY_A
				);
				if (empty($journey_stops)) {
					continue;
				}
				$first_stop = reset($journey_stops);
				$departure_names[] = self::get_station_display_name((string) $first_stop['station_code']);
				$journey_stop_counts[(string) $family_journey['journey_ref']] = count($journey_stops);
				$journey_sequences[] = array_values(
					array_map(
						function ($stop) {
							return (string) $stop['station_code'];
						},
						$journey_stops
					)
				);
			}
			usort(
				$samples,
				function ($a, $b) use ($journey_stop_counts) {
					$a_count = isset($journey_stop_counts[(string) $a['journey_ref']]) ? (int) $journey_stop_counts[(string) $a['journey_ref']] : 0;
					$b_count = isset($journey_stop_counts[(string) $b['journey_ref']]) ? (int) $journey_stop_counts[(string) $b['journey_ref']] : 0;
					if ($a_count !== $b_count) {
						return $b_count - $a_count;
					}
					return ((int) $a['departure_seconds']) - ((int) $b['departure_seconds']);
				}
			);
			$sample = $samples[0];
			$departure_counts = array();
			$destination_counts = array();
			foreach ($journey_sequences as $sequence) {
				if (empty($sequence)) {
					continue;
				}
				$departure_name = self::get_station_display_name((string) reset($sequence));
				$destination_name = self::get_station_display_name((string) end($sequence));
				if (!isset($departure_counts[$departure_name])) {
					$departure_counts[$departure_name] = 0;
				}
				if (!isset($destination_counts[$destination_name])) {
					$destination_counts[$destination_name] = 0;
				}
				$departure_counts[$departure_name]++;
				$destination_counts[$destination_name]++;
			}
			$company_code = (string) $sample['company_code'];
			$operator_name = isset(self::$companies[$company_code]) ? self::$companies[$company_code] : $company_code;
			$departure_code = (string) $wpdb->get_var(
				$wpdb->prepare(
					'
					SELECT station_code
					FROM ' . self::table('journey_stops') . '
					WHERE journey_ref = %s
					ORDER BY stop_order ASC
					LIMIT 1
					',
					$sample['journey_ref']
				)
			);
			$destination_code = (string) $wpdb->get_var(
				$wpdb->prepare(
					'
					SELECT station_code
					FROM ' . self::table('journey_stops') . '
					WHERE journey_ref = %s
					ORDER BY stop_order DESC
					LIMIT 1
					',
					$sample['journey_ref']
				)
			);
			$transport_code = trim((string) $sample['transport_code']);
			$train_type = isset(self::$trnsmodes[$transport_code]) ? self::$trnsmodes[$transport_code] : $transport_code;
			$train_number = isset($sample['train_number']) ? (string) $sample['train_number'] : '';
			$line_code = isset($sample['line_code']) ? (string) $sample['line_code'] : '';
			$direction = self::build_direction_metadata($train_type, $company_code, $operator_name, $departure_code, $destination_code, $train_number, $line_code);
			$departure_names = self::select_significant_terminals($departure_counts);
			$destination_names = self::select_significant_terminals($destination_counts);
			if (empty($departure_names)) {
				$departure_names[] = $direction['departure_name'];
			}
			if (empty($destination_names)) {
				$destination_names[] = $direction['destination_name'];
			}
			$departure_label = self::compact_station_name(implode('/', $departure_names));
			$destination_label = self::compact_station_name(implode('/', $destination_names));
			$direction['departure_name'] = implode('/', $departure_names);
			$direction['destination_name'] = implode('/', $destination_names);
			$direction['label'] = trim($train_type . ' ' . $departure_label . ' richting ' . $destination_label . ' - ' . $operator_name);
			$sort_key = 0;
			$insert_rows[] = array(
				'direction_ref' => $direction_ref,
				'series_key' => $direction['series_key'],
				'direction_bucket' => $direction['direction_bucket'],
				'train_type' => $direction['train_type'],
				'departure_name' => $direction['departure_name'],
				'destination_name' => $direction['destination_name'],
				'operator_code' => $direction['operator_code'],
				'operator_name' => $direction['operator_name'],
				'label' => $direction['label'],
				'sort_key' => $sort_key,
			);
		}

		$table = self::table('directions');
		foreach ($insert_rows as $row) {
			$wpdb->replace(
				$table,
				array(
					'direction_ref' => $row['direction_ref'],
					'series_key' => $row['series_key'],
					'direction_bucket' => $row['direction_bucket'],
					'train_type' => $row['train_type'],
					'departure_name' => $row['departure_name'],
					'destination_name' => $row['destination_name'],
					'operator_code' => $row['operator_code'],
					'operator_name' => $row['operator_name'],
					'label' => $row['label'],
					'sort_key' => (int) $row['sort_key'],
				),
				array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
			);
		}
	}

	private static function parse_delivery_header($file) {
		if (!file_exists($file)) {
			return null;
		}
		$line = '';
		$handle = self::open_dat_file($file);
		while (($line = fgets($handle)) !== false) {
			if ($line !== '' && $line[0] === '@') {
				break;
			}
		}
		fclose($handle);
		if ($line === '' || $line[0] !== '@') {
			return null;
		}
		$parts = array_map('trim', explode(',', substr($line, 1)));
		if (count($parts) < 3) {
			return null;
		}
		return array(
			'from' => self::iff_date_to_iso($parts[1]),
			'to' => self::iff_date_to_iso($parts[2]),
		);
	}

	private static function iff_date_to_iso($ddmmyy) {
		$digits = preg_replace('/\D/', '', (string) $ddmmyy);
		if (strlen($digits) === 8) {
			$day = substr($digits, 0, 2);
			$month = substr($digits, 2, 2);
			$year = substr($digits, 4, 4);
			return $year . '-' . $month . '-' . $day;
		}
		if (strlen($digits) !== 6) {
			return '';
		}
		$day = substr($digits, 0, 2);
		$month = substr($digits, 2, 2);
		$year = '20' . substr($digits, 4, 2);
		return $year . '-' . $month . '-' . $day;
	}

	private static function open_dat_file($file) {
		if (!file_exists($file)) {
			throw new Exception('Bestand niet gevonden: ' . wp_basename($file));
		}
		$handle = fopen($file, 'rb');
		if (!$handle) {
			throw new Exception('Bestand niet leesbaar: ' . wp_basename($file));
		}
		return $handle;
	}

	private static function bulk_upsert($table_suffix, array $columns, array $rows, array $int_columns = array(), $chunk_size = 200) {
		global $wpdb;
		if (empty($rows)) {
			return;
		}
		$table = self::table($table_suffix);
		$column_sql = '`' . implode('`,`', $columns) . '`';
		$update_columns = array();
		foreach ($columns as $column) {
			$update_columns[] = '`' . $column . '` = VALUES(`' . $column . '`)';
		}
		$update_sql = implode(', ', $update_columns);

		foreach (array_chunk($rows, $chunk_size) as $chunk) {
			$value_groups = array();
			foreach ($chunk as $row) {
				$values = array();
				foreach ($columns as $column) {
					$value = isset($row[$column]) ? $row[$column] : '';
					if (in_array($column, $int_columns, true)) {
						$values[] = (string) (int) $value;
					} else {
						$values[] = "'" . esc_sql((string) $value) . "'";
					}
				}
				$value_groups[] = '(' . implode(',', $values) . ')';
			}
			$sql = "INSERT INTO {$table} ({$column_sql}) VALUES " . implode(',', $value_groups) . " ON DUPLICATE KEY UPDATE {$update_sql}";
			$wpdb->query($sql);
		}
	}

	/**
	 * Verwijdert alle geüploade bestanden, importstatus en databaseregels.
	 */
	public static function purge_all_import_data() {
		self::delete_directory(self::dataset_dir());
		delete_option(self::OPTION_IMPORT_STATE);
		delete_option(self::OPTION_IMPORT_INFO);
		delete_option(self::OPTION_DIRECTION_SIGNATURE);
		self::$station_names = array();
		self::$regional_codes = array();
		self::$companies = array();
		self::$trnsmodes = array();
		self::clear_tables();
	}

	public static function is_data_available() {
		global $wpdb;
		$table = self::table('journeys');
		return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	}

	private static function ensure_direction_signature_current() {
		if (!self::is_data_available()) {
			return;
		}
		$current = (int) get_option(self::OPTION_DIRECTION_SIGNATURE, 0);
		if ($current >= self::DIRECTION_SIGNATURE_VERSION) {
			return;
		}
		self::ensure_reference_data();
		self::reassign_journey_direction_refs();
		self::finalize_directions();
		update_option(self::OPTION_DIRECTION_SIGNATURE, self::DIRECTION_SIGNATURE_VERSION, false);
	}

	/**
	 * Eerstvolgende treinvertrekken op een station (voor koppeling met OV Halte Importer).
	 *
	 * @return array<int, array{public_code:string,colour:string,text_colour:string,destination:string,departures:array,schedule_url:string}>
	 */
	public static function find_next_departures_at_station($station_code, $limit = 2, $schedule_base_url = '') {
		global $wpdb;

		if (!self::is_data_available()) {
			return array();
		}

		$station_code = strtolower(trim((string) $station_code));
		$limit = max(1, (int) $limit);
		if ($station_code === '') {
			return array();
		}

		list($dataset_from, $dataset_to) = self::get_delivery_range();
		if ($dataset_from === '' || $dataset_to === '') {
			return array();
		}

		$window = self::current_operational_window();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'
				SELECT j.journey_ref, j.train_number, j.direction_ref, j.footnote_ref, js.departure_seconds, js.arrival_seconds, js.stop_order, jm.max_stop_order,
					d.train_type, d.destination_name, d.label
				FROM ' . self::table('journey_stops') . ' js
				INNER JOIN ' . self::table('journeys') . ' j ON j.journey_ref = js.journey_ref
				INNER JOIN (
					SELECT journey_ref, MAX(stop_order) AS max_stop_order
					FROM ' . self::table('journey_stops') . '
					GROUP BY journey_ref
				) jm ON jm.journey_ref = js.journey_ref
				LEFT JOIN ' . self::table('directions') . ' d ON d.direction_ref = j.direction_ref
				WHERE js.station_code = %s
				',
				$station_code
			),
			ARRAY_A
		);
		if (empty($rows)) {
			return array();
		}

		$footnotes_cache = array();
		$candidates = array();
		foreach ($rows as $row) {
			if (isset($row['stop_order'], $row['max_stop_order']) && (int) $row['stop_order'] >= (int) $row['max_stop_order']) {
				continue;
			}

			$footnote_ref = isset($row['footnote_ref']) ? (string) $row['footnote_ref'] : '';
			if ($footnote_ref !== '') {
				if (!isset($footnotes_cache[$footnote_ref])) {
					$footnotes_cache[$footnote_ref] = $wpdb->get_row(
						$wpdb->prepare('SELECT * FROM ' . self::table('footnotes') . ' WHERE footnote_ref = %s', $footnote_ref),
						ARRAY_A
					);
				}
			}

			$departure_seconds = (int) $row['departure_seconds'];
			if ($departure_seconds < 0) {
				$departure_seconds = (int) $row['arrival_seconds'];
			}
			if ($departure_seconds < 0) {
				continue;
			}

			$from_date = max($window['start_date'], $dataset_from);
			$to_date = min($window['end_date'], $dataset_to);
			if ($from_date > $to_date) {
				continue;
			}

			$date = new DateTimeImmutable($from_date . ' 00:00:00', $window['timezone']);
			$end = new DateTimeImmutable($to_date . ' 00:00:00', $window['timezone']);
			while ($date <= $end) {
				$service_date = $date->format('Y-m-d');
				$footnote = $footnote_ref !== '' ? $footnotes_cache[$footnote_ref] : null;
				if ($footnote_ref === '' || ($footnote && self::footnote_matches_date($footnote, $service_date, $dataset_from))) {
					$candidate = $date->modify('+' . $departure_seconds . ' seconds');
					if ($departure_seconds < 5 * HOUR_IN_SECONDS) {
						$candidate = $candidate->modify('+1 day');
					}
					if ($candidate > $window['now'] && $candidate >= $window['start'] && $candidate < $window['end']) {
						$candidates[$candidate->getTimestamp() . '|' . $row['journey_ref']] = array(
							'time' => $candidate,
							'row' => $row,
							'service_date' => $service_date,
						);
					}
				}
				$date = $date->modify('+1 day');
			}
		}

		if (empty($candidates)) {
			return array();
		}

		// Fetch realtime delays for candidate train journeys at this station
		$lookup_journey_refs = array();
		$scheduled_refs_by_lookup = array();
		foreach ($candidates as $candidate) {
			$row = isset($candidate['row']) ? $candidate['row'] : array();
			$scheduled_ref = isset($row['journey_ref']) ? trim((string) $row['journey_ref']) : '';
			if ($scheduled_ref === '') {
				continue;
			}
			$refs = array($scheduled_ref);
			if (!empty($row['train_number'])) {
				$refs[] = trim((string) $row['train_number']);
			}
			foreach (array_values(array_unique(array_filter($refs, 'strlen'))) as $ref) {
				$lookup_journey_refs[] = $ref;
				if (!isset($scheduled_refs_by_lookup[$ref])) {
					$scheduled_refs_by_lookup[$ref] = array();
				}
				$scheduled_refs_by_lookup[$ref][] = $scheduled_ref;
			}
		}
		$lookup_journey_refs = array_values(array_unique($lookup_journey_refs));
		$delays = array();
		$realtime_table = $wpdb->prefix . 'ovhi_realtime_delays';

		// Check if table exists before querying
		if (!empty($lookup_journey_refs) && (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $realtime_table))) {
			$j_placeholders = implode(',', array_fill(0, count($lookup_journey_refs), '%s'));
			$one_hour_ago = date('Y-m-d H:i:s', time() - 3600);
			$params_query = array_merge($lookup_journey_refs, array(strtolower($station_code), $one_hour_ago));
			$delay_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT journey_ref, stop_code, delay_seconds, is_cancelled FROM $realtime_table WHERE journey_ref IN ($j_placeholders) AND stop_code = %s AND updated_at >= %s",
					$params_query
				),
				ARRAY_A
			);
			if (!empty($delay_rows)) {
				foreach ($delay_rows as $d_row) {
					$matched_refs = isset($scheduled_refs_by_lookup[$d_row['journey_ref']]) ? $scheduled_refs_by_lookup[$d_row['journey_ref']] : array($d_row['journey_ref']);
					foreach ($matched_refs as $matched_ref) {
						$delays[(string) $matched_ref] = $d_row;
					}
				}
			}
		}

		// NS API fallback: vult alleen de ontbrekende combinaties aan (zie
		// dezelfde toelichting in get_realtime_delays_for_journeys()).
		$ns_delays = self::fetch_ns_api_delays(array($station_code));
		foreach ($ns_delays as $ns_key => $ns_delay) {
			if (!isset($delays[$ns_key])) {
				$delays[$ns_key] = $ns_delay;
			}
		}
		// Calculate actual times and sort
		$processed_candidates = array();
		foreach ($candidates as $cand_key => $candidate) {
			$j_ref = (string) $candidate['row']['journey_ref'];
			$delay_info = self::get_delay_info_for_journey($delays, $j_ref, $station_code);
			$delay_seconds = $delay_info ? (int) $delay_info['delay_seconds'] : 0;
			$is_cancelled = $delay_info ? (bool) $delay_info['is_cancelled'] : false;

			$scheduled_time = $candidate['time'];
			$actual_time = $delay_seconds > 0 ? $scheduled_time->modify('+' . $delay_seconds . ' seconds') : $scheduled_time;

			$processed_candidates[] = array(
				'sort_ts' => $actual_time->getTimestamp(),
				'scheduled_time' => $scheduled_time,
				'actual_time' => $actual_time,
				'delay_seconds' => $delay_seconds,
				'is_cancelled' => $is_cancelled,
				'row' => $candidate['row'],
				'service_date' => $candidate['service_date'],
			);
		}

		usort($processed_candidates, function ($a, $b) {
			return $a['sort_ts'] - $b['sort_ts'];
		});

		$items = array();
		foreach ($processed_candidates as $cand) {
			$row = $cand['row'];
			$train_type = !empty($row['train_type']) ? (string) $row['train_type'] : 'Trein';
			$destination_name = !empty($row['destination_name']) ? (string) $row['destination_name'] : '';

			$time_str = $cand['scheduled_time']->format('H:i');
			if ($cand['is_cancelled']) {
				$time_str = '<s>' . $time_str . '</s> <span class="ov-cancelled" style="color: #d93025; font-weight: bold;">Vervallen</span>';
			} elseif ($cand['delay_seconds'] > 0) {
				$delay_minutes = round($cand['delay_seconds'] / 60);
				if ($delay_minutes > 0) {
					$time_str .= ' <span class="ov-delay" style="color: #d93025; font-weight: bold;">+' . $delay_minutes . '</span>';
				}
			}

			$items[] = array(
				'public_code' => self::train_badge($train_type),
				'colour' => self::FALLBACK_COLOR,
				'text_colour' => self::resolve_text_colour(self::FALLBACK_COLOR, ''),
				'destination' => $destination_name !== '' ? 'richting ' . $destination_name : 'Trein',
				'departures' => array($time_str),
				'schedule_url' => self::build_schedule_url_for_direction(
					isset($row['direction_ref']) ? (string) $row['direction_ref'] : '',
					$cand['service_date'],
					$schedule_base_url
				),
			);
			if (count($items) >= $limit) {
				break;
			}
		}

		return $items;
	}

	public static function build_schedule_url_for_direction($direction_ref, $service_date, $base_url = '') {
		$direction_ref = trim((string) $direction_ref);
		$service_date = trim((string) $service_date);
		if ($direction_ref === '' || $service_date === '') {
			return '';
		}
		if ($base_url === '') {
			$base_url = home_url('/treindienstregeling/');
		}
		return add_query_arg(
			array(
				'ovtd_direction' => $direction_ref,
				'ovtd_variant' => $service_date,
			),
			$base_url
		);
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

	private static function clear_tables() {
		global $wpdb;
		$tables = array('journey_stops', 'journeys', 'directions', 'footnotes', 'stations');
		foreach ($tables as $suffix) {
			$table = self::table($suffix);
			$truncated = $wpdb->query('TRUNCATE TABLE ' . $table);
			if ($truncated === false) {
				$wpdb->query('DELETE FROM ' . $table);
			}
		}
	}

	private static function dataset_dir() {
		$upload = wp_upload_dir();
		return trailingslashit($upload['basedir']) . 'ov-trein-dienstregeling';
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

	private static function redirect_admin($notice, $message = '') {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'ov-trein-dienstregeling',
					'ovtd_notice' => $notice,
					'ovtd_message' => rawurlencode($message),
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	private static function get_hidden_directions() {
		$hidden = get_option(self::OPTION_HIDDEN_DIRECTIONS, array());
		return is_array($hidden) ? array_values(array_filter($hidden)) : array();
	}

	private static function get_directions($include_hidden = false) {
		global $wpdb;
		$hidden = self::get_hidden_directions();
		$rows = $wpdb->get_results('SELECT * FROM ' . self::table('directions') . ' ORDER BY label ASC', ARRAY_A);
		if ($include_hidden || empty($hidden)) {
			return $rows ? $rows : array();
		}
		return array_values(
			array_filter(
				$rows,
				function ($row) use ($hidden) {
					return !in_array($row['direction_ref'], $hidden, true);
				}
			)
		);
	}

	public static function get_operators() {
		global $wpdb;
		if (!self::is_data_available()) {
			return array();
		}
		$directions_table = self::table('directions');
		return $wpdb->get_results("SELECT DISTINCT operator_code, operator_name FROM {$directions_table} WHERE operator_code <> '' ORDER BY operator_name", ARRAY_A);
	}

	public static function render_shortcode_safely($atts) {
		try {
			return self::render_shortcode($atts);
		} catch (Throwable $exception) {
			error_log('OVTD shortcode error: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
			if (current_user_can('manage_options')) {
				return '<p class="ovtd-error">Actuele treintijden zijn tijdelijk niet beschikbaar.</p><pre class="ovtd-error-detail">' . esc_html($exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine()) . '</pre>';
			}
			return '<p class="ovtd-error">Actuele treintijden zijn tijdelijk niet beschikbaar.</p>';
		}
	}

	public static function render_shortcode($atts) {
		$atts = shortcode_atts(array(), $atts, 'ov_trein_dienstregeling');
		self::ensure_direction_signature_current();

		if (self::is_data_available() && self::count_table('directions') < 1) {
			self::ensure_reference_data();
			self::finalize_directions();
		}

		$directions = self::get_directions(false);
		if (empty($directions)) {
			if (self::is_data_available()) {
				return '<p>Er zijn ritten geïmporteerd, maar er zijn nog geen richtingen beschikbaar. Ga naar Ovalino → OV Trein Dienstregeling en klik op &quot;Stamdata en richtingen opnieuw opbouwen&quot;.</p>';
			}
			return '<p>Er is nog geen treindienstregeling geïmporteerd.</p>';
		}

		$selected_direction = isset($_GET['ovtd_direction']) ? sanitize_text_field(wp_unslash($_GET['ovtd_direction'])) : '';
		$selected_variant = isset($_GET['ovtd_variant']) ? sanitize_text_field(wp_unslash($_GET['ovtd_variant'])) : '';
		$variants = $selected_direction ? self::get_variants($selected_direction) : array();
		$opposite_direction = $selected_direction ? self::get_opposite_direction_ref($selected_direction) : '';
		$has_opposite_direction = $selected_direction && $opposite_direction !== '' && !empty(self::get_variants($opposite_direction));
		$current_service_date = self::get_current_service_date();
		$tomorrow_service_date = date('Y-m-d', strtotime($current_service_date . ' +1 day'));

		self::enqueue_frontend_style();

		ob_start();
		?>
		<div class="ovtd-wrapper">
			<form class="ovtd-form" method="get">
				<?php foreach ($_GET as $key => $value) : ?>
					<?php if (!in_array($key, array('ovtd_direction', 'ovtd_variant'), true) && !is_array($value)) : ?>
						<input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(wp_unslash($value)); ?>" />
					<?php endif; ?>
				<?php endforeach; ?>
				<label>
					<span>Trein</span>
					<select name="ovtd_direction" onchange="this.form.ovtd_variant.value=''; this.form.submit();">
						<option value="">Kies een trein</option>
						<?php foreach ($directions as $direction) : ?>
							<option value="<?php echo esc_attr($direction['direction_ref']); ?>" <?php selected($selected_direction, $direction['direction_ref']); ?>>
								<?php echo esc_html($direction['label']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>Datum</span>
					<select name="ovtd_variant" <?php disabled(empty($variants)); ?>>
						<option value="">Kies een datum</option>
						<?php foreach ($variants as $variant) : ?>
							<option value="<?php echo esc_attr($variant['date']); ?>" <?php selected($selected_variant, $variant['date']); ?>>
								<?php echo esc_html($variant['label']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php if ($selected_direction) : ?>
					<div class="ovtd-quick-switch" aria-label="Snel datum kiezen">
						<?php echo self::render_quick_date_link('Vandaag', $current_service_date, $variants, $selected_variant, $selected_direction); ?>
						<?php echo self::render_quick_date_link('Morgen', $tomorrow_service_date, $variants, $selected_variant, $selected_direction); ?>
					</div>
				<?php endif; ?>
				<?php if ($selected_direction && $selected_variant && $has_opposite_direction) : ?>
					<button type="submit" class="ovtd-opposite" onclick="this.form.ovtd_direction.value='<?php echo esc_js($opposite_direction); ?>'; this.form.ovtd_variant.value='<?php echo esc_js($selected_variant); ?>';">Omgekeerde richting</button>
				<?php endif; ?>
				<button type="submit">Zoeken</button>
			</form>
			<?php
			if ($selected_direction && $selected_variant) {
				$label = self::selected_variant_label($variants, $selected_variant);
				echo self::render_schedule($selected_direction, $selected_variant, $label);
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_quick_date_link($label, $date, array $variants, $selected_variant, $direction_ref) {
		$available = false;
		foreach ($variants as $variant) {
			if ($variant['date'] === $date) {
				$available = true;
				break;
			}
		}
		$classes = array('ovtd-quick-date');
		if (!$available) {
			$classes[] = 'is-disabled';
			return '<span class="' . esc_attr(implode(' ', $classes)) . '">' . esc_html($label) . '</span>';
		}
		if ($selected_variant === $date) {
			$classes[] = 'is-active';
		}
		$url = add_query_arg(array('ovtd_direction' => $direction_ref, 'ovtd_variant' => $date));
		return '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
	}

	private static function get_current_service_date() {
		$timestamp = current_time('timestamp');
		$seconds = (int) date('G', $timestamp) * HOUR_IN_SECONDS + (int) date('i', $timestamp) * MINUTE_IN_SECONDS + (int) date('s', $timestamp);
		if ($seconds < self::SERVICE_DAY_START_SECONDS) {
			return date('Y-m-d', $timestamp - DAY_IN_SECONDS);
		}
		return date('Y-m-d', $timestamp);
	}

	private static function get_opposite_direction_ref($direction_ref) {
		global $wpdb;

		$direction = $wpdb->get_row(
			$wpdb->prepare('SELECT * FROM ' . self::table('directions') . ' WHERE direction_ref = %s', $direction_ref),
			ARRAY_A
		);
		if (!$direction) {
			return '';
		}

		$opposite_bucket = ((string) $direction['direction_bucket'] === 'even') ? 'odd' : (((string) $direction['direction_bucket'] === 'odd') ? 'even' : '');
		if ($opposite_bucket === '' || empty($direction['series_key'])) {
			return '';
		}

		$opposite = $wpdb->get_var(
			$wpdb->prepare(
				'
				SELECT direction_ref
				FROM ' . self::table('directions') . '
				WHERE series_key = %s
					AND operator_code = %s
					AND direction_bucket = %s
					AND direction_ref <> %s
				ORDER BY label ASC
				LIMIT 1
				',
				$direction['series_key'],
				$direction['operator_code'],
				$opposite_bucket,
				$direction_ref
			)
		);

		return $opposite ? (string) $opposite : '';
	}

	private static function get_delivery_range() {
		$delivery = self::parse_delivery_header(self::dataset_dir() . '/delivery.dat');
		if (!$delivery || $delivery['from'] === '' || $delivery['to'] === '') {
			return array('', '');
		}
		return array($delivery['from'], $delivery['to']);
	}

	private static function get_variants($direction_ref) {
		list($from_date, $to_date) = self::get_delivery_range();
		if ($from_date === '' || $to_date === '') {
			return array();
		}

		global $wpdb;
		$footnote_refs = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT footnote_ref FROM ' . self::table('journeys') . ' WHERE direction_ref = %s AND footnote_ref <> %s',
				$direction_ref,
				''
			)
		);
		if (empty($footnote_refs)) {
			return array();
		}

		$placeholders = implode(',', array_fill(0, count($footnote_refs), '%s'));
		$footnotes = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table('footnotes') . ' WHERE footnote_ref IN (' . $placeholders . ')',
				$footnote_refs
			),
			ARRAY_A
		);
		if (empty($footnotes)) {
			return array();
		}

		$current_service_date = self::get_current_service_date();
		$start = new DateTimeImmutable($from_date . ' 00:00:00');
		$end = new DateTimeImmutable($to_date . ' 00:00:00');
		if ($start->diff($end)->days > 180) {
			$end = $start->modify('+180 days');
		}

		$variants = array();
		for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
			$service_date = $date->format('Y-m-d');
			if ($service_date < $current_service_date) {
				continue;
			}
			$has_service = false;
			foreach ($footnotes as $footnote) {
				if (self::footnote_matches_date($footnote, $service_date, $from_date)) {
					$has_service = true;
					break;
				}
			}
			if ($has_service) {
				$variants[] = array(
					'date' => $service_date,
					'label' => date_i18n('j F Y', strtotime($service_date)),
				);
			}
		}
		return $variants;
	}

	private static function footnote_matches_date(array $footnote, $service_date, $dataset_from) {
		$bits = isset($footnote['run_bits']) ? (string) $footnote['run_bits'] : '';
		if ($bits === '') {
			return true;
		}
		$start = new DateTimeImmutable($dataset_from . ' 00:00:00');
		$date = new DateTimeImmutable($service_date . ' 00:00:00');
		$index = (int) $start->diff($date)->format('%a');
		return $index >= 0 && $index < strlen($bits) && $bits[$index] === '1';
	}

	private static function selected_variant_label(array $variants, $selected_variant) {
		foreach ($variants as $variant) {
			if ($variant['date'] === $selected_variant) {
				return $variant['label'];
			}
		}
		return $selected_variant ? date_i18n('j F Y', strtotime($selected_variant)) : '';
	}

	public static function render_schedule($direction_ref, $service_date, $variant_label = '') {
		global $wpdb;
		$direction = $wpdb->get_row(
			$wpdb->prepare('SELECT * FROM ' . self::table('directions') . ' WHERE direction_ref = %s', $direction_ref),
			ARRAY_A
		);
		if (!$direction) {
			return '<p>Deze richting is niet gevonden.</p>';
		}

		list($dataset_from,) = self::get_delivery_range();
		$journeys = $wpdb->get_results(
			$wpdb->prepare(
				'
				SELECT j.*
				FROM ' . self::table('journeys') . ' j
				INNER JOIN ' . self::table('footnotes') . ' f ON f.footnote_ref = j.footnote_ref
				WHERE j.direction_ref = %s
				',
				$direction_ref
			),
			ARRAY_A
		);
		$footnotes_cache = array();
		$journeys = array_values(
			array_filter(
				$journeys,
				function ($journey) use ($service_date, $dataset_from, &$footnotes_cache) {
					if ($journey['footnote_ref'] === '') {
						return true;
					}
					if (!isset($footnotes_cache[$journey['footnote_ref']])) {
						global $wpdb;
						$footnotes_cache[$journey['footnote_ref']] = $wpdb->get_row(
							$wpdb->prepare('SELECT * FROM ' . self::table('footnotes') . ' WHERE footnote_ref = %s', $journey['footnote_ref']),
							ARRAY_A
						);
					}
					$row = $footnotes_cache[$journey['footnote_ref']];
					return $row ? self::footnote_matches_date($row, $service_date, $dataset_from) : true;
				}
			)
		);

		if (empty($journeys)) {
			return '<p>Voor deze keuze is geen dienstregeling gevonden.</p>';
		}

		usort(
			$journeys,
			function ($a, $b) {
				return self::service_day_order_seconds((int) $a['departure_seconds']) - self::service_day_order_seconds((int) $b['departure_seconds']);
			}
		);

		$stops = self::get_merged_stops($journeys);
		if (empty($stops)) {
			return '<p>Voor deze route zijn geen stations gevonden.</p>';
		}

		$times = self::get_journey_times($journeys, $stops);
		$journeys = self::filter_journeys_with_visible_stops($journeys, $stops, $times);
		if (empty($journeys)) {
			return '<p>Voor deze keuze zijn alleen niet-toonbare ritten gevonden.</p>';
		}
		$route = self::route_label($stops);
		$variant_label = $variant_label !== '' ? $variant_label : date_i18n('j F Y', strtotime($service_date));
		$valid_date_range = 'Geldig op: ' . date_i18n('d-m-Y', strtotime($service_date));
		$background = self::FALLBACK_COLOR;
		$text_color = '#FFFFFF';
		$current_timestamp = current_time('timestamp');
		$current_group = self::day_group_key(date('Y-m-d', $current_timestamp));
		$selected_group = self::day_group_key($service_date);
		$current_seconds = (int) date('G', $current_timestamp) * HOUR_IN_SECONDS + (int) date('i', $current_timestamp) * MINUTE_IN_SECONDS;
		$scroll_time = $current_group === $selected_group ? (string) self::service_day_order_seconds($current_seconds) : '';

		$op_code = isset($direction['operator_code']) ? $direction['operator_code'] : '';
		$logo_url = '';
		if ($op_code !== '') {
			$logos = get_option('ovtd_operator_logos', array());
			if (isset($logos[$op_code])) {
				$logo_url = $logos[$op_code];
			}
		}

		$realtime_delays = array();
		if ($service_date === self::get_current_service_date()) {
			$realtime_delays = self::get_realtime_delays_for_journeys($journeys, $stops);
		}

		ob_start();
		?>
		<div class="ovtd-schedule">
			<div class="ovtd-line-heading">
				<span class="ovtd-badge" style="background-color: <?php echo esc_attr($background); ?>; color: <?php echo esc_attr($text_color); ?>;"><?php echo esc_html(self::train_badge($direction['train_type'])); ?></span>
				<div>
					<div class="ovtd-route"><?php echo esc_html($route); ?></div>
					<div class="ovtd-variant"><?php echo esc_html($direction['label']); ?> · <?php echo esc_html($variant_label); ?></div>
					<?php if ($valid_date_range) : ?><div class="ovtd-valid-dates"><?php echo esc_html($valid_date_range); ?></div><?php endif; ?>
				</div>
				<div class="ovtd-meta-container">
					<?php if ($logo_url) : ?>
						<img src="<?php echo esc_url($logo_url); ?>" alt="Vervoerder logo" class="ovtd-operator-logo" />
					<?php endif; ?>
					<button type="button" class="ovtd-print" onclick="window.print();">Print</button>
				</div>
			</div>
			<div class="ovtd-table-shell" data-ovtd-current-time="<?php echo esc_attr($scroll_time); ?>">
				<button type="button" class="ovtd-scroll ovld-scroll-left" data-ovtd-scroll="-1">‹</button>
				<div class="ovtd-table-wrap" tabindex="0">
					<?php
					$journey_count = count($journeys);
					$density_class = 'ovtd-density-normal';
					if ($journey_count > 25) {
						$density_class = 'ovtd-density-ultra-dense';
					} elseif ($journey_count > 15) {
						$density_class = 'ovtd-density-dense';
					}
					?>
					<div class="ovtd-grid <?php echo esc_attr($density_class); ?>" style="--ovtd-columns: <?php echo esc_attr((string) $journey_count); ?>;">
						<div class="ovtd-stop ovtd-head">Station</div>
						<?php foreach ($journeys as $journey) : ?>
							<div class="ovtd-time ovtd-head"></div>
						<?php endforeach; ?>
						<?php
						$last_index = count($stops) - 1;
						foreach ($stops as $stop_index => $stop) :
							$row_class = ($stop_index % 2 === 0) ? 'ovtd-row-even' : 'ovtd-row-odd';
							?>
							<div class="ovtd-stop <?php echo esc_attr($row_class); ?>"><?php echo esc_html($stop['station_name']); ?></div>
							<?php foreach ($journeys as $journey) : ?>
								<?php
								$key = $journey['journey_ref'] . '|' . $stop['station_code'];
								$cell = isset($times[$key]) ? $times[$key] : array('departure' => '', 'arrival' => '');
								$display = self::stop_display_time($cell, $stop_index, $last_index);
								$d_key = $journey['journey_ref'] . '|' . strtolower($stop['station_code']);
								$d_info = isset($realtime_delays[$d_key]) ? $realtime_delays[$d_key] : null;
								$departure_attr = ($stop_index === 0 && $display !== '') ? ' data-ovtd-departure="' . esc_attr((string) self::service_day_order_seconds((int) $journey['departure_seconds'])) . '"' : '';
								?>
								<div class="ovtd-time <?php echo esc_attr($row_class); ?>"<?php echo $departure_attr; ?>><?php echo wp_kses_post(self::format_stop_display($display, $d_info)); ?></div>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</div>
				</div>
				<button type="button" class="ovtd-scroll ovtd-scroll-right" data-ovtd-scroll="1">›</button>
			</div>
			<?php echo self::render_mobile_cards($journeys, $stops, $times, $direction, $background, $text_color, $realtime_delays); ?>
		</div>
		<?php echo self::render_print_schedule($journeys, $stops, $times, $direction, $background, $text_color, $route, $valid_date_range, $variant_label); ?>
		<?php
		return ob_get_clean();
	}

	private static function render_print_schedule(array $journeys, array $stops, array $times, array $direction, $background, $text_color, $route, $valid_date_range, $variant_label) {
		if (empty($journeys) || empty($stops)) {
			return '';
		}

		$op_code = isset($direction['operator_code']) ? $direction['operator_code'] : '';
		$logo_url = '';
		if ($op_code !== '') {
			$logos = get_option('ovtd_operator_logos', array());
			if (isset($logos[$op_code])) {
				$logo_url = $logos[$op_code];
			}
		}

		$chunks = array_chunk($journeys, 10);
		$total_chunks = count($chunks);
		$last_index = count($stops) - 1;

		ob_start();
		?>
		<div class="ovtd-print-schedule">
			<?php foreach ($chunks as $chunk_index => $chunk_journeys) : ?>
				<div class="ovtd-print-chunk">
					<div class="ovtd-line-heading">
						<span class="ovtd-badge" style="background-color: <?php echo esc_attr($background); ?>; color: <?php echo esc_attr($text_color); ?>;"><?php echo esc_html(self::train_badge($direction['train_type'])); ?></span>
						<div>
							<div class="ovtd-route"><?php echo esc_html($route); ?></div>
							<div class="ovtd-variant"><?php echo esc_html($direction['label']); ?> · <?php echo esc_html($variant_label); ?> (Deel <?php echo ($chunk_index + 1) . '/' . $total_chunks; ?>)</div>
							<?php if ($valid_date_range) : ?><div class="ovtd-valid-dates"><?php echo esc_html($valid_date_range); ?></div><?php endif; ?>
						</div>
						<?php if ($logo_url) : ?>
							<img src="<?php echo esc_url($logo_url); ?>" alt="Vervoerder logo" class="ovtd-operator-logo" style="margin-left: auto; max-height: 40px; max-width: 40px; object-fit: contain;" />
						<?php endif; ?>
					</div>
					<div class="ovtd-print-grid" style="--ovtd-columns: <?php echo esc_attr((string) count($chunk_journeys)); ?>;">
						<div class="ovtd-stop ovtd-head">Station</div>
						<?php foreach ($chunk_journeys as $journey) : ?>
							<div class="ovtd-time ovtd-head"></div>
						<?php endforeach; ?>
						<?php foreach ($stops as $stop_index => $stop) :
							$row_class = ($stop_index % 2 === 0) ? 'ovtd-row-even' : 'ovtd-row-odd';
							?>
							<div class="ovtd-stop <?php echo esc_attr($row_class); ?>"><?php echo esc_html($stop['station_name']); ?></div>
							<?php foreach ($chunk_journeys as $journey) : ?>
								<?php
								$key = $journey['journey_ref'] . '|' . $stop['station_code'];
								$cell = isset($times[$key]) ? $times[$key] : array('departure' => '', 'arrival' => '');
								$display = self::stop_display_time($cell, $stop_index, $last_index);
								?>
								<div class="ovtd-time <?php echo esc_attr($row_class); ?>"><?php echo wp_kses_post(self::format_stop_display($display)); ?></div>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
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

	private static function train_badge($train_type) {
		$map = array(
			'Intercity' => 'IC',
			'Intercity direct' => 'ICD',
			'Sprinter' => 'Spr',
			'Sneltrein' => 'Snl',
		);
		return isset($map[$train_type]) ? $map[$train_type] : mb_substr($train_type, 0, 3);
	}

	private static function get_merged_stops(array $journeys) {
		global $wpdb;
		if (empty($journeys)) {
			return array();
		}

		$refs = wp_list_pluck($journeys, 'journey_ref');
		$placeholders = implode(',', array_fill(0, count($refs), '%s'));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'
				SELECT js.journey_ref, js.station_code, s.station_name, js.stop_order
				FROM ' . self::table('journey_stops') . ' js
				INNER JOIN ' . self::table('stations') . ' s ON s.station_code = js.station_code
				WHERE js.journey_ref IN (' . $placeholders . ')
				ORDER BY js.journey_ref ASC, js.stop_order ASC
				',
				$refs
			),
			ARRAY_A
		);
		if (empty($rows)) {
			return array();
		}

		$station_names = array();
		$avg_position = array();
		$journey_sequences = array();
		foreach ($rows as $row) {
			$station_code = (string) $row['station_code'];
			$station_names[$station_code] = (string) $row['station_name'];
			if (!isset($avg_position[$station_code])) {
				$avg_position[$station_code] = array('total' => 0, 'count' => 0);
			}
			$avg_position[$station_code]['total'] += (int) $row['stop_order'];
			$avg_position[$station_code]['count']++;
			$journey_ref = (string) $row['journey_ref'];
			if (!isset($journey_sequences[$journey_ref])) {
				$journey_sequences[$journey_ref] = array();
			}
			$journey_sequences[$journey_ref][] = $station_code;
		}

		$edges = array();
		$indegree = array_fill_keys(array_keys($station_names), 0);
		foreach ($journey_sequences as $sequence) {
			for ($i = 0; $i < count($sequence) - 1; $i++) {
				$from = $sequence[$i];
				$to = $sequence[$i + 1];
				if ($from === $to) {
					continue;
				}
				if (!isset($edges[$from])) {
					$edges[$from] = array();
				}
				if (isset($edges[$from][$to])) {
					continue;
				}
				$edges[$from][$to] = true;
				$indegree[$to]++;
			}
		}

		$position_score = array();
		foreach ($avg_position as $station_code => $data) {
			$position_score[$station_code] = $data['count'] > 0 ? ($data['total'] / $data['count']) : 0;
		}

		$queue = array_keys(array_filter($indegree, function ($value) {
			return (int) $value === 0;
		}));
		usort($queue, function ($a, $b) use ($position_score, $station_names) {
			$position_compare = ($position_score[$a] <=> $position_score[$b]);
			if ($position_compare !== 0) {
				return $position_compare;
			}
			return strcasecmp($station_names[$a], $station_names[$b]);
		});

		$ordered_codes = array();
		while (!empty($queue)) {
			$current = array_shift($queue);
			$ordered_codes[] = $current;
			if (empty($edges[$current])) {
				continue;
			}
			foreach (array_keys($edges[$current]) as $next) {
				$indegree[$next]--;
				if ($indegree[$next] === 0) {
					$queue[] = $next;
				}
			}
			usort($queue, function ($a, $b) use ($position_score, $station_names) {
				$position_compare = ($position_score[$a] <=> $position_score[$b]);
				if ($position_compare !== 0) {
					return $position_compare;
				}
				return strcasecmp($station_names[$a], $station_names[$b]);
			});
		}

		if (count($ordered_codes) < count($station_names)) {
			$remaining = array_diff(array_keys($station_names), $ordered_codes);
			usort($remaining, function ($a, $b) use ($position_score, $station_names) {
				$position_compare = ($position_score[$a] <=> $position_score[$b]);
				if ($position_compare !== 0) {
					return $position_compare;
				}
				return strcasecmp($station_names[$a], $station_names[$b]);
			});
			$ordered_codes = array_merge($ordered_codes, $remaining);
		}

		$stops = array();
		foreach ($ordered_codes as $index => $station_code) {
			$stops[] = array(
				'station_code' => $station_code,
				'station_name' => $station_names[$station_code],
				'stop_order' => $index,
			);
		}

		return $stops;
	}

	private static function get_journey_times(array $journeys, array $stops) {
		global $wpdb;
		$refs = wp_list_pluck($journeys, 'journey_ref');
		if (empty($refs)) {
			return array();
		}
		$placeholders = implode(',', array_fill(0, count($refs), '%s'));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table('journey_stops') . ' WHERE journey_ref IN (' . $placeholders . ')',
				$refs
			),
			ARRAY_A
		);
		$times = array();
		foreach ($rows as $row) {
			$key = $row['journey_ref'] . '|' . $row['station_code'];
			$dep = (int) $row['departure_seconds'];
			$arr = (int) $row['arrival_seconds'];
			$times[$key] = array(
				'departure' => $dep >= 0 ? self::format_seconds($dep) : '',
				'arrival' => $arr >= 0 ? self::format_seconds($arr) : '',
			);
		}
		return $times;
	}

	private static function filter_journeys_with_visible_stops(array $journeys, array $stops, array $times, $minimum_visible_stops = 2) {
		$minimum_visible_stops = max(1, (int) $minimum_visible_stops);
		return array_values(
			array_filter(
				$journeys,
				function ($journey) use ($stops, $times, $minimum_visible_stops) {
					$visible = 0;
					$last_index = count($stops) - 1;
					foreach ($stops as $stop_index => $stop) {
						$key = $journey['journey_ref'] . '|' . $stop['station_code'];
						$cell = isset($times[$key]) ? $times[$key] : array('departure' => '', 'arrival' => '');
						$display = self::stop_display_time($cell, $stop_index, $last_index);
						if (is_array($display)) {
							if (!empty($display['arrival']) || !empty($display['departure'])) {
								$visible++;
							}
						} elseif ($display !== '') {
							$visible++;
						}
						if ($visible >= $minimum_visible_stops) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	private static function stop_display_time(array $cell, $stop_index, $last_index) {
		$arrival = isset($cell['arrival']) ? (string) $cell['arrival'] : '';
		$departure = isset($cell['departure']) ? (string) $cell['departure'] : '';
		if ($stop_index <= 0) {
			return $departure !== '' ? $departure : $arrival;
		}
		if ($stop_index >= $last_index) {
			return $arrival !== '' ? $arrival : $departure;
		}
		if ($arrival !== '' && $departure !== '' && $arrival !== $departure) {
			return array(
				'arrival' => $arrival,
				'departure' => $departure,
			);
		}
		if ($departure !== '') {
			return $departure;
		}
		return $arrival;
	}

	private static function format_stop_display($display, $delay_info = null) {
		if (!is_array($display)) {
			return self::format_time_with_realtime_delay(esc_html((string) $display), $delay_info);
		}
		$arrival = isset($display['arrival']) ? (string) $display['arrival'] : '';
		$departure = isset($display['departure']) ? (string) $display['departure'] : '';
		$parts = array();
		if ($arrival !== '') {
			$parts[] = '<span class="ovtd-time-label">A:</span> <span class="ovtd-time-value">' . self::format_time_with_realtime_delay(esc_html($arrival), $delay_info) . '</span>';
		}
		if ($departure !== '') {
			$parts[] = '<span class="ovtd-time-label">V:</span> <span class="ovtd-time-value">' . self::format_time_with_realtime_delay(esc_html($departure), $delay_info) . '</span>';
		}
		return implode('<br />', $parts);
	}

	private static function route_label(array $stops) {
		if (empty($stops)) {
			return 'Route';
		}
		$first = reset($stops);
		$last = end($stops);
		return 'van ' . $first['station_name'] . ' naar ' . $last['station_name'];
	}

	private static function render_mobile_cards(array $journeys, array $stops, array $times, array $direction, $background, $text_color, array $realtime_delays = array()) {
		if (empty($journeys) || empty($stops)) {
			return '';
		}
		$last_index = count($stops) - 1;
		ob_start();
		?>
		<div class="ovtd-mobile-cards">
			<?php foreach ($journeys as $journey) : ?>
				<?php
				$departure_time = self::format_seconds((int) $journey['departure_seconds']);
				$first = $stops[0];
				$last = $stops[$last_index];
				$first_key = $journey['journey_ref'] . '|' . $first['station_code'];
				$last_key = $journey['journey_ref'] . '|' . $last['station_code'];
				$first_time = isset($times[$first_key]['departure']) ? $times[$first_key]['departure'] : $departure_time;
				$last_time = isset($times[$last_key]['arrival']) ? $times[$last_key]['arrival'] : self::format_seconds((int) $journey['arrival_seconds']);
				?>
				<div class="ovtd-mobile-card" tabindex="0">
					<div class="ovtd-mobile-card-head">
						<span class="ovtd-mobile-badge" style="background-color: <?php echo esc_attr($background); ?>; color: <?php echo esc_attr($text_color); ?>;"><?php echo esc_html(self::train_badge($direction['train_type'])); ?></span>
						<span class="ovtd-mobile-card-trip">Vertrek <?php echo esc_html($first_time); ?> naar <?php echo esc_html($last['station_name']); ?></span>
					</div>
					<div class="ovtd-mobile-card-stops">
						<?php foreach ($stops as $stop_index => $stop) : ?>
							<?php
							$key = $journey['journey_ref'] . '|' . $stop['station_code'];
							$cell = isset($times[$key]) ? $times[$key] : array('departure' => '', 'arrival' => '');
							$display = self::stop_display_time($cell, $stop_index, $last_index);
							if ($display === '') {
								continue;
							}
							$d_key = $journey['journey_ref'] . '|' . strtolower($stop['station_code']);
							$d_info = isset($realtime_delays[$d_key]) ? $realtime_delays[$d_key] : null;
							?>
							<div class="ovtd-mobile-stop-row">
								<span><?php echo esc_html($stop['station_name']); ?></span>
								<strong><?php echo wp_kses_post(self::format_stop_display($display, $d_info)); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function get_realtime_delays_for_journeys(array $journeys, array $stops) {
		global $wpdb;
		$realtime_table = $wpdb->prefix . 'ovhi_realtime_delays';
		$st_codes = array_unique(array_filter(array_map('strtolower', wp_list_pluck($stops, 'station_code'))));
		$lookup_journey_refs = array();
		$scheduled_refs_by_lookup = array();
		foreach ($journeys as $journey) {
			$scheduled_ref = isset($journey['journey_ref']) ? trim((string) $journey['journey_ref']) : '';
			if ($scheduled_ref === '') {
				continue;
			}
			$candidates = array($scheduled_ref);
			if (!empty($journey['train_number'])) {
				$candidates[] = trim((string) $journey['train_number']);
			}
			foreach (array_values(array_unique(array_filter($candidates, 'strlen'))) as $candidate) {
				$lookup_journey_refs[] = $candidate;
				if (!isset($scheduled_refs_by_lookup[$candidate])) {
					$scheduled_refs_by_lookup[$candidate] = array();
				}
				$scheduled_refs_by_lookup[$candidate][] = $scheduled_ref;
			}
		}
		$lookup_journey_refs = array_values(array_unique($lookup_journey_refs));
		if (empty($lookup_journey_refs) || empty($st_codes)) {
			return array();
		}
		$delays = array();
		// Try local DB table first
		if ((bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $realtime_table))) {
			$j_placeholders = implode(',', array_fill(0, count($lookup_journey_refs), '%s'));
			$s_placeholders = implode(',', array_fill(0, count($st_codes), '%s'));
			$one_hour_ago = date('Y-m-d H:i:s', time() - 3600);
			$params = array_merge($lookup_journey_refs, $st_codes, array($one_hour_ago));
			$query = "SELECT journey_ref, stop_code, delay_seconds, is_cancelled FROM $realtime_table WHERE journey_ref IN ($j_placeholders) AND stop_code IN ($s_placeholders) AND updated_at >= %s";
			$rows = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);
			if (!empty($rows)) {
				foreach ($rows as $r) {
					$matched_refs = isset($scheduled_refs_by_lookup[$r['journey_ref']]) ? $scheduled_refs_by_lookup[$r['journey_ref']] : array($r['journey_ref']);
					foreach ($matched_refs as $matched_ref) {
						$delays[$matched_ref . '|' . strtolower($r['stop_code'])] = $r;
					}
				}
			}
		}
		// NS API fallback: vult alleen de ontbrekende combinaties aan. Voorheen
		// werd dit alleen aangeroepen als de hele batch leeg was, waardoor één
		// geslaagde lokale match (bv. een Arriva-trein) de NS API-aanvulling
		// voor de rest van diezelfde stationsbatch (bv. NS-treinen) blokkeerde.
		$ns_delays = self::fetch_ns_api_delays($st_codes);
		foreach ($ns_delays as $ns_key => $ns_delay) {
			if (!isset($delays[$ns_key])) {
				$delays[$ns_key] = $ns_delay;
			}
		}
		return $delays;
	}

	private static function get_delay_info_for_journey(array $delays, $journey_ref, $station_code) {
		$journey_ref = trim((string) $journey_ref);
		$station_code = strtolower(trim((string) $station_code));
		if ($journey_ref === '') {
			return null;
		}

		$candidates = array($journey_ref);
		if ($station_code !== '') {
			$candidates[] = $journey_ref . '|' . $station_code;
		}
		foreach ($candidates as $candidate) {
			if (isset($delays[$candidate]) && is_array($delays[$candidate])) {
				return $delays[$candidate];
			}
		}

		return null;
	}

	private static function format_time_with_realtime_delay($time_str, $delay_info) {
		if (empty($time_str) || !$delay_info) {
			return $time_str;
		}
		if (!empty($delay_info['is_cancelled'])) {
			return '<s>' . $time_str . '</s> <span class="ov-cancelled" style="color: #d93025; font-size: 11px; font-weight: bold;">Vervallen</span>';
		}
		$delay_seconds = (int) $delay_info['delay_seconds'];
		if ($delay_seconds > 0) {
			$delay_min = round($delay_seconds / 60);
			if ($delay_min > 0) {
				return $time_str . ' <span class="ov-delay" style="color: #d93025; font-size: 11px; font-weight: bold;">+' . $delay_min . '</span>';
			}
		}
		return $time_str;
	}

	/**
	 * Fetch real-time delays for one or more NS station codes via the NS Reisinformatie API v3.
	 *
	 * Returns an array keyed by "{trainNumber}|{stationCode}" (lowercase) with
	 * values: ['delay_seconds' => int, 'is_cancelled' => bool].
	 * Results are cached as WordPress Transients for 45 seconds.
	 */
	public static function fetch_ns_api_delays(array $station_codes) {
		$api_key = get_option('ovtd_ns_api_key', '');
		if ($api_key === '' || empty($station_codes)) {
			return array();
		}

		$station_codes = array_values(array_unique(array_map('strtolower', array_filter($station_codes))));
		$delays = array();

		foreach ($station_codes as $station_code) {
			$cache_key = 'ovtd_ns_dep_' . substr(md5($station_code), 0, 20);
			$cached = get_transient($cache_key);

			if (is_array($cached)) {
				foreach ($cached as $k => $v) {
					$delays[$k] = $v;
				}
				continue;
			}

			$url = 'https://gateway.apiportal.ns.nl/reisinformatie-api/api/v3/departures?station=' . rawurlencode(strtoupper($station_code));
			$response = wp_remote_get($url, array(
				'timeout' => 4,
				'headers' => array(
					'Ocp-Apim-Subscription-Key' => $api_key,
					'Accept'                    => 'application/json',
					'User-Agent'                => 'Ovalino/1.0',
				),
			));

			$station_delays = array();
			if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
				$body = wp_remote_retrieve_body($response);
				$json = json_decode($body, true);
				$departures = isset($json['payload']['departures']) ? $json['payload']['departures'] : array();

				foreach ($departures as $dep) {
					// Extract train number from "name" field (e.g. "IC 2400" → "2400")
					// or from product.number
					$train_num = '';
					if (!empty($dep['product']['number'])) {
						$train_num = (string) $dep['product']['number'];
					} elseif (!empty($dep['name'])) {
						$parts = explode(' ', trim((string) $dep['name']));
						$train_num = end($parts);
					}
					if ($train_num === '') {
						continue;
					}

					$is_cancelled = !empty($dep['cancelled']) && $dep['cancelled'] === true;

					$delay_seconds = 0;
					if (!empty($dep['delayInSeconds'])) {
						$delay_seconds = (int) $dep['delayInSeconds'];
					} elseif (!empty($dep['plannedDepartureTime']) && !empty($dep['actualDepartureTime'])) {
						$planned = strtotime($dep['plannedDepartureTime']);
						$actual  = strtotime($dep['actualDepartureTime']);
						if ($planned && $actual) {
							$delay_seconds = max(0, $actual - $planned);
						}
					}

					$item = array('delay_seconds' => $delay_seconds, 'is_cancelled' => $is_cancelled);
					$station_delays[$train_num . '|' . $station_code] = $item;
					// Also index by train number alone so matching without stop code works
					$station_delays[$train_num] = $item;
				}
			}

			set_transient($cache_key, $station_delays, 45);
			foreach ($station_delays as $k => $v) {
				$delays[$k] = $v;
			}
		}

		return $delays;
	}

	private static function day_group_key($date) {
		$day = (int) gmdate('N', strtotime($date));
		return $day >= 1 && $day <= 5 ? 'weekday' : ($day === 6 ? 'saturday' : 'sunday');
	}

	private static function service_day_order_seconds($seconds) {
		$seconds = (int) $seconds;
		if ($seconds >= 0 && $seconds < self::SERVICE_DAY_START_SECONDS) {
			return $seconds + DAY_IN_SECONDS;
		}
		return $seconds;
	}

	private static function format_seconds($seconds) {
		$seconds = (int) $seconds;
		$seconds = $seconds % DAY_IN_SECONDS;
		if ($seconds < 0) {
			$seconds += DAY_IN_SECONDS;
		}
		return sprintf('%02d:%02d', floor($seconds / HOUR_IN_SECONDS), floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS));
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
			'.ovtd-wrapper{max-width:100%;overflow:hidden;color:#861121;}
			.ovtd-form{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0 0 24px;}
			.ovtd-form label{display:flex;flex-direction:column;gap:5px;font-family:circularstd-bold,sans-serif;font-size:14px;color:#861121;}
			.ovtd-form select{min-width:260px;max-width:100%;padding:9px 10px;border:1px solid rgba(134,17,33,.25);border-radius:8px;background:#fff;color:#861121;}
			.ovtd-form button{padding:10px 18px;border:0;border-radius:999px;background:#861121;color:#fff;font-family:circularstd-bold,sans-serif;cursor:pointer;}
			.ovtd-quick-switch{display:flex;gap:7px;align-items:center;}
			.ovtd-quick-date{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 13px;border-radius:999px;background:rgba(134,17,33,.08);color:#861121;font-family:circularstd-bold,sans-serif;font-size:13px;text-decoration:none;border:1px solid rgba(134,17,33,.16);}
			.ovtd-quick-date.is-active{background:#861121;color:#fff;}
			.ovtd-quick-date.is-disabled{opacity:.38;}
			.ovtd-line-heading{display:flex;gap:12px;align-items:center;margin:0 0 18px;}
			.ovtd-meta-container{margin-left:auto;display:flex;flex-direction:column;align-items:flex-end;gap:6px;}
			.ovtd-operator-logo{max-width:40px;max-height:40px;width:auto;height:auto;object-fit:contain;}
			.ovtd-print{padding:0 14px;height:38px;border:0;border-radius:999px;background:#fff;color:#861121;font-family:circularstd-bold,sans-serif;cursor:pointer;border:1px solid rgba(134,17,33,.15);}
			.ovtd-badge{min-width:38px;height:38px;padding:0 8px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-family:circularstd-bold,sans-serif;font-size:14px;}
			.ovtd-route{font-family:circularstd-bold,sans-serif;font-size:18px;color:#861121;}
			.ovtd-variant,.ovtd-valid-dates{font-family:circularstd-bold,sans-serif;font-size:11px;line-height:1.35;color:#861121;margin-top:2px;}
			.ovtd-table-shell{display:flex;align-items:center;gap:10px;max-width:100%;}
			.ovtd-table-wrap{flex:1 1 auto;min-width:0;overflow-x:auto;padding-bottom:8px;}
			.ovtd-grid{display:grid;grid-template-columns:minmax(190px,1.3fr) repeat(var(--ovtd-columns),minmax(58px,.45fr));min-width:max-content;}
			.ovtd-stop,.ovtd-time{padding:7px 10px;border-bottom:1px solid rgba(134,17,33,.13);font-size:14px;white-space:nowrap;}
			.ovtd-stop{position:sticky;left:0;background:#fff;z-index:1;font-weight:700;box-shadow:2px 0 4px rgba(0,0,0,.08);}
			.ovtd-time{text-align:center;}
			.ovtd-time-label{font-weight:700;}
			.ovtd-time-value{font-variant-numeric:tabular-nums;}
			.ovtd-time.ovtd-current-trip,.ovtd-head.ovtd-current-trip{background:rgba(134,17,33,.075);}
			.ovtd-head{font-weight:700;color:#5d0e18;}
			.ovtd-scroll{width:30px;height:44px;border:0;border-radius:999px;background:#861121;color:#fff;font-size:28px;cursor:pointer;}
			.ovtd-mobile-cards{display:none;}
			.ovtd-mobile-card{border:1px solid rgba(134,17,33,.14);border-radius:14px;padding:11px 12px;margin:0 0 12px;}
			.ovtd-mobile-card-head{display:flex;gap:9px;align-items:center;font-family:circularstd-bold,sans-serif;color:#861121;}
			.ovtd-mobile-badge{min-width:30px;height:30px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;}
			.ovtd-mobile-stop-row{display:flex;justify-content:space-between;border-top:1px solid rgba(134,17,33,.1);padding:7px 0;}
			@media (orientation:portrait){.ovtd-table-shell{display:none}.ovtd-mobile-cards{display:block}}
			.ovtd-print-schedule { display: none; }
			@media print {
				@page { size: landscape; margin: 8mm; }
				html { margin: 0 !important; }
				body.ovtd-printing-active > :not(.ovtd-print-schedule) {
					display: none !important;
				}
				body.ovtd-printing-active {
					margin: 0 !important;
					padding: 0 !important;
					background: #fff !important;
				}
				.ovtd-schedule { display: none !important; }
				.ovtd-print-schedule {
					display: block !important;
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
					-webkit-print-color-adjust: exact !important;
					print-color-adjust: exact !important;
				}
				.ovtd-print-chunk {
					page-break-inside: avoid;
					break-inside: avoid;
					margin-bottom: 16px !important;
				}
				.ovtd-print-grid {
					display: grid !important;
					min-width: max-content !important;
					width: max-content !important;
					grid-template-columns: minmax(150px, 1.4fr) repeat(var(--ovtd-columns), minmax(48px, 1fr)) !important;
					border: 1px solid #cbd5e1 !important;
					border-radius: 4px !important;
					overflow: visible !important;
				}
				.ovtd-print-grid .ovtd-stop {
					position: static !important;
					box-shadow: none !important;
					background: #fff !important;
					border-right: 2px solid #cbd5e1 !important;
					font-weight: bold !important;
					text-align: left !important;
					white-space: normal !important;
					font-size: 10px !important;
					padding: 5px 8px !important;
				}
				.ovtd-print-grid .ovtd-time {
					text-align: center !important;
					white-space: nowrap !important;
					font-size: 10px !important;
					padding: 5px 8px !important;
				}
				.ovtd-print-grid .ovtd-stop, .ovtd-print-grid .ovtd-time {
					border-bottom: 1px solid #e2e8f0 !important;
					color: #000 !important;
					line-height: 1.1 !important;
				}
				.ovtd-print-grid .ovtd-head {
					position: static !important;
					background: #f1f5f9 !important;
					color: #1e293b !important;
					font-weight: bold !important;
					border-bottom: 2px solid #cbd5e1 !important;
				}
				.ovtd-print-grid .ovtd-row-even {
					background: #f8fafc !important;
				}
				.ovtd-print-grid .ovtd-row-odd {
					background: #ffffff !important;
				}
				.ovtd-line-heading {
					display: flex !important;
					margin: 0 0 12px !important;
					align-items: center !important;
					border-bottom: 2px solid #861121 !important;
					padding-bottom: 8px !important;
				}
				.ovtd-badge {
					display: inline-flex !important;
					border-radius: 6px !important;
					width: auto !important;
					min-width: 32px !important;
					height: 32px !important;
					padding: 0 6px !important;
					font-weight: bold !important;
					font-size: 14px !important;
					margin-right: 12px !important;
					-webkit-print-color-adjust: exact !important;
					print-color-adjust: exact !important;
				}
				.ovtd-route {
					font-size: 16px !important;
					font-weight: bold !important;
					color: #861121 !important;
				}
				.ovtd-variant, .ovtd-valid-dates {
					font-size: 10px !important;
					margin-top: 1px !important;
					color: #555 !important;
				}
			}'
		);

		wp_register_script('ovtd-frontend', false, array(), self::VERSION, true);
		wp_enqueue_script('ovtd-frontend');
		wp_add_inline_script(
			'ovtd-frontend',
			"(function(){
				(function(){
					var originalParent=null;
					var originalSibling=null;
					var schedule=null;
					window.addEventListener('beforeprint',function(){
						schedule=document.querySelector('.ovtd-print-schedule');
						if(!schedule){return;}
						originalParent=schedule.parentNode;
						originalSibling=schedule.nextSibling;
						document.body.classList.add('ovtd-printing-active');
						document.body.appendChild(schedule);
					});
					window.addEventListener('afterprint',function(){
						if(!schedule||!originalParent){return;}
						if(originalSibling){
							originalParent.insertBefore(schedule,originalSibling);
						}else{
							originalParent.appendChild(schedule);
						}
						document.body.classList.remove('ovtd-printing-active');
					});
				})();

				function update(shell){
					var wrap=shell.querySelector('.ovtd-table-wrap');
					var left=shell.querySelector('.ovtd-scroll-left');
					var right=shell.querySelector('.ovtd-scroll-right');
					if(!wrap||!left||!right){return;}
					var max=wrap.scrollWidth-wrap.clientWidth;
					var has=max>2;
					left.style.display=has?'flex':'none';
					right.style.display=has?'flex':'none';
				}
				function scrollToCurrent(shell){
					var current=parseInt(shell.dataset.ovtdCurrentTime,10);
					if(isNaN(current)){return;}
					var cells=shell.querySelectorAll('.ovtd-grid > .ovtd-time[data-ovtd-departure]');
					var target=cells[cells.length-1];
					for(var i=0;i<cells.length;i++){var dep=parseInt(cells[i].dataset.ovtdDeparture,10);if(!isNaN(dep)&&dep>=current){target=cells[i];break;}}
					var wrap=shell.querySelector('.ovtd-table-wrap');
					if(wrap&&target){wrap.scrollTo({left:Math.max(0,target.offsetLeft-120),behavior:'smooth'});}
					shell.querySelectorAll('.ovtd-current-trip').forEach(function(el){el.classList.remove('ovtd-current-trip');});
					if(target){target.classList.add('ovtd-current-trip');}
				}
				document.querySelectorAll('.ovtd-table-shell').forEach(function(shell){
					shell.querySelectorAll('[data-ovtd-scroll]').forEach(function(btn){
						btn.addEventListener('click',function(){
							var wrap=shell.querySelector('.ovtd-table-wrap');
							var dir=parseInt(btn.getAttribute('data-ovtd-scroll'),10)||1;
							if(wrap){wrap.scrollBy({left:dir*240,behavior:'smooth'});}
						});
					});
					update(shell); scrollToCurrent(shell);
				});
			})();"
		);
	}
}

OV_Trein_Dienstregeling::init();
