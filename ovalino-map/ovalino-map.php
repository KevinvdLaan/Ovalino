<?php
/**
 * Plugin Name: Ovalino Map
 * Description: Interactieve kaart met alle haltes en treinstations voor Ovalino.
 * Version: 1.2.0
 * Author: Kevin van der Laan
 * License: GPL-2.0-or-later
 * Requires Plugins: ov-halte-importer, ov-trein-dienstregeling
 */

if (!defined('ABSPATH')) {
	exit;
}

class Ovalino_Map {
	const VERSION = '1.2.0';
	const FRONTEND_STYLE = 'ovalino-map-style';
	const FRONTEND_SCRIPT = 'ovalino-map-script';

	public static function init() {
		add_shortcode('ov_reisplanner_map', array(__CLASS__, 'render_map_shortcode'));
		add_action('wp_ajax_ovrp_get_stops', array(__CLASS__, 'ajax_get_stops'));
		add_action('wp_ajax_nopriv_ovrp_get_stops', array(__CLASS__, 'ajax_get_stops'));
	}

	private static function table($prefix, $suffix) {
		global $wpdb;
		return $wpdb->prefix . $prefix . '_' . $suffix;
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
		if (preg_match('/^\d+$/', $value)) {
			return 'NL:S:' . $value;
		}
		return $value;
	}

	private static function get_stop_page_links() {
		global $wpdb;
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		$cache = array('stopplace' => array(), 'user_stop' => array(), 'quay' => array(), 'station' => array());
		$posts = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%[ov_halte%'", ARRAY_A);
		foreach ($posts as $post) {
			$url = get_permalink((int) $post['ID']);
			if (!$url) {
				continue;
			}
			if (preg_match_all('/\b(stopplace|stopplaces|user_stop|user_stops|quay|quays|station|stations)\s*=\s*"([^"]+)"/i', $post['post_content'], $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$codes = preg_split('/[\s,;|]+/', $match[2]);
					foreach ($codes as $code) {
						$code = trim($code);
						if ($code === '') {
							continue;
						}
						$lower_match = strtolower($match[1]);
						if (strpos($lower_match, 'stopplace') === 0) {
							$cache['stopplace'][self::normalize_stopplace_code($code)] = $url;
						} elseif (strpos($lower_match, 'user_stop') === 0) {
							$cache['user_stop'][$code] = $url;
						} elseif (strpos($lower_match, 'quay') === 0) {
							$cache['quay'][self::normalize_quay_code($code)] = $url;
						} elseif (strpos($lower_match, 'station') === 0) {
							$cache['station'][strtolower($code)] = $url;
						}
					}
				}
			}
		}
		return $cache;
	}

	private static function get_page_url_by_shortcode($shortcode) {
		global $wpdb;
		$post_id = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE %s LIMIT 1",
			'%' . $shortcode . '%'
		));
		if ($post_id) {
			return get_permalink((int) $post_id);
		}
		return '';
	}

	private static function get_line_schedule_page_url() {
		$url = self::get_page_url_by_shortcode('[ov_lijn_dienstregeling]');
		return $url ?: home_url('/dienstregeling/');
	}

	private static function get_train_schedule_page_url() {
		$url = self::get_page_url_by_shortcode('[ov_trein_dienstregeling]');
		return $url ?: home_url('/treindienstregeling/');
	}

	public static function render_map_shortcode($atts) {
		$atts = shortcode_atts(
			array(
				'center_lat' => '53.2197',
				'center_lon' => '6.5620',
				'zoom'       => '15',
				'height'     => '600px',
			),
			$atts,
			'ov_reisplanner_map'
		);

		self::enqueue_assets();

		$hidden_lines = get_option('ovld_hidden_lines', array());
		if (!is_array($hidden_lines)) {
			$hidden_lines = array();
		}

		$config = array(
			'centerLat'    => (float) $atts['center_lat'],
			'centerLon'    => (float) $atts['center_lon'],
			'defaultZoom'  => (int) $atts['zoom'],
			'ajaxUrl'      => admin_url('admin-ajax.php'),
			'scheduleBaseUrl' => home_url('/'),
			'lineScheduleUrl' => self::get_line_schedule_page_url(),
			'trainScheduleUrl' => self::get_train_schedule_page_url(),
			'hiddenLines'  => $hidden_lines,
		);

		$config_js = '<script type="text/javascript">window.ovrepMapConfig = ' . json_encode($config) . ';</script>';

		return '<div id="ovrp-map" style="height:' . esc_attr($atts['height']) . '; position: relative;"></div>' . $config_js;
	}

	public static function ajax_get_stops() {
		global $wpdb;

		$north = isset($_GET['north']) ? (float) $_GET['north'] : 0;
		$south = isset($_GET['south']) ? (float) $_GET['south'] : 0;
		$east  = isset($_GET['east'])  ? (float) $_GET['east']  : 0;
		$west  = isset($_GET['west'])  ? (float) $_GET['west']  : 0;
		$zoom  = isset($_GET['zoom'])  ? (int) $_GET['zoom']    : 9;
 
		$cache_key = 'ovmap_stops_v12_' . md5($north . $south . $east . $west . $zoom);
		$cached_results = get_transient($cache_key);
		if ($cached_results !== false) {
			wp_send_json_success($cached_results);
		}

		$stops = array();
		$all_quay_codes = array();
		
		$hidden_lines = get_option('ovld_hidden_lines', array());
		if (!is_array($hidden_lines)) {
			$hidden_lines = array();
		}

		$now = time();
		$two_hours_later = $now + (2 * HOUR_IN_SECONDS);

		// Bepaal alle unieke OV-service dagen en kalenderdagen binnen het geplande 2-uurs venster
		$timezone = wp_timezone();
		$dt_now = new DateTimeImmutable('@' . $now);
		$dt_now = $dt_now->setTimezone($timezone);
		$dt_later = new DateTimeImmutable('@' . $two_hours_later);
		$dt_later = $dt_later->setTimezone($timezone);

		$service_dates = array_unique(array(
			self::get_service_date_for_timestamp($now),
			self::get_service_date_for_timestamp($two_hours_later),
			$dt_now->format('Y-m-d'),
			$dt_later->format('Y-m-d')
		));

		// Behoud de huidige actuele OV-dag uitsluitend voor de paginalinks
		$current_service_date = self::get_current_service_date_string();
		$link_map = self::get_stop_page_links();

		// 1. Bus Stops
		if ($wpdb->get_var("SHOW TABLES LIKE '" . self::table('ovhi', 'stopplaces') . "'")) {
			$bus_sql = "
				SELECT sp.stopplace_code, sp.stopplace_name, q.latitude, q.longitude, q.quay_code, q.quay_name,
				       GROUP_CONCAT(DISTINCT CONCAT(IFNULL(l.public_code, sl.line_ref), '|', IFNULL(l.colour,''), '|', IFNULL(l.text_colour,''), '|', sl.line_ref, '|', IFNULL(sl.direction_type, 'outbound')) SEPARATOR '###') as lines_data,
				       MAX(ss.user_stop_code) as user_stop_code,
				       MAX(ss.stop_name) as stop_name
				FROM " . self::table('ovhi', 'stopplaces') . " sp
				INNER JOIN " . self::table('ovhi', 'quays') . " q ON q.stopplace_code = sp.stopplace_code
				LEFT JOIN " . self::table('ovhi', 'stop_lines') . " sl ON sl.quay_code = q.quay_code
				LEFT JOIN " . self::table('ovhi', 'lines') . " l ON l.line_ref = sl.line_ref
				LEFT JOIN " . self::table('ovhi', 'assignments') . " ass ON ass.quay_code = q.quay_code
				LEFT JOIN " . self::table('ovhi', 'scheduled_stops') . " ss ON ss.scheduled_stop_point_ref = ass.scheduled_stop_point_ref
				WHERE q.latitude BETWEEN %f AND %f
				AND q.longitude BETWEEN %f AND %f
				AND q.latitude != 0 AND q.longitude != 0
				GROUP BY q.quay_code
			";
			$bus_results = $wpdb->get_results($wpdb->prepare($bus_sql, $south, $north, $west, $east), ARRAY_A);

			foreach ($bus_results as $row) {
				$lines = array();
				if (!empty($row['lines_data'])) {
					$parts = explode('###', $row['lines_data']);
					foreach ($parts as $part) {
						$l = explode('|', $part, 5);
						if (count($l) >= 4 && trim($l[0]) !== '') {
							$line_name = trim($l[0]);
							if (strpos($line_name, ':') !== false) {
								$parts_ref = explode(':', $line_name);
								$line_name = end($parts_ref);
							}
							$lines[] = array(
								'name'       => $line_name,
								'colour'     => trim($l[1]) ?: '#861121',
								'textColour' => trim($l[2]) ?: '#ffffff',
								'lineRef'    => trim($l[3]),
								'direction'  => isset($l[4]) ? trim($l[4]) : 'outbound',
							);
						}
					}
				}

				$all_quay_codes[] = $row['quay_code'];
				
				$platform_label = '';
				$stop_name = !empty($row['stop_name']) ? trim($row['stop_name']) : '';
				
				if ($stop_name !== '' && strtolower($stop_name) !== strtolower($row['stopplace_name'])) {
					if (preg_match('/\(\s*Perron\s+([^)]+)\)\s*$/i', $stop_name, $matches)) {
						$platform_label = 'Perron: ' . trim($matches[1]);
					}
				}

				$stopplace_code = self::normalize_stopplace_code($row['stopplace_code']);
				$quay_code = self::normalize_quay_code($row['quay_code']);
				$user_stop_code = isset($row['user_stop_code']) ? trim($row['user_stop_code']) : '';
				
				$departures_url = '';
				if ($stopplace_code !== '' && isset($link_map['stopplace'][$stopplace_code])) {
					$departures_url = $link_map['stopplace'][$stopplace_code];
				} elseif ($quay_code !== '' && isset($link_map['quay'][$quay_code])) {
					$departures_url = $link_map['quay'][$quay_code];
				} elseif ($user_stop_code !== '' && isset($link_map['user_stop'][$user_stop_code])) {
					$departures_url = $link_map['user_stop'][$user_stop_code];
				}

				$stops[] = array(
					'type'  => 'bus',
					'lat'   => (float) $row['latitude'],
					'lon'   => (float) $row['longitude'],
					'name'  => $row['stopplace_name'],
					'code'  => $row['quay_code'],
					'lines' => $lines,
					'platform' => $platform_label,
					'departures' => array(),
					'departures_url' => $departures_url,
				);
			}
		}

		if ($zoom >= 13 && !empty($all_quay_codes)) {
			$departures_by_quay = self::get_batch_departures($all_quay_codes, $now, $two_hours_later, $service_dates);
			foreach ($stops as &$stop) {
				if ($stop['type'] === 'bus' && isset($departures_by_quay[$stop['code']])) {
					$stop['departures'] = $departures_by_quay[$stop['code']];
				}
			}
			unset($stop);
		}

		// 2. Train Stations
		if ($wpdb->get_var("SHOW TABLES LIKE '" . self::table('ovtd', 'stations') . "'")) {
			$stations_cache_key = 'ovmap_stations_wgs84_v2';
			$stations_wgs84 = get_transient($stations_cache_key);

			if ($stations_wgs84 === false) {
				$stations_wgs84 = array();
				$stations = $wpdb->get_results("SELECT station_code, station_name, x, y FROM " . self::table('ovtd', 'stations'), ARRAY_A);
				foreach ($stations as $s) {
					$coords = self::rd_to_wgs84($s['x'], $s['y']);
					$stations_wgs84[] = array(
						'code' => $s['station_code'],
						'name' => $s['station_name'],
						'lat'  => $coords['lat'],
						'lon'  => $coords['lon']
					);
				}
				set_transient($stations_cache_key, $stations_wgs84, DAY_IN_SECONDS);
			}

			$train_departures_by_station = array();
			if ($zoom >= 13) {
				$train_departures_by_station = self::get_batch_train_departures(array_column($stations_wgs84, 'code'), $now, $two_hours_later, $service_dates);
			}

			foreach ($stations_wgs84 as $s) {
				if ($s['lat'] >= $south && $s['lat'] <= $north && $s['lon'] >= $west && $s['lon'] <= $east) {
					$directions = self::get_train_directions($s['code']);
					if (empty($directions)) continue;

					// Bushaltes tonen alleen het lijnnummer in de bol, want dat
					// is al uniek genoeg. Treinen zonder eigen lijnnummer
					// (nu nog bijna alle NS-treinen) delen dezelfde korte
					// code (IC/Sto/Snl) voor meerdere bestemmingen — daarom
					// zetten we de bestemming in dat geval in de bol zelf
					// ("IC richting Veendam"), zodat het niet lijkt op één
					// echte lijn. Heeft de richting wél een lijncode (bv.
					// "RE1"), dan staat alleen die code in de bol, net als
					// bij bussen.
					$train_lines = array();
					foreach ($directions as $direction) {
						$badge = isset($direction['badge']) ? trim((string) $direction['badge']) : '';
						$line_code = isset($direction['line_code']) ? trim((string) $direction['line_code']) : '';
						$destination = isset($direction['label']) ? trim((string) $direction['label']) : '';

						if ($badge === '') {
							continue;
						}

						$pill_label = ($line_code !== '' || $destination === '')
							? $badge
							: $badge . ' richting ' . $destination;

						if (isset($train_lines[$pill_label])) {
							continue;
						}

						$train_lines[$pill_label] = array(
							'name'       => $pill_label,
							'colour'     => '#861121',
							'textColour' => '#ffffff',
							'lineRef'    => (string) $direction['ref'],
							'direction'  => 'outbound',
						);
					}

					$departures = isset($train_departures_by_station[$s['code']]) ? $train_departures_by_station[$s['code']] : array();
					$station_code = strtolower($s['code']);
					$departures_url = isset($link_map['station'][$station_code]) ? $link_map['station'][$station_code] : '';

					$stops[] = array(
						'type'  => 'train',
						'lat'   => $s['lat'],
						'lon'   => $s['lon'],
						'name'  => $s['name'],
						'code'  => $s['code'],
						'lines' => array_values($train_lines),
						'platform' => '',
						'departures' => $departures,
						'departures_url' => $departures_url,
					);
				}
			}
		}

		if (count($stops) > 5000) {
			$stops = array_slice($stops, 0, 5000);
		}

		set_transient($cache_key, $stops, 2 * MINUTE_IN_SECONDS);
		wp_send_json_success($stops);
	}

	private static function get_current_service_date_string() {
		// Fix: Gebruik een betrouwbare tijdzone-bewuste datumafhandeling via WordPress
		$current = current_datetime();
		$hours = (int) $current->format('G');
		
		// Indien vóór 04:00 uur 's nachts, valt de rit binnen de vorige OV-exploitatiedag
		if ($hours < 4) {
			return $current->modify('-1 day')->format('Y-m-d');
		}
		return $current->format('Y-m-d');
	}

	private static function get_platform_label($quay_code, $stopplace_name) {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT q.quay_name FROM " . self::table('ovhi', 'quays') . " q WHERE q.quay_code = %s",
			$quay_code
		));
		if ($row && !empty($row->quay_name)) {
			$name = trim($row->quay_name);
			if ($name === $stopplace_name) {
				return '';
			}
			if (preg_match('/\(Perron\s+([^)]+)\)/i', $name, $matches)) {
				return 'Perron: ' . $matches[1];
			}
			return 'Perron: ' . $name;
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

	private static function pick_primary_destination_local(array $destinations, $fallback = '') {
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

	private static function get_batch_departures($quay_codes, $now, $two_hours_later, $service_dates) {
		global $wpdb;
		if (empty($quay_codes) || empty($service_dates)) return array();

		$departures_by_quay = array();
		$timezone = wp_timezone();

		// Gebruik min/max als SQL-grens, maar itereer alleen de actuele OV-servicedag.
		// De +1 dag-correctie voor ritten na middernacht (total_seconds < 5u) geldt enkel
		// voor de huidige OV-dag; bij de volgende kalenderdag zou de correctie dubbel tellen.
		$service_date_min = min($service_dates);
		$service_date_max = max($service_dates);
		$current_ov_service_date = self::get_service_date_for_timestamp($now);
		$service_dates_arr = array($current_ov_service_date);

		$placeholders = implode(',', array_fill(0, count($quay_codes), '%s'));
		$params = array_merge($quay_codes);
		$params[] = $service_date_min;
		$params[] = $service_date_max;

		// 1. Haal de scheduled_stop_refs per quay op
		$assignments_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT quay_code, scheduled_stop_point_ref FROM " . self::table('ovhi', 'assignments') . " WHERE quay_code IN ($placeholders)",
				...$quay_codes
			),
			ARRAY_A
		);

		$scheduled_stops_by_quay = array();
		foreach ($assignments_rows as $row) {
			$scheduled_stops_by_quay[$row['quay_code']][] = $row['scheduled_stop_point_ref'];
		}

		// 2. Haal alle stop_lines op voor de quays
		$stop_lines_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sl.quay_code, sl.line_ref, sl.direction_type, sl.destination, l.public_code, l.line_name
				 FROM " . self::table('ovhi', 'stop_lines') . " sl
				 INNER JOIN " . self::table('ovhi', 'lines') . " l ON l.line_ref = sl.line_ref
				 WHERE sl.quay_code IN ($placeholders)",
				...$quay_codes
			),
			ARRAY_A
		);

		if (empty($stop_lines_rows)) {
			return array();
		}

		// Groepeer stop_lines per quay en lineRef + direction_type
		$items_by_quay = array();
		foreach ($stop_lines_rows as $row) {
			$q = $row['quay_code'];
			$key = $row['line_ref'] . '|' . $row['direction_type'];
			
			if (!isset($items_by_quay[$q])) {
				$items_by_quay[$q] = array();
			}
			if (!isset($items_by_quay[$q][$key])) {
				$items_by_quay[$q][$key] = array(
					'line_ref' => $row['line_ref'],
					'public_code' => $row['public_code'],
					'line_name' => $row['line_name'],
					'direction_type' => $row['direction_type'],
					'destinations' => array(),
				);
			}
			$dest_cleaned = self::clean_destination_text($row['destination']);
			if (!isset($items_by_quay[$q][$key]['destinations'][$dest_cleaned])) {
				$items_by_quay[$q][$key]['destinations'][$dest_cleaned] = 0;
			}
			$items_by_quay[$q][$key]['destinations'][$dest_cleaned]++;
		}

		// 3. Batch-opvraging van alle vertrektijden voor deze quays binnen de service dates
		$sql_deps = "
			SELECT ass.quay_code, so.offset_seconds, j.departure_seconds, j.journey_ref AS journey_ref,
			       rd.delay_seconds, rd.is_cancelled, a.from_date, a.to_date, a.valid_day_bits,
			       so.line_ref, so.direction_type
			FROM " . self::table('ovhi', 'stop_offsets') . " so
			INNER JOIN " . self::table('ovhi', 'assignments') . " ass ON ass.scheduled_stop_point_ref = so.scheduled_stop_point_ref
			INNER JOIN " . self::table('ovhi', 'journeys') . " j
				ON j.service_journey_pattern_ref = so.service_journey_pattern_ref
				AND j.time_demand_type_ref = so.time_demand_type_ref
			INNER JOIN " . self::table('ovhi', 'availability') . " a ON a.availability_ref = j.availability_ref
			LEFT JOIN " . $wpdb->prefix . "ovhi_realtime_delays rd
				ON rd.journey_ref = j.journey_ref
				AND rd.stop_code = ass.quay_code
			WHERE ass.quay_code IN ($placeholders)
				AND so.for_boarding = 1
				AND a.to_date >= %s
				AND a.from_date <= %s
		";

		$departures_rows = $wpdb->get_results($wpdb->prepare($sql_deps, ...$params), ARRAY_A);

		// Alleen de huidige OV-servicedag controleren (zie boven)
		$service_date = $current_ov_service_date;

		if ($departures_rows) {
			// Eerst filteren op geldigheid voor de huidige servicedag, pas dáárna dedupliceren.
			// Zo niet, dan kan een rit die vandaag echt rijdt worden weggegooid omdat een
			// heel ander dagpatroon (bijv. een losse uitzonderingsdag met een latere from_date)
			// toevallig op hetzelfde kloktijdstip vertrekt.
			$valid_rows = array();
			foreach ($departures_rows as $row) {
				if (self::is_date_valid_for_availability($row, $service_date)) {
					$valid_rows[] = $row;
				}
			}

			// Filter duplicates: keep only the newest version of journeys with the same departure time at this stop
			$groups = array();
			foreach ($valid_rows as $row) {
				$key = $row['quay_code'] . '|' . $row['line_ref'] . '|' . $row['direction_type'] . '|' . $row['departure_seconds'];
				$groups[$key][] = $row;
			}
			$departures_rows = array();
			foreach ($groups as $key => $group) {
				if (count($group) > 1) {
					usort($group, function ($a, $b) {
						return strcmp($b['from_date'], $a['from_date']);
					});
				}
				$departures_rows[] = $group[0];
			}
		}

		$candidates_by_quay_line = array();
		foreach ($departures_rows as $row) {
			$q = $row['quay_code'];
			$line_ref = $row['line_ref'];
			$dir = $row['direction_type'];
			$key = $q . '|' . $line_ref . '|' . $dir;

			$total_seconds = (int) $row['departure_seconds'] + (int) $row['offset_seconds'];
			$midnight = new DateTimeImmutable($service_date . ' 00:00:00', $timezone);
			$dt = $midnight->modify('+' . $total_seconds . ' seconds');
			if ($total_seconds < 5 * HOUR_IN_SECONDS) {
				$dt = $dt->modify('+1 day');
			}
			$ts = $dt->getTimestamp();

			// Blijf zichtbaar tot de geplande tijd is verstreken (bij voorloop) of tot de
			// vertraagde tijd is verstreken (bij vertraging): gebruik steeds de laatste van de twee.
			$delay_seconds = isset($row['delay_seconds']) ? (int) $row['delay_seconds'] : 0;
			$realtime_ts = $ts + $delay_seconds;
			$visibility_ts = max($ts, $realtime_ts);

			if ($visibility_ts >= $now && $ts <= $two_hours_later) {
				$candidates_by_quay_line[$key][$ts] = array(
					'time' => $dt->format('H:i'),
					'timestamp' => $ts,
					'delay_seconds' => $delay_seconds,
					'is_cancelled' => !empty($row['is_cancelled']),
				);
			}
		}

		$next_departure_by_quay_line = array();
		foreach ($candidates_by_quay_line as $key => $times) {
			ksort($times, SORT_NUMERIC);
			$ts = key($times);
			$next_departure_by_quay_line[$key] = $times[$ts];
		}

		// 4. Batch-opvraging van operationele bestemmingen
		$sql_dest = "
			SELECT DISTINCT slp.quay_code, slp.line_ref, slp.direction_type, slp.destination_display,
			                so.offset_seconds, j.journey_ref, j.departure_seconds, a.from_date, a.to_date, a.valid_day_bits
			FROM " . self::table('ovhi', 'stop_line_patterns') . " slp
			INNER JOIN " . self::table('ovhi', 'assignments') . " ass ON ass.quay_code = slp.quay_code
			INNER JOIN " . self::table('ovhi', 'stop_offsets') . " so
				ON so.service_journey_pattern_ref = slp.service_journey_pattern_ref
				AND so.scheduled_stop_point_ref = ass.scheduled_stop_point_ref
				AND so.line_ref = slp.line_ref
				AND so.direction_type = slp.direction_type
			INNER JOIN " . self::table('ovhi', 'journeys') . " j
				ON j.service_journey_pattern_ref = so.service_journey_pattern_ref
				AND j.time_demand_type_ref = so.time_demand_type_ref
			INNER JOIN " . self::table('ovhi', 'availability') . " a ON a.availability_ref = j.availability_ref
			WHERE slp.quay_code IN ($placeholders)
				AND a.to_date >= %s
				AND a.from_date <= %s
		";

		$dest_rows = $wpdb->get_results($wpdb->prepare($sql_dest, ...$params), ARRAY_A);

		if ($dest_rows) {
			// Eerst filteren op geldigheid voor de huidige servicedag, pas dáárna dedupliceren.
			// Zo niet, dan kan een rit die vandaag echt rijdt worden weggegooid omdat een
			// heel ander dagpatroon (bijv. een losse uitzonderingsdag met een latere from_date)
			// toevallig op hetzelfde kloktijdstip vertrekt.
			$valid_rows = array();
			foreach ($dest_rows as $row) {
				if (self::is_date_valid_for_availability($row, $current_ov_service_date)) {
					$valid_rows[] = $row;
				}
			}

			// Filter duplicates: keep only the newest version of journeys with the same departure time at this stop
			$groups = array();
			foreach ($valid_rows as $row) {
				$key = $row['quay_code'] . '|' . $row['line_ref'] . '|' . $row['direction_type'] . '|' . $row['departure_seconds'];
				$groups[$key][] = $row;
			}
			$dest_rows = array();
			foreach ($groups as $key => $group) {
				if (count($group) > 1) {
					usort($group, function ($a, $b) {
						return strcmp($b['from_date'], $a['from_date']);
					});
				}
				$dest_rows[] = $group[0];
			}
		}

		$dest_counts_by_quay_line = array();
		$seen = array();

		foreach ($dest_rows as $row) {
			$q = $row['quay_code'];
			$line_ref = $row['line_ref'];
			$dir = $row['direction_type'];
			$key = $q . '|' . $line_ref . '|' . $dir;
			$destination = self::clean_destination_text($row['destination_display']);
			if ($destination === '') {
				continue;
			}

			$service_date = $current_ov_service_date;
			$total_seconds = (int) $row['departure_seconds'] + (int) $row['offset_seconds'];
			$midnight = new DateTimeImmutable($service_date . ' 00:00:00', $timezone);
			$dt = $midnight->modify('+' . $total_seconds . ' seconds');
			if ($total_seconds < 5 * HOUR_IN_SECONDS) {
				$dt = $dt->modify('+1 day');
			}
			$ts = $dt->getTimestamp();

			if ($ts >= $now && $ts <= $two_hours_later) {
				$seen_key = $key . '|' . $destination . '|' . $row['journey_ref'] . '|' . $ts;
				if (!isset($seen[$seen_key])) {
					$seen[$seen_key] = true;
					if (!isset($dest_counts_by_quay_line[$key][$destination])) {
						$dest_counts_by_quay_line[$key][$destination] = 0;
					}
					$dest_counts_by_quay_line[$key][$destination]++;
				}
			}
		}

		$resolved_destination_by_quay_line = array();
		foreach ($dest_counts_by_quay_line as $key => $counts) {
			arsort($counts, SORT_NUMERIC);
			$top_count = reset($counts);
			$candidates = array();
			foreach ($counts as $destination => $count) {
				if ($count !== $top_count) {
					break;
				}
				$candidates[] = $destination;
			}
			usort($candidates, 'strcasecmp');
			$resolved_destination_by_quay_line[$key] = reset($candidates);
		}

		// 5. Bouw het resultaat op
		foreach ($items_by_quay as $q => $lines) {
			foreach ($lines as $line_key => $item) {
				$lookup_key = $q . '|' . $item['line_ref'] . '|' . $item['direction_type'];

				if (isset($next_departure_by_quay_line[$lookup_key])) {
					$departure = $next_departure_by_quay_line[$lookup_key];

					$fallback_destination = self::pick_primary_destination_local($item['destinations'], $item['line_name']);
					$destination = isset($resolved_destination_by_quay_line[$lookup_key]) ? $resolved_destination_by_quay_line[$lookup_key] : $fallback_destination;

					$line_name = trim($item['public_code']);
					if ($line_name === '') {
						$line_name = trim($item['line_name']);
					}
					if (strpos($line_name, ':') !== false) {
						$parts_ref = explode(':', $line_name);
						$line_name = end($parts_ref);
					}

					$departures_by_quay[$q][] = array(
						'line' => $line_name,
						'destination' => $destination,
						'time' => $departure['time'],
						'delay_seconds' => isset($departure['delay_seconds']) ? (int) $departure['delay_seconds'] : 0,
						'is_cancelled' => !empty($departure['is_cancelled']),
						'lineRef' => $item['line_ref'],
						'direction' => $item['direction_type'],
						'timestamp' => $departure['timestamp']
					);
				}
			}
		}

		// Sorteer de ritten per halte chronologisch en verwijder timestamp
		foreach ($departures_by_quay as $q => &$deps) {
			usort($deps, function($a, $b) {
				return $a['timestamp'] <=> $b['timestamp'];
			});
			foreach ($deps as &$dep) {
				unset($dep['timestamp']);
			}
		}

		return $departures_by_quay;
	}	

	private static function is_date_valid_for_availability(array $availability, $service_date) {
		$from_date = isset($availability['from_date']) ? (string) $availability['from_date'] : '';
		$to_date = isset($availability['to_date']) ? (string) $availability['to_date'] : '';
		$bits = isset($availability['valid_day_bits']) ? (string) $availability['valid_day_bits'] : '';

		// Check basis datumbereik
		if ($from_date === '' || $to_date === '' || $service_date < $from_date || $service_date > $to_date) {
			return false;
		}

		// Geen bits = dagelijks geldig
		if ($bits === '') {
			return true;
		}

		// Check valid_day_bits patroon (0=ongeldig, 1=geldig)
		$start = new DateTimeImmutable($from_date . ' 00:00:00');
		$date = new DateTimeImmutable($service_date . ' 00:00:00');
		$index = (int) $start->diff($date)->format('%a');

		return $index >= 0 && $index < strlen($bits) && $bits[$index] === '1';
	}

	private static function get_batch_train_departures($station_codes, $now, $two_hours_later, $service_dates) {
		global $wpdb;
		if (empty($station_codes) || empty($service_dates)) return array();

		$departures_by_station = array();
		$seen_direction_per_station = array();

		// Gebruik alleen de huidige OV-servicedag: de +1 dag-correctie in timestamp_from_service_seconds
		// (voor ritten na middernacht met dep_sec < 5u) mag maar één keer worden toegepast.
		// Bij de volgende kalenderdag zou de correctie dubbel tellen en ritten twee dagen vooruit schuiven.
		$service_date = self::get_service_date_for_timestamp($now);

		$placeholders = implode(',', array_fill(0, count($station_codes), '%s'));
		$params = array_merge($station_codes);

		$sql = "
			SELECT so.station_code,
			       d.train_type, d.line_code, d.destination_name as destination, so.departure_seconds as dep_sec, j.direction_ref,
			       j.journey_ref, j.train_number,
			       rd.delay_seconds, rd.is_cancelled
			FROM " . self::table('ovtd', 'journey_stops') . " so
			INNER JOIN " . self::table('ovtd', 'journeys') . " j ON j.journey_ref = so.journey_ref
			INNER JOIN " . self::table('ovtd', 'directions') . " d ON d.direction_ref = j.direction_ref
			LEFT JOIN " . $wpdb->prefix . "ovhi_realtime_delays rd
				ON (rd.journey_ref = j.journey_ref OR rd.journey_ref = j.train_number OR rd.journey_ref = TRIM(LEADING '0' FROM j.train_number))
				AND rd.stop_code = so.station_code
			WHERE so.station_code IN ($placeholders)
			ORDER BY so.station_code, so.departure_seconds ASC
		";

		$results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

		// Single-pass NS API fallback voor stations zonder DB match indien OV_Trein_Dienstregeling aanwezig is
		$ns_delays = array();
		if (class_exists('OV_Trein_Dienstregeling') && method_exists('OV_Trein_Dienstregeling', 'fetch_ns_api_delays')) {
			$ns_delays = OV_Trein_Dienstregeling::fetch_ns_api_delays($station_codes);
		}

		foreach ($results as $row) {
			$station_code = $row['station_code'];
			$direction_ref = $row['direction_ref'];

			if (!isset($departures_by_station[$station_code])) {
				$departures_by_station[$station_code] = array();
				$seen_direction_per_station[$station_code] = array();
			}

			if (in_array($direction_ref, $seen_direction_per_station[$station_code], true)) {
				continue;
			}

			$ts = self::timestamp_from_service_seconds($service_date, (int)$row['dep_sec']);

			$delay_seconds = isset($row['delay_seconds']) ? (int) $row['delay_seconds'] : 0;
			$is_cancelled = !empty($row['is_cancelled']);

			// Indien geen DB match, controleer NS API resultaten
			if ($delay_seconds === 0 && !$is_cancelled && !empty($ns_delays)) {
				$clean_st = strtolower($station_code);
				$raw_tnum = isset($row['train_number']) ? trim((string) $row['train_number']) : '';
				$clean_tnum = ltrim($raw_tnum, '0');
				$ns_candidates = array(
					$raw_tnum . '|' . $clean_st,
					$clean_tnum . '|' . $clean_st,
					$raw_tnum,
					$clean_tnum,
				);
				foreach ($ns_candidates as $cand) {
					if ($cand !== '' && isset($ns_delays[$cand]) && is_array($ns_delays[$cand])) {
						$delay_seconds = (int) $ns_delays[$cand]['delay_seconds'];
						$is_cancelled = !empty($ns_delays[$cand]['is_cancelled']);
						break;
					}
				}
			}

			// Blijf zichtbaar tot de geplande tijd is verstreken (bij voorloop) of tot de
			// vertraagde tijd is verstreken (bij vertraging): gebruik steeds de laatste van de twee.
			$realtime_ts = $ts + $delay_seconds;
			$visibility_ts = max($ts, $realtime_ts);

			if ($visibility_ts >= $now && $ts <= $two_hours_later) {
				$timezone = wp_timezone();
				$dt = new DateTimeImmutable('@' . $ts);
				$dt = $dt->setTimezone($timezone);
				// Zelfde lijnaanduiding als de "Lijnen:"-badges op dit station:
				// lijncode indien bekend, anders het afgekorte treintype.
				$line_badge = class_exists('OV_Trein_Dienstregeling')
					? OV_Trein_Dienstregeling::get_line_badge($row['train_type'], $row['line_code'])
					: (string) $row['train_type'];
				$departures_by_station[$station_code][] = array(
					'line' => $line_badge,
					'destination' => $row['destination'],
					'time' => $dt->format('H:i'),
					'delay_seconds' => $delay_seconds,
					'is_cancelled' => $is_cancelled,
					'lineRef' => $direction_ref,
					'direction' => $direction_ref,
					'timestamp' => $ts
				);
				$seen_direction_per_station[$station_code][] = $direction_ref;
			}
		}

		// Sorteer de treinen chronologisch per station
		foreach ($departures_by_station as $station_code => &$deps) {
			usort($deps, function($a, $b) {
				return $a['timestamp'] <=> $b['timestamp'];
			});
			foreach ($deps as &$dep) {
				unset($dep['timestamp']);
			}
		}
		return $departures_by_station;
	}

	private static function get_active_departure_url($code, $type = 'bus') {
		global $wpdb;
		if ($type === 'bus') {
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM " . self::table('ovhi', 'stop_offsets') . " WHERE scheduled_stop_point_ref IN (
					SELECT scheduled_stop_point_ref FROM " . self::table('ovhi', 'assignments') . " WHERE quay_code = %s
				) LIMIT 1",
				$code
			));
			return $exists ? home_url('/dienstregeling/?quay=' . $code) : '';
		} else {
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM " . self::table('ovtd', 'journey_stops') . " WHERE station_code = %s LIMIT 1",
				$code
			));
			return $exists ? home_url('/treindienstregeling/?station=' . $code) : '';
		}
	}

	private static function get_train_directions($station_code) {
		global $wpdb;
		$sql = "
			SELECT DISTINCT d.direction_ref as ref, d.destination_name as label, d.train_type, d.line_code
			FROM " . self::table('ovtd', 'journey_stops') . " js
			INNER JOIN " . self::table('ovtd', 'journeys') . " j ON j.journey_ref = js.journey_ref
			INNER JOIN " . self::table('ovtd', 'directions') . " d ON d.direction_ref = j.direction_ref
			WHERE js.station_code = %s
		";
		$rows = $wpdb->get_results($wpdb->prepare($sql, $station_code), ARRAY_A);

		// Bepaal per richting de lijnbadge op dezelfde manier als OV Trein
		// Dienstregeling: lijncode (bv. "RSx") indien bekend, anders het
		// afgekorte treintype (IC/Spr/Snl/etc). Zo tonen we op de kaart
		// straks dezelfde lijnaanduiding als in de dienstregeling zelf.
		foreach ($rows as &$row) {
			$row['badge'] = class_exists('OV_Trein_Dienstregeling')
				? OV_Trein_Dienstregeling::get_line_badge($row['train_type'], $row['line_code'])
				: (string) $row['train_type'];
		}
		unset($row);

		return $rows;
	}

	/**
	 * Nauwkeurige RD (Rijksdriehoek) -> WGS84 conversie.
	 *
	 * De eerder gebruikte lineaire benadering week tientallen kilometers af
	 * van de werkelijke locatie. Dit is de gangbare polynomiale
	 * RD-benadering (nauwkeurig tot op ~1 meter), zodat stations ook binnen
	 * een strak ingezoomd kaartgebied op de juiste plek terechtkomen.
	 */
	private static function rd_to_wgs84($x, $y) {
		$x0 = 155000.0;
		$y0 = 463000.0;
		$phi0 = 52.15517440;
		$lam0 = 5.38720621;

		// [macht van dX, macht van dY, coëfficiënt]
		$k = array(
			array(0, 1, 3235.65389),
			array(2, 0, -32.58297),
			array(0, 2, -0.24750),
			array(2, 1, -0.84978),
			array(0, 3, -0.06550),
			array(2, 2, -0.01709),
			array(1, 0, -0.00738),
			array(4, 0, 0.00530),
			array(2, 3, -0.00039),
			array(4, 1, 0.00033),
			array(1, 1, -0.00012),
		);
		$l = array(
			array(1, 0, 5260.52916),
			array(1, 1, 105.94684),
			array(1, 2, 2.45656),
			array(3, 0, -0.81885),
			array(1, 3, 0.05594),
			array(3, 1, -0.05607),
			array(0, 1, 0.01199),
			array(3, 2, -0.00256),
			array(1, 4, 0.00128),
			array(0, 2, 0.00022),
			array(2, 0, -0.00022),
			array(5, 0, 0.00026),
		);

		$dx = ($x - $x0) * 0.00001;
		$dy = ($y - $y0) * 0.00001;

		$sum_phi = 0.0;
		foreach ($k as $term) {
			$sum_phi += $term[2] * pow($dx, $term[0]) * pow($dy, $term[1]);
		}
		$sum_lam = 0.0;
		foreach ($l as $term) {
			$sum_lam += $term[2] * pow($dx, $term[0]) * pow($dy, $term[1]);
		}

		$lat = $phi0 + ($sum_phi / 3600);
		$lon = $lam0 + ($sum_lam / 3600);

		return array('lat' => $lat, 'lon' => $lon);
	}

	private static function get_service_date_for_timestamp($ts) {
		$timezone = wp_timezone();
		$dt = new DateTimeImmutable('@' . $ts);
		$dt = $dt->setTimezone($timezone);
		$hours = (int) $dt->format('G');
		
		if ($hours < 5) {
			return $dt->modify('-1 day')->format('Y-m-d');
		}
		return $dt->format('Y-m-d');
	}

	private static function timestamp_from_service_seconds($service_date, $seconds) {
		$timezone = wp_timezone();
		$midnight = new DateTimeImmutable($service_date . ' 00:00:00', $timezone);
		$dt = $midnight->modify('+' . (int) $seconds . ' seconds');
		if ((int) $seconds < 5 * HOUR_IN_SECONDS) {
			$dt = $dt->modify('+1 day');
		}
		return $dt->getTimestamp();
	}

	private static function enqueue_assets() {
		wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
		wp_enqueue_style('leaflet-cluster-css', 'https://unpkg.com/leaflet.markercluster@1.5.0/dist/MarkerCluster.css');
		wp_enqueue_style('leaflet-cluster-default-css', 'https://unpkg.com/leaflet.markercluster@1.5.0/dist/MarkerCluster.Default.css');

		wp_enqueue_style(self::FRONTEND_STYLE);

		wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), null, true);
		wp_enqueue_script('leaflet-cluster-js', 'https://unpkg.com/leaflet.markercluster@1.5.0/dist/leaflet.markercluster.js', array('leaflet-js'), null, true);

		wp_enqueue_script(self::FRONTEND_SCRIPT);
	}
}

add_action('wp_enqueue_scripts', function() {
	wp_register_style('ovalino-map-style', plugins_url('assets/map.css', __FILE__));
	wp_register_script('ovalino-map-script', plugins_url('assets/map.js', __FILE__), array('leaflet-js', 'leaflet-cluster-js'), Ovalino_Map::VERSION, true);
});

Ovalino_Map::init();