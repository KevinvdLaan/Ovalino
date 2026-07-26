<?php
/**
 * Plugin Name: OV Reisplanner
 * Description: Eenvoudige statische reisplanner op basis van OV Halte Importer en OV Trein Dienstregeling.
 * Version: 0.3.1
 * Author: Kevin van der Laan
 * License: GPL-2.0-or-later
 * Requires Plugins: ov-halte-importer, ov-trein-dienstregeling
 */

if (!defined('ABSPATH')) {
	exit;
}

class OV_Reisplanner {
	const VERSION = '0.3.1';
	const FRONTEND_STYLE = 'ovrp-frontend';
	const SERVICE_DAY_START_SECONDS = 14400;
	const DEFAULT_MIN_TRANSFER_SECONDS = 240;
	const LEG_SQL_LIMIT_MULTIPLIER = 12;
	const LEG_SQL_LIMIT_MAX = 800;
	const INDEX_VERSION = '20260531-1';
	const REGIONAL_TOWNS = array(
		'Aalden', 'Alteveer', 'Annen', 'Appelscha', 'Assen', 'Baflo', 'Barger-Compascuum', 'Beilen', 'Borger',
		'Bourtange', 'Coevorden', 'Delfzijl', 'De Punt', 'Diever', 'Dwingeloo', 'Eelde', 'Eelderwolde', 'Eemshaven',
		'Emmen', 'Emmer-Compascuum', 'Exloo', 'Foxhol', 'Gasselte', 'Gieten', 'Glimmen', 'Groningen', 'Haren',
		'Hoogeveen', 'Hoogezand', 'Klazienaveen', 'Leek', 'Loppersum', 'Marum', 'Meppel', 'Musselkanaal',
		'Nieuw-Buinen', 'Nieuw-Amsterdam', 'Norg', 'Pekela', 'Roden', 'Rolde', 'Sappemeer', 'Scheemda',
		'Schoonebeek', 'Sellingen', 'Stadskanaal', 'Ter Apel', 'Tynaarlo', 'Uithuizen', 'Uithuizermeeden',
		'Veendam', 'Vries', 'Winschoten', 'Winsum', 'Wildervank', 'Zuidlaren', 'Zuidwolde',
	);

	public static function init() {
		add_shortcode('ov_reisplanner', array(__CLASS__, 'render_shortcode'));
		add_action('wp_ajax_ovrp_autocomplete', array(__CLASS__, 'ajax_autocomplete'));
		add_action('wp_ajax_nopriv_ovrp_autocomplete', array(__CLASS__, 'ajax_autocomplete'));
	}

	private static function table($prefix, $suffix) {
		global $wpdb;
		return $wpdb->prefix . $prefix . '_' . $suffix;
	}

	private static function table_exists($prefix, $suffix) {
		global $wpdb;
		$table = self::table($prefix, $suffix);
		return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	}

	private static function dependencies_available() {
		return self::table_exists('ovhi', 'journeys')
			&& self::table_exists('ovhi', 'stop_offsets')
			&& self::table_exists('ovtd', 'journeys')
			&& self::table_exists('ovtd', 'journey_stops');
	}

	private static function maybe_add_runtime_indexes() {
		$option_key = 'ovrp_index_version';
		if (get_option($option_key) === self::INDEX_VERSION) {
			return;
		}

		self::add_index_if_missing(
			self::table('ovhi', 'stop_offsets'),
			'ovrp_pattern_time_order',
			'(service_journey_pattern_ref, time_demand_type_ref, stop_order)'
		);
		self::add_index_if_missing(
			self::table('ovhi', 'stop_offsets'),
			'ovrp_scheduled_pattern_time',
			'(scheduled_stop_point_ref, service_journey_pattern_ref, time_demand_type_ref)'
		);
		self::add_index_if_missing(
			self::table('ovhi', 'journeys'),
			'ovrp_pattern_departure',
			'(service_journey_pattern_ref, time_demand_type_ref, departure_seconds)'
		);
		self::add_index_if_missing(
			self::table('ovtd', 'journey_stops'),
			'ovrp_station_order_journey',
			'(station_code, stop_order, journey_ref)'
		);

		update_option($option_key, self::INDEX_VERSION, false);
	}

	private static function add_index_if_missing($table, $index_name, $columns_sql) {
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s',
				$index_name
			)
		);
		if ($exists) {
			return;
		}
		$wpdb->query('ALTER TABLE ' . $table . ' ADD INDEX ' . $index_name . ' ' . $columns_sql);
	}

	public static function ajax_autocomplete() {
		nocache_headers();

		$query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
		$normalized = self::normalize_search_text($query);

		if ($normalized === '') {
			wp_send_json_success(array());
		}

		$nodes = self::get_nodes();
		$matches = array();
		$limit = 8;

		foreach ($nodes as $node) {
			$name = isset($node['name']) ? (string) $node['name'] : (string) $node['label'];
			$place = isset($node['place']) ? (string) $node['place'] : '';
			$type = $node['mode'] === 'train' ? 'Station' : 'Bushalte';

			$search_text = self::normalize_search_text($name . ' ' . $place . ' ' . $type);
			$name_normalized = self::normalize_search_text($name);

			if (strpos($search_text, $normalized) !== false || strpos($name_normalized, $normalized) === 0) {
				$matches[] = array(
					self::node_value($node),
					$name,
					$type,
					$place,
				);
				if (count($matches) >= $limit) {
					break;
				}
			}
		}

		wp_send_json_success($matches);
	}

	public static function render_shortcode($atts) {
		$atts = shortcode_atts(
			array(
				'limit' => 3,
				'min_transfer' => 3,
				'max_transfers' => 5,
				'transfers' => '',
			),
			$atts,
			'ov_reisplanner'
		);

		if (!self::dependencies_available()) {
			return '<p>De reisplanner heeft eerst geimporteerde halte- en treindata nodig.</p>';
		}
		self::maybe_add_runtime_indexes();

		$from_value = isset($_GET['ovrp_from']) ? sanitize_text_field(wp_unslash($_GET['ovrp_from'])) : '';
		$to_value = isset($_GET['ovrp_to']) ? sanitize_text_field(wp_unslash($_GET['ovrp_to'])) : '';
		$when_value = isset($_GET['ovrp_when']) ? sanitize_text_field(wp_unslash($_GET['ovrp_when'])) : self::default_when_value();
		$from = self::parse_node_value($from_value);
		$to = self::parse_node_value($to_value);
		$page_size = max(1, min(10, (int) $atts['limit']));
		$page = isset($_GET['ovrp_page']) ? max(0, (int) $_GET['ovrp_page']) : 0;
		$request_limit = min(80, (($page + 1) * $page_size) + 1);
		$min_transfer_seconds = max(self::DEFAULT_MIN_TRANSFER_SECONDS, ((int) $atts['min_transfer']) * MINUTE_IN_SECONDS);
		$max_transfers = max(0, min(2, (int) $atts['max_transfers']));

		self::enqueue_frontend_style();

		$output = '<div class="ovrp-wrapper">';
		$output .= self::render_form($from_value, $to_value, $when_value);
		$output .= self::render_autocomplete_script();

		if ($from && $to && self::node_key($from) === self::node_key($to)) {
			$output .= '<p>Kies twee verschillende haltes of stations.</p>';
		} elseif ($from && $to) {
			$transfer_map = self::merge_transfer_maps(
				self::build_auto_transfer_map(),
				self::parse_transfer_map((string) $atts['transfers'])
			);
			$after_timestamp = self::parse_when_timestamp($when_value);
			$itineraries = self::plan($from, $to, $after_timestamp, $request_limit, $min_transfer_seconds, $max_transfers, $transfer_map);
			$output .= self::render_results($itineraries, array(), $min_transfer_seconds, $page, $page_size);
		}

		$output .= '</div>';
		return $output;
	}

	private static function get_nodes() {
		global $wpdb;
		static $memory_cache = null;
		if (is_array($memory_cache)) {
			return $memory_cache;
		}

		$cache_key = 'ovrp_nodes_' . self::VERSION;
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			$memory_cache = $cached;
			return $cached;
		}

		$nodes = array();

		if (self::table_exists('ovhi', 'stopplaces')) {
			$rows = $wpdb->get_results(
				'
				SELECT DISTINCT sp.stopplace_code, sp.stopplace_name, sp.town
				FROM ' . self::table('ovhi', 'stopplaces') . ' sp
				INNER JOIN ' . self::table('ovhi', 'quays') . ' q ON q.stopplace_code = sp.stopplace_code
				INNER JOIN ' . self::table('ovhi', 'assignments') . ' ass ON ass.quay_code = q.quay_code
				WHERE EXISTS (
					SELECT 1
					FROM ' . self::table('ovhi', 'stop_offsets') . ' so
					INNER JOIN ' . self::table('ovhi', 'journeys') . ' j
						ON j.service_journey_pattern_ref = so.service_journey_pattern_ref
						AND j.time_demand_type_ref = so.time_demand_type_ref
					WHERE so.scheduled_stop_point_ref = ass.scheduled_stop_point_ref
					LIMIT 1
				)
				AND EXISTS (
					SELECT 1
					FROM ' . self::table('ovhi', 'stop_offsets') . ' so_current
					INNER JOIN ' . self::table('ovhi', 'stop_offsets') . ' so_regional
						ON so_regional.service_journey_pattern_ref = so_current.service_journey_pattern_ref
					INNER JOIN ' . self::table('ovhi', 'assignments') . ' ass_regional
						ON ass_regional.scheduled_stop_point_ref = so_regional.scheduled_stop_point_ref
					INNER JOIN ' . self::table('ovhi', 'quays') . ' q_regional
						ON q_regional.quay_code = ass_regional.quay_code
					INNER JOIN ' . self::table('ovhi', 'stopplaces') . ' sp_regional
						ON sp_regional.stopplace_code = q_regional.stopplace_code
					WHERE so_current.scheduled_stop_point_ref = ass.scheduled_stop_point_ref
						AND ' . self::regional_town_sql('sp_regional.town') . '
					LIMIT 1
				)
				ORDER BY sp.stopplace_name ASC, sp.town ASC
				',
				ARRAY_A
			);
			foreach ($rows as $row) {
				$name = trim((string) $row['stopplace_name']);
				$place = trim((string) $row['town']);
				$node = array(
					'mode' => 'bus',
					'ref' => (string) $row['stopplace_code'],
					'name' => $name !== '' ? $name : (string) $row['stopplace_code'],
					'place' => $place,
					'label' => self::format_node_label($name, (string) $row['stopplace_code']),
				);
				$nodes[self::node_key($node)] = $node;
			}
		}

		if (self::table_exists('ovtd', 'stations')) {
			$rows = $wpdb->get_results(
				'
				SELECT DISTINCT s.station_code, s.station_name
				FROM ' . self::table('ovtd', 'stations') . ' s
				INNER JOIN ' . self::table('ovtd', 'journey_stops') . ' js ON js.station_code = s.station_code
				ORDER BY s.station_name ASC
				',
				ARRAY_A
			);
			foreach ($rows as $row) {
				$name = trim((string) $row['station_name']);
				$place = self::station_place_name($name);
				$node = array(
					'mode' => 'train',
					'ref' => strtolower((string) $row['station_code']),
					'name' => $name !== '' ? $name : strtoupper((string) $row['station_code']),
					'place' => $place,
					'label' => self::format_node_label($name, (string) $row['station_code']),
				);
				$nodes[self::node_key($node)] = $node;
			}
		}

		$memory_cache = $nodes;
		set_transient($cache_key, $nodes, DAY_IN_SECONDS);
		return $nodes;
	}

	private static function render_form($from_value, $to_value, $when_value) {
		$action = esc_url(remove_query_arg(array('ovrp_from', 'ovrp_to', 'ovrp_when')));
		$html = '<form class="ovrp-form" method="get" action="' . $action . '">';

		foreach ($_GET as $key => $value) {
			if (in_array($key, array('ovrp_from', 'ovrp_to', 'ovrp_when', 'ovrp_page'), true) || is_array($value)) {
				continue;
			}
			$html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(wp_unslash($value)) . '" />';
		}

		$html .= self::render_node_search('ovrp_from', 'Van', $from_value);
		$html .= self::render_node_search('ovrp_to', 'Naar', $to_value);
		$html .= '<label>Vertrek vanaf<input type="datetime-local" name="ovrp_when" value="' . esc_attr($when_value) . '" /></label>';
		$html .= '<button type="submit">Plan reis</button>';
		$html .= '</form>';
		return $html;
	}

	private static function render_node_search($name, $label, $selected) {
		$selected_node = self::node_by_value_lazy($selected);
		$display_value = $selected_node ? $selected_node['name'] : '';
		$id = 'ovrp-search-' . sanitize_key($name);
		$suggestions_id = $id . '-suggestions';
		$html = '<div class="ovrp-search-field">';
		$html .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
		$html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($selected_node ? self::node_value($selected_node) : '') . '" data-ovrp-node-value />';
		$html .= '<input id="' . esc_attr($id) . '" type="search" value="' . esc_attr($display_value) . '" placeholder="Typ de eerste letters..." autocomplete="off" aria-autocomplete="list" aria-controls="' . esc_attr($suggestions_id) . '" aria-expanded="false" data-ovrp-node-search />';
		$html .= '<div id="' . esc_attr($suggestions_id) . '" class="ovrp-suggestions" role="listbox" data-ovrp-suggestions hidden></div>';
		$html .= '</div>';
		return $html;
	}

	private static function render_autocomplete_script() {
		return '<script>
(function(){
	function normalize(value){
		return (value || "").toString().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]+/g, " ").trim();
	}
	function clear(box, state){
		box.innerHTML = "";
		box.hidden = true;
		if (state) {
			state.activeIndex = -1;
			state.matches = [];
		}
	}
	document.querySelectorAll(".ovrp-search-field").forEach(function(field){
		var input = field.querySelector("[data-ovrp-node-search]");
		var hidden = field.querySelector("[data-ovrp-node-value]");
		var box = field.querySelector("[data-ovrp-suggestions]");
		var form = field.closest("form");
		var state = {
			matches: [],
			activeIndex: -1
		};
		function nodeValue(node){ return node[0]; }
		function nodeName(node){ return node[1]; }
		function nodeType(node){ return node[2]; }
		function nodePlace(node){ return node[3]; }
		function selectNode(node){
			if(!node){ return; }
			input.value = nodeName(node);
			hidden.value = nodeValue(node);
			input.dispatchEvent(new Event("change", {bubbles:true}));
			hidden.dispatchEvent(new Event("change", {bubbles:true}));
			clear(box, state);
			input.setAttribute("aria-expanded", "false");
		}
		function suggestionButton(target){
			return target && target.closest ? target.closest("button[data-index]") : null;
		}
		function chooseSuggestion(event){
			var button = suggestionButton(event.target);
			if(!button || !box.contains(button)){ return; }
			event.preventDefault();
			selectNode(state.matches[parseInt(button.getAttribute("data-index"), 10)]);
		}
		function renderMatches(){
			if(!state.matches.length){ clear(box, state); input.setAttribute("aria-expanded", "false"); return; }
			box.innerHTML = state.matches.map(function(node, index){
				var activeClass = index === state.activeIndex ? " class=\"is-active\" aria-selected=\"true\"" : "";
				return "<button type=\"button\" role=\"option\" data-index=\"" + index + "\"" + activeClass + "><strong>" + escapeHtml(nodeName(node)) + "</strong><span>" + escapeHtml(nodeType(node) + (nodePlace(node) ? " | " + nodePlace(node) : "")) + "</span></button>";
			}).join("");
			box.hidden = false;
			input.setAttribute("aria-expanded", "true");
		}
		function updateHighlight(){
			var buttons = box.querySelectorAll("button");
			buttons.forEach(function(btn, idx){
				if(idx === state.activeIndex){
					btn.classList.add("is-active");
					btn.setAttribute("aria-selected", "true");
					btn.scrollIntoView({ block: "nearest" });
				} else {
					btn.classList.remove("is-active");
					btn.removeAttribute("aria-selected");
				}
			});
		}
		function findTypedMatch(items){
			var query = normalize(input.value);
			return items.find(function(node){ return normalize(nodeName(node)) === query; }) || items.find(function(node){ return normalize(nodeName(node)).indexOf(query) === 0; });
		}
		var debounceTimeout;
		input.addEventListener("input", function(){
			hidden.value = "";
			var query = input.value.trim();
			if(query.length < 1){ clear(box, state); input.setAttribute("aria-expanded", "false"); return; }
			
			clearTimeout(debounceTimeout);
			debounceTimeout = setTimeout(function() {
				var url = "' . esc_url(admin_url('admin-ajax.php')) . '?action=ovrp_autocomplete&q=" + encodeURIComponent(query);
				fetch(url)
					.then(function(res){ return res.json(); })
					.then(function(json){
						if(json && json.success && Array.isArray(json.data)){
							state.matches = json.data;
							state.activeIndex = -1;
							renderMatches();
						}
					})
					.catch(function(err){ console.error(err); });
			}, 250);
		});
		input.addEventListener("keydown", function(event){
			var buttons = box.querySelectorAll("button");
			if(box.hidden || !buttons.length){ return; }
			
			if(event.key === "ArrowDown"){
				event.preventDefault();
				state.activeIndex = (state.activeIndex + 1) % buttons.length;
				updateHighlight();
			} else if(event.key === "ArrowUp"){
				event.preventDefault();
				state.activeIndex = (state.activeIndex - 1 + buttons.length) % buttons.length;
				updateHighlight();
			} else if(event.key === "Enter"){
				if(state.activeIndex >= 0 && state.activeIndex < buttons.length){
					event.preventDefault();
					selectNode(state.matches[state.activeIndex]);
				}
			} else if(event.key === "Escape"){
				event.preventDefault();
				clear(box, state);
				input.setAttribute("aria-expanded", "false");
			}
		});
		box.addEventListener(window.PointerEvent ? "pointerdown" : "mousedown", chooseSuggestion);
		box.addEventListener("click", chooseSuggestion);
		input.addEventListener("blur", function(){ setTimeout(function(){ clear(box, state); input.setAttribute("aria-expanded", "false"); }, 150); });
		if(form){
			form.addEventListener("submit", function(event){
				if(hidden.value){ return; }
				event.preventDefault();
				var query = input.value.trim();
				if(!query){ return; }
				
				var match = findTypedMatch(state.matches);
				if(match){
					selectNode(match);
					form.submit();
					return;
				}
				
				var url = "' . esc_url(admin_url('admin-ajax.php')) . '?action=ovrp_autocomplete&q=" + encodeURIComponent(query);
				fetch(url)
					.then(function(res){ return res.json(); })
					.then(function(json){
						if(json && json.success && Array.isArray(json.data) && json.data.length > 0){
							var match = findTypedMatch(json.data);
							if(match){
								selectNode(match);
								form.submit();
							} else {
								alert("Halte of station niet gevonden. Kies een optie uit de lijst.");
							}
						} else {
							alert("Halte of station niet gevonden. Kies een optie uit de lijst.");
						}
					})
					.catch(function(err){
						console.error(err);
						alert("Er is een fout opgetreden bij het zoeken naar de halte.");
					});
			});
		}
	});
	var modal;
	function getModal(){
		if(modal){return modal;}
		modal=document.createElement("div");
		modal.className="ovrp-modal";
		modal.innerHTML="<div class=\"ovrp-modal-panel\" role=\"dialog\" aria-modal=\"true\" aria-label=\"Volledige reis\"><div class=\"ovrp-modal-head\"><div class=\"ovrp-modal-title\">Volledige reis</div><button type=\"button\" class=\"ovrp-modal-close\" aria-label=\"Sluit venster\">x</button></div><div class=\"ovrp-modal-content\"></div></div>";
		document.body.appendChild(modal);
		modal.querySelector(".ovrp-modal-close").addEventListener("click", closeModal);
		modal.addEventListener("click", function(event){ if(event.target === modal){ closeModal(); } });
		return modal;
	}
	function closeModal(){
		if(modal){ modal.classList.remove("is-open"); }
	}
	function initCards(){
		document.querySelectorAll(".ovrp-card").forEach(function(card){
			if(card.dataset.ovrpDetailReady === "1"){ return; }
			card.dataset.ovrpDetailReady = "1";
			card.addEventListener("click", function(){
				var detail = card.querySelector(".ovrp-mobile-detail");
				if(!detail){ return; }
				var active = getModal();
				active.querySelector(".ovrp-modal-content").innerHTML = detail.innerHTML;
				active.classList.add("is-open");
				active.querySelector(".ovrp-modal-close").focus();
			});
			card.addEventListener("keydown", function(event){
				if(event.key === "Enter" || event.key === " "){
					event.preventDefault();
					card.click();
				}
			});
		});
	}
	initCards();
	document.addEventListener("DOMContentLoaded", initCards);
	document.addEventListener("keydown", function(event){ if(event.key === "Escape"){ closeModal(); } });
	function escapeHtml(value){
		return (value || "").replace(/[&<>"\']/g, function(char){
			return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#039;"}[char];
		});
	}
})();
</script>';
	}

	private static function plan(array $from, array $to, $after_timestamp, $limit, $min_transfer_seconds, $max_transfers, array $transfer_map) {
		$itineraries = array();
		$origin_nodes = self::terminal_nodes($from, $transfer_map);
		$destination_nodes = self::terminal_nodes($to, $transfer_map);
		$destination_keys = array();
		foreach ($destination_nodes as $destination) {
			$destination_keys[self::node_key($destination['node'])] = true;
		}

		$target0 = array();
		foreach ($destination_nodes as $dest) {
			$target0[self::node_key($dest['node'])] = $dest['node'];
		}

		$expanded_target0 = array();
		foreach ($target0 as $node) {
			foreach (self::transfer_nodes($node, $transfer_map) as $transfer) {
				$expanded_target0[self::node_key($transfer['node'])] = $transfer['node'];
			}
		}

		$target1 = $target0;
		if ($max_transfers >= 1) {
			$preds1 = self::get_direct_predecessors(array_values($expanded_target0), $transfer_map);
			foreach ($preds1 as $node) {
				$target1[self::node_key($node)] = $node;
			}
		}

		$expanded_target1 = array();
		foreach ($target1 as $node) {
			foreach (self::transfer_nodes($node, $transfer_map) as $transfer) {
				$expanded_target1[self::node_key($transfer['node'])] = $transfer['node'];
			}
		}

		$target2 = $target1;
		if ($max_transfers >= 2) {
			$preds2 = self::get_direct_predecessors(array_values($expanded_target1), $transfer_map);
			foreach ($preds2 as $node) {
				$target2[self::node_key($node)] = $node;
			}
		}

		$expanded_target2 = array();
		foreach ($target2 as $node) {
			foreach (self::transfer_nodes($node, $transfer_map) as $transfer) {
				$expanded_target2[self::node_key($transfer['node'])] = $transfer['node'];
			}
		}

		$origin_nodes_only = array_column($origin_nodes, 'node');
		$expanded_target0_nodes = array_values($expanded_target0);
		$expanded_target1_nodes = array_values($expanded_target1);
		$expanded_target2_nodes = array_values($expanded_target2);

		$L1 = self::find_legs_multi($origin_nodes_only, $expanded_target2_nodes, $after_timestamp, 120);
		if (empty($L1)) {
			return array();
		}

		foreach ($L1 as $l1) {
			if (isset($destination_keys[self::node_key($l1['to_node'])])) {
				$itineraries[] = array('legs' => array($l1));
			}
		}

		$L2 = array();
		if ($max_transfers >= 1) {
			$l1_dest_map = array();
			foreach ($L1 as $l1) {
				foreach (self::transfer_nodes($l1['to_node'], $transfer_map) as $transfer) {
					$l1_dest_map[self::node_key($transfer['node'])] = $transfer['node'];
				}
			}

			if (!empty($l1_dest_map)) {
				$earliest_l1_arrival = min(array_column($L1, 'arrival_timestamp'));
				$l2_after = $earliest_l1_arrival + 60;
				$L2 = self::find_legs_multi(array_values($l1_dest_map), $expanded_target1_nodes, $l2_after, 240);
			}
		}

		if (!empty($L2)) {
			foreach ($L1 as $l1) {
				foreach ($L2 as $l2) {
					if ($l1['mode'] === $l2['mode'] && $l1['journey_ref'] === $l2['journey_ref']) {
						continue;
					}
					$transfer_found = false;
					$transfer_sec = 0;
					foreach (self::transfer_nodes($l1['to_node'], $transfer_map) as $transfer) {
						if (self::node_key($transfer['node']) === self::node_key($l2['from_node'])) {
							$transfer_found = true;
							$transfer_sec = (int) $transfer['seconds'];
							break;
						}
					}
					if (!$transfer_found) {
						continue;
					}
					$is_same_node = (self::node_key($l1['to_node']) === self::node_key($l2['from_node']));
					$required_transfer_time = $is_same_node ? 60 : $min_transfer_seconds;
					$min_dep = $l1['arrival_timestamp'] + max($required_transfer_time, $transfer_sec);
					if ($l2['departure_timestamp'] < $min_dep) {
						continue;
					}

					if (isset($destination_keys[self::node_key($l2['to_node'])])) {
						$itineraries[] = array('legs' => array($l1, $l2));
					}
				}
			}
		}

		$L3 = array();
		if ($max_transfers >= 2 && !empty($L2)) {
			$l2_dest_map = array();
			foreach ($L2 as $l2) {
				foreach (self::transfer_nodes($l2['to_node'], $transfer_map) as $transfer) {
					$l2_dest_map[self::node_key($transfer['node'])] = $transfer['node'];
				}
			}

			if (!empty($l2_dest_map)) {
				$earliest_l2_arrival = min(array_column($L2, 'arrival_timestamp'));
				$l3_after = $earliest_l2_arrival + 60;
				$L3 = self::find_legs_multi(array_values($l2_dest_map), $expanded_target0_nodes, $l3_after, 120);
			}
		}

		if (!empty($L3)) {
			foreach ($L1 as $l1) {
				foreach ($L2 as $l2) {
					if ($l1['mode'] === $l2['mode'] && $l1['journey_ref'] === $l2['journey_ref']) {
						continue;
					}
					$transfer1_found = false;
					$transfer1_sec = 0;
					foreach (self::transfer_nodes($l1['to_node'], $transfer_map) as $transfer) {
						if (self::node_key($transfer['node']) === self::node_key($l2['from_node'])) {
							$transfer1_found = true;
							$transfer1_sec = (int) $transfer['seconds'];
							break;
						}
					}
					if (!$transfer1_found) {
						continue;
					}
					$is_same_node1 = (self::node_key($l1['to_node']) === self::node_key($l2['from_node']));
					$required_transfer_time1 = $is_same_node1 ? 60 : $min_transfer_seconds;
					$min_dep1 = $l1['arrival_timestamp'] + max($required_transfer_time1, $transfer1_sec);
					if ($l2['departure_timestamp'] < $min_dep1) {
						continue;
					}

					foreach ($L3 as $l3) {
						if ($l2['mode'] === $l3['mode'] && $l2['journey_ref'] === $l3['journey_ref']) {
							continue;
						}
						if ($l1['mode'] === $l3['mode'] && $l1['journey_ref'] === $l3['journey_ref']) {
							continue;
						}
						$transfer2_found = false;
						$transfer2_sec = 0;
						foreach (self::transfer_nodes($l2['to_node'], $transfer_map) as $transfer) {
							if (self::node_key($transfer['node']) === self::node_key($l3['from_node'])) {
								$transfer2_found = true;
								$transfer2_sec = (int) $transfer['seconds'];
								break;
							}
						}
						if (!$transfer2_found) {
							continue;
						}
						$is_same_node2 = (self::node_key($l2['to_node']) === self::node_key($l3['from_node']));
						$required_transfer_time2 = $is_same_node2 ? 60 : $min_transfer_seconds;
						$min_dep2 = $l2['arrival_timestamp'] + max($required_transfer_time2, $transfer2_sec);
						if ($l3['departure_timestamp'] < $min_dep2) {
							continue;
						}

						if (isset($destination_keys[self::node_key($l3['to_node'])])) {
							$itineraries[] = array('legs' => array($l1, $l2, $l3));
						}
					}
				}
			}
		}

		$itineraries = self::dedupe_itineraries($itineraries);
		$itineraries = self::collapse_equivalent_itineraries($itineraries);
		$itineraries = self::filter_loop_itineraries($itineraries, $transfer_map);
		$itineraries = self::filter_dominated_itineraries($itineraries);
		usort(
			$itineraries,
			function ($a, $b) {
				$a_last = end($a['legs']);
				$b_last = end($b['legs']);
				$a_score = $a_last['arrival_timestamp'] + ((count($a['legs']) - 1) * 600);
				$b_score = $b_last['arrival_timestamp'] + ((count($b['legs']) - 1) * 600);
				if ($a_score === $b_score) {
					return $a['legs'][0]['departure_timestamp'] <=> $b['legs'][0]['departure_timestamp'];
				}
				return $a_score <=> $b_score;
			}
		);

		return array_slice($itineraries, 0, $limit);
	}

	private static function get_bus_leg_stop_codes(array $leg) {
		global $wpdb;
		static $cache = array();
		$key = $leg['service_journey_pattern_ref'] . '|' . $leg['time_demand_type_ref'] . '|' . $leg['from_order'] . '|' . $leg['to_order'];
		if (isset($cache[$key])) {
			return $cache[$key];
		}
		$res = $wpdb->get_col(
			$wpdb->prepare(
				'
				SELECT DISTINCT q.stopplace_code
				FROM ' . self::table('ovhi', 'stop_offsets') . ' so
				LEFT JOIN ' . self::table('ovhi', 'assignments') . ' ass ON ass.scheduled_stop_point_ref = so.scheduled_stop_point_ref
				LEFT JOIN ' . self::table('ovhi', 'quays') . ' q ON q.quay_code = ass.quay_code
				WHERE so.service_journey_pattern_ref = %s
					AND so.time_demand_type_ref = %s
					AND so.stop_order >= %d
					AND so.stop_order <= %d
				ORDER BY so.stop_order ASC
				',
				$leg['service_journey_pattern_ref'],
				$leg['time_demand_type_ref'],
				(int) $leg['from_order'],
				(int) $leg['to_order']
			)
		);
		$filtered = array();
		if ($res) {
			foreach ($res as $code) {
				if ($code !== null && $code !== '') {
					$filtered[] = (string) $code;
				}
			}
		}
		$cache[$key] = $filtered;
		return $filtered;
	}

	private static function get_train_leg_stop_codes(array $leg) {
		global $wpdb;
		static $cache = array();
		$key = $leg['journey_ref'] . '|' . $leg['from_order'] . '|' . $leg['to_order'];
		if (isset($cache[$key])) {
			return $cache[$key];
		}
		$res = $wpdb->get_col(
			$wpdb->prepare(
				'
				SELECT js.station_code
				FROM ' . self::table('ovtd', 'journey_stops') . ' js
				WHERE js.journey_ref = %s
					AND js.stop_order >= %d
					AND js.stop_order <= %d
				ORDER BY js.stop_order ASC
				',
				$leg['journey_ref'],
				(int) $leg['from_order'],
				(int) $leg['to_order']
			)
		);
		$filtered = array();
		if ($res) {
			foreach ($res as $code) {
				if ($code !== null && $code !== '') {
					$filtered[] = (string) $code;
				}
			}
		}
		$cache[$key] = $filtered;
		return $filtered;
	}

	private static function get_bus_leg_prior_stop_codes(array $leg) {
		global $wpdb;
		static $cache = array();
		$key = $leg['service_journey_pattern_ref'] . '|' . $leg['time_demand_type_ref'] . '|' . $leg['from_order'];
		if (isset($cache[$key])) {
			return $cache[$key];
		}
		$res = $wpdb->get_col(
			$wpdb->prepare(
				'
				SELECT DISTINCT q.stopplace_code
				FROM ' . self::table('ovhi', 'stop_offsets') . ' so
				LEFT JOIN ' . self::table('ovhi', 'assignments') . ' ass ON ass.scheduled_stop_point_ref = so.scheduled_stop_point_ref
				LEFT JOIN ' . self::table('ovhi', 'quays') . ' q ON q.quay_code = ass.quay_code
				WHERE so.service_journey_pattern_ref = %s
					AND so.time_demand_type_ref = %s
					AND so.stop_order < %d
				ORDER BY so.stop_order ASC
				',
				$leg['service_journey_pattern_ref'],
				$leg['time_demand_type_ref'],
				(int) $leg['from_order']
			)
		);
		$filtered = array();
		if ($res) {
			foreach ($res as $code) {
				if ($code !== null && $code !== '') {
					$filtered[] = (string) $code;
				}
			}
		}
		$cache[$key] = $filtered;
		return $filtered;
	}

	private static function get_train_leg_prior_stop_codes(array $leg) {
		global $wpdb;
		static $cache = array();
		$key = $leg['journey_ref'] . '|' . $leg['from_order'];
		if (isset($cache[$key])) {
			return $cache[$key];
		}
		$res = $wpdb->get_col(
			$wpdb->prepare(
				'
				SELECT js.station_code
				FROM ' . self::table('ovtd', 'journey_stops') . ' js
				WHERE js.journey_ref = %s
					AND js.stop_order < %d
				ORDER BY js.stop_order ASC
				',
				$leg['journey_ref'],
				(int) $leg['from_order']
			)
		);
		$filtered = array();
		if ($res) {
			foreach ($res as $code) {
				if ($code !== null && $code !== '') {
					$filtered[] = (string) $code;
				}
			}
		}
		$cache[$key] = $filtered;
		return $filtered;
	}

	private static function has_loop_or_backtrack(array $itinerary, array $transfer_map) {
		$visited = array();
		foreach ($itinerary['legs'] as $leg_index => $leg) {
			if ($leg['mode'] === 'bus') {
				$codes = self::get_bus_leg_stop_codes($leg);
				$stops = array_map(function($c) { return 'bus:' . $c; }, $codes);

				$prior_codes = self::get_bus_leg_prior_stop_codes($leg);
				$prior_stops = array_map(function($c) { return 'bus:' . $c; }, $prior_codes);
			} else {
				$codes = self::get_train_leg_stop_codes($leg);
				$stops = array_map(function($c) { return 'train:' . strtolower($c); }, $codes);

				$prior_codes = self::get_train_leg_prior_stop_codes($leg);
				$prior_stops = array_map(function($c) { return 'train:' . strtolower($c); }, $prior_codes);
			}

			if (!empty($prior_stops)) {
				for ($j = $leg_index; $j < count($itinerary['legs']); $j++) {
					$subseq_dest = self::node_key($itinerary['legs'][$j]['to_node']);
					if (in_array($subseq_dest, $prior_stops, true)) {
						return true;
					}
					if (isset($transfer_map[$subseq_dest])) {
						foreach ($transfer_map[$subseq_dest] as $t) {
							if (in_array(self::node_key($t['node']), $prior_stops, true)) {
								return true;
							}
						}
					}
				}
			}

			if (empty($stops)) {
				continue;
			}

			foreach ($stops as $stop_index => $stop) {
				if ($leg_index > 0 && $stop_index === 0) {
					$prev_leg = $itinerary['legs'][$leg_index - 1];
					if ($prev_leg['mode'] === 'bus') {
						$prev_stops = self::get_bus_leg_stop_codes($prev_leg);
						$prev_last_stop = 'bus:' . end($prev_stops);
					} else {
						$prev_stops = self::get_train_leg_stop_codes($prev_leg);
						$prev_last_stop = 'train:' . strtolower(end($prev_stops));
					}

					if ($stop === $prev_last_stop) {
						continue;
					}

					$is_transfer = false;
					if (isset($transfer_map[$prev_last_stop])) {
						foreach ($transfer_map[$prev_last_stop] as $t) {
							if (self::node_key($t['node']) === $stop) {
								$is_transfer = true;
								break;
							}
						}
					}
					if ($is_transfer) {
						continue;
					}
				}

				if (isset($visited[$stop])) {
					return true;
				}
				if (isset($transfer_map[$stop])) {
					foreach ($transfer_map[$stop] as $t) {
						$t_key = self::node_key($t['node']);
						if (isset($visited[$t_key])) {
							return true;
						}
					}
				}

				$visited[$stop] = true;
			}
		}
		return false;
	}

	private static function filter_loop_itineraries(array $itineraries, array $transfer_map) {
		$result = array();
		foreach ($itineraries as $itinerary) {
			if (!self::has_loop_or_backtrack($itinerary, $transfer_map)) {
				$result[] = $itinerary;
			}
		}
		return $result;
	}

	private static function lookup_stop_refs_and_names(array $stopplace_codes) {
		global $wpdb;
		if (empty($stopplace_codes)) {
			return array('refs' => array(), 'names' => array(), 'stopplace_map' => array());
		}
		$placeholders = implode(',', array_fill(0, count($stopplace_codes), '%s'));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'
				SELECT ass.scheduled_stop_point_ref, q.stopplace_code, sp.stopplace_name
				FROM ' . self::table('ovhi', 'assignments') . ' ass
				INNER JOIN ' . self::table('ovhi', 'quays') . ' q ON q.quay_code = ass.quay_code
				INNER JOIN ' . self::table('ovhi', 'stopplaces') . ' sp ON sp.stopplace_code = q.stopplace_code
				WHERE q.stopplace_code IN (' . $placeholders . ')
				',
				$stopplace_codes
			),
			ARRAY_A
		);
		$refs = array();
		$names = array();
		$stopplace_map = array();
		if ($rows) {
			foreach ($rows as $row) {
				$refs[] = $row['scheduled_stop_point_ref'];
				$names[$row['scheduled_stop_point_ref']] = $row['stopplace_name'];
				$stopplace_map[$row['scheduled_stop_point_ref']] = $row['stopplace_code'];
			}
		}
		return array('refs' => array_values(array_unique($refs)), 'names' => $names, 'stopplace_map' => $stopplace_map);
	}

	private static function lookup_stop_refs(array $stopplace_codes) {
		global $wpdb;
		if (empty($stopplace_codes)) {
			return array();
		}
		$placeholders = implode(',', array_fill(0, count($stopplace_codes), '%s'));
		$refs = $wpdb->get_col(
			$wpdb->prepare(
				'
				SELECT DISTINCT ass.scheduled_stop_point_ref
				FROM ' . self::table('ovhi', 'assignments') . ' ass
				INNER JOIN ' . self::table('ovhi', 'quays') . ' q ON q.quay_code = ass.quay_code
				WHERE q.stopplace_code IN (' . $placeholders . ')
				',
				$stopplace_codes
			)
		);
		return $refs ? array_values(array_unique($refs)) : array();
	}

	private static function get_direct_predecessors(array $target_nodes, array $transfer_map) {
		global $wpdb;

		$expanded_targets = array();
		foreach ($target_nodes as $node) {
			foreach (self::transfer_nodes($node, $transfer_map) as $transfer) {
				$expanded_targets[self::node_key($transfer['node'])] = $transfer['node'];
			}
		}

		$bus_refs = array();
		$train_refs = array();
		foreach ($expanded_targets as $node) {
			if ($node['mode'] === 'bus') {
				$bus_refs[] = $node['ref'];
			} elseif ($node['mode'] === 'train') {
				$train_refs[] = $node['ref'];
			}
		}

		$predecessors = array();

		if (!empty($bus_refs) && self::table_exists('ovhi', 'stop_offsets')) {
			$dest_stop_refs = self::lookup_stop_refs($bus_refs);
			if (!empty($dest_stop_refs)) {
				$placeholders = implode(',', array_fill(0, count($dest_stop_refs), '%s'));
				$sql = '
					SELECT DISTINCT qf.stopplace_code
					FROM ' . self::table('ovhi', 'stop_offsets') . ' fd
					INNER JOIN ' . self::table('ovhi', 'stop_offsets') . ' fo
						ON fo.service_journey_pattern_ref = fd.service_journey_pattern_ref
						AND fo.time_demand_type_ref = fd.time_demand_type_ref
						AND fo.stop_order < fd.stop_order
					INNER JOIN ' . self::table('ovhi', 'assignments') . ' af ON af.scheduled_stop_point_ref = fo.scheduled_stop_point_ref
					INNER JOIN ' . self::table('ovhi', 'quays') . ' qf ON qf.quay_code = af.quay_code
					WHERE fd.scheduled_stop_point_ref IN (' . $placeholders . ')
						AND fd.for_alighting = 1
						AND fo.for_boarding = 1
				';
				$rows = $wpdb->get_col($wpdb->prepare($sql, $dest_stop_refs));
				if ($rows) {
					foreach ($rows as $ref) {
						$node = array('mode' => 'bus', 'ref' => (string) $ref);
						$predecessors[self::node_key($node)] = $node;
					}
				}
			}
		}

		if (!empty($train_refs) && self::table_exists('ovtd', 'journey_stops')) {
			$placeholders = implode(',', array_fill(0, count($train_refs), '%s'));
			$sql = '
				SELECT DISTINCT so.station_code
				FROM ' . self::table('ovtd', 'journey_stops') . ' sd
				INNER JOIN ' . self::table('ovtd', 'journey_stops') . ' so ON so.journey_ref = sd.journey_ref AND so.stop_order < sd.stop_order
				WHERE sd.station_code IN (' . $placeholders . ')
			';
			$rows = $wpdb->get_col($wpdb->prepare($sql, $train_refs));
			if ($rows) {
				foreach ($rows as $ref) {
					$node = array('mode' => 'train', 'ref' => strtolower((string) $ref));
					$predecessors[self::node_key($node)] = $node;
				}
			}
		}

		return array_values($predecessors);
	}

	private static function find_bus_legs_multi(array $from_nodes, array $to_nodes, $after_timestamp, $limit) {
		global $wpdb;
		$legs = array();
		$service_dates = self::candidate_service_dates($after_timestamp);

		$from_refs = array();
		foreach ($from_nodes as $node) {
			if ($node['mode'] === 'bus') {
				$from_refs[] = $node['ref'];
			}
		}
		$to_refs = array();
		foreach ($to_nodes as $node) {
			if ($node['mode'] === 'bus') {
				$to_refs[] = $node['ref'];
			}
		}

		if (empty($from_refs) || empty($to_refs)) {
			return array();
		}

		$from_stop_info = self::lookup_stop_refs_and_names($from_refs);
		$to_stop_info = self::lookup_stop_refs_and_names($to_refs);

		if (empty($from_stop_info['refs']) || empty($to_stop_info['refs'])) {
			return array();
		}

		$from_placeholders = implode(',', array_fill(0, count($from_stop_info['refs']), '%s'));
		$to_placeholders = implode(',', array_fill(0, count($to_stop_info['refs']), '%s'));

		foreach ($service_dates as $service_date) {
			$min_seconds = max(0, self::seconds_from_service_midnight($service_date, $after_timestamp) - 600);
			
			$params = array_merge(
				$from_stop_info['refs'],
				array($service_date, $service_date, $min_seconds),
				$to_stop_info['refs'],
				array(self::leg_sql_limit($limit))
			);

			$sql = '
				SELECT DISTINCT j.journey_ref, j.departure_seconds, a.from_date, a.to_date, a.valid_day_bits,
					l.public_code, l.line_name, l.colour, l.text_colour,
					slp.destination_display,
					fo.service_journey_pattern_ref, fo.time_demand_type_ref,
					fo.offset_seconds AS from_offset, fo.stop_order AS from_order,
					fd.offset_seconds AS to_offset, fd.stop_order AS to_order,
					fo.scheduled_stop_point_ref AS from_stop_ref,
					fd.scheduled_stop_point_ref AS to_stop_ref,
					fo.line_ref, fo.direction_type
				FROM ' . self::table('ovhi', 'stop_offsets') . ' fo
				INNER JOIN ' . self::table('ovhi', 'assignments') . ' af ON af.scheduled_stop_point_ref = fo.scheduled_stop_point_ref
				INNER JOIN ' . self::table('ovhi', 'stop_offsets') . ' fd
					ON fd.service_journey_pattern_ref = fo.service_journey_pattern_ref
					AND fd.time_demand_type_ref = fo.time_demand_type_ref
					AND fd.stop_order > fo.stop_order
				INNER JOIN ' . self::table('ovhi', 'journeys') . ' j
					ON j.service_journey_pattern_ref = fo.service_journey_pattern_ref
					AND j.time_demand_type_ref = fo.time_demand_type_ref
				INNER JOIN ' . self::table('ovhi', 'availability') . ' a ON a.availability_ref = j.availability_ref
				LEFT JOIN ' . self::table('ovhi', 'lines') . ' l ON l.line_ref = fo.line_ref
				LEFT JOIN ' . self::table('ovhi', 'stop_line_patterns') . ' slp
					ON slp.quay_code = af.quay_code
					AND slp.line_ref = fo.line_ref
					AND slp.direction_type = fo.direction_type
					AND slp.service_journey_pattern_ref = fo.service_journey_pattern_ref
				WHERE fo.scheduled_stop_point_ref IN (' . $from_placeholders . ')
					AND fo.for_boarding = 1
					AND fd.for_alighting = 1
					AND a.from_date <= %s
					AND a.to_date >= %s
					AND (j.departure_seconds + fo.offset_seconds) >= %d
					AND fd.scheduled_stop_point_ref IN (' . $to_placeholders . ')
				ORDER BY (j.departure_seconds + fo.offset_seconds) ASC, (j.departure_seconds + fd.offset_seconds) ASC
				LIMIT %d
			';

			$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
			if ($rows) {
				foreach ($rows as $row) {
					if (!self::availability_matches_date($row, $service_date)) {
						continue;
					}
					$departure_timestamp = self::timestamp_from_service_seconds($service_date, (int) $row['departure_seconds'] + (int) $row['from_offset']);
					$arrival_timestamp = self::timestamp_from_service_seconds($service_date, (int) $row['departure_seconds'] + (int) $row['to_offset']);
					if ($departure_timestamp < $after_timestamp || $arrival_timestamp <= $departure_timestamp) {
						continue;
					}

					$from_ref = isset($from_stop_info['stopplace_map'][$row['from_stop_ref']]) ? $from_stop_info['stopplace_map'][$row['from_stop_ref']] : '';
					$from_name = isset($from_stop_info['names'][$row['from_stop_ref']]) ? $from_stop_info['names'][$row['from_stop_ref']] : '';
					$to_ref = isset($to_stop_info['stopplace_map'][$row['to_stop_ref']]) ? $to_stop_info['stopplace_map'][$row['to_stop_ref']] : '';
					$to_name = isset($to_stop_info['names'][$row['to_stop_ref']]) ? $to_stop_info['names'][$row['to_stop_ref']] : '';

					$row['to_name'] = $to_name;

					$legs[] = array(
						'mode' => 'bus',
						'journey_ref' => (string) $row['journey_ref'],
						'service_date' => $service_date,
						'base_departure_seconds' => (int) $row['departure_seconds'],
						'service_journey_pattern_ref' => (string) $row['service_journey_pattern_ref'],
						'time_demand_type_ref' => (string) $row['time_demand_type_ref'],
						'from_order' => (int) $row['from_order'],
						'to_order' => (int) $row['to_order'],
						'line' => trim((string) $row['public_code']) !== '' ? trim((string) $row['public_code']) : 'Bus',
						'line_name' => self::bus_direction_label($row),
						'colour' => self::sanitize_hex_colour(isset($row['colour']) ? (string) $row['colour'] : ''),
						'text_colour' => self::sanitize_hex_colour(isset($row['text_colour']) ? (string) $row['text_colour'] : ''),
						'from_node' => array('mode' => 'bus', 'ref' => $from_ref, 'label' => $from_name),
						'to_node' => array('mode' => 'bus', 'ref' => $to_ref, 'label' => $to_name),
						'departure_timestamp' => $departure_timestamp,
						'arrival_timestamp' => $arrival_timestamp,
						'line_ref' => (string) $row['line_ref'],
						'direction_type' => (string) $row['direction_type'],
						'from_date' => (string) $row['from_date'],
					);
				}
			}
		}

		$legs = self::filter_duplicate_legs($legs);
		$legs = self::add_notices_to_legs($legs);
		return self::sort_and_limit_legs($legs, $limit);
	}

	private static function find_train_legs_multi(array $from_nodes, array $to_nodes, $after_timestamp, $limit) {
		global $wpdb;
		$legs = array();
		$service_dates = self::candidate_service_dates($after_timestamp);
		$dataset_from = self::train_dataset_from();

		$from_refs = array();
		foreach ($from_nodes as $node) {
			if ($node['mode'] === 'train') {
				$from_refs[] = $node['ref'];
			}
		}
		$to_refs = array();
		foreach ($to_nodes as $node) {
			if ($node['mode'] === 'train') {
				$to_refs[] = $node['ref'];
			}
		}

		if (empty($from_refs) || empty($to_refs)) {
			return array();
		}

		$from_placeholders = implode(',', array_fill(0, count($from_refs), '%s'));
		$to_placeholders = implode(',', array_fill(0, count($to_refs), '%s'));

		foreach ($service_dates as $service_date) {
			$min_seconds = max(0, self::seconds_from_service_midnight($service_date, $after_timestamp) - 600);
			
			$params = array_merge(
				$from_refs,
				array($min_seconds),
				$to_refs,
				array(self::leg_sql_limit($limit))
			);

			$sql = '
				SELECT j.journey_ref, j.train_number, j.footnote_ref, j.departure_seconds AS journey_departure_seconds,
					d.train_type, d.destination_name,
					so.station_code AS from_ref, sfo.station_name AS from_name,
					sd.station_code AS to_ref, sfd.station_name AS to_name,
					so.departure_seconds AS from_departure, so.arrival_seconds AS from_arrival, so.stop_order AS from_order,
					sd.arrival_seconds AS to_arrival, sd.departure_seconds AS to_departure, sd.stop_order AS to_order,
					f.run_bits, f.not_run_bits,
					j.attributes
				FROM ' . self::table('ovtd', 'journey_stops') . ' so
				INNER JOIN ' . self::table('ovtd', 'journey_stops') . ' sd
					ON sd.journey_ref = so.journey_ref
					AND sd.stop_order > so.stop_order
				INNER JOIN ' . self::table('ovtd', 'journeys') . ' j ON j.journey_ref = so.journey_ref
				LEFT JOIN ' . self::table('ovtd', 'directions') . ' d ON d.direction_ref = j.direction_ref
				LEFT JOIN ' . self::table('ovtd', 'stations') . ' sfo ON sfo.station_code = so.station_code
				LEFT JOIN ' . self::table('ovtd', 'stations') . ' sfd ON sfd.station_code = sd.station_code
				LEFT JOIN ' . self::table('ovtd', 'footnotes') . ' f ON f.footnote_ref = j.footnote_ref
				WHERE so.station_code IN (' . $from_placeholders . ')
					AND COALESCE(NULLIF(so.departure_seconds, -1), so.arrival_seconds) >= %d
					AND sd.station_code IN (' . $to_placeholders . ')
				ORDER BY COALESCE(NULLIF(so.departure_seconds, -1), so.arrival_seconds) ASC,
					COALESCE(NULLIF(sd.arrival_seconds, -1), sd.departure_seconds) ASC
				LIMIT %d
			';

			$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
			if ($rows) {
				foreach ($rows as $row) {
					if (!self::footnote_matches_date($row, $service_date, $dataset_from)) {
						continue;
					}
					$departure_seconds = (int) $row['from_departure'];
					if ($departure_seconds < 0) {
						$departure_seconds = (int) $row['from_arrival'];
					}
					$arrival_seconds = (int) $row['to_arrival'];
					if ($arrival_seconds < 0) {
						$arrival_seconds = (int) $row['to_departure'];
					}
					if ($departure_seconds < 0 || $arrival_seconds < 0) {
						continue;
					}
					$departure_timestamp = self::timestamp_from_service_seconds($service_date, $departure_seconds);
					$arrival_timestamp = self::timestamp_from_service_seconds($service_date, $arrival_seconds);
					if ($departure_timestamp < $after_timestamp || $arrival_timestamp <= $departure_timestamp) {
						continue;
					}
					$legs[] = array(
						'mode' => 'train',
						'journey_ref' => (string) $row['journey_ref'],
						'service_date' => $service_date,
						'from_order' => (int) $row['from_order'],
						'to_order' => (int) $row['to_order'],
						'line' => self::train_badge((string) $row['train_type']),
						'line_name' => trim((string) $row['destination_name']) !== '' ? 'richting ' . trim((string) $row['destination_name']) : '',
						'colour' => '',
						'text_colour' => '',
						'attributes' => isset($row['attributes']) ? (string) $row['attributes'] : '',
						'from_node' => array('mode' => 'train', 'ref' => strtolower((string) $row['from_ref']), 'label' => (string) $row['from_name']),
						'to_node' => array('mode' => 'train', 'ref' => strtolower((string) $row['to_ref']), 'label' => (string) $row['to_name']),
						'departure_timestamp' => $departure_timestamp,
						'arrival_timestamp' => $arrival_timestamp,
					);
				}
			}
		}

		return self::sort_and_limit_legs($legs, $limit);
	}

	private static function find_legs_multi(array $from_nodes, array $to_nodes, $after_timestamp, $limit) {
		$legs = array();

		$bus_from = array();
		$train_from = array();
		foreach ($from_nodes as $node) {
			if ($node['mode'] === 'bus') {
				$bus_from[] = $node;
			} elseif ($node['mode'] === 'train') {
				$train_from[] = $node;
			}
		}

		$bus_to = array();
		$train_to = array();
		foreach ($to_nodes as $node) {
			if ($node['mode'] === 'bus') {
				$bus_to[] = $node;
			} elseif ($node['mode'] === 'train') {
				$train_to[] = $node;
			}
		}

		if (!empty($bus_from) && !empty($bus_to)) {
			$legs = array_merge($legs, self::find_bus_legs_multi($bus_from, $bus_to, $after_timestamp, $limit));
		}
		if (!empty($train_from) && !empty($train_to)) {
			$legs = array_merge($legs, self::find_train_legs_multi($train_from, $train_to, $after_timestamp, $limit));
		}

		usort(
			$legs,
			function ($a, $b) {
				if ($a['departure_timestamp'] === $b['departure_timestamp']) {
					return $a['arrival_timestamp'] <=> $b['arrival_timestamp'];
				}
				return $a['departure_timestamp'] <=> $b['departure_timestamp'];
			}
		);

		return array_slice($legs, 0, $limit);
	}

	private static function find_legs(array $from, $to, $after_timestamp, $limit) {
		static $cache = array();

		$to_key = $to === null ? '*' : self::node_key($to);
		$cache_key = implode('|', array(self::node_key($from), $to_key, (string) (int) $after_timestamp, (string) (int) $limit));
		if (isset($cache[$cache_key])) {
			return $cache[$cache_key];
		}

		$legs = array();
		if ($from['mode'] === 'bus' && ($to === null || $to['mode'] === 'bus')) {
			$legs = array_merge($legs, self::find_bus_legs($from, $to, $after_timestamp, $limit));
		}
		if ($from['mode'] === 'train' && ($to === null || $to['mode'] === 'train')) {
			$legs = array_merge($legs, self::find_train_legs($from, $to, $after_timestamp, $limit));
		}

		usort(
			$legs,
			function ($a, $b) {
				if ($a['departure_timestamp'] === $b['departure_timestamp']) {
					return $a['arrival_timestamp'] <=> $b['arrival_timestamp'];
				}
				return $a['departure_timestamp'] <=> $b['departure_timestamp'];
			}
		);

		$result = array_slice($legs, 0, $limit);
		$cache[$cache_key] = $result;
		return $result;
	}

	private static function find_bus_legs(array $from, $to, $after_timestamp, $limit) {
		global $wpdb;
		$legs = array();
		$service_dates = self::candidate_service_dates($after_timestamp);
		$destination_sql = '';
		$destination_params = array();
		if ($to !== null) {
			$destination_sql = 'AND qd.stopplace_code = %s';
			$destination_params[] = $to['ref'];
		}

		foreach ($service_dates as $service_date) {
			$min_seconds = max(0, self::seconds_from_service_midnight($service_date, $after_timestamp) - 600);
			$params = array_merge(
				array($from['ref'], $service_date, $service_date, $min_seconds),
				$destination_params,
				array(self::leg_sql_limit($limit))
			);
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'
					SELECT DISTINCT j.journey_ref, j.departure_seconds, a.from_date, a.to_date, a.valid_day_bits,
						l.public_code, l.line_name, l.colour, l.text_colour,
						slp.destination_display,
						fo.service_journey_pattern_ref, fo.time_demand_type_ref,
						fo.offset_seconds AS from_offset, fo.stop_order AS from_order,
						fd.offset_seconds AS to_offset, fd.stop_order AS to_order,
						spf.stopplace_code AS from_ref, spf.stopplace_name AS from_name,
						spd.stopplace_code AS to_ref, spd.stopplace_name AS to_name,
						fo.line_ref, fo.direction_type
					FROM ' . self::table('ovhi', 'stop_offsets') . ' fo
					INNER JOIN ' . self::table('ovhi', 'assignments') . ' af ON af.scheduled_stop_point_ref = fo.scheduled_stop_point_ref
					INNER JOIN ' . self::table('ovhi', 'quays') . ' qf ON qf.quay_code = af.quay_code
					INNER JOIN ' . self::table('ovhi', 'stopplaces') . ' spf ON spf.stopplace_code = qf.stopplace_code
					INNER JOIN ' . self::table('ovhi', 'stop_offsets') . ' fd
						ON fd.service_journey_pattern_ref = fo.service_journey_pattern_ref
						AND fd.time_demand_type_ref = fo.time_demand_type_ref
						AND fd.stop_order > fo.stop_order
					INNER JOIN ' . self::table('ovhi', 'assignments') . ' ad ON ad.scheduled_stop_point_ref = fd.scheduled_stop_point_ref
					INNER JOIN ' . self::table('ovhi', 'quays') . ' qd ON qd.quay_code = ad.quay_code
					INNER JOIN ' . self::table('ovhi', 'stopplaces') . ' spd ON spd.stopplace_code = qd.stopplace_code
					INNER JOIN ' . self::table('ovhi', 'journeys') . ' j
						ON j.service_journey_pattern_ref = fo.service_journey_pattern_ref
						AND j.time_demand_type_ref = fo.time_demand_type_ref
					INNER JOIN ' . self::table('ovhi', 'availability') . ' a ON a.availability_ref = j.availability_ref
					LEFT JOIN ' . self::table('ovhi', 'lines') . ' l ON l.line_ref = fo.line_ref
					LEFT JOIN ' . self::table('ovhi', 'stop_line_patterns') . ' slp
						ON slp.quay_code = qf.quay_code
						AND slp.line_ref = fo.line_ref
						AND slp.direction_type = fo.direction_type
						AND slp.service_journey_pattern_ref = fo.service_journey_pattern_ref
					WHERE qf.stopplace_code = %s
						AND fo.for_boarding = 1
						AND fd.for_alighting = 1
						AND a.from_date <= %s
						AND a.to_date >= %s
						AND (j.departure_seconds + fo.offset_seconds) >= %d
						' . $destination_sql . '
					ORDER BY (j.departure_seconds + fo.offset_seconds) ASC, (j.departure_seconds + fd.offset_seconds) ASC
					LIMIT %d
					',
					$params
				),
				ARRAY_A
			);

			foreach ($rows as $row) {
				if (!self::availability_matches_date($row, $service_date)) {
					continue;
				}
				$departure_timestamp = self::timestamp_from_service_seconds($service_date, (int) $row['departure_seconds'] + (int) $row['from_offset']);
				$arrival_timestamp = self::timestamp_from_service_seconds($service_date, (int) $row['departure_seconds'] + (int) $row['to_offset']);
				if ($departure_timestamp < $after_timestamp || $arrival_timestamp <= $departure_timestamp) {
					continue;
				}
				$legs[] = array(
					'mode' => 'bus',
					'journey_ref' => (string) $row['journey_ref'],
					'service_date' => $service_date,
					'base_departure_seconds' => (int) $row['departure_seconds'],
					'service_journey_pattern_ref' => (string) $row['service_journey_pattern_ref'],
					'time_demand_type_ref' => (string) $row['time_demand_type_ref'],
					'from_order' => (int) $row['from_order'],
					'to_order' => (int) $row['to_order'],
					'line' => trim((string) $row['public_code']) !== '' ? trim((string) $row['public_code']) : 'Bus',
					'line_name' => self::bus_direction_label($row),
					'colour' => self::sanitize_hex_colour(isset($row['colour']) ? (string) $row['colour'] : ''),
					'text_colour' => self::sanitize_hex_colour(isset($row['text_colour']) ? (string) $row['text_colour'] : ''),
					'from_node' => array('mode' => 'bus', 'ref' => (string) $row['from_ref'], 'label' => (string) $row['from_name']),
					'to_node' => array('mode' => 'bus', 'ref' => (string) $row['to_ref'], 'label' => (string) $row['to_name']),
					'departure_timestamp' => $departure_timestamp,
					'arrival_timestamp' => $arrival_timestamp,
					'line_ref' => (string) $row['line_ref'],
					'direction_type' => (string) $row['direction_type'],
					'from_date' => (string) $row['from_date'],
				);
			}
		}

		$legs = self::filter_duplicate_legs($legs);
		$legs = self::add_notices_to_legs($legs);
		return self::sort_and_limit_legs($legs, $limit);
	}

	private static function filter_duplicate_legs(array $legs) {
		if (empty($legs)) {
			return array();
		}

		// Group by line_ref, direction_type, base_departure_seconds, and service_date
		$groups = array();
		foreach ($legs as $leg) {
			$key = $leg['line_ref'] . '|' . $leg['direction_type'] . '|' . $leg['base_departure_seconds'] . '|' . $leg['service_date'];
			$groups[$key][] = $leg;
		}

		$filtered = array();
		foreach ($groups as $key => $group) {
			if (count($group) > 1) {
				// Sort by from_date descending
				usort($group, function ($a, $b) {
					return strcmp($b['from_date'], $a['from_date']);
				});
			}
			$filtered[] = $group[0];
		}

		return $filtered;
	}

	private static function find_train_legs(array $from, $to, $after_timestamp, $limit) {
		global $wpdb;
		$legs = array();
		$service_dates = self::candidate_service_dates($after_timestamp);
		$dataset_from = self::train_dataset_from();
		$destination_sql = '';
		$destination_params = array();
		if ($to !== null) {
			$destination_sql = 'AND sd.station_code = %s';
			$destination_params[] = $to['ref'];
		}

		foreach ($service_dates as $service_date) {
			$min_seconds = max(0, self::seconds_from_service_midnight($service_date, $after_timestamp) - 600);
			$params = array_merge(
				array($from['ref'], $min_seconds),
				$destination_params,
				array(self::leg_sql_limit($limit))
			);
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'
					SELECT j.journey_ref, j.train_number, j.footnote_ref, j.departure_seconds AS journey_departure_seconds,
						d.train_type, d.destination_name,
						so.station_code AS from_ref, sfo.station_name AS from_name,
						sd.station_code AS to_ref, sfd.station_name AS to_name,
						so.departure_seconds AS from_departure, so.arrival_seconds AS from_arrival, so.stop_order AS from_order,
						sd.arrival_seconds AS to_arrival, sd.departure_seconds AS to_departure, sd.stop_order AS to_order,
						f.run_bits, f.not_run_bits,
						j.attributes
					FROM ' . self::table('ovtd', 'journey_stops') . ' so
					INNER JOIN ' . self::table('ovtd', 'journey_stops') . ' sd
						ON sd.journey_ref = so.journey_ref
						AND sd.stop_order > so.stop_order
					INNER JOIN ' . self::table('ovtd', 'journeys') . ' j ON j.journey_ref = so.journey_ref
					LEFT JOIN ' . self::table('ovtd', 'directions') . ' d ON d.direction_ref = j.direction_ref
					LEFT JOIN ' . self::table('ovtd', 'stations') . ' sfo ON sfo.station_code = so.station_code
					LEFT JOIN ' . self::table('ovtd', 'stations') . ' sfd ON sfd.station_code = sd.station_code
					LEFT JOIN ' . self::table('ovtd', 'footnotes') . ' f ON f.footnote_ref = j.footnote_ref
					WHERE so.station_code = %s
						AND COALESCE(NULLIF(so.departure_seconds, -1), so.arrival_seconds) >= %d
						' . $destination_sql . '
					ORDER BY COALESCE(NULLIF(so.departure_seconds, -1), so.arrival_seconds) ASC,
						COALESCE(NULLIF(sd.arrival_seconds, -1), sd.departure_seconds) ASC
					LIMIT %d
					',
					$params
				),
				ARRAY_A
			);

			foreach ($rows as $row) {
				if (!self::footnote_matches_date($row, $service_date, $dataset_from)) {
					continue;
				}
				$departure_seconds = (int) $row['from_departure'];
				if ($departure_seconds < 0) {
					$departure_seconds = (int) $row['from_arrival'];
				}
				$arrival_seconds = (int) $row['to_arrival'];
				if ($arrival_seconds < 0) {
					$arrival_seconds = (int) $row['to_departure'];
				}
				if ($departure_seconds < 0 || $arrival_seconds < 0) {
					continue;
				}
				$departure_timestamp = self::timestamp_from_service_seconds($service_date, $departure_seconds);
				$arrival_timestamp = self::timestamp_from_service_seconds($service_date, $arrival_seconds);
				if ($departure_timestamp < $after_timestamp || $arrival_timestamp <= $departure_timestamp) {
					continue;
				}
				$legs[] = array(
					'mode' => 'train',
					'journey_ref' => (string) $row['journey_ref'],
					'service_date' => $service_date,
					'from_order' => (int) $row['from_order'],
					'to_order' => (int) $row['to_order'],
					'line' => self::train_badge((string) $row['train_type']),
					'line_name' => trim((string) $row['destination_name']) !== '' ? 'richting ' . trim((string) $row['destination_name']) : '',
					'colour' => '',
					'text_colour' => '',
					'attributes' => isset($row['attributes']) ? (string) $row['attributes'] : '',
					'from_node' => array('mode' => 'train', 'ref' => (string) $row['from_ref'], 'label' => (string) $row['from_name']),
					'to_node' => array('mode' => 'train', 'ref' => (string) $row['to_ref'], 'label' => (string) $row['to_name']),
					'departure_timestamp' => $departure_timestamp,
					'arrival_timestamp' => $arrival_timestamp,
				);
			}
		}

		return self::sort_and_limit_legs($legs, $limit);
	}

	private static function leg_sql_limit($limit) {
		$limit = max(1, (int) $limit);
		return min(self::LEG_SQL_LIMIT_MAX, max(30, $limit * self::LEG_SQL_LIMIT_MULTIPLIER));
	}

	private static function render_results(array $itineraries, array $nodes, $min_transfer_seconds, $page, $page_size) {
		if (empty($itineraries)) {
			return '<p>Geen geplande reis gevonden. Controleer ook of een bus-trein-overstap expliciet gekoppeld moet worden.</p>';
		}

		$page = max(0, (int) $page);
		$page_size = max(1, (int) $page_size);
		$offset = $page * $page_size;
		$visible_itineraries = array_slice($itineraries, $offset, $page_size);
		$has_previous = $page > 0;
		$has_next = count($itineraries) > ($offset + $page_size);

		if (empty($visible_itineraries) && $page > 0) {
			return self::render_result_nav($page, $has_previous, false, $page_size) . '<p>Er zijn geen verdere geplande reizen gevonden.</p>';
		}

		$html = '<div class="ovrp-results">';
		$html .= '<p class="ovrp-note">Geplande opties ' . esc_html((string) ($offset + 1)) . '-' . esc_html((string) ($offset + count($visible_itineraries))) . ', zonder realtime vertragingen.</p>';
		$html .= self::render_result_nav($page, $has_previous, $has_next, $page_size);
		foreach ($visible_itineraries as $itinerary) {
			$first = $itinerary['legs'][0];
			$last = $itinerary['legs'][count($itinerary['legs']) - 1];
			$has_train = false;
$has_bus = false;

foreach ($itinerary['legs'] as $leg) {

    if ($leg['mode'] === 'train') {
        $has_train = true;
    }

    if ($leg['mode'] === 'bus') {
        $has_bus = true;
    }
}

if ($has_train && $has_bus) {
    $badge_text = 'T+B';
} elseif ($has_train) {
    $badge_text = 'Trein';
} else {
    $badge_text = 'Bus';
}
			$html .= '<article class="ovrp-card" role="button" tabindex="0" aria-label="Toon alle tussenhaltes van deze reis">';
			$html .= '<div class="ovrp-card-head">';
			$html .= self::render_badge($first, 'ovrp-mobile-badge', $badge_text);
			$html .= '<span class="ovrp-card-trip">' . esc_html(self::format_timestamp($first['departure_timestamp']) . ' - ' . self::format_timestamp($last['arrival_timestamp'])) . ' van ' . esc_html(self::node_label($first['from_node'], $nodes)). ' naar ' . esc_html(self::node_label($last['to_node'], $nodes)) . '</span>';
			$html .= '<span class="ovrp-card-action">Alle haltes</span>';
			$html .= '</div>';
			$html .= '<div class="ovrp-card-meta">' . esc_html(self::duration_label($last['arrival_timestamp'] - $first['departure_timestamp'])) . ' / ' . esc_html((string) (count($itinerary['legs']) - 1)) . ' overstap(pen)</div>';
			$html .= '<div class="ovrp-mobile-card-stops">';
			foreach ($itinerary['legs'] as $index => $leg) {
				if ($index > 0) {
					$previous = $itinerary['legs'][$index - 1];
					$html .= '<div class="ovrp-transfer">Overstap: ' . esc_html(self::duration_label($leg['departure_timestamp'] - $previous['arrival_timestamp'])) . '</div>';
				}
				$html .= '<div class="ovrp-mobile-stop-row">';
				$html .= '<span>' . self::render_badge($leg, 'ovrp-badge', ($leg['mode'] === 'train' ? 'Trein ' : 'Buslijn ') . $leg['line']) . ' ' . esc_html(self::node_label($leg['from_node'], $nodes)) . ' naar ' . esc_html(self::node_label($leg['to_node'], $nodes)) . '</span>';
				$html .= '<strong>' . esc_html(self::format_timestamp($leg['departure_timestamp']) . '-' . self::format_timestamp($leg['arrival_timestamp'])) . '</strong>';
				if ($leg['line_name'] !== '') {
					$html .= '<small>' . esc_html($leg['line_name']) . '</small>';
				}
				$amenity_html = self::render_amenity_icons($leg);
				if ($amenity_html !== '') {
					$html .= '<div class="ovrp-amenities">' . $amenity_html . '</div>';
				}
				if (!empty($leg['notice'])) {
					$html .= '<div class="ovrp-leg-notice">⚠️ ' . esc_html($leg['notice']) . '</div>';
				}
				$html .= '</div>';
			}
			$html .= '</div>';
			$html .= self::render_itinerary_detail($itinerary, $nodes);
			$html .= '</article>';
		}
		$html .= self::render_result_nav($page, $has_previous, $has_next, $page_size);
		$html .= '</div>';
		return $html;
	}

	private static function render_result_nav($page, $has_previous, $has_next, $page_size) {
		if (!$has_previous && !$has_next) {
			return '';
		}

		$html = '<nav class="ovrp-result-nav" aria-label="Reisopties">';
		if ($has_previous) {
			$html .= '<a href="' . esc_url(self::page_url(max(0, (int) $page - 1))) . '">&larr; Vorige ' . esc_html((string) $page_size) . ' ritten</a>';
		} else {
			$html .= '<span></span>';
		}
		if ($has_next) {
			$html .= '<a href="' . esc_url(self::page_url((int) $page + 1)) . '">Volgende ' . esc_html((string) $page_size) . ' ritten &rarr;</a>';
		}
		$html .= '</nav>';
		return $html;
	}

	private static function render_badge(array $leg, $class_name, $label) {
		$background = isset($leg['colour']) ? self::sanitize_hex_colour((string) $leg['colour']) : '';
		$text = isset($leg['text_colour']) ? self::sanitize_hex_colour((string) $leg['text_colour']) : '';
		$style = '';
		if ($background !== '') {
			if ($text === '') {
				$text = self::contrast_text_colour($background);
			}
			$style = ' style="background-color:' . esc_attr($background) . ';color:' . esc_attr($text) . ';"';
		}
		return '<span class="' . esc_attr($class_name) . '"' . $style . '>' . esc_html($label) . '</span>';
	}

	private static function render_itinerary_detail(array $itinerary, array $nodes) {
		$html = '<div class="ovrp-mobile-detail" hidden>';
		$html .= '<div class="ovrp-mobile-detail-title">Volledige reis</div>';
		foreach ($itinerary['legs'] as $index => $leg) {
			if ($index > 0) {
				$previous = $itinerary['legs'][$index - 1];
				$html .= '<div class="ovrp-mobile-detail-row ovrp-mobile-detail-transfer"><span>Overstap</span><strong>' . esc_html(self::duration_label($leg['departure_timestamp'] - $previous['arrival_timestamp'])) . '</strong></div>';
			}
			$html .= '<div class="ovrp-mobile-detail-title">' . esc_html(($leg['mode'] === 'train' ? 'Trein ' : 'Bus ') . $leg['line']) . ': ' . esc_html(self::node_label($leg['from_node'], $nodes)) . ' naar ' . esc_html(self::node_label($leg['to_node'], $nodes)) . '</div>';
			$detail_amenity_html = self::render_amenity_icons($leg);
			if ($detail_amenity_html !== '') {
				$html .= '<div class="ovrp-amenities ovrp-detail-amenities">' . $detail_amenity_html . '</div>';
			}
			if (!empty($leg['notice'])) {
				$html .= '<div class="ovrp-mobile-detail-notice">⚠️ ' . esc_html($leg['notice']) . '</div>';
			}
			$rows = self::get_leg_detail_rows($leg);
			foreach ($rows as $row) {
				$html .= '<div class="ovrp-mobile-detail-row">';
				$html .= '<span>' . esc_html($row['name']);
				if (!empty($row['platform'])) {
					$html .= '<small>' . esc_html($row['platform']) . '</small>';
				}
				$html .= '</span>';
				$html .= '<strong>' . esc_html($row['time']) . '</strong>';
				$html .= '</div>';
			}
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Returns HTML for amenity icons (rolstoel, wifi, toilet, fiets, stiltezone)
	 * based on IFF journey attributes stored in the 'attributes' field of a leg.
	 * Only shown for train legs; bus legs have no IFF attribute data.
	 */
	private static function render_amenity_icons(array $leg) {
		if ($leg['mode'] !== 'train' || empty($leg['attributes'])) {
			return '';
		}

		$raw = (string) $leg['attributes'];
		// IFF attributes are stored as a comma- or space-separated list of codes
		$codes = preg_split('/[\s,]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY);
		if (empty($codes)) {
			return '';
		}

		// Map: attribute code => [title, SVG path]
		$amenity_map = array(
			// Rolstoel toegankelijk
			'ROL'  => array(
				'title' => 'Rolstoel toegankelijk',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="4" r="1"/><path d="m18 8-4-1h-1.5c-.8 0-1.5.6-1.7 1.4L9.2 14H6"/><path d="M9 18h5l2.7 5"/><circle cx="10" cy="18" r="3"/><path d="M13 18a3 3 0 0 1-3-3"/></svg>',
			),
			// Niet rolstoel toegankelijk
			'NROL' => array(
				'title' => 'Niet rolstoel toegankelijk',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="4" r="1"/><path d="m18 8-4-1h-1.5c-.8 0-1.5.6-1.7 1.4L9.2 14H6"/><path d="M9 18h5l2.7 5"/><circle cx="10" cy="18" r="3"/><path d="M13 18a3 3 0 0 1-3-3"/><line x1="22" y1="2" x2="2" y2="22"/></svg>',
			),
			// Wifi beschikbaar
			'WIFI' => array(
				'title' => 'Wifi beschikbaar',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h.01"/><path d="M8.5 16.5a5 5 0 0 1 7 0"/><path d="M5 13a10 10 0 0 1 14 0"/><path d="M1.5 9.5a15 15 0 0 1 21 0"/></svg>',
			),
			// Toilet / Sprinter zonder toilet (negatief signaal)
			'SPRZ' => array(
				'title' => 'Geen toilet aan boord',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 22V12h4v10"/><path d="M12 4a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/><path d="M6 12h12"/><path d="M14 7h-4L7 11v5h2v6h6v-6h2v-5z"/><line x1="22" y1="2" x2="2" y2="22"/></svg>',
			),
			// Restauratie / Bar / Bistro / Kops (voedsel en drank)
			'REST' => array(
				'title' => 'Restauratie aan boord',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2v0a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>',
			),
			'BAR'  => array(
				'title' => 'Bar/Buffet aan boord',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2v0a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>',
			),
			'BIST' => array(
				'title' => 'Bistro aan boord',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2v0a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>',
			),
			'KOPS' => array(
				'title' => 'Warme en koude dranken',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2v0a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>',
			),
			// Fiets meenemen mogelijk
			'FIET' => array(
				'title' => 'Fietsvervoer mogelijk',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="18.5" cy="17.5" r="2.5"/><circle cx="5.5" cy="17.5" r="2.5"/><path d="M9 17.5h6"/><path d="M12 11.5V14"/><path d="M12 11.5 16 7.5h1.5"/><path d="M16 11.5h-5.5l-3.5-7H3"/></svg>',
			),
			// Fiets meenemen beperkt mogelijk
			'FMBM' => array(
				'title' => 'Fiets meenemen beperkt mogelijk',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="18.5" cy="17.5" r="2.5"/><circle cx="5.5" cy="17.5" r="2.5"/><path d="M9 17.5h6"/><path d="M12 11.5V14"/><path d="M12 11.5 16 7.5h1.5"/><path d="M16 11.5h-5.5l-3.5-7H3"/><path d="M12 2v3"/><circle cx="12" cy="7" r="0.5" fill="white"/></svg>',
			),
			// Fiets NIET meenemen
			'FINI' => array(
				'title' => 'Fiets meenemen niet mogelijk',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="18.5" cy="17.5" r="2.5"/><circle cx="5.5" cy="17.5" r="2.5"/><path d="M9 17.5h6"/><path d="M12 11.5V14"/><path d="M12 11.5 16 7.5h1.5"/><path d="M16 11.5h-5.5l-3.5-7H3"/><line x1="22" y1="2" x2="2" y2="22"/></svg>',
			),
			// Stiltezone
			'STIL' => array(
				'title' => 'Stiltezone aanwezig',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="22" y1="9" x2="16" y2="15"/><line x1="16" y1="9" x2="22" y2="15"/></svg>',
			),
			// Stopcontacten
			'STOP' => array(
				'title' => 'Stopcontacten beschikbaar',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v6"/><path d="M6 8h12v6a6 6 0 0 1-12 0V8Z"/><path d="m9 14-.5 6a1 1 0 0 0 1 1h5a1 1 0 0 0 1-1l-.5-6"/></svg>',
			),
			// Ligwagen
			'LIGW' => array(
				'title' => 'Ligwagen',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><circle cx="6" cy="12" r="2"/></svg>',
			),
			// Slaapwagen
			'SLP'  => array(
				'title' => 'Slaapwagen',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><circle cx="6" cy="12" r="2"/></svg>',
			),
			// Eerste klas
			'EKL'  => array(
				'title' => 'Eerste klas',
				'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
			),
		);

		// Deduplicate: show REST/BAR/BIST/KOPS as a single icon
		$shown = array();
		$html = '';
		$food_codes = array('REST', 'BAR', 'BIST', 'KOPS');
		$food_shown = false;

		foreach ($codes as $code) {
			if (in_array($code, $food_codes, true)) {
				if ($food_shown) {
					continue;
				}
				$food_shown = true;
				$code = 'REST'; // normalize to single representative
			}
			if (!isset($amenity_map[$code]) || isset($shown[$code])) {
				continue;
			}
			$shown[$code] = true;
			$item = $amenity_map[$code];
			$html .= '<span class="ovrp-amenity" data-title="' . esc_attr($item['title']) . '" aria-label="' . esc_attr($item['title']) . '">' . $item['svg'] . '</span>';
		}

		return $html;
	}

	private static function get_leg_detail_rows(array $leg) {
		if ($leg['mode'] === 'bus') {
			return self::get_bus_leg_detail_rows($leg);
		}
		if ($leg['mode'] === 'train') {
			return self::get_train_leg_detail_rows($leg);
		}
		return array();
	}

	private static function get_bus_leg_detail_rows(array $leg) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'
				SELECT so.offset_seconds, s.stop_name, q.quay_name, q.stopplace_code,
					sp.stopplace_name
				FROM ' . self::table('ovhi', 'stop_offsets') . ' so
				INNER JOIN ' . self::table('ovhi', 'scheduled_stops') . ' s ON s.scheduled_stop_point_ref = so.scheduled_stop_point_ref
				LEFT JOIN ' . self::table('ovhi', 'assignments') . ' ass ON ass.scheduled_stop_point_ref = so.scheduled_stop_point_ref
				LEFT JOIN ' . self::table('ovhi', 'quays') . ' q ON q.quay_code = ass.quay_code
				LEFT JOIN ' . self::table('ovhi', 'stopplaces') . ' sp ON sp.stopplace_code = q.stopplace_code
				WHERE so.service_journey_pattern_ref = %s
					AND so.time_demand_type_ref = %s
					AND so.stop_order >= %d
					AND so.stop_order <= %d
				ORDER BY so.stop_order ASC, so.offset_seconds ASC
				',
				$leg['service_journey_pattern_ref'],
				$leg['time_demand_type_ref'],
				(int) $leg['from_order'],
				(int) $leg['to_order']
			),
			ARRAY_A
		);

		$quay_names_by_stopplace = array();
		foreach ($rows as $row) {
			$stopplace_code = isset($row['stopplace_code']) ? (string) $row['stopplace_code'] : '';
			$quay_name = isset($row['quay_name']) ? trim((string) $row['quay_name']) : '';
			if ($stopplace_code === '' || $quay_name === '') {
				continue;
			}
			if (!isset($quay_names_by_stopplace[$stopplace_code])) {
				$quay_names_by_stopplace[$stopplace_code] = array();
			}
			$quay_names_by_stopplace[$stopplace_code][$quay_name] = true;
		}

		$detail_rows = array();
		foreach ($rows as $row) {
			$platform = trim((string) $row['quay_name']);
			$stopplace_code = isset($row['stopplace_code']) ? (string) $row['stopplace_code'] : '';
			$show_platform = $platform !== ''
				&& $stopplace_code !== ''
				&& isset($quay_names_by_stopplace[$stopplace_code])
				&& count($quay_names_by_stopplace[$stopplace_code]) > 1;
			$detail_rows[] = array(
				// Prefer the public CHB stopplace name; fall back to the NeTEx scheduled stop name
				'name' => (trim((string) $row['stopplace_name']) !== '' ? trim((string) $row['stopplace_name']) : (trim((string) $row['stop_name']) !== '' ? trim((string) $row['stop_name']) : 'Halte')),
				'platform' => $show_platform ? 'perron ' . $platform : '',
				'time' => self::format_service_seconds((int) $leg['base_departure_seconds'] + (int) $row['offset_seconds']),
			);
		}
		return $detail_rows;
	}

	private static function get_train_leg_detail_rows(array $leg) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'
				SELECT js.arrival_seconds, js.departure_seconds, s.station_name
				FROM ' . self::table('ovtd', 'journey_stops') . ' js
				LEFT JOIN ' . self::table('ovtd', 'stations') . ' s ON s.station_code = js.station_code
				WHERE js.journey_ref = %s
					AND js.stop_order >= %d
					AND js.stop_order <= %d
				ORDER BY js.stop_order ASC
				',
				$leg['journey_ref'],
				(int) $leg['from_order'],
				(int) $leg['to_order']
			),
			ARRAY_A
		);

		$detail_rows = array();
		foreach ($rows as $row) {
			$seconds = (int) $row['departure_seconds'];
			if ($seconds < 0) {
				$seconds = (int) $row['arrival_seconds'];
			}
			$detail_rows[] = array(
				'name' => trim((string) $row['station_name']) !== '' ? (string) $row['station_name'] : 'Station',
				'platform' => '',
				'time' => $seconds >= 0 ? self::format_service_seconds($seconds) : '',
			);
		}
		return $detail_rows;
	}

	private static function transfer_nodes(array $node, array $transfer_map) {
		$nodes = array(array('node' => $node, 'seconds' => 0));
		$key = self::node_key($node);
		if (!empty($transfer_map[$key])) {
			foreach ($transfer_map[$key] as $transfer) {
				$nodes[] = $transfer;
			}
		}
		return $nodes;
	}

	private static function terminal_nodes(array $node, array $transfer_map) {
		$nodes = array();
		foreach (self::transfer_nodes($node, $transfer_map) as $transfer) {
			$transfer['seconds'] = 0;
			$key = self::node_key($transfer['node']);
			if (!isset($nodes[$key])) {
				$nodes[$key] = $transfer;
			}
		}
		return array_values($nodes);
	}

	private static function build_auto_transfer_map(array $nodes = null) {
		static $memory_cache = null;
		if (is_array($memory_cache)) {
			return $memory_cache;
		}

		$cache_key = 'ovrp_auto_transfer_map_' . self::VERSION;
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			$memory_cache = $cached;
			return $cached;
		}

		if ($nodes === null) {
			$nodes = self::get_nodes();
		}

		$map = array();
		$bus_nodes = array();
		$train_nodes_by_place = array();

		foreach ($nodes as $node) {
			if ($node['mode'] === 'bus') {
				$bus_nodes[] = $node;
			} elseif ($node['mode'] === 'train') {
				$place_key = self::normalize_search_text(isset($node['place']) ? $node['place'] : '');
				if ($place_key !== '') {
					if (!isset($train_nodes_by_place[$place_key])) {
						$train_nodes_by_place[$place_key] = array();
					}
					$train_nodes_by_place[$place_key][] = $node;
				}
			}
		}

		foreach ($bus_nodes as $bus) {
			$bus_place_key = self::normalize_search_text(isset($bus['place']) ? $bus['place'] : '');
			$bus_name_key = self::normalize_search_text(isset($bus['name']) ? $bus['name'] : '');
			if ($bus_place_key === '' || $bus_name_key === '') {
				continue;
			}
			if (empty($train_nodes_by_place[$bus_place_key])) {
				continue;
			}

			foreach ($train_nodes_by_place[$bus_place_key] as $train) {
				if (!self::bus_stop_matches_station($bus_name_key, $train)) {
					continue;
				}
				self::add_transfer($map, $bus, $train, self::DEFAULT_MIN_TRANSFER_SECONDS);
				self::add_transfer($map, $train, $bus, self::DEFAULT_MIN_TRANSFER_SECONDS);
			}
		}

		$memory_cache = $map;
		set_transient($cache_key, $map, 6 * HOUR_IN_SECONDS);
		return $map;
	}

	private static function bus_stop_matches_station($bus_name_key, array $station) {
		$station_name_key = self::normalize_search_text(isset($station['name']) ? $station['name'] : '');
		$place_key = self::normalize_search_text(isset($station['place']) ? $station['place'] : '');
		if ($station_name_key === '' || $place_key === '') {
			return false;
		}

		$suffix_key = '';
		if ($station_name_key === $place_key) {
			$suffix_key = '';
		} elseif (strpos($station_name_key, $place_key . ' ') === 0) {
			$suffix_key = trim(substr($station_name_key, strlen($place_key)));
		}

		$aliases = array(
			$station_name_key,
			'station ' . $station_name_key,
			$station_name_key . ' station',
		);

		if ($suffix_key === '') {
			$exact_aliases = array_merge(
				$aliases,
				array(
					'station',
					'hoofdstation',
					'hoofd station',
					'centraal station',
					'station centraal',
					'cs',
					$place_key . ' station',
					'station ' . $place_key,
				)
			);
			if (in_array($bus_name_key, array_unique(array_filter($exact_aliases)), true)) {
				return true;
			}
			foreach (array('hoofdstation', 'hoofd station', 'centraal station', 'station centraal') as $alias) {
				if (self::normalized_phrase_matches($bus_name_key, $alias)) {
					return true;
				}
			}
			return false;
		} else {
			$aliases = array_merge(
				$aliases,
				array(
					$suffix_key,
					'station ' . $suffix_key,
					$suffix_key . ' station',
				)
			);
		}

		foreach (array_unique(array_filter($aliases)) as $alias) {
			if (self::normalized_phrase_matches($bus_name_key, $alias)) {
				return true;
			}
		}

		return false;
	}

	private static function normalized_phrase_matches($haystack, $needle) {
		$haystack = ' ' . self::normalize_search_text($haystack) . ' ';
		$needle = ' ' . self::normalize_search_text($needle) . ' ';
		if (trim($needle) === '') {
			return false;
		}
		return strpos($haystack, $needle) !== false;
	}

	private static function add_transfer(array &$map, array $from, array $to, $seconds) {
		$key = self::node_key($from);
		$target_key = self::node_key($to);
		if (!isset($map[$key])) {
			$map[$key] = array();
		}
		foreach ($map[$key] as $existing) {
			if (self::node_key($existing['node']) === $target_key) {
				return;
			}
		}
		$map[$key][] = array('node' => $to, 'seconds' => (int) $seconds);
	}

	private static function merge_transfer_maps(array $primary, array $secondary) {
		foreach ($secondary as $key => $transfers) {
			if (!isset($primary[$key])) {
				$primary[$key] = array();
			}
			foreach ($transfers as $transfer) {
				$exists = false;
				foreach ($primary[$key] as $existing) {
					if (self::node_key($existing['node']) === self::node_key($transfer['node'])) {
						$exists = true;
						break;
					}
				}
				if (!$exists) {
					$primary[$key][] = $transfer;
				}
			}
		}
		return $primary;
	}

	private static function parse_transfer_map($value) {
		$map = array();
		$specs = array_filter(array_map('trim', explode(',', $value)));
		foreach ($specs as $spec) {
			$parts = array_map('trim', explode('|', $spec));
			if (count($parts) < 2) {
				continue;
			}
			$from = self::parse_node_shorthand($parts[0]);
			$to = self::parse_node_shorthand($parts[1]);
			$seconds = isset($parts[2]) ? max(0, (int) $parts[2] * MINUTE_IN_SECONDS) : self::DEFAULT_MIN_TRANSFER_SECONDS;
			if (!$from || !$to) {
				continue;
			}
			$map[self::node_key($from)][] = array('node' => $to, 'seconds' => $seconds);
			$map[self::node_key($to)][] = array('node' => $from, 'seconds' => $seconds);
		}
		return $map;
	}

	private static function parse_node_shorthand($value) {
		$value = trim((string) $value);
		$pos = strpos($value, ':');
		if ($pos === false) {
			return null;
		}
		$mode = strtolower(substr($value, 0, $pos));
		$ref = substr($value, $pos + 1);
		if (!in_array($mode, array('bus', 'train'), true) || $ref === '') {
			return null;
		}
		return array('mode' => $mode, 'ref' => $mode === 'train' ? strtolower($ref) : $ref, 'label' => $ref);
	}

	private static function dedupe_itineraries(array $itineraries) {
		$seen = array();
		$result = array();
		foreach ($itineraries as $itinerary) {
			$parts = array();
			foreach ($itinerary['legs'] as $leg) {
				$parts[] = implode(':', array($leg['mode'], $leg['journey_ref'], $leg['from_node']['ref'], $leg['to_node']['ref'], $leg['departure_timestamp']));
			}
			$key = implode('|', $parts);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$result[] = $itinerary;
		}
		return $result;
	}

	private static function collapse_equivalent_itineraries(array $itineraries) {
		$best = array();
		foreach ($itineraries as $itinerary) {
			$key = self::equivalent_itinerary_key($itinerary);
			$score = self::equivalent_itinerary_score($itinerary);
			if (!isset($best[$key]) || $score > $best[$key]['score']) {
				$best[$key] = array(
					'score' => $score,
					'itinerary' => $itinerary,
				);
			}
		}

		$result = array();
		foreach ($best as $item) {
			$result[] = $item['itinerary'];
		}
		return $result;
	}

	private static function filter_dominated_itineraries(array $itineraries) {
		$result = array();
		foreach ($itineraries as $candidate) {
			$cand_legs = array_values($candidate['legs']);
			$cand_first = $cand_legs[0];
			$cand_last = end($cand_legs);
			$cand_dep = (int) $cand_first['departure_timestamp'];
			$cand_arr = (int) $cand_last['arrival_timestamp'];
			$cand_transfers = count($cand_legs) - 1;
			// 10-minute penalty (600 seconds) per transfer for dominance calculations
			$cand_penalized_arr = $cand_arr + ($cand_transfers * 600);

			$dominated = false;
			foreach ($itineraries as $other) {
				if ($other === $candidate) {
					continue;
				}
				$other_legs = array_values($other['legs']);
				$other_first = $other_legs[0];
				$other_last = end($other_legs);
				$other_dep = (int) $other_first['departure_timestamp'];
				$other_arr = (int) $other_last['arrival_timestamp'];
				$other_transfers = count($other_legs) - 1;
				$other_penalized_arr = $other_arr + ($other_transfers * 600);

				// Other dominates candidate if:
				// - it departs at the same time or later, AND its penalized arrival is strictly earlier
				if ($other_dep >= $cand_dep && $other_penalized_arr < $cand_penalized_arr) {
					$dominated = true;
					break;
				}
				// - it departs strictly later, AND its penalized arrival is the same or earlier
				if ($other_dep > $cand_dep && $other_penalized_arr <= $cand_penalized_arr) {
					$dominated = true;
					break;
				}
				// - it departs at the same time, AND its penalized arrival is the same, AND it has strictly fewer transfers
				if ($other_dep === $cand_dep && $other_penalized_arr === $cand_penalized_arr && $other_transfers < $cand_transfers) {
					$dominated = true;
					break;
				}
			}
			if (!$dominated) {
				$result[] = $candidate;
			}
		}
		return $result;
	}

	private static function remove_transfers_when_direct_available(array $itineraries) {
		return $itineraries; // No longer needed since filter_dominated_itineraries handles this better, kept for compatibility if called dynamically
	}

	private static function remove_later_transfer_variants(array $itineraries) {
		return $itineraries; // No longer needed since filter_dominated_itineraries handles this better, kept for compatibility if called dynamically
	}

	private static function is_better_itinerary(array $candidate, array $current) {
		$candidate_legs = array_values($candidate['legs']);
		$current_legs = array_values($current['legs']);
		$candidate_last = end($candidate_legs);
		$current_last = end($current_legs);
		if ($candidate_last['arrival_timestamp'] !== $current_last['arrival_timestamp']) {
			return $candidate_last['arrival_timestamp'] < $current_last['arrival_timestamp'];
		}
		if (count($candidate_legs) !== count($current_legs)) {
			return count($candidate_legs) < count($current_legs);
		}
		return self::first_transfer_departure($candidate_legs) < self::first_transfer_departure($current_legs);
	}

	private static function first_transfer_departure(array $legs) {
		if (count($legs) < 2) {
			return PHP_INT_MAX;
		}
		return (int) $legs[1]['departure_timestamp'];
	}

	private static function itinerary_endpoint_key(array $itinerary) {
		$legs = isset($itinerary['legs']) ? array_values($itinerary['legs']) : array();
		if (empty($legs)) {
			return '';
		}
		$first = $legs[0];
		$last = $legs[count($legs) - 1];
		return self::node_key($first['from_node']) . '>' . self::node_key($last['to_node']);
	}

	private static function itinerary_relation_key(array $itinerary) {

    $legs = isset($itinerary['legs']) ? array_values($itinerary['legs']) : array();

    if (empty($legs)) {
        return '';
    }

    $first = $legs[0];
    $last = $legs[count($legs) - 1];

    $modes = array();

    foreach ($legs as $leg) {
        $modes[] = $leg['mode'] . ':' . $leg['line'];
    }

    return
        self::node_key($first['from_node']) .
        '>' .
        self::node_key($last['to_node']) .
        '|' .
        implode('|', $modes);
}

	private static function equivalent_itinerary_key(array $itinerary) {
		$legs = isset($itinerary['legs']) ? $itinerary['legs'] : array();
		if (empty($legs)) {
			return md5(serialize($itinerary));
		}
		$first = $legs[0];
		$last = $legs[count($legs) - 1];
		$parts = array(
			(string) $first['departure_timestamp'],
			(string) $last['arrival_timestamp'],
		);
		foreach ($legs as $leg) {
			$parts[] = $leg['mode'] . ':' . $leg['journey_ref'];
		}
		return implode('|', $parts);
	}

	private static function equivalent_itinerary_score(array $itinerary) {
		$legs = isset($itinerary['legs']) ? array_values($itinerary['legs']) : array();
		if (count($legs) < 2) {
			return PHP_INT_MAX;
		}
		$wait = (int) $legs[1]['departure_timestamp'] - (int) $legs[0]['arrival_timestamp'];
		return max(0, $wait);
	}

	private static function add_notices_to_legs(array $legs) {
		if (empty($legs)) {
			return $legs;
		}

		$bus_journey_refs = array();
		foreach ($legs as $leg) {
			if ($leg['mode'] === 'bus') {
				$bus_journey_refs[] = $leg['journey_ref'];
			}
		}

		if (empty($bus_journey_refs)) {
			return $legs;
		}

		// Initialise notice to empty string for all legs before potentially returning early.
		foreach ($legs as $index => $leg) {
			$legs[$index]['notice'] = '';
		}

		// Guard against missing tables (e.g. before the first import after the schema update).
		if (!self::table_exists('ovhi', 'notices') || !self::table_exists('ovhi', 'notice_assignments')) {
			return $legs;
		}

		global $wpdb;
		$bus_journey_refs = array_values(array_unique($bus_journey_refs));
		$placeholders = implode(',', array_fill(0, count($bus_journey_refs), '%s'));

		$footnotes_query = "
			SELECT na.noticed_object_ref AS journey_ref, GROUP_CONCAT(n.notice_text ORDER BY n.notice_id SEPARATOR ' / ') AS footnote
			FROM " . self::table('ovhi', 'notice_assignments') . " na
			INNER JOIN " . self::table('ovhi', 'notices') . " n ON n.notice_id = na.notice_ref
			WHERE na.noticed_object_ref IN ($placeholders) AND na.name_of_ref_class = 'ServiceJourney'
			GROUP BY na.noticed_object_ref
		";
		$footnotes = $wpdb->get_results($wpdb->prepare($footnotes_query, $bus_journey_refs), ARRAY_A);
		$footnotes_map = array();
		if (is_array($footnotes)) {
			foreach ($footnotes as $f) {
				$footnotes_map[$f['journey_ref']] = $f['footnote'];
			}
		}

		foreach ($legs as $index => $leg) {
			if ($leg['mode'] === 'bus') {
				$ref = $leg['journey_ref'];
				$legs[$index]['notice'] = isset($footnotes_map[$ref]) ? $footnotes_map[$ref] : '';
			}
		}

		return $legs;
	}

	private static function sort_and_limit_legs(array $legs, $limit) {
		$legs = self::dedupe_legs($legs);
		usort(
			$legs,
			function ($a, $b) {
				if ($a['departure_timestamp'] === $b['departure_timestamp']) {
					return $a['arrival_timestamp'] <=> $b['arrival_timestamp'];
				}
				return $a['departure_timestamp'] <=> $b['departure_timestamp'];
			}
		);
		return array_slice($legs, 0, $limit);
	}

	private static function dedupe_legs(array $legs) {
		$seen = array();
		$result = array();
		foreach ($legs as $leg) {
			$key = implode(':', array($leg['mode'], $leg['journey_ref'], $leg['from_node']['ref'], $leg['to_node']['ref'], $leg['departure_timestamp']));
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$result[] = $leg;
		}
		return $result;
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

	private static function train_dataset_from() {
		$info = get_option('ovtd_import_info', array());
		$validity = is_array($info) && isset($info['validity']) ? (string) $info['validity'] : '';
		if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+t\/m\s+\d{4}-\d{2}-\d{2}$/', $validity, $matches)) {
			return $matches[1];
		}
		return gmdate('Y-m-d');
	}

	private static function candidate_service_dates($after_timestamp) {
		$timezone = wp_timezone();
		$dt = (new DateTimeImmutable('@' . $after_timestamp))->setTimezone($timezone);
		$dates = array($dt->format('Y-m-d'), $dt->modify('+1 day')->format('Y-m-d'));
		$seconds = ((int) $dt->format('H') * HOUR_IN_SECONDS) + ((int) $dt->format('i') * MINUTE_IN_SECONDS) + (int) $dt->format('s');
		if ($seconds < self::SERVICE_DAY_START_SECONDS) {
			$dates[] = $dt->modify('-1 day')->format('Y-m-d');
		}
		return array_values(array_unique($dates));
	}

	private static function seconds_from_service_midnight($service_date, $timestamp) {
		$timezone = wp_timezone();
		$midnight = new DateTimeImmutable($service_date . ' 00:00:00', $timezone);
		return $timestamp - $midnight->getTimestamp();
	}

	private static function timestamp_from_service_seconds($service_date, $seconds) {
		$timezone = wp_timezone();
		$midnight = new DateTimeImmutable($service_date . ' 00:00:00', $timezone);
		return $midnight->modify('+' . (int) $seconds . ' seconds')->getTimestamp();
	}

	private static function parse_when_timestamp($value) {
		$timezone = wp_timezone();
		$value = trim((string) $value);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
			return (new DateTimeImmutable('now', $timezone))->getTimestamp();
		}
		return (new DateTimeImmutable(str_replace('T', ' ', $value), $timezone))->getTimestamp();
	}

	private static function page_url($page) {
		$page = max(0, (int) $page);
		$args = array('ovrp_page' => $page);
		if ($page === 0) {
			return remove_query_arg('ovrp_page');
		}
		return add_query_arg($args);
	}

	private static function default_when_value() {
		$timezone = wp_timezone();
		$now = new DateTimeImmutable('now', $timezone);
		return $now->format('Y-m-d\TH:i');
	}

	private static function parse_node_value($value) {
		$parts = explode('|', (string) $value, 2);
		if (count($parts) !== 2) {
			return null;
		}
		$mode = $parts[0];
		$ref = $parts[1];
		if (!in_array($mode, array('bus', 'train'), true) || $ref === '') {
			return null;
		}
		return array('mode' => $mode, 'ref' => $mode === 'train' ? strtolower($ref) : $ref, 'label' => $ref);
	}

	private static function node_by_value(array $nodes, $value) {
		$node = self::parse_node_value($value);
		if (!$node) {
			return null;
		}
		$key = self::node_key($node);
		return isset($nodes[$key]) ? $nodes[$key] : null;
	}

	private static function node_by_value_lazy($value) {
		$node = self::parse_node_value($value);
		if (!$node) {
			return null;
		}
		return self::lookup_node($node);
	}

	private static function lookup_node(array $node) {
		global $wpdb;
		static $cache = array();

		$key = self::node_key($node);
		if (array_key_exists($key, $cache)) {
			return $cache[$key];
		}

		if ($node['mode'] === 'bus' && self::table_exists('ovhi', 'stopplaces')) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'
					SELECT stopplace_code, stopplace_name, town
					FROM ' . self::table('ovhi', 'stopplaces') . '
					WHERE stopplace_code = %s
					LIMIT 1
					',
					$node['ref']
				),
				ARRAY_A
			);
			if ($row) {
				$name = trim((string) $row['stopplace_name']);
				$cache[$key] = array(
					'mode' => 'bus',
					'ref' => (string) $row['stopplace_code'],
					'name' => $name !== '' ? $name : (string) $row['stopplace_code'],
					'place' => trim((string) $row['town']),
					'label' => self::format_node_label($name, (string) $row['stopplace_code']),
				);
				return $cache[$key];
			}
		}

		if ($node['mode'] === 'train' && self::table_exists('ovtd', 'stations')) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'
					SELECT station_code, station_name
					FROM ' . self::table('ovtd', 'stations') . '
					WHERE station_code = %s
					LIMIT 1
					',
					$node['ref']
				),
				ARRAY_A
			);
			if ($row) {
				$name = trim((string) $row['station_name']);
				$cache[$key] = array(
					'mode' => 'train',
					'ref' => strtolower((string) $row['station_code']),
					'name' => $name !== '' ? $name : strtoupper((string) $row['station_code']),
					'place' => self::station_place_name($name),
					'label' => self::format_node_label($name, (string) $row['station_code']),
				);
				return $cache[$key];
			}
		}

		$cache[$key] = null;
		return null;
	}

	private static function node_value(array $node) {
		return $node['mode'] . '|' . $node['ref'];
	}

	private static function node_key(array $node) {
		return $node['mode'] . ':' . $node['ref'];
	}

	private static function node_label(array $node, array $nodes = array()) {
		if (!empty($node['label']) && $node['label'] !== $node['ref']) {
			return $node['label'];
		}
		$key = self::node_key($node);
		if (isset($nodes[$key])) {
			return $nodes[$key]['label'];
		}
		$looked_up = self::lookup_node($node);
		if ($looked_up && !empty($looked_up['label'])) {
			return $looked_up['label'];
		}
		return !empty($node['label']) ? $node['label'] : $node['ref'];
	}

	private static function format_node_label($name, $fallback) {
		$name = trim((string) $name);
		if ($name === '') {
			$name = trim((string) $fallback);
		}
		return $name;
	}

	private static function bus_direction_label(array $row) {
		$destination = isset($row['destination_display']) ? trim((string) $row['destination_display']) : '';
		if ($destination === '') {
			$destination = isset($row['to_name']) ? trim((string) $row['to_name']) : '';
		}
		if ($destination === '') {
			return '';
		}
		if (stripos($destination, 'richting ') === 0) {
			return $destination;
		}
		return 'richting ' . $destination;
	}

	private static function sanitize_hex_colour($value) {
		$value = strtoupper(trim((string) $value));
		$value = ltrim($value, '#');
		if (!preg_match('/^[0-9A-F]{6}$/', $value)) {
			return '';
		}
		return '#' . $value;
	}

	private static function contrast_text_colour($background) {
		$background = ltrim(self::sanitize_hex_colour($background), '#');
		if (strlen($background) !== 6) {
			return '#FFFFFF';
		}
		$r = hexdec(substr($background, 0, 2));
		$g = hexdec(substr($background, 2, 2));
		$b = hexdec(substr($background, 4, 2));
		$luminance = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
		return $luminance >= 150 ? '#000000' : '#FFFFFF';
	}

	private static function regional_town_sql($column) {
		$parts = array();
		foreach (self::REGIONAL_TOWNS as $town) {
			$parts[] = $column . " = '" . esc_sql($town) . "'";
		}
		return '(' . implode(' OR ', $parts) . ')';
	}

	private static function station_place_name($station_name) {
		$name = trim((string) $station_name);
		if ($name === '') {
			return '';
		}

		$parts = preg_split('/\s+/', $name);
		if (!$parts || count($parts) < 2) {
			return $name;
		}

		$last = strtolower((string) end($parts));
		$suffixes = array(
			'centrum',
			'centraal',
			'station noord',
			'oost',
			'zuid',
			'west',
			'park',
			'poort',
			'europapark',
			'goederen',
			'haven',
			'wijk',
		);

		if (in_array($last, $suffixes, true)) {
			array_pop($parts);
			return implode(' ', $parts);
		}

		return $name;
	}

	private static function normalize_search_text($value) {
		$value = strtolower(trim((string) $value));
		if (function_exists('remove_accents')) {
			$value = remove_accents($value);
		}
		$value = preg_replace('/[^a-z0-9]+/', ' ', $value);
		return trim((string) preg_replace('/\s+/', ' ', $value));
	}

	private static function train_badge($train_type) {
		$train_type = trim((string) $train_type);
		$map = array(
			'Intercity direct' => 'ICD',
			'Intercity' => 'IC',
			'Sprinter' => 'Spr',
			'Stoptrein' => 'Sto',
			'Sneltrein' => 'Snl',
		);
		return isset($map[$train_type]) ? $map[$train_type] : ($train_type !== '' ? mb_substr($train_type, 0, 3) : 'Trein');
	}

	private static function format_timestamp($timestamp) {
		return wp_date('H:i', $timestamp);
	}

	private static function format_service_seconds($seconds) {
		$seconds = (int) $seconds;
		$seconds = $seconds % DAY_IN_SECONDS;
		if ($seconds < 0) {
			$seconds += DAY_IN_SECONDS;
		}
		return sprintf('%02d:%02d', floor($seconds / HOUR_IN_SECONDS), floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS));
	}

	private static function duration_label($seconds) {
		$seconds = max(0, (int) $seconds);
		$hours = (int) floor($seconds / HOUR_IN_SECONDS);
		$minutes = (int) floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
		if ($hours > 0) {
			return $hours . 'u ' . sprintf('%02d', $minutes) . 'm';
		}
		return $minutes . 'm';
	}

	private static function enqueue_frontend_style() {
		wp_register_style(self::FRONTEND_STYLE, false, array(), self::VERSION);
		wp_enqueue_style(self::FRONTEND_STYLE);
		wp_add_inline_style(
			self::FRONTEND_STYLE,
			'.ovrp-wrapper{max-width:100%;color:#861121}
			.ovrp-form{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0 0 22px}
			.ovrp-form label,.ovrp-search-field{display:flex;flex-direction:column;gap:5px;font-weight:700;font-size:14px}
			.ovrp-search-field{position:relative}
			.ovrp-form select,.ovrp-form input{min-width:240px;max-width:100%;padding:9px 10px;border:1px solid rgba(134,17,33,.24);border-radius:8px;background:#fff;color:#471018}
			.ovrp-form button{padding:10px 18px;border:0;border-radius:999px;background:#861121;color:#fff;font-weight:700;cursor:pointer}
			.ovrp-suggestions{position:absolute;z-index:10;left:0;right:0;top:100%;margin-top:4px;border:1px solid rgba(134,17,33,.18);border-radius:12px;background:#fff;box-shadow:0 12px 30px rgba(40,10,15,.16);overflow:hidden}
			.ovrp-suggestions button{display:block;width:100%;padding:10px 12px;border:0;border-bottom:1px solid rgba(134,17,33,.08);border-radius:0;background:#fff;color:#471018;text-align:left;cursor:pointer}
			.ovrp-suggestions button.is-active,.ovrp-suggestions button:hover,.ovrp-suggestions button:focus{background:rgba(134,17,33,.07);outline:none}
			.ovrp-suggestions strong{display:block;font-size:14px}
			.ovrp-suggestions span{display:block;margin-top:2px;font-size:12px;opacity:.72}
			.ovrp-note{font-size:13px;opacity:.78}
			.ovrp-result-nav{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:10px 0 14px}
			.ovrp-result-nav a{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 13px;border-radius:999px;background:rgba(134,17,33,.09);color:#861121;text-decoration:none;font-weight:700}
			.ovrp-result-nav a:hover,.ovrp-result-nav a:focus{background:rgba(134,17,33,.15)}
			.ovrp-card{border:1px solid rgba(134,17,33,.14);border-radius:14px;background:#fff;box-shadow:0 5px 18px rgba(0,0,0,.06);padding:11px 12px;margin:0 0 12px;cursor:pointer}
			.ovrp-card:focus{outline:2px solid rgba(134,17,33,.35);outline-offset:2px}
			.ovrp-card-head{display:flex;align-items:center;gap:9px;margin:0 0 8px;font-size:14px;color:#861121;font-weight:700}
			.ovrp-card-trip{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			.ovrp-card-action{margin-left:auto;font-size:11px;line-height:1;color:#861121;opacity:.72}
			.ovrp-card-meta{font-size:12px;line-height:1.35;color:#861121;opacity:.72;margin:0 0 4px}
			.ovrp-mobile-badge{width:30px;height:30px;min-width:30px;border-radius:999px;background:#861121;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;line-height:1;font-weight:700}
			.ovrp-mobile-stop-row{display:flex;justify-content:space-between;gap:12px;border-top:1px solid rgba(134,17,33,.1);padding:7px 0;font-size:13px;line-height:1.25;color:#861121}
			.ovrp-mobile-stop-row:first-child{border-top:0}
			.ovrp-mobile-stop-row strong{font-weight:700;font-size:13px;white-space:nowrap}
			.ovrp-mobile-stop-row small{display:block;margin-top:2px;opacity:.72}
			.ovrp-leg-notice{font-size:12px;opacity:.88;color:#b45309;margin-top:4px;padding-left:34px;white-space:normal;}
			.ovrp-transfer{border-top:1px solid rgba(134,17,33,.1);padding:7px 0;font-size:13px;opacity:.78}
			.ovrp-badge{display:inline-flex;align-items:center;min-height:24px;padding:2px 8px;border-radius:999px;background:rgba(134,17,33,.09);font-weight:700}
			.ovrp-mobile-detail{display:none}
			.ovrp-modal{position:fixed;inset:0;z-index:99999;display:none;align-items:flex-end;justify-content:center;background:rgba(20,12,14,.52);padding:18px;box-sizing:border-box}
			.ovrp-modal.is-open{display:flex}
			.ovrp-modal-panel{width:100%;max-width:540px;max-height:84vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 18px 60px rgba(0,0,0,.28);padding:16px;color:#861121;box-sizing:border-box}
			.ovrp-modal-head{display:flex;align-items:center;gap:12px;margin:0 0 10px}
			.ovrp-modal-title{font-weight:700;font-size:16px;line-height:1.25;color:#861121;margin-right:auto}
			.ovrp-modal-close{width:34px;height:34px;border:0;border-radius:999px;background:#861121;color:#fff;font-size:22px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
			.ovrp-mobile-detail-title{font-weight:700;font-size:15px;line-height:1.3;color:#861121;margin:10px 0 8px}
			.ovrp-mobile-detail-title:first-child{margin-top:0}
			.ovrp-mobile-detail-notice{font-size:12px;opacity:.88;color:#b45309;margin:4px 0 10px;padding:6px 10px;background:rgba(180,83,9,.06);border-radius:6px;border-left:3px solid #b45309;white-space:normal;}
			.ovrp-mobile-detail-row{display:flex;justify-content:space-between;gap:14px;border-top:1px solid rgba(134,17,33,.11);padding:8px 0;font-size:13px;line-height:1.25;color:#861121}
			.ovrp-mobile-detail-row strong{font-weight:700;white-space:nowrap}
			.ovrp-mobile-detail-row small{display:block;margin-top:2px;font-size:12px;opacity:.72}
			.ovrp-mobile-detail-transfer{background:rgba(134,17,33,.045);padding-left:8px;padding-right:8px}
			@media (max-width:700px){.ovrp-form{display:block}.ovrp-form label,.ovrp-search-field{margin-bottom:12px}.ovrp-form select,.ovrp-form input,.ovrp-form button{width:100%;min-width:0}}
			.ovrp-amenities{display:inline-flex;flex-wrap:wrap;gap:6px;margin-top:6px}
			.ovrp-amenity{position:relative;display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;min-width:24px;border-radius:50%;background:#861121;padding:5px;box-sizing:border-box;cursor:help;transition:all .2s ease}
			.ovrp-amenity:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(134,17,33,.3)}
			.ovrp-amenity svg{display:block;width:100%;height:100%}
			.ovrp-detail-amenities{margin-bottom:10px}
			.ovrp-detail-amenities .ovrp-amenity{width:28px;height:28px;min-width:28px;padding:6px}
			.ovrp-amenity::after{content:attr(data-title);position:absolute;bottom:125%;left:50%;transform:translateX(-50%) scale(.85);background:#2b0b10;color:#fff;padding:5px 9px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap;opacity:0;pointer-events:none;transition:all .2s cubic-bezier(.175,.885,.32,1.275);z-index:100;box-shadow:0 5px 15px rgba(0,0,0,.15)}
			.ovrp-amenity:hover::after{opacity:1;transform:translateX(-50%) scale(1)}'
		);
	}
}

OV_Reisplanner::init();
