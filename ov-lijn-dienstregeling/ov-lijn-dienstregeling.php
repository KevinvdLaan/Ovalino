<?php
/**
 * Plugin Name: OV Lijn Dienstregeling
 * Description: Toon een busboekje per lijn op basis van de data uit OV Halte Importer.
 * Version: 1.2.1
 * Author: Kevin van der Laan
 * License: GPL-2.0-or-later
 * Requires Plugins: ov-halte-importer
 */

if (!defined('ABSPATH')) {
	exit;
}

class OV_Lijn_Dienstregeling {
	const VERSION = '1.2.1';
	const OPTION_HIDDEN_LINES = 'ovld_hidden_lines';
	const NONCE_ACTION = 'ovld_save_settings';
	const FRONTEND_STYLE = 'ovld-frontend';
	const FALLBACK_COLOR = '#861121';
	const SERVICE_DAY_START_SECONDS = 18000; // 5:00 begins the OV service day

	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'admin_menu'));
		add_action('admin_post_ovld_save_settings', array(__CLASS__, 'save_settings'));
		add_shortcode('ov_lijn_dienstregeling', array(__CLASS__, 'render_shortcode'));
		add_action('admin_notices', array(__CLASS__, 'dependency_notice'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_enqueue_assets'));
	}

	public static function admin_enqueue_assets($hook) {
		if (strpos($hook, 'ov-lijn-dienstregeling') !== false) {
			wp_enqueue_media();
		}
	}

	private static function table($suffix) {
		global $wpdb;
		return $wpdb->prefix . 'ovhi_' . $suffix;
	}

	private static function table_exists_by_suffix($suffix) {
		global $wpdb;
		$table = self::table($suffix);
		return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	}

	private static function dependency_available() {
		global $wpdb;
		$table = self::table('lines');
		return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	}

	public static function dependency_notice() {
		if (!current_user_can('activate_plugins') || self::dependency_available()) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>OV Lijn Dienstregeling</strong> vereist de plugin <strong>OV Halte Importer</strong> en een uitgevoerde data-import.</p></div>';
	}

	public static function admin_menu() {
		// Koppel deze pagina als submenu onder het 'Ovalino' hoofdmenu
		add_submenu_page(
			'ovalino-menu',
			'OV Lijn Dienstregeling',
			'OV Lijn Dienstregeling',
			'manage_options',
			'ov-lijn-dienstregeling',
			array(__CLASS__, 'render_admin_page')
		);

		// Koppel deze diagnosepagina als submenu onder het 'Ovalino' hoofdmenu
		add_submenu_page(
			'ovalino-menu',
			'OV Lijn Diagnose',
			'OV Lijn Diagnose',
			'manage_options',
			'ov-lijn-diagnose',
			array(__CLASS__, 'render_diagnosis_page')
		);
	}

	public static function render_admin_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om deze pagina te bekijken.', 'ovld'));
		}

		$lines = self::get_lines(true);
		$hidden = self::get_hidden_lines();
		?>
		<div class="wrap">
			<h1>OV Lijn Dienstregeling</h1>
			<?php if (!self::dependency_available()) : ?>
				<div class="notice notice-error"><p>OV Halte Importer-data is nog niet gevonden. Importeer eerst de OV-data via OV Halte Importer.</p></div>
			<?php endif; ?>
			
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="ovld_save_settings" />
				<?php wp_nonce_field(self::NONCE_ACTION); ?>

				<?php if (self::dependency_available()) : ?>
					<h2>Vervoerder Logo's</h2>
					<p>Upload of kies een logo voor elke vervoerder in de database. Deze worden rechtsboven de dienstregeling getoond.</p>
					<table class="form-table" style="max-width: 900px; margin-bottom: 20px;">
						<?php
						$operators = self::get_operators();
						$logos = get_option('ovld_operator_logos', array());
						foreach ($operators as $operator) :
							$friendly = self::get_operator_friendly_name($operator);
							$value = isset($logos[$operator]) ? $logos[$operator] : '';
							?>
							<tr>
								<th scope="row" style="width: 200px;"><label for="logo_<?php echo esc_attr($operator); ?>"><?php echo esc_html($friendly); ?></label></th>
								<td>
									<div style="display: flex; align-items: center; gap: 10px;">
										<input type="text" name="operator_logos[<?php echo esc_attr($operator); ?>]" id="logo_<?php echo esc_attr($operator); ?>" value="<?php echo esc_url($value); ?>" class="regular-text" placeholder="https://..." />
										<button type="button" class="button ov-upload-logo-btn" data-target="logo_<?php echo esc_attr($operator); ?>">Selecteer logo</button>
										<button type="button" class="button ov-clear-logo-btn" data-target="logo_<?php echo esc_attr($operator); ?>">Verwijder</button>
										<img src="<?php echo esc_url($value); ?>" id="preview_logo_<?php echo esc_attr($operator); ?>" style="max-height: 40px; max-width: 100px; display: <?php echo $value ? 'block' : 'none'; ?>; object-fit: contain;" />
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
					<hr />
				<?php endif; ?>

				<h2>Lijnen verbergen</h2>
				<p>Vink lijnen aan die je niet wilt tonen in de frontend-dropdown.</p>
				<table class="widefat striped" style="max-width: 900px; margin-bottom: 20px;">
					<thead>
						<tr>
							<th scope="col">Verbergen</th>
							<th scope="col">Lijn</th>
							<th scope="col">Naam</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($lines as $line) : ?>
							<tr>
								<td><input type="checkbox" name="hidden_lines[]" value="<?php echo esc_attr($line['line_ref']); ?>" <?php checked(in_array($line['line_ref'], $hidden, true)); ?> /></td>
								<td><strong><?php echo esc_html($line['public_code']); ?></strong></td>
								<td><?php echo esc_html($line['line_name']); ?></td>
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

			<h2>Shortcode</h2>
			<p>Gebruik op een WordPress-pagina:</p>
			<code>[ov_lijn_dienstregeling]</code>
		</div>
		<?php
	}

	public static function render_diagnosis_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om deze pagina te bekijken.', 'ovld'));
		}

		$lines = self::get_lines(true);
		$selected_line = isset($_GET['ovld_diag_line']) ? sanitize_text_field(wp_unslash($_GET['ovld_diag_line'])) : '';
		$diagnosis = $selected_line !== '' ? self::get_line_diagnosis($selected_line) : array();
		?>
		<div class="wrap">
			<h1>OV Lijn Diagnose</h1>
			<?php if (!self::dependency_available()) : ?>
				<div class="notice notice-error"><p>OV Halte Importer-data is nog niet gevonden. Importeer eerst de OV-data via OV Halte Importer.</p></div>
			<?php endif; ?>
			<p>Kies een lijn om snel te controleren welke richtingen, datums, ritten en patronen uit de database komen.</p>
			<form method="get" style="margin: 0 0 20px;">
				<input type="hidden" name="page" value="ov-lijn-diagnose" />
				<select name="ovld_diag_line" style="min-width: 280px;">
					<option value="">Kies een lijn</option>
					<?php foreach ($lines as $line) : ?>
						<option value="<?php echo esc_attr($line['line_ref']); ?>" <?php selected($selected_line, $line['line_ref']); ?>>
							<?php echo esc_html(trim($line['public_code'] . ' ' . $line['line_name'])); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button('Diagnose tonen', 'primary', '', false); ?>
			</form>
			<?php if ($selected_line && !empty($diagnosis)) : ?>
				<h2><?php echo esc_html($diagnosis['line_label']); ?></h2>
				<p><strong>Beschikbare richtingen in data:</strong> <?php echo esc_html($diagnosis['direction_list']); ?></p>
				<table class="widefat striped" style="max-width: 1100px;">
					<thead>
						<tr>
							<th>Richting</th>
							<th>Datumvarianten vanaf vandaag</th>
							<th>Ritten vandaag</th>
							<th>Ritten totaal</th>
							<th>Patronen</th>
							<th>Haltes</th>
							<th>Geldig van/tot</th>
							<th>Eerste komende datums</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($diagnosis['directions'] as $row) : ?>
							<tr>
								<td><strong><?php echo esc_html($row['direction']); ?></strong></td>
								<td><?php echo esc_html((string) $row['variant_count']); ?></td>
								<td><?php echo esc_html((string) $row['today_journey_count']); ?></td>
								<td><?php echo esc_html((string) $row['journey_count']); ?></td>
								<td><?php echo esc_html((string) $row['pattern_count']); ?></td>
								<td><?php echo esc_html((string) $row['stop_count']); ?></td>
								<td><?php echo esc_html($row['date_range']); ?></td>
								<td><?php echo esc_html($row['next_dates']); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<h2>Patronen</h2>
				<?php foreach ($diagnosis['directions'] as $row) : ?>
					<h3><?php echo esc_html($row['direction']); ?></h3>
					<?php if (empty($row['patterns'])) : ?>
						<p>Geen patronen gevonden.</p>
					<?php else : ?>
						<table class="widefat striped" style="max-width: 1100px; margin-bottom: 18px;">
							<thead>
								<tr>
									<th>Pattern ref</th>
									<th>Ritten</th>
									<th>Haltes</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($row['patterns'] as $pattern) : ?>
									<tr>
										<td><code><?php echo esc_html($pattern['service_journey_pattern_ref']); ?></code></td>
										<td><?php echo esc_html((string) $pattern['journey_count']); ?></td>
										<td><?php echo esc_html((string) $pattern['stop_count']); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php elseif ($selected_line) : ?>
				<p>Geen diagnosegegevens gevonden voor deze lijn.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function save_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Je hebt geen rechten om dit uit te voeren.', 'ovld'));
		}
		check_admin_referer(self::NONCE_ACTION);

		$hidden = isset($_POST['hidden_lines']) && is_array($_POST['hidden_lines']) ? wp_unslash($_POST['hidden_lines']) : array();
		$hidden = array_values(array_unique(array_filter(array_map('sanitize_text_field', $hidden))));
		update_option(self::OPTION_HIDDEN_LINES, $hidden, false);

		$logos = isset($_POST['operator_logos']) && is_array($_POST['operator_logos']) ? wp_unslash($_POST['operator_logos']) : array();
		$sanitized_logos = array();
		foreach ($logos as $op => $url) {
			$sanitized_logos[sanitize_text_field($op)] = esc_url_raw($url);
		}
		update_option('ovld_operator_logos', $sanitized_logos, false);

		wp_safe_redirect(add_query_arg(array('page' => 'ov-lijn-dienstregeling', 'updated' => 'true'), admin_url('admin.php')));
		exit;
	}

	private static function get_hidden_lines() {
		$hidden = get_option(self::OPTION_HIDDEN_LINES, array());
		return is_array($hidden) ? array_values(array_filter($hidden)) : array();
	}

	private static function get_valid_date_range($journeys) {
		if (empty($journeys)) {
			return array();
		}
		$min_date = null;
		$max_date = null;
		foreach ($journeys as $journey) {
			$from_date = isset($journey['from_date']) ? $journey['from_date'] : '';
			$to_date = isset($journey['to_date']) ? $journey['to_date'] : '';
			if ($from_date && ($min_date === null || $from_date < $min_date)) {
				$min_date = $from_date;
			}
			if ($to_date && ($max_date === null || $to_date > $max_date)) {
				$max_date = $to_date;
			}
		}
		return array($min_date, $max_date);
	}

	private static function format_date_range($min_date, $max_date) {
		if (!$min_date) {
			return '';
		}
		$min_formatted = date_i18n('d-m-Y', strtotime($min_date));
		return 'Geldig vanaf: ' . $min_formatted;
	}

	private static function get_current_service_date() {
		$timestamp = current_time('timestamp');
		$seconds = (int) date('G', $timestamp) * HOUR_IN_SECONDS + (int) date('i', $timestamp) * MINUTE_IN_SECONDS + (int) date('s', $timestamp);
		if ($seconds < self::SERVICE_DAY_START_SECONDS) {
			return date('Y-m-d', $timestamp - DAY_IN_SECONDS);
		}
		return date('Y-m-d', $timestamp);
	}

	private static function get_lines($include_hidden = false) {
		global $wpdb;
		if (!self::dependency_available()) {
			return array();
		}

		$hidden = self::get_hidden_lines();
		$query = "
			SELECT l.line_ref, l.public_code, l.line_name, l.colour, l.text_colour
			FROM " . self::table('lines') . " l
			WHERE EXISTS (
				SELECT 1 FROM " . self::table('stop_offsets') . " so WHERE so.line_ref = l.line_ref LIMIT 1
			)
			ORDER BY l.public_code + 0, l.public_code, l.line_name
		";
		$lines = $wpdb->get_results($query, ARRAY_A);

		if ($include_hidden || empty($hidden)) {
			return $lines ? $lines : array();
		}

		return array_values(array_filter($lines, function ($line) use ($hidden) {
			return !in_array($line['line_ref'], $hidden, true);
		}));
	}

	public static function get_operators() {
		global $wpdb;
		if (!self::dependency_available()) {
			return array();
		}
		$lines_table = self::table('lines');
		$line_refs = $wpdb->get_col("SELECT DISTINCT line_ref FROM {$lines_table}");
		$operators = array();
		foreach ($line_refs as $line_ref) {
			$parts = explode(':', $line_ref);
			if (count($parts) > 1) {
				$operator = $parts[1];
				if (!in_array($operator, $operators, true)) {
					$operators[] = $operator;
				}
			}
		}
		sort($operators);
		return $operators;
	}

	public static function get_operator_friendly_name($operator) {
		$map = array(
			'QBUZZ' => 'Qbuzz',
			'CXX'   => 'Connexxion / Transdev',
			'EBS'   => 'EBS',
		);
		return isset($map[strtoupper($operator)]) ? $map[strtoupper($operator)] : $operator;
	}

	private static function get_line_diagnosis($line_ref) {
		global $wpdb;
		if (!self::dependency_available()) {
			return array();
		}

		$line = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('lines') . ' WHERE line_ref = %s', $line_ref), ARRAY_A);
		if (!$line) {
			return array();
		}

		$directions_in_data = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT direction_type FROM ' . self::table('stop_offsets') . ' WHERE line_ref = %s ORDER BY direction_type', $line_ref));
		$directions = array_values(array_unique(array_filter(array_merge(array('inbound', 'outbound'), $directions_in_data))));
		$today = self::get_current_service_date();
		$rows = array();

		foreach ($directions as $direction) {
			$stats = $wpdb->get_row(
				$wpdb->prepare(
					"
					SELECT COUNT(DISTINCT j.journey_ref) AS journey_count,
						COUNT(DISTINCT so.service_journey_pattern_ref) AS pattern_count,
						COUNT(DISTINCT so.scheduled_stop_point_ref) AS stop_count,
						MIN(a.from_date) AS min_date,
						MAX(a.to_date) AS max_date
					FROM " . self::table('stop_offsets') . " so
					LEFT JOIN " . self::table('journeys') . " j
						ON j.service_journey_pattern_ref = so.service_journey_pattern_ref
						AND j.time_demand_type_ref = so.time_demand_type_ref
					LEFT JOIN " . self::table('availability') . " a ON a.availability_ref = j.availability_ref
					WHERE so.line_ref = %s AND so.direction_type = %s
					",
					$line_ref,
					$direction
				),
				ARRAY_A
			);

			$variants = self::get_variants($line_ref, $direction);
			$today_journeys = self::get_active_journeys($line_ref, $direction, $today);
			$patterns = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT so.service_journey_pattern_ref,
						COUNT(DISTINCT j.journey_ref) AS journey_count,
						COUNT(DISTINCT so.scheduled_stop_point_ref) AS stop_count
					FROM " . self::table('stop_offsets') . " so
					LEFT JOIN " . self::table('journeys') . " j
						ON j.service_journey_pattern_ref = so.service_journey_pattern_ref
						AND j.time_demand_type_ref = so.time_demand_type_ref
					WHERE so.line_ref = %s AND so.direction_type = %s
					GROUP BY so.service_journey_pattern_ref
					ORDER BY journey_count DESC, stop_count DESC
					LIMIT 10
					",
					$line_ref,
					$direction
				),
				ARRAY_A
			);

			$next_dates = array();
			foreach (array_slice($variants, 0, 5) as $variant) {
				$next_dates[] = isset($variant['date']) ? $variant['date'] : '';
			}
			$min_date = isset($stats['min_date']) ? (string) $stats['min_date'] : '';
			$max_date = isset($stats['max_date']) ? (string) $stats['max_date'] : '';
			$rows[] = array(
				'direction' => $direction,
				'variant_count' => count($variants),
				'today_journey_count' => count($today_journeys),
				'journey_count' => isset($stats['journey_count']) ? (int) $stats['journey_count'] : 0,
				'pattern_count' => isset($stats['pattern_count']) ? (int) $stats['pattern_count'] : 0,
				'stop_count' => isset($stats['stop_count']) ? (int) $stats['stop_count'] : 0,
				'date_range' => ($min_date && $max_date) ? $min_date . ' t/m ' . $max_date : '-',
				'next_dates' => !empty($next_dates) ? implode(', ', array_filter($next_dates)) : '-',
				'patterns' => $patterns ? $patterns : array(),
			);
		}

		return array(
			'line_label' => trim($line['public_code'] . ' ' . $line['line_name']),
			'direction_list' => !empty($directions_in_data) ? implode(', ', $directions_in_data) : 'geen',
			'directions' => $rows,
		);
	}

	public static function render_shortcode($atts) {
		if (!self::dependency_available()) {
			return '<p>OV Halte Importer-data is nog niet beschikbaar.</p>';
		}

		$atts = shortcode_atts(
			array(
				'direction' => 'inbound',
			),
			$atts,
			'ov_lijn_dienstregeling'
		);

		$direction = isset($_GET['ovld_direction']) ? sanitize_text_field(wp_unslash($_GET['ovld_direction'])) : sanitize_text_field($atts['direction']);
		$direction = in_array($direction, array('inbound', 'outbound'), true) ? $direction : 'inbound';
		$opposite_direction = $direction === 'inbound' ? 'outbound' : 'inbound';
		$selected_line = isset($_GET['ovld_line']) ? sanitize_text_field(wp_unslash($_GET['ovld_line'])) : '';
		$selected_variant = isset($_GET['ovld_variant']) ? sanitize_text_field(wp_unslash($_GET['ovld_variant'])) : '';
		$lines = self::get_lines(false);
		$variants = $selected_line ? self::get_variants($selected_line, $direction) : array();
		if ($selected_line && empty($variants)) {
			$fallback_direction = $direction === 'inbound' ? 'outbound' : 'inbound';
			$fallback_variants = self::get_variants($selected_line, $fallback_direction);
			if (!empty($fallback_variants)) {
				$direction = $fallback_direction;
				$opposite_direction = $direction === 'inbound' ? 'outbound' : 'inbound';
				$variants = $fallback_variants;
			}
		}
		$has_opposite_direction = $selected_line ? !empty(self::get_variants($selected_line, $opposite_direction)) : false;
		$current_service_date = self::get_current_service_date();
		$tomorrow_service_date = date('Y-m-d', strtotime($current_service_date . ' +1 day'));

		self::enqueue_frontend_style();

		ob_start();
		?>
		<div class="ovld-wrapper">
			<form class="ovld-form" method="get">
				<?php foreach ($_GET as $key => $value) : ?>
					<?php if (!in_array($key, array('ovld_line', 'ovld_variant', 'ovld_direction'), true) && !is_array($value)) : ?>
						<input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(wp_unslash($value)); ?>" />
					<?php endif; ?>
				<?php endforeach; ?>
				<input type="hidden" name="ovld_direction" value="<?php echo esc_attr($direction); ?>" />
				<label>
					<span>Lijn</span>
					<select name="ovld_line" onchange="this.form.ovld_variant.value=''; this.form.submit();">
						<option value="">Kies een lijn</option>
						<?php foreach ($lines as $line) : ?>
							<option value="<?php echo esc_attr($line['line_ref']); ?>" <?php selected($selected_line, $line['line_ref']); ?>>
								<?php echo esc_html(trim($line['public_code'] . ' ' . $line['line_name'])); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>Datum</span>
					<select name="ovld_variant" <?php disabled(empty($variants)); ?>>
						<option value="">Kies een datum</option>
						<?php foreach ($variants as $variant) : ?>
							<option value="<?php echo esc_attr($variant['date']); ?>" <?php selected($selected_variant, $variant['date']); ?>>
								<?php echo esc_html($variant['label']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php if ($selected_line) : ?>
					<div class="ovld-quick-switch" aria-label="Snel datum kiezen">
						<?php echo self::render_quick_date_link('Vandaag', $current_service_date, $variants, $selected_variant, $selected_line, $direction); ?>
						<?php echo self::render_quick_date_link('Morgen', $tomorrow_service_date, $variants, $selected_variant, $selected_line, $direction); ?>
					</div>
				<?php endif; ?>
				<button type="submit">Zoeken</button>
				<?php if ($selected_line && $selected_variant && $has_opposite_direction) : ?>
					<button type="submit" class="ovld-opposite" onclick="this.form.ovld_direction.value='<?php echo esc_js($opposite_direction); ?>';">Tegenovergestelde richting</button>
				<?php endif; ?>
			</form>
			<?php
		if ($selected_line && $selected_variant) {
			$selected_variant_label = self::selected_variant_label($variants, $selected_variant);
			error_log('Rendering schedule for line: ' . $selected_line . ', direction: ' . $direction . ', variant: ' . $selected_variant);
			echo self::render_schedule($selected_line, $direction, $selected_variant, $selected_variant_label);
		}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function variant_date_available(array $variants, $date) {
		foreach ($variants as $variant) {
			if (isset($variant['date']) && $variant['date'] === $date) {
				return true;
			}
		}
		return false;
	}

	private static function render_quick_date_link($label, $date, array $variants, $selected_variant, $selected_line, $direction) {
		$available = self::variant_date_available($variants, $date);
		$classes = array('ovld-quick-date');
		if (!$available) {
			$classes[] = 'is-disabled';
			return '<span class="' . esc_attr(implode(' ', $classes)) . '">' . esc_html($label) . '</span>';
		}
		if ($selected_variant === $date) {
			$classes[] = 'is-active';
		}
		$url = add_query_arg(
			array(
				'ovld_direction' => $direction,
				'ovld_line' => $selected_line,
				'ovld_variant' => $date,
			)
		);
		return '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
	}

	private static function get_variants($line_ref, $direction) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT DISTINCT a.from_date, a.to_date, a.valid_day_bits
				FROM " . self::table('journeys') . " j
				INNER JOIN " . self::table('availability') . " a ON a.availability_ref = j.availability_ref
				INNER JOIN " . self::table('stop_offsets') . " so
					ON so.service_journey_pattern_ref = j.service_journey_pattern_ref
					AND so.time_demand_type_ref = j.time_demand_type_ref
				WHERE so.line_ref = %s AND so.direction_type = %s
				",
				$line_ref,
				$direction
			),
			ARRAY_A
		);
		if (empty($rows)) {
			return array();
		}

		$min = null;
		$max = null;
		foreach ($rows as $row) {
			$min = $min === null || $row['from_date'] < $min ? $row['from_date'] : $min;
			$max = $max === null || $row['to_date'] > $max ? $row['to_date'] : $max;
		}
		if (!$min || !$max) {
			return array();
		}

		$current_service_date = self::get_current_service_date();
		$start = new DateTimeImmutable($min . ' 00:00:00');
		$end = new DateTimeImmutable($max . ' 00:00:00');
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
			foreach ($rows as $row) {
				if (self::availability_matches_date($row, $service_date)) {
					$has_service = true;
					break;
				}
			}
			if (!$has_service) {
				continue;
			}

			$variants[] = array(
				'date' => $service_date,
				'label' => date_i18n('j F Y', strtotime($service_date)),
				'group' => self::day_group_key($service_date),
			);
		}

		usort($variants, function ($a, $b) {
			return strcmp($a['date'], $b['date']);
		});

		return $variants;
	}

	private static function selected_variant_label(array $variants, $selected_variant) {
		foreach ($variants as $variant) {
			if ($variant['date'] === $selected_variant) {
				return self::variant_group_label($variant['group'], array($variant['date']));
			}
		}
		if ($selected_variant) {
			return self::variant_group_label(self::day_group_key($selected_variant), array($selected_variant));
		}
		return '';
	}

	private static function day_group_key($date) {
		$holiday = self::holiday_name($date);
		if ($holiday !== '') {
			return 'holiday:' . $holiday;
		}

		$day = (int) gmdate('N', strtotime($date));
		if ($day >= 1 && $day <= 5) {
			return 'weekday';
		}
		return $day === 6 ? 'saturday' : 'sunday';
	}

	public static function render_schedule($line_ref, $direction, $service_date, $variant_label = '') {
		global $wpdb;

		$line = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('lines') . ' WHERE line_ref = %s', $line_ref), ARRAY_A);
		if (!$line) {
			return '<p>Deze lijn is niet gevonden.</p>';
		}

		$available_directions = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT direction_type FROM ' . self::table('stop_offsets') . ' WHERE line_ref = %s', $line_ref));
		error_log('Available direction_types for line ' . $line_ref . ': ' . implode(', ', $available_directions));
		error_log('Trying direction: ' . $direction);

		$journeys = self::get_active_journeys($line_ref, $direction, $service_date);
		if (empty($journeys)) {
			$opposite_direction = $direction === 'inbound' ? 'outbound' : 'inbound';
			$journeys = self::get_active_journeys($line_ref, $opposite_direction, $service_date);
			if (!empty($journeys)) {
				$direction = $opposite_direction;
				error_log('Switched to opposite direction: ' . $direction);
			} else {
				error_log('No journeys found for line_ref: ' . $line_ref . ', direction: ' . $direction . ', service_date: ' . $service_date);
				return '<p>Voor deze keuze is geen dienstregeling gevonden.</p>';
			}
		}

		list($journeys, $footnote_map) = self::map_footnotes($journeys);

		$pattern_counts = array();
		foreach ($journeys as $journey) {
			$pattern = $journey['service_journey_pattern_ref'];
			$pattern_counts[$pattern] = isset($pattern_counts[$pattern]) ? $pattern_counts[$pattern] + 1 : 1;
		}
		arsort($pattern_counts, SORT_NUMERIC);
		$pattern_refs = array_keys($pattern_counts);
		usort($journeys, function ($a, $b) {
			return self::service_day_order_seconds((int) $a['departure_seconds']) - self::service_day_order_seconds((int) $b['departure_seconds']);
		});

		$primary_pattern_ref = reset($pattern_refs);
		$stops = self::get_pattern_stops($pattern_refs, $line_ref, $direction, $primary_pattern_ref);
		if (empty($stops)) {
			error_log('No stops found for line_ref: ' . $line_ref . ', direction: ' . $direction . ', patterns: ' . implode(',', $pattern_refs));
			return '<p>Voor deze route zijn geen haltes gevonden.</p>';
		}

		$offsets = self::get_offsets_for_journeys($pattern_refs, $journeys);
		$journey_refs = wp_list_pluck($journeys, 'journey_ref');
		$stop_codes = array();
		foreach ($stops as $stop) {
			if (!empty($stop['quay_code'])) {
				$stop_codes[] = $stop['quay_code'];
			}
		}
		$realtime_delay_map = self::get_realtime_delay_map($journey_refs, $stop_codes);
		$link_map = self::get_stop_page_links();
		$valid_date_range = 'Geldig op: ' . date_i18n('d-m-Y', strtotime($service_date));
		$route = self::route_label($stops, $primary_pattern_ref, $pattern_refs);
		$variant_label = $variant_label !== '' ? $variant_label : self::variant_label(array($service_date));
		$background = !empty($line['colour']) ? $line['colour'] : self::FALLBACK_COLOR;
		$text_color = self::resolve_text_colour($background, isset($line['text_colour']) ? $line['text_colour'] : '');
		$current_timestamp = current_time('timestamp');
		$current_group = self::day_group_key(date('Y-m-d', $current_timestamp));
		$selected_group = self::day_group_key($service_date);
		$current_seconds = (int) date('G', $current_timestamp) * HOUR_IN_SECONDS + (int) date('i', $current_timestamp) * MINUTE_IN_SECONDS + (int) date('s', $current_timestamp);
		$scroll_time = $current_group === $selected_group ? (string) self::service_day_order_seconds($current_seconds) : '';

		$line_ref_val = isset($line['line_ref']) ? $line['line_ref'] : '';
		$logo_url = '';
		$parts = explode(':', $line_ref_val);
		if (count($parts) > 1) {
			$operator = $parts[1];
			$logos = get_option('ovld_operator_logos', array());
			if (isset($logos[$operator])) {
				$logo_url = $logos[$operator];
			}
		}

		ob_start();
		?>
		<div class="ovld-schedule">
			<div class="ovld-line-heading">
				<span class="ovld-badge" style="background-color: <?php echo esc_attr($background); ?>; color: <?php echo esc_attr($text_color); ?>;"><?php echo esc_html($line['public_code']); ?></span>
				<div>
					<div class="ovld-route"><?php echo esc_html($route); ?></div>
					<div class="ovld-variant"><?php echo esc_html($variant_label); ?></div>
					<?php if ($valid_date_range) : ?><div class="ovld-valid-dates"><?php echo esc_html($valid_date_range); ?></div><?php endif; ?>
				</div>
				<div class="ovld-meta-container">
					<?php if ($logo_url) : ?>
						<img src="<?php echo esc_url($logo_url); ?>" alt="Vervoerder logo" class="ovld-operator-logo" />
					<?php endif; ?>
					<button type="button" class="ovld-print" onclick="window.print();" aria-label="Print deze dienstregeling">Print</button>
				</div>
			</div>
			<div class="ovld-table-shell" data-ovld-current-time="<?php echo esc_attr($scroll_time); ?>">
				<button type="button" class="ovld-scroll ovld-scroll-left" aria-label="Scroll dienstregeling naar links" data-ovld-scroll="-1">‹</button>
				<div class="ovld-table-wrap" tabindex="0">
					<?php
					$journey_count = count($journeys);
					$density_class = 'ovld-density-normal';
					if ($journey_count > 25) {
						$density_class = 'ovld-density-ultra-dense';
					} elseif ($journey_count > 15) {
						$density_class = 'ovld-density-dense';
					}
					?>
					<div class="ovld-grid <?php echo esc_attr($density_class); ?>" style="--ovld-columns: <?php echo esc_attr((string) $journey_count); ?>;">
						<div class="ovld-stop ovld-head">Halte</div>
						<?php foreach ($journeys as $journey) : ?>
							<div class="ovld-time ovld-head"></div>
						<?php endforeach; ?>
						<?php foreach ($stops as $stop_index => $stop) : 
							$row_class = ($stop_index % 2 === 0) ? 'ovld-row-even' : 'ovld-row-odd';
							?>
							<div class="ovld-stop <?php echo esc_attr($row_class); ?>">
								<?php echo self::render_stop_name($stop, $link_map); ?>
							</div>
							<?php foreach ($journeys as $journey) : ?>
								<?php
								$key = $journey['service_journey_pattern_ref'] . '|' . $journey['time_demand_type_ref'] . '|' . $stop['scheduled_stop_point_ref'];
								$time = isset($offsets[$key]) ? self::format_seconds((int) $journey['departure_seconds'] + (int) $offsets[$key]) : '';
								$departure = self::service_day_order_seconds((int) $journey['departure_seconds']); 
								$departure_attr = $stop_index === 0 ? ' data-ovld-departure="' . esc_attr($departure) . '"' : ''; 
								$footnote_marker = ($time !== '' && !empty($journey['footnote_letter'])) ? '<span class="ovld-footnote-marker">' . esc_html($journey['footnote_letter']) . '</span>' : ''; 
								?>
								<?php
								$delay = array('delay_seconds' => 0, 'is_cancelled' => false);
								if (!empty($stop['quay_code'])) {
									$delay_key = self::get_realtime_delay_map_key($journey['journey_ref'], $stop['quay_code']);
									if ($delay_key !== '' && isset($realtime_delay_map[$delay_key])) {
										$scheduled_ts = self::timestamp_from_service_seconds($service_date, (int) $journey['departure_seconds'] + (int) $offsets[$key]);
										if ($scheduled_ts <= (current_time('timestamp') + 2 * HOUR_IN_SECONDS)) {
											$delay = $realtime_delay_map[$delay_key];
										}
									}
								}
								$time_html = $time !== '' ? self::format_schedule_time($time, $delay['delay_seconds'], $delay['is_cancelled']) : '';
								?>
								<div class="ovld-time <?php echo esc_attr($row_class); ?>"<?php echo $departure_attr; ?>><?php echo wp_kses_post($time_html); ?><?php echo $footnote_marker; ?></div>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</div>
				</div>
				<button type="button" class="ovld-scroll ovld-scroll-right" aria-label="Scroll dienstregeling naar rechts" data-ovld-scroll="1">›</button>
			</div>
			<?php echo self::render_mobile_cards($journeys, $stops, $offsets, $line, $background, $text_color, $realtime_delay_map, $service_date); ?>
			<?php echo self::render_footnotes($footnote_map); ?>
		</div>
		<?php echo self::render_print_schedule($journeys, $stops, $offsets, $line, $background, $text_color, $route, $valid_date_range, $variant_label, $footnote_map, $link_map, $realtime_delay_map, $service_date); ?>
		<?php
		return ob_get_clean();
	}

	private static function render_print_schedule(array $journeys, array $stops, array $offsets, array $line, $background, $text_color, $route, $valid_date_range, $variant_label, array $footnote_map, array $link_map, array $realtime_delay_map = array(), $service_date = '') {
		if (empty($journeys) || empty($stops)) {
			return '';
		}

		$line_ref_val = isset($line['line_ref']) ? $line['line_ref'] : '';
		$logo_url = '';
		$parts = explode(':', $line_ref_val);
		if (count($parts) > 1) {
			$operator = $parts[1];
			$logos = get_option('ovld_operator_logos', array());
			if (isset($logos[$operator])) {
				$logo_url = $logos[$operator];
			}
		}

		$chunks = array_chunk($journeys, 10);
		$total_chunks = count($chunks);

		ob_start();
		?>
		<div class="ovld-print-schedule">
			<?php foreach ($chunks as $chunk_index => $chunk_journeys) : ?>
				<div class="ovld-print-chunk">
					<div class="ovld-line-heading">
						<span class="ovld-badge" style="background-color: <?php echo esc_attr($background); ?>; color: <?php echo esc_attr($text_color); ?>;"><?php echo esc_html($line['public_code']); ?></span>
						<div>
							<div class="ovld-route"><?php echo esc_html($route); ?></div>
							<div class="ovld-variant"><?php echo esc_html($line['name']); ?> · <?php echo esc_html($variant_label); ?> (Deel <?php echo ($chunk_index + 1) . '/' . $total_chunks; ?>)</div>
							<?php if ($valid_date_range) : ?><div class="ovld-valid-dates"><?php echo esc_html($valid_date_range); ?></div><?php endif; ?>
						</div>
						<?php if ($logo_url) : ?>
							<img src="<?php echo esc_url($logo_url); ?>" alt="Vervoerder logo" class="ovld-operator-logo" style="margin-left: auto; max-height: 40px; max-width: 40px; object-fit: contain;" />
						<?php endif; ?>
					</div>
					<div class="ovld-print-grid" style="--ovld-columns: <?php echo esc_attr((string) count($chunk_journeys)); ?>;">
						<div class="ovld-stop ovld-head">Halte</div>
						<?php foreach ($chunk_journeys as $journey) : ?>
							<div class="ovld-time ovld-head"></div>
						<?php endforeach; ?>
						<?php foreach ($stops as $stop_index => $stop) : 
							$row_class = ($stop_index % 2 === 0) ? 'ovld-row-even' : 'ovld-row-odd';
							?>
							<div class="ovld-stop <?php echo esc_attr($row_class); ?>">
								<?php echo self::render_stop_name($stop, $link_map); ?>
							</div>
							<?php foreach ($chunk_journeys as $journey) : ?>
								<?php
								$key = $journey['service_journey_pattern_ref'] . '|' . $journey['time_demand_type_ref'] . '|' . $stop['scheduled_stop_point_ref'];
								$time = isset($offsets[$key]) ? self::format_seconds((int) $journey['departure_seconds'] + (int) $offsets[$key]) : '';
								$delay = array('delay_seconds' => 0, 'is_cancelled' => false);
								if (!empty($stop['quay_code'])) {
									$delay_key = self::get_realtime_delay_map_key($journey['journey_ref'], $stop['quay_code']);
									if ($delay_key !== '' && isset($realtime_delay_map[$delay_key])) {
										$scheduled_ts = self::timestamp_from_service_seconds($service_date, (int) $journey['departure_seconds'] + (int) $offsets[$key]);
										if ($scheduled_ts <= (current_time('timestamp') + 2 * HOUR_IN_SECONDS)) {
											$delay = $realtime_delay_map[$delay_key];
										}
									}
								}
								$time_html = $time !== '' ? self::format_schedule_time($time, $delay['delay_seconds'], $delay['is_cancelled']) : '';
								$footnote_marker = ($time !== '' && !empty($journey['footnote_letter'])) ? '<span class="ovld-footnote-marker">' . esc_html($journey['footnote_letter']) . '</span>' : ''; 
								?>
								<div class="ovld-time <?php echo esc_attr($row_class); ?>"><?php echo wp_kses_post($time_html); ?><?php echo $footnote_marker; ?></div>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			<?php if (!empty($footnote_map)) : ?>
				<div class="ovld-footnotes">
					<?php foreach ($footnote_map as $letter => $description) : ?>
						<div><strong><?php echo esc_html($letter); ?></strong>: <?php echo esc_html($description); ?></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_mobile_cards(array $journeys, array $stops, array $offsets, array $line, $background, $text_color, array $realtime_delay_map = array(), $service_date = '') {
		if (empty($journeys) || empty($stops)) {
			return '';
		}

		ob_start();
		?>
		<div class="ovld-mobile-cards" aria-label="Compacte mobiele dienstregeling">
			<?php foreach ($journeys as $journey) : ?>
				<?php
				$departure_seconds = self::service_day_order_seconds((int) $journey['departure_seconds']);
				$departure_time = self::format_seconds((int) $journey['departure_seconds']);
				$card_rows = array();
				$full_rows = array();
				foreach ($stops as $stop) {
					$key = $journey['service_journey_pattern_ref'] . '|' . $journey['time_demand_type_ref'] . '|' . $stop['scheduled_stop_point_ref'];
					if (!isset($offsets[$key])) {
						continue;
					}
						$delay = array('delay_seconds' => 0, 'is_cancelled' => false);
						if (!empty($stop['quay_code'])) {
							$delay_key = self::get_realtime_delay_map_key($journey['journey_ref'], $stop['quay_code']);
							if ($delay_key !== '' && isset($realtime_delay_map[$delay_key])) {
								// Only apply realtime delay if the scheduled departure is within the next 2 hours
								$scheduled_ts = self::timestamp_from_service_seconds($service_date, (int) $journey['departure_seconds'] + (int) $offsets[$key]);
								if ($scheduled_ts <= (current_time('timestamp') + 2 * HOUR_IN_SECONDS)) {
									$delay = $realtime_delay_map[$delay_key];
								}
							}
						}
						$full_rows[] = array(
							'name' => $stop['stop_name'] !== '' ? $stop['stop_name'] : $stop['scheduled_stop_point_ref'],
							'time' => self::format_schedule_time(self::format_seconds((int) $journey['departure_seconds'] + (int) $offsets[$key]), $delay['delay_seconds'], $delay['is_cancelled']),
						);
				}
				if (empty($full_rows)) {
					continue;
				}
				$last_full_index = count($full_rows) - 1;
				$destination_name = $full_rows[$last_full_index]['name'];
				foreach ($full_rows as $row_index => $row) {
					if ($row_index !== 0 && $row_index !== $last_full_index && !preg_match('/(Centrum|Station|P\+R)/i', $row['name'])) {
						continue;
					}
					$card_rows[] = $row;
				}
				?>
				<div class="ovld-mobile-card" role="button" tabindex="0" data-ovld-departure-card="<?php echo esc_attr((string) $departure_seconds); ?>" aria-label="Toon alle haltes van de rit met vertrek <?php echo esc_attr($departure_time); ?>">
					<div class="ovld-mobile-card-head">
						<span class="ovld-mobile-badge" style="background-color: <?php echo esc_attr($background); ?>; color: <?php echo esc_attr($text_color); ?>;"><?php echo esc_html($line['public_code']); ?></span>
						<span class="ovld-mobile-card-trip">Vertrek <?php echo esc_html($departure_time); ?> naar <?php echo esc_html($destination_name); ?></span>
						<span class="ovld-mobile-card-action">Alle haltes</span>
					</div>
					<div class="ovld-mobile-card-stops">
						<?php foreach ($card_rows as $row) : ?>
							<div class="ovld-mobile-stop-row">
								<span><?php echo esc_html($row['name']); ?></span>
								<strong><?php echo wp_kses_post($row['time']); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="ovld-mobile-detail" hidden>
						<div class="ovld-mobile-detail-title">Lijn <?php echo esc_html($line['public_code']); ?> - vertrek <?php echo esc_html($departure_time); ?></div>
						<?php if (!empty($journey['footnote'])) : ?>
							<div class="ovld-mobile-detail-notice">⚠️ <?php echo esc_html($journey['footnote']); ?></div>
						<?php endif; ?>
						<?php foreach ($full_rows as $row) : ?>
							<div class="ovld-mobile-detail-row">
								<span><?php echo esc_html($row['name']); ?></span>
								<strong><?php echo wp_kses_post($row['time']); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function get_offsets_for_journeys($pattern_refs, $journeys) {
		global $wpdb;
		if (empty($journeys)) {
			return array();
		}

		$time_demand_refs = array();
		foreach ($journeys as $journey) {
			$time_demand_refs[$journey['time_demand_type_ref']] = true;
		}
		$time_demand_refs = array_keys($time_demand_refs);

		if (empty($pattern_refs) || empty($time_demand_refs)) {
			return array();
		}

		$pattern_placeholders = implode(',', array_fill(0, count($pattern_refs), '%s'));
		$time_placeholders = implode(',', array_fill(0, count($time_demand_refs), '%s'));
		$params = array_merge($pattern_refs, $time_demand_refs);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT service_journey_pattern_ref, time_demand_type_ref, scheduled_stop_point_ref, offset_seconds
				FROM " . self::table('stop_offsets') . "
				WHERE service_journey_pattern_ref IN ($pattern_placeholders) AND time_demand_type_ref IN ($time_placeholders)
				",
				$params
			),
			ARRAY_A
		);
		$offsets = array();
		foreach ($rows as $row) {
			$offsets[$row['service_journey_pattern_ref'] . '|' . $row['time_demand_type_ref'] . '|' . $row['scheduled_stop_point_ref']] = (int) $row['offset_seconds'];
		}
		return $offsets;
	}

	private static function get_active_journeys($line_ref, $direction, $service_date) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT j.*, a.from_date, a.to_date, a.valid_day_bits
				FROM " . self::table('journeys') . " j
				INNER JOIN " . self::table('availability') . " a ON a.availability_ref = j.availability_ref
				INNER JOIN " . self::table('stop_offsets') . " so
					ON so.service_journey_pattern_ref = j.service_journey_pattern_ref
					AND so.time_demand_type_ref = j.time_demand_type_ref
				WHERE so.line_ref = %s AND so.direction_type = %s AND a.from_date <= %s AND a.to_date >= %s
				GROUP BY j.journey_signature
				",
				$line_ref,
				$direction,
				$service_date,
				$service_date
			),
			ARRAY_A
		);

		$active_rows = array_values(array_filter($rows, function ($row) use ($service_date) {
			return self::availability_matches_date($row, $service_date);
		}));

		if (empty($active_rows)) {
			return array();
		}

		// Find the latest start date (from_date) among the active rows for this date
		$max_from_date = '';
		foreach ($active_rows as $row) {
			if ($row['from_date'] > $max_from_date) {
				$max_from_date = $row['from_date'];
			}
		}

		// Only keep rows that belong to the dataset with this latest start date
		if ($max_from_date !== '') {
			$active_rows = array_values(array_filter($active_rows, function ($row) use ($max_from_date) {
				return $row['from_date'] === $max_from_date;
			}));
		}

		// Filter duplicates: keep only the newest version of journeys with the same departure time
		$groups = array();
		foreach ($active_rows as $row) {
			$dep = (int) $row['departure_seconds'];
			$groups[$dep][] = $row;
		}
		$active_rows = array();
		foreach ($groups as $dep => $group) {
			if (count($group) > 1) {
				usort($group, function ($a, $b) {
					return strcmp($b['from_date'], $a['from_date']);
				});
			}
			$active_rows[] = $group[0];
		}

		// Initialise footnote field to empty string.
		foreach ($active_rows as $index => $row) {
			$active_rows[$index]['footnote'] = '';
		}

		// Guard against missing tables (e.g. before the first import after the schema update).
		if (self::table_exists_by_suffix('notices') && self::table_exists_by_suffix('notice_assignments')) {
			$journey_refs = array_column($active_rows, 'journey_ref');
			$placeholders = implode(',', array_fill(0, count($journey_refs), '%s'));
			$footnotes_query = "
				SELECT na.noticed_object_ref AS journey_ref, GROUP_CONCAT(n.notice_text ORDER BY n.notice_id SEPARATOR ' / ') AS footnote
				FROM " . self::table('notice_assignments') . " na
				INNER JOIN " . self::table('notices') . " n ON n.notice_id = na.notice_ref
				WHERE na.noticed_object_ref IN ($placeholders) AND na.name_of_ref_class = 'ServiceJourney'
				GROUP BY na.noticed_object_ref
			";
			$footnotes = $wpdb->get_results($wpdb->prepare($footnotes_query, $journey_refs), ARRAY_A);
			$footnotes_map = array();
			if (is_array($footnotes)) {
				foreach ($footnotes as $f) {
					$footnotes_map[$f['journey_ref']] = $f['footnote'];
				}
			}

			foreach ($active_rows as $index => $row) {
				$ref = $row['journey_ref'];
				$active_rows[$index]['footnote'] = isset($footnotes_map[$ref]) ? $footnotes_map[$ref] : '';
			}
		}

		return $active_rows;
	}

	private static function get_realtime_delay_map(array $journey_refs, array $stop_codes) {
		global $wpdb;

		$journey_refs = array_values(array_filter(array_map('trim', $journey_refs), function ($value) {
			return $value !== '';
		}));
		$stop_codes = array_values(array_filter(array_map('trim', $stop_codes), function ($value) {
			return $value !== '';
		}));
		if (empty($journey_refs) || empty($stop_codes)) {
			return array();
		}

		$lookup_stop_codes = array();
		foreach ($stop_codes as $stop_code) {
			$lookup_stop_codes[] = $stop_code;
			if (preg_match('/^NL:Q:(\d+)$/', $stop_code, $matches)) {
				$lookup_stop_codes[] = $matches[1];
			} elseif (preg_match('/^\d+$/', $stop_code)) {
				$lookup_stop_codes[] = 'NL:Q:' . $stop_code;
			}
		}
		$lookup_stop_codes = array_values(array_unique($lookup_stop_codes));

		$lookup_journey_refs = array();
		$scheduled_refs_by_lookup = array();
		foreach ($journey_refs as $journey_ref) {
			foreach (self::get_realtime_journey_ref_candidates($journey_ref) as $candidate) {
				$lookup_journey_refs[] = $candidate;
				if (!isset($scheduled_refs_by_lookup[$candidate])) {
					$scheduled_refs_by_lookup[$candidate] = array();
				}
				$scheduled_refs_by_lookup[$candidate][] = $journey_ref;
			}
		}
		$lookup_journey_refs = array_values(array_unique($lookup_journey_refs));

		$table = $wpdb->prefix . 'ovhi_realtime_delays';
		if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
			return array();
		}

		$placeholders_j = implode(',', array_fill(0, count($lookup_journey_refs), '%s'));
		$placeholders_s = implode(',', array_fill(0, count($lookup_stop_codes), '%s'));
		$params = array_merge($lookup_journey_refs, $lookup_stop_codes);

		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT journey_ref, stop_code, delay_seconds, is_cancelled FROM ' . $table . ' WHERE journey_ref IN (' . $placeholders_j . ') AND stop_code IN (' . $placeholders_s . ')',
			$params
		), ARRAY_A);

		$map = array();
		foreach ($rows as $row) {
			$value = array(
				'delay_seconds' => isset($row['delay_seconds']) ? (int) $row['delay_seconds'] : 0,
				'is_cancelled' => !empty($row['is_cancelled']),
			);
			$matched_refs = isset($scheduled_refs_by_lookup[$row['journey_ref']]) ? $scheduled_refs_by_lookup[$row['journey_ref']] : array($row['journey_ref']);
			foreach ($matched_refs as $matched_ref) {
				$key = $matched_ref . '|' . $row['stop_code'];
				$map[$key] = $value;
				if (preg_match('/^NL:Q:(\d+)$/', $row['stop_code'], $matches)) {
					$map[$matched_ref . '|' . $matches[1]] = $value;
				} elseif (preg_match('/^(\d+)$/', $row['stop_code'], $matches)) {
					$map[$matched_ref . '|NL:Q:' . $matches[1]] = $value;
				}
			}
		}

		return $map;
	}

	private static function get_realtime_journey_ref_candidates($journey_ref) {
		$journey_ref = trim((string) $journey_ref);
		if ($journey_ref === '') {
			return array();
		}

		$candidates = array($journey_ref);
		$last_colon_part = $journey_ref;
		if (strpos($journey_ref, ':') !== false) {
			$parts = explode(':', $journey_ref);
			$last_colon_part = end($parts);
			if ($last_colon_part !== '') {
				$candidates[] = $last_colon_part;
			}
		}

		if (strpos($last_colon_part, '-') !== false) {
			$parts = array_values(array_filter(explode('-', $last_colon_part), 'strlen'));
			if (count($parts) >= 2) {
				$candidates[] = $parts[count($parts) - 2];
			}
		}

		return array_values(array_unique(array_filter($candidates, 'strlen')));
	}

	private static function get_realtime_delay_map_key($journey_ref, $stop_code) {
		$journey_ref = trim((string) $journey_ref);
		$stop_code = trim((string) $stop_code);
		if ($journey_ref === '' || $stop_code === '') {
			return '';
		}

		return $journey_ref . '|' . $stop_code;
	}

	private static function format_schedule_time($time, $delay_seconds = 0, $is_cancelled = false) {
		$html = esc_html((string) $time);
		if ($is_cancelled) {
			return '<span style="color:#d00;font-weight:700;">(vervallen)</span>';
		}
		if ($delay_seconds === 0) {
			return $html;
		}
		$sign = $delay_seconds > 0 ? '+' : '-';
		$minutes = (int) floor((abs((int) $delay_seconds) + 59) / 60);
		$color = $delay_seconds > 0 ? '#d00' : '#0a0';
		return $html . ' <span style="color:' . esc_attr($color) . ';">' . esc_html($sign . $minutes) . '</span>';
	}

	private static function get_pattern_stops($pattern_refs, $line_ref, $direction, $primary_pattern_ref = null) {
		global $wpdb;
		$pattern_refs = is_array($pattern_refs) ? array_values(array_unique(array_filter($pattern_refs))) : array($pattern_refs);
		if (empty($pattern_refs)) {
			return array();
		}

		$placeholders = implode(',', array_fill(0, count($pattern_refs), '%s'));
		$params = array_merge($pattern_refs, array($line_ref, $direction));
		$query = "
			SELECT so.service_journey_pattern_ref, so.scheduled_stop_point_ref, so.stop_order, so.offset_seconds, s.stop_name, s.user_stop_code, s.stop_area_ref, ass.quay_code, q.stopplace_code
			FROM " . self::table('stop_offsets') . " so
			INNER JOIN " . self::table('scheduled_stops') . " s ON s.scheduled_stop_point_ref = so.scheduled_stop_point_ref
			LEFT JOIN " . self::table('assignments') . " ass ON ass.scheduled_stop_point_ref = so.scheduled_stop_point_ref
			LEFT JOIN " . self::table('quays') . " q ON q.quay_code = ass.quay_code
			WHERE so.service_journey_pattern_ref IN ($placeholders) AND so.line_ref = %s AND so.direction_type = %s
			ORDER BY so.service_journey_pattern_ref, CASE WHEN so.stop_order > 0 THEN so.stop_order ELSE so.offset_seconds END, so.offset_seconds, so.id
		";
		$all_stops_raw = $wpdb->get_results(
			$wpdb->prepare(
				$query,
				$params
			),
			ARRAY_A
		);

		if (empty($all_stops_raw)) {
			return array();
		}

		$route_groups = array();
		$pattern_max_orders = array();
		foreach ($all_stops_raw as $stop) {
			$pattern = $stop['service_journey_pattern_ref'];
			if (!isset($route_groups[$pattern])) {
				$route_groups[$pattern] = array('stops' => array());
			}
			if ((int) $stop['stop_order'] <= 0) {
				$stop['stop_order'] = count($route_groups[$pattern]['stops']) + 1;
			}
			$route_groups[$pattern]['stops'][] = $stop;
			if (!isset($pattern_max_orders[$pattern]) || $stop['stop_order'] > $pattern_max_orders[$pattern]) {
				$pattern_max_orders[$pattern] = $stop['stop_order'];
			}
		}

		$all_stops_info = array();
		$shared_stops = array();
		foreach ($route_groups as $pattern => $group) {
			foreach ($group['stops'] as $index => $stop) {
				$ref = $stop['scheduled_stop_point_ref'];
				if (!isset($all_stops_info[$ref])) {
					$all_stops_info[$ref] = array(
						'stop' => $stop,
						'positions' => array(),
						'pattern_count' => 0,
						'end_stop_count' => 0
					);
				}
				$all_stops_info[$ref]['positions'][] = $index;
				$all_stops_info[$ref]['pattern_count']++;
				if ($stop['stop_order'] == $pattern_max_orders[$pattern]) {
					$all_stops_info[$ref]['end_stop_count']++;
				}
			}
		}

		foreach ($all_stops_info as $ref => $info) {
			if ($info['pattern_count'] > 1) {
				$shared_stops[$ref] = true;
			}
		}

		$unique_preceding_counts = array();
		foreach ($route_groups as $pattern => $group) {
			$unique_before = 0;
			foreach ($group['stops'] as $index => $stop) {
				$ref = $stop['scheduled_stop_point_ref'];
				if (!isset($unique_preceding_counts[$ref])) {
					$unique_preceding_counts[$ref] = array();
				}
				$unique_preceding_counts[$ref][] = $unique_before;
				if (!isset($shared_stops[$ref])) {
					$unique_before++;
				}
			}
		}

		$graph = array();
		$indegree = array();
		foreach ($all_stops_info as $ref => $info) {
			$graph[$ref] = array();
			$indegree[$ref] = 0;
		}
		$edge_keys = array();
		foreach ($route_groups as $pattern => $group) {
			foreach ($group['stops'] as $index => $stop) {
				if (!isset($group['stops'][$index + 1])) {
					continue;
				}
				$from = $stop['scheduled_stop_point_ref'];
				$to = $group['stops'][$index + 1]['scheduled_stop_point_ref'];
				if ($from === $to) {
					continue;
				}
				$key = $from . '|' . $to;
				if (!isset($edge_keys[$key])) {
					$edge_keys[$key] = true;
					$graph[$from][] = $to;
					$indegree[$to]++;
				}
			}
		}

		foreach ($all_stops_info as $ref => &$info) {
			$info['is_always_end_stop'] = ($info['end_stop_count'] == $info['pattern_count']);
			if (isset($unique_preceding_counts[$ref])) {
				$info['shared_delay'] = max($unique_preceding_counts[$ref]);
			} else {
				$info['shared_delay'] = 0;
			}
		}
		unset($info);

		$sorted_refs = array();
		$available = array();
		foreach ($indegree as $ref => $count) {
			if ($count === 0) {
				$available[] = $ref;
			}
		}

		$compare_available = function($a, $b) use ($all_stops_info) {
			if ($all_stops_info[$a]['is_always_end_stop'] !== $all_stops_info[$b]['is_always_end_stop']) {
				return $all_stops_info[$a]['is_always_end_stop'] ? 1 : -1;
			}
			if ($all_stops_info[$a]['shared_delay'] !== $all_stops_info[$b]['shared_delay']) {
				return $all_stops_info[$a]['shared_delay'] < $all_stops_info[$b]['shared_delay'] ? -1 : 1;
			}
			$a_avg_pos = array_sum($all_stops_info[$a]['positions']) / count($all_stops_info[$a]['positions']);
			$b_avg_pos = array_sum($all_stops_info[$b]['positions']) / count($all_stops_info[$b]['positions']);
			if (abs($a_avg_pos - $b_avg_pos) > 0.001) {
				return $a_avg_pos < $b_avg_pos ? -1 : 1;
			}
			if ($all_stops_info[$a]['pattern_count'] !== $all_stops_info[$b]['pattern_count']) {
				return $all_stops_info[$b]['pattern_count'] - $all_stops_info[$a]['pattern_count'];
			}
			return strcmp($all_stops_info[$a]['stop']['stop_name'], $all_stops_info[$b]['stop']['stop_name']);
		};

		while (!empty($available)) {
			usort($available, $compare_available);
			$ref = array_shift($available);
			$sorted_refs[] = $ref;
			foreach ($graph[$ref] as $next) {
				$indegree[$next]--;
				if ($indegree[$next] === 0) {
					$available[] = $next;
				}
			}
		}

		if (count($sorted_refs) !== count($all_stops_info)) {
			$sorted_refs = array_keys($all_stops_info);
			usort($sorted_refs, $compare_available);
		}

		$final_stops = array();
		foreach ($sorted_refs as $ref) {
			$stop = $all_stops_info[$ref]['stop'];
			$final_stops[] = array(
				'scheduled_stop_point_ref' => $ref,
				'offset_seconds' => $stop['offset_seconds'],
				'stop_name' => $stop['stop_name'],
				'user_stop_code' => $stop['user_stop_code'],
				'stop_area_ref' => isset($stop['stop_area_ref']) ? $stop['stop_area_ref'] : '',
				'quay_code' => $stop['quay_code'],
				'stopplace_code' => isset($stop['stopplace_code']) ? $stop['stopplace_code'] : ''
			);
		}

		error_log('get_pattern_stops: primary_pattern=' . $primary_pattern_ref . ', all_patterns=' . implode(',', array_keys($route_groups)) . ', final_stops_count=' . count($final_stops));
		return $final_stops;
	}

	private static function get_stop_page_links() {
		global $wpdb;
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		$cache = array('stopplace' => array(), 'user_stop' => array(), 'quay' => array());
		$posts = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%[ov_halte%'", ARRAY_A);
		foreach ($posts as $post) {
			$url = get_permalink((int) $post['ID']);
			if (!$url) {
				continue;
			}
			if (preg_match_all('/\b(stopplace|stopplaces|user_stop|user_stops|quay|quays)\s*=\s*"([^"]+)"/i', $post['post_content'], $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$codes = preg_split('/[\s,;|]+/', $match[2]);
					foreach ($codes as $code) {
						$code = trim($code);
						if ($code === '') {
							continue;
						}
						if (stripos($match[1], 'stopplace') === 0) {
							$cache['stopplace'][self::normalize_stopplace_code($code)] = $url;
						} elseif (stripos($match[1], 'user_stop') === 0) {
							$cache['user_stop'][$code] = $url;
						} else {
							$cache['quay'][self::normalize_quay_code($code)] = $url;
						}
					}
				}
			}
		}
		return $cache;
	}

	private static function render_stop_name(array $stop, array $link_map) {
		$name = $stop['stop_name'] !== '' ? $stop['stop_name'] : $stop['scheduled_stop_point_ref'];
		$url = '';
		$stopplace_code = !empty($stop['stopplace_code']) ? self::normalize_stopplace_code($stop['stopplace_code']) : '';
		$stop_area_ref = !empty($stop['stop_area_ref']) ? self::normalize_stopplace_code($stop['stop_area_ref']) : '';
		if ($stopplace_code !== '' && isset($link_map['stopplace'][$stopplace_code])) {
			$url = $link_map['stopplace'][$stopplace_code];
		} elseif ($stop_area_ref !== '' && isset($link_map['stopplace'][$stop_area_ref])) {
			$url = $link_map['stopplace'][$stop_area_ref];
		} elseif (!empty($stop['user_stop_code']) && isset($link_map['user_stop'][$stop['user_stop_code']])) {
			$url = $link_map['user_stop'][$stop['user_stop_code']];
		} elseif (!empty($stop['quay_code']) && isset($link_map['quay'][$stop['quay_code']])) {
			$url = $link_map['quay'][$stop['quay_code']];
		}
		if ($url) {
			return '<a href="' . esc_url($url) . '">' . esc_html($name) . '</a>';
		}
		return esc_html($name);
	}

	private static function render_footnotes(array $footnote_map) {
		if (empty($footnote_map)) {
			return '';
		}

		$output = '<div class="ovld-footnotes">';
		foreach ($footnote_map as $marker => $footnote) {
			$output .= '<div><strong>' . esc_html($marker) . '.</strong> ' . esc_html($footnote) . '</div>';
		}
		$output .= '</div>';

		return $output;
	}

	private static function map_footnotes(array $journeys) {
		$footnote_map = array();
		foreach ($journeys as $index => $journey) {
			$footnote = isset($journey['footnote']) ? trim((string) $journey['footnote']) : '';
			if ($footnote === '') {
				continue;
			}
			if (!isset($footnote_map[$footnote])) {
				$footnote_map[$footnote] = self::footnote_marker(count($footnote_map));
			}
			$journeys[$index]['footnote_letter'] = $footnote_map[$footnote];
		}
		return array($journeys, array_flip($footnote_map));
	}

	private static function footnote_marker($index) {
		$letter = '';
		while ($index >= 0) {
			$letter = chr(97 + ($index % 26)) . $letter;
			$index = intdiv($index, 26) - 1;
		}
		return $letter;
	}

	private static function clean_route_stop_name($name) {
		$name = trim((string) $name);
		$name = preg_replace('/\s*\(\s*Perron\b[^)]*\)\s*$/i', '', $name);
		$name = preg_replace('/\s*,\s*uitstaphalte\s*$/i', '', (string) $name);
		$name = preg_replace('/\s*,\s*Hoofdstation uitstaphalte ZZ\s*$/i', ', Hoofdstation', (string) $name);
		$name = preg_replace('/\s*,\s*Hoofdstation uitstaphalte NZ\s*$/i', ', Hoofdstation', (string) $name);
		$name = preg_replace('/\s*,\s*Station uitstaphalte\s*$/i', ', Station', (string) $name);
		return trim((string) $name);
	}

	private static function route_label(array $stops, $primary_pattern_ref = null, $pattern_refs = array()) {
			if (empty($stops)) {
				return 'Route';
			}

			if ($primary_pattern_ref && !empty($pattern_refs)) {
				global $wpdb;
				$placeholders = implode(',', array_fill(0, count($pattern_refs), '%s'));
				$query = "SELECT service_journey_pattern_ref, scheduled_stop_point_ref, stop_order FROM " . self::table('stop_offsets') . " WHERE service_journey_pattern_ref IN ($placeholders) ORDER BY service_journey_pattern_ref, stop_order";
				$pattern_stops = $wpdb->get_results(
					$wpdb->prepare($query, $pattern_refs),
					ARRAY_A
				);

				$primary_first = null;
				$primary_last = null;
				foreach ($pattern_stops as $stop) {
					if ($stop['service_journey_pattern_ref'] === $primary_pattern_ref) {
						if ($primary_first === null || $stop['stop_order'] == 0) {
							$primary_first = $stop['scheduled_stop_point_ref'];
						}
						$primary_last = $stop['scheduled_stop_point_ref'];
					}
				}

				if ($primary_first || $primary_last) {
					$from = '';
					$to = '';
					foreach ($stops as $stop) {
						if ($stop['scheduled_stop_point_ref'] === $primary_first && !empty($stop['stop_name'])) {
							$from = $stop['stop_name'];
						}
						if ($stop['scheduled_stop_point_ref'] === $primary_last && !empty($stop['stop_name'])) {
							$to = $stop['stop_name'];
						}
					}
					if ($from && $to) {
						$from = self::clean_route_stop_name($from);
						$to = self::clean_route_stop_name($to);
						return 'van ' . $from . ' naar ' . $to;
					}
				}
			}

		$first = reset($stops);
		$last = end($stops);
		$from = $first && !empty($first['stop_name']) ? $first['stop_name'] : '';
		$to = $last && !empty($last['stop_name']) ? $last['stop_name'] : '';
		if ($from && $to) {
			$from = self::clean_route_stop_name($from);
			$to = self::clean_route_stop_name($to);
			return 'van ' . $from . ' naar ' . $to;
		}
		return 'Route';
	}

	private static function variant_label(array $dates) {
		sort($dates, SORT_STRING);
		$days = array();
		foreach ($dates as $date) {
			$days[(int) gmdate('N', strtotime($date))] = true;
		}
		$day_keys = array_keys($days);
		sort($day_keys, SORT_NUMERIC);
		if ($day_keys === array(1, 2, 3, 4, 5)) {
			$label = 'Maandag t/m vrijdag';
		} elseif ($day_keys === array(6)) {
			$label = 'Zaterdag';
		} elseif ($day_keys === array(7)) {
			$label = 'Zondag';
		} else {
			$names = array(1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag', 5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag');
			$parts = array();
			foreach ($day_keys as $day) {
				$parts[] = $names[$day];
			}
			$label = ucfirst(implode(', ', $parts));
		}
		return $label;
	}

	private static function variant_group_label($group, array $dates) {
		sort($dates, SORT_STRING);
		if ($group === 'weekday') {
			$label = 'Maandag t/m vrijdag';
		} elseif ($group === 'saturday') {
			$label = 'Zaterdag';
		} elseif ($group === 'sunday') {
			$label = 'Zondag';
		} elseif (strpos($group, 'holiday:') === 0) {
			$label = substr($group, 8);
		} else {
			return self::variant_label($dates);
		}
		return $label;
	}

	private static function holiday_name($date) {
		$timestamp = strtotime($date . ' 00:00:00');
		if (!$timestamp || !function_exists('easter_date')) {
			return '';
		}

		$year = (int) gmdate('Y', $timestamp);
		$service_date = gmdate('Y-m-d', $timestamp);
		$fixed = array(
			$year . '-01-01' => 'Nieuwjaarsdag',
			$year . '-04-27' => 'Koningsdag',
			$year . '-05-05' => 'Bevrijdingsdag',
			$year . '-12-25' => 'Eerste kerstdag',
			$year . '-12-26' => 'Tweede kerstdag',
		);

		$kings_day = new DateTimeImmutable($year . '-04-27');
		if ($kings_day->format('N') === '7') {
			$fixed[$year . '-04-26'] = 'Koningsdag';
		}

		$easter = new DateTimeImmutable('@' . easter_date($year));
		$easter = $easter->setTimezone(wp_timezone());
		$movable = array(
			$easter->format('Y-m-d') => 'Eerste paasdag',
			$easter->modify('+1 day')->format('Y-m-d') => 'Tweede paasdag',
			$easter->modify('+39 days')->format('Y-m-d') => 'Hemelvaartsdag',
			$easter->modify('+49 days')->format('Y-m-d') => 'Eerste pinksterdag',
			$easter->modify('+50 days')->format('Y-m-d') => 'Tweede pinksterdag',
		);

		if (isset($fixed[$service_date])) {
			return $fixed[$service_date];
		}
		if (isset($movable[$service_date])) {
			return $movable[$service_date];
		}

		return '';
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

	private static function service_day_order_seconds($seconds) {
		$seconds = (int) $seconds;
		if ($seconds >= 0 && $seconds < self::SERVICE_DAY_START_SECONDS) {
			return $seconds + DAY_IN_SECONDS;
		}
		return $seconds;
	}

	/**
	 * Convert a service-day seconds value into a UNIX timestamp for the given service_date
	 * taking into account the service-day boundary (e.g. times before 05:00 belong to the next calendar day).
	 */
	private static function timestamp_from_service_seconds($service_date, $seconds) {
		$timezone = wp_timezone();
		$midnight = new DateTimeImmutable($service_date . ' 00:00:00', $timezone);
		$dt = $midnight->modify('+' . (int) $seconds . ' seconds');
		if ((int) $seconds < self::SERVICE_DAY_START_SECONDS) {
			$dt = $dt->modify('+1 day');
		}
		return $dt->getTimestamp();
	}

	private static function format_seconds($seconds) {
		$seconds = (int) $seconds;
		$seconds = $seconds % DAY_IN_SECONDS;
		if ($seconds < 0) {
			$seconds += DAY_IN_SECONDS;
		}
		return sprintf('%02d:%02d', floor($seconds / HOUR_IN_SECONDS), floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS));
	}

	private static function normalize_quay_code($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		if (strpos($value, 'NL:Q:') === 0) {
			return $value;
		}
		if (preg_match('/^\d+$/', $value)) {
			return 'NL:Q:' . $value;
		}
		return $value;
	}

	private static function normalize_stopplace_code($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		if (strpos($value, 'NL:S:') === 0) {
			return $value;
		}
		if (preg_match('/^CHB:StopPlace:(.+)$/', $value, $matches)) {
			return 'NL:S:' . $matches[1];
		}
		if (preg_match('/^\d+$/', $value)) {
			return 'NL:S:' . $value;
		}
		return $value;
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
			'.ovld-wrapper{max-width:100%;overflow:hidden;color:#861121;}
			.ovld-form{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0 0 24px;}
			.ovld-form label{display:flex;flex-direction:column;gap:5px;font-family:circularstd-bold,sans-serif;font-size:14px;color:#861121;}
			.ovld-form select{min-width:220px;max-width:100%;padding:9px 10px;border:1px solid rgba(134,17,33,.25);border-radius:8px;background:#fff;color:#861121;}
			.ovld-form button{padding:10px 18px;border:0;border-radius:999px;background:#861121;color:#fff;font-family:circularstd-bold,sans-serif;cursor:pointer;}
			.ovld-quick-switch{display:flex;gap:7px;align-items:center;margin:0 0 2px;}
			.ovld-quick-date{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 13px;border-radius:999px;background:rgba(134,17,33,.08);color:#861121;font-family:circularstd-bold,sans-serif;font-size:13px;line-height:1;text-decoration:none;border:1px solid rgba(134,17,33,.16);}
			.ovld-quick-date.is-active{background:#861121;color:#fff;}
			.ovld-quick-date.is-disabled{opacity:.38;cursor:not-allowed;text-decoration:none;}
			.ovld-line-heading{display:flex;gap:12px;align-items:center;margin:0 0 18px;}
			.ovld-meta-container{margin-left:auto;display:flex;flex-direction:column;align-items:flex-end;gap:6px;}
			.ovld-operator-logo{max-width:40px;max-height:40px;width:auto;height:auto;object-fit:contain;}
			.ovld-print{padding:0 14px;height:38px;border:0;border-radius:999px;background:#fff;color:#861121;font-family:circularstd-bold,sans-serif;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(0,0,0,.08);border:1px solid rgba(134,17,33,.15);}.ovld-print:hover{background:rgba(134,17,33,.05);}
			.ovld-badge{width:38px;height:38px;min-width:38px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-family:circularstd-bold,sans-serif;font-size:18px;line-height:1;}
			.ovld-route{font-family:circularstd-bold,sans-serif;font-size:18px;line-height:1.25;color:#861121;}
			.ovld-variant{font-family:circularstd-bold,sans-serif;font-size:13px;line-height:1.35;color:#861121;margin-top:2px;}
			.ovld-valid-dates{font-family:circularstd-bold,sans-serif;font-size:11px;line-height:1.35;color:#861121;margin-top:2px;}
			.ovld-table-shell{display:flex;align-items:center;gap:10px;max-width:100%;overflow:hidden;padding:0;}
			.ovld-table-wrap{flex:1 1 auto;min-width:0;overflow-x:auto;overflow-y:visible;padding-bottom:8px;scroll-behavior:smooth;overscroll-behavior-x:contain;}
			.ovld-table-wrap::-webkit-scrollbar{height:8px;}
			.ovld-table-wrap::-webkit-scrollbar-thumb{background:rgba(134,17,33,.35);border-radius:999px;}
			.ovld-grid{display:grid;grid-template-columns:minmax(190px,1.3fr) repeat(var(--ovld-columns),minmax(58px,.45fr));gap:0;min-width:max-content;}
			.ovld-stop,.ovld-time{padding:7px 10px;border-bottom:1px solid rgba(134,17,33,.13);font-size:14px;line-height:1.25;color:inherit;white-space:nowrap;}
			.ovld-stop{position:sticky;left:0;background:#fff;z-index:1;box-shadow:2px 0 4px rgba(0,0,0,.1);}
			.ovld-stop{font-family:inherit;font-weight:700;}
			.ovld-time{font-family:inherit;font-weight:400;}
			.ovld-time{text-align:center;}
			.ovld-time.ovld-current-trip,.ovld-head.ovld-current-trip{background:rgba(134,17,33,.075);}
			.ovld-head{position:sticky;top:0;background:#fff;font-size:13px;color:#5d0e18;font-family:inherit;font-weight:700;}
			.ovld-head.ovld-current-trip{background:rgba(134,17,33,.075);}
			.ovld-stop a{color:#861121;text-decoration:underline;text-underline-offset:2px;}
			.ovld-footnotes{margin:12px 0 0;font-size:12px;line-height:1.35;color:inherit;opacity:.8;}
			.ovld-footnote-marker{font-size:10px;line-height:1;vertical-align:super;margin-left:3px;}
			.ovld-mobile-cards{display:none;}
			.ovld-mobile-card{border:1px solid rgba(134,17,33,.14);border-radius:14px;background:#fff;box-shadow:0 5px 18px rgba(0,0,0,.06);padding:11px 12px;margin:0 0 12px;cursor:pointer;}
			.ovld-mobile-card:focus{outline:2px solid rgba(134,17,33,.35);outline-offset:2px;}
			.ovld-mobile-card.ovld-current-trip{border-color:rgba(134,17,33,.38);background:rgba(134,17,33,.045);}
			.ovld-mobile-card-head{display:flex;align-items:center;gap:9px;margin:0 0 8px;font-family:circularstd-bold,sans-serif;font-size:14px;color:#861121;}
			.ovld-mobile-card-trip{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
			.ovld-mobile-card-action{margin-left:auto;font-size:11px;line-height:1;color:#861121;opacity:.72;}
			.ovld-mobile-badge{width:30px;height:30px;min-width:30px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-family:circularstd-bold,sans-serif;font-size:13px;line-height:1;}
			.ovld-mobile-stop-row{display:flex;justify-content:space-between;gap:12px;border-top:1px solid rgba(134,17,33,.1);padding:7px 0;font-size:13px;line-height:1.25;color:#861121;}
			.ovld-mobile-stop-row:first-child{border-top:0;}
			.ovld-mobile-stop-row strong{font-family:circularstd-bold,sans-serif;font-size:13px;white-space:nowrap;}
			.ovld-mobile-detail{display:none;}
			.ovld-modal{position:fixed;inset:0;z-index:99999;display:none;align-items:flex-end;justify-content:center;background:rgba(20,12,14,.52);padding:18px;box-sizing:border-box;}
			.ovld-modal.is-open{display:flex;}
			.ovld-modal-panel{width:100%;max-width:540px;max-height:84vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 18px 60px rgba(0,0,0,.28);padding:16px;color:#861121;box-sizing:border-box;}
			.ovld-modal-head{display:flex;align-items:center;gap:12px;margin:0 0 10px;}
			.ovld-modal-title{font-family:circularstd-bold,sans-serif;font-size:16px;line-height:1.25;color:#861121;margin-right:auto;}
			.ovld-modal-close{width:34px;height:34px;border:0;border-radius:999px;background:#861121;color:#fff;font-size:22px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}
			.ovld-mobile-detail-title{font-family:circularstd-bold,sans-serif;font-size:15px;line-height:1.3;color:#861121;margin:0 0 8px;}
			.ovld-mobile-detail-notice{font-size:12px;opacity:.88;color:#b45309;margin:4px 0 10px;padding:6px 10px;background:rgba(180,83,9,.06);border-radius:6px;border-left:3px solid #b45309;white-space:normal;}
			.ovld-mobile-detail-row{display:flex;justify-content:space-between;gap:14px;border-top:1px solid rgba(134,17,33,.11);padding:8px 0;font-size:13px;line-height:1.25;color:#861121;}
			.ovld-mobile-detail-row strong{font-family:circularstd-bold,sans-serif;white-space:nowrap;}
			.ovld-scroll{position:relative;z-index:2;width:30px;height:44px;border:0;border-radius:999px;background:#861121;color:#fff;font-size:28px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 5px 16px rgba(0,0,0,.14);}
			.ovld-scroll-left,.ovld-scroll-right{left:auto;right:auto;}
			.ovld-print-schedule { display: none; }
			@media print {
				@page { size: landscape; margin: 8mm; }
				html { margin: 0 !important; }
				body.ovld-printing-active > :not(.ovld-print-schedule) {
					display: none !important;
				}
				body.ovld-printing-active {
					margin: 0 !important;
					padding: 0 !important;
					background: #fff !important;
				}
				.ovld-schedule { display: none !important; }
				.ovld-print-schedule {
					display: block !important;
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
					-webkit-print-color-adjust: exact !important;
					print-color-adjust: exact !important;
				}
				.ovld-print-chunk {
					page-break-inside: avoid;
					break-inside: avoid;
					margin-bottom: 16px !important;
				}
				.ovld-print-grid {
					display: grid !important;
					min-width: max-content !important;
					width: max-content !important;
					grid-template-columns: minmax(150px, 1.4fr) repeat(var(--ovld-columns), minmax(48px, 1fr)) !important;
					border: 1px solid #cbd5e1 !important;
					border-radius: 4px !important;
					overflow: visible !important;
				}
				.ovld-print-grid .ovld-stop {
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
				.ovld-print-grid .ovld-time {
					text-align: center !important;
					white-space: nowrap !important;
					font-size: 10px !important;
					padding: 5px 8px !important;
				}
				.ovld-print-grid .ovld-stop, .ovld-print-grid .ovld-time {
					border-bottom: 1px solid #e2e8f0 !important;
					color: #000 !important;
					line-height: 1.1 !important;
				}
				.ovld-print-grid .ovld-head {
					position: static !important;
					background: #f1f5f9 !important;
					color: #1e293b !important;
					font-weight: bold !important;
					border-bottom: 2px solid #cbd5e1 !important;
				}
				.ovld-print-grid .ovld-row-even {
					background: #f8fafc !important;
				}
				.ovld-print-grid .ovld-row-odd {
					background: #ffffff !important;
				}
				.ovld-footnotes {
					margin-top: 12px !important;
					font-size: 8px !important;
					color: #475569 !important;
					line-height: 1.3 !important;
				}
				.ovld-line-heading {
					display: flex !important;
					margin: 0 0 12px !important;
					align-items: center !important;
					border-bottom: 2px solid #861121 !important;
					padding-bottom: 8px !important;
				}
				.ovld-badge {
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
				.ovld-route {
					font-size: 16px !important;
					font-weight: bold !important;
					color: #861121 !important;
				}
				.ovld-variant, .ovld-valid-dates {
					font-size: 10px !important;
					margin-top: 1px !important;
					color: #555 !important;
				}
			}
			.ovld-scroll[disabled]{opacity:.25;cursor:default;}
			@media (max-width:640px){.ovld-form{display:block}.ovld-form label{margin:0 0 10px}.ovld-form select{width:100%}.ovld-form button{width:100%}.ovld-line-heading{align-items:flex-start}.ovld-table-shell{padding:0}.ovld-scroll{width:26px;height:40px;font-size:24px}}
			@media (orientation:portrait){.ovld-table-shell{display:none}.ovld-mobile-cards{display:block}.ovld-mobile-cards::before{content:"Compacte mobiele weergave met begin/eindhalte en belangrijke haltes";display:block;margin:0 0 8px;font-size:12px;line-height:1.35;color:#861121;opacity:.75}.ovld-line-heading{gap:10px;margin-bottom:14px}.ovld-print{display:none}.ovld-quick-switch{margin:2px 0 12px}.ovld-quick-date{min-height:32px;padding:7px 11px}}'
		);

		wp_register_script('ovld-frontend', false, array(), self::VERSION, true);
		wp_enqueue_script('ovld-frontend');
		wp_add_inline_script(
			'ovld-frontend',
			"(function(){
				(function(){
					var originalParent=null;
					var originalSibling=null;
					var schedule=null;
					window.addEventListener('beforeprint',function(){
						schedule=document.querySelector('.ovld-print-schedule');
						if(!schedule){return;}
						originalParent=schedule.parentNode;
						originalSibling=schedule.nextSibling;
						document.body.classList.add('ovld-printing-active');
						document.body.appendChild(schedule);
					});
					window.addEventListener('afterprint',function(){
						if(!schedule||!originalParent){return;}
						if(originalSibling){
							originalParent.insertBefore(schedule,originalSibling);
						}else{
							originalParent.appendChild(schedule);
						}
						document.body.classList.remove('ovld-printing-active');
					});
				})();

				function clearCurrentHighlights(shell){
					shell.querySelectorAll('.ovld-current-trip').forEach(function(el){el.classList.remove('ovld-current-trip');});
					var schedule=shell.closest('.ovld-schedule');
					if(schedule){
						schedule.querySelectorAll('.ovld-mobile-card.ovld-current-trip').forEach(function(el){el.classList.remove('ovld-current-trip');});
					}
				}
				function highlightCurrentTrip(shell,target,departureCells){
					clearCurrentHighlights(shell);
					var column=Array.prototype.indexOf.call(departureCells,target);
					if(column<0){return;}
					var allTimes=shell.querySelectorAll('.ovld-grid > .ovld-time');
					for(var i=column;i<allTimes.length;i+=departureCells.length){
						allTimes[i].classList.add('ovld-current-trip');
					}
					var dep=target.getAttribute('data-ovld-departure');
					var schedule=shell.closest('.ovld-schedule');
					if(schedule&&dep){
						var card=schedule.querySelector('.ovld-mobile-card[data-ovld-departure-card=\"'+dep+'\"]');
						if(card){card.classList.add('ovld-current-trip');}
					}
				}
				function scrollToCurrentTime(shell){
					var current = parseInt(shell.dataset.ovldCurrentTime,10);
					if(isNaN(current)||current<0){return;}
					var wrap=shell.querySelector('.ovld-table-wrap');
					var departureCells=shell.querySelectorAll('.ovld-grid > .ovld-time[data-ovld-departure]');
					var sticky=shell.querySelector('.ovld-grid > .ovld-stop');
					if(!wrap||!departureCells.length||!sticky){return;}
					var stickyStyle=window.getComputedStyle(sticky);
					var stickyWidth=stickyStyle.position==='sticky'?sticky.getBoundingClientRect().width:0;
					var target=departureCells[departureCells.length-1];
					for(var i=0;i<departureCells.length;i++){
						var dep=parseInt(departureCells[i].dataset.ovldDeparture,10);
						if(!isNaN(dep)&&dep>=current){target=departureCells[i];break;}
					}
					highlightCurrentTrip(shell,target,departureCells);
					if(window.matchMedia&&window.matchMedia('(orientation: portrait)').matches){return;}
					var visibleWidth=wrap.clientWidth-stickyWidth;
					var targetOffset=target.offsetLeft-stickyWidth-(visibleWidth*.42)+(target.getBoundingClientRect().width/2);
					if(targetOffset<0){targetOffset=0;}
					wrap.scrollTo({left:targetOffset,behavior:'smooth'});
				}
				function update(shell){
					var wrap=shell.querySelector('.ovld-table-wrap');
					var left=shell.querySelector('.ovld-scroll-left');
					var right=shell.querySelector('.ovld-scroll-right');
					if(!wrap||!left||!right){return;}
					var max=wrap.scrollWidth-wrap.clientWidth;
					var hasOverflow=max>2;
					left.style.display=hasOverflow?'flex':'none';
					right.style.display=hasOverflow?'flex':'none';
					left.disabled=!hasOverflow||wrap.scrollLeft<=2;
					right.disabled=!hasOverflow||wrap.scrollLeft>=max-2;
				}
				function closeDetailModal(){
					var modal=document.querySelector('.ovld-modal');
					if(!modal){return;}
					modal.classList.remove('is-open');
					var content=modal.querySelector('.ovld-modal-content');
					if(content){content.innerHTML='';}
				}
				function getDetailModal(){
					var modal=document.querySelector('.ovld-modal');
					if(modal){return modal;}
					modal=document.createElement('div');
					modal.className='ovld-modal';
					modal.setAttribute('role','dialog');
					modal.setAttribute('aria-modal','true');
					modal.setAttribute('aria-label','Volledige rit');
					var panel=document.createElement('div');
					panel.className='ovld-modal-panel';
					var head=document.createElement('div');
					head.className='ovld-modal-head';
					var title=document.createElement('div');
					title.className='ovld-modal-title';
					title.textContent='Volledige rit';
					var close=document.createElement('button');
					close.type='button';
					close.className='ovld-modal-close';
					close.setAttribute('aria-label','Sluit venster');
					close.textContent='x';
					var content=document.createElement('div');
					content.className='ovld-modal-content';
					head.appendChild(title);
					head.appendChild(close);
					panel.appendChild(head);
					panel.appendChild(content);
					modal.appendChild(panel);
					document.body.appendChild(modal);
					close.addEventListener('click',closeDetailModal);
					modal.addEventListener('click',function(event){
						if(event.target===modal){closeDetailModal();}
					});
					return modal;
				}
				function openMobileDetail(card){
					var detail=card.querySelector('.ovld-mobile-detail');
					if(!detail){return;}
					var modal=getDetailModal();
					var content=modal.querySelector('.ovld-modal-content');
					if(!content){return;}
					content.innerHTML=detail.innerHTML;
					modal.classList.add('is-open');
					var close=modal.querySelector('.ovld-modal-close');
					if(close){close.focus();}
				}
				function initMobileCards(root){
					if(!root){return;}
					root.querySelectorAll('.ovld-mobile-card').forEach(function(card){
						if(card.dataset.ovldDetailReady==='1'){return;}
						card.dataset.ovldDetailReady='1';
						card.addEventListener('click',function(){openMobileDetail(card);});
						card.addEventListener('keydown',function(event){
							if(event.key==='Enter'||event.key===' '){
								event.preventDefault();
								openMobileDetail(card);
							}
						});
					});
				}
				document.addEventListener('keydown',function(event){
					if(event.key==='Escape'){closeDetailModal();}
				});
				function init(shell){
					var wrap=shell.querySelector('.ovld-table-wrap');
					if(!wrap){return;}
					initMobileCards(shell.closest('.ovld-schedule'));
					shell.querySelectorAll('[data-ovld-scroll]').forEach(function(button){
						button.addEventListener('click',function(){
							var direction=parseInt(button.getAttribute('data-ovld-scroll'),10)||1;
							wrap.scrollBy({left:direction*Math.max(220,Math.floor(wrap.clientWidth*.75)),behavior:'smooth'});
						});
					});
					wrap.addEventListener('scroll',function(){update(shell);});
					window.addEventListener('resize',function(){update(shell);});
					update(shell);
					scrollToCurrentTime(shell);
					setTimeout(function(){update(shell);scrollToCurrentTime(shell);},250);
				}
				document.querySelectorAll('.ovld-table-shell').forEach(init);
			})();"
		);
	}
}

OV_Lijn_Dienstregeling::init();
