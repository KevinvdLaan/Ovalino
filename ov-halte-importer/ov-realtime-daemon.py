#!/usr/bin/env python3
"""
Ovalino Realtime Data Daemon
============================
Consumes ZeroMQ feeds from NDOVloket.nl:
  - KV78turbo (Busses): tcp://pubsub.besteffort.ndovloket.nl:7817
  - InfoPlus DVS (Trains): tcp://pubsub.besteffort.ndovloket.nl:7664

Updates `wp_ovhi_realtime_delays` in WordPress database.
Deletes records older than 1 hour.
"""

import sys
import os
import re
import time
import gzip
import io
import argparse
import threading
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone, tzinfo
import json


class AmsterdamTZ(tzinfo):
    """Timezone implementation for Europe/Amsterdam (CET/CEST)."""
    def utcoffset(self, dt):
        return timedelta(hours=2) if self._is_dst(dt) else timedelta(hours=1)

    def dst(self, dt):
        return timedelta(hours=1) if self._is_dst(dt) else timedelta(0)

    def tzname(self, dt):
        return 'CEST' if self._is_dst(dt) else 'CET'

    def _is_dst(self, dt):
        if dt is None:
            return False
        year = dt.year
        # Last Sunday of March (02:00 local)
        start = datetime(year, 3, 31)
        start = start - timedelta(days=(start.weekday() + 1) % 7)
        start = start.replace(hour=2)
        # Last Sunday of October (03:00 local)
        end = datetime(year, 10, 31)
        end = end - timedelta(days=(end.weekday() + 1) % 7)
        end = end.replace(hour=3)
        dt_naive = dt.replace(tzinfo=None) if dt.tzinfo else dt
        return start <= dt_naive < end


AMSTERDAM_TZ = AmsterdamTZ()


def parse_and_format_datetime(raw_val, add_delay_seconds=0):
    """Parse an ISO 8601 string or time string into Europe/Amsterdam 'YYYY-MM-DD HH:MM:SS'."""
    if not raw_val:
        return None
    val = str(raw_val).strip()
    if not val:
        return None
    try:
        # Check ISO format with date
        if 'T' in val:
            v = val
            if v.endswith('Z') or v.endswith('z'):
                v = v[:-1] + '+00:00'
            dt = datetime.fromisoformat(v)
            if dt.tzinfo is not None:
                dt_local = dt.astimezone(AMSTERDAM_TZ)
            else:
                dt_local = dt
            if add_delay_seconds:
                dt_local += timedelta(seconds=add_delay_seconds)
            return dt_local.strftime('%Y-%m-%d %H:%M:%S')

        # Check HH:MM or HH:MM:SS
        m = re.match(r'^(\d{1,2}):(\d{2})(?::(\d{2}))?$', val)
        if m:
            now_local = datetime.now(AMSTERDAM_TZ)
            h = int(m.group(1))
            mi = int(m.group(2))
            s = int(m.group(3) or 0)
            dt_local = now_local.replace(hour=h, minute=mi, second=s, microsecond=0)
            if add_delay_seconds:
                dt_local += timedelta(seconds=add_delay_seconds)
            return dt_local.strftime('%Y-%m-%d %H:%M:%S')
    except Exception:
        pass
    return None


def compute_service_date(expected_time=None):
    """Return the service date YYYY-MM-DD in Europe/Amsterdam timezone (service day boundary at 05:00)."""
    if expected_time:
        try:
            dt = datetime.strptime(expected_time, '%Y-%m-%d %H:%M:%S')
            if dt.hour < 5:
                dt = dt - timedelta(days=1)
            return dt.strftime('%Y-%m-%d')
        except Exception:
            pass
    now = datetime.now(AMSTERDAM_TZ)
    if now.hour < 5:
        now = now - timedelta(days=1)
    return now.strftime('%Y-%m-%d')


try:
    import zmq
except ImportError:
    print("Error: 'pyzmq' is required. Install it using: pip install pyzmq")
    sys.exit(1)

try:
    import mysql.connector
except ImportError:
    try:
        import pymysql as mysql
    except ImportError:
        print("Error: 'mysql-connector-python' or 'PyMySQL' is required. Install via pip.")
        sys.exit(1)


def parse_wp_config(wp_config_path):
    """Parse db credentials and prefix from wp-config.php."""
    config = {
        'host': 'localhost',
        'user': 'root',
        'password': '',
        'database': '',
        'prefix': 'wp_'
    }
    if not os.path.exists(wp_config_path):
        return config

    with open(wp_config_path, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()

    host_m = re.search(r"define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", content)
    user_m = re.search(r"define\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", content)
    pass_m = re.search(r"define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", content)
    name_m = re.search(r"define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", content)
    pref_m = re.search(r"\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]", content)

    if host_m: config['host'] = host_m.group(1).split(':')[0]
    if user_m: config['user'] = user_m.group(1)
    if pass_m: config['password'] = pass_m.group(1)
    if name_m: config['database'] = name_m.group(1)
    if pref_m: config['prefix'] = pref_m.group(1)

    return config


def find_wp_config():
    """Look for wp-config.php up the directory tree."""
    curr = os.path.abspath(os.path.dirname(__file__))
    for _ in range(5):
        cand = os.path.join(curr, 'wp-config.php')
        if os.path.exists(cand):
            return cand
        parent = os.path.dirname(curr)
        if parent == curr:
            break
        curr = parent
    return None


# --- Logging setup for environments with only SFTP/FTP access ---
# Configure a rotating file logger placed under the WordPress install if
# found (so you can download it via SFTP), otherwise next to the daemon.
import logging
from logging.handlers import RotatingFileHandler

try:
    def _find_writable_log_path(candidates):
        """Return the first writable path from candidates, or None."""
        for p in candidates:
            try:
                d = os.path.dirname(p)
                if d:
                    os.makedirs(d, exist_ok=True)
                # try to open for append/create
                with open(p, 'a', encoding='utf-8') as fh:
                    fh.write('')
                return p
            except Exception:
                continue
        return None

    _wp_conf = find_wp_config()
    _plugin_dir = os.path.abspath(os.path.dirname(__file__))
    candidates = []
    if _wp_conf:
        _webroot = os.path.dirname(_wp_conf)
        candidates.append(os.path.join(_webroot, 'wp-content', 'plugins', 'ov-realtime-daemon.log'))
    # prefer plugin location next to the script
    candidates.append(os.path.join(_plugin_dir, 'ov-realtime-daemon.log'))
    # fallback to /tmp which is often writable on shared hosting
    candidates.append('/tmp/ov-realtime-daemon.log')

    _chosen = _find_writable_log_path(candidates)

    _logger = logging.getLogger('ov-realtime-daemon')
    _logger.setLevel(logging.DEBUG)
    _fmt = logging.Formatter('%(asctime)s [%(levelname)s] %(message)s')

    if _chosen:
        try:
            _handler = RotatingFileHandler(_chosen, maxBytes=10 * 1024 * 1024, backupCount=5, encoding='utf-8')
            _handler.setFormatter(_fmt)
            _logger.addHandler(_handler)
            # write an initial marker so SFTP users can see the file was created by the running process
            try:
                with open(_chosen, 'a', encoding='utf-8') as fh:
                    fh.write(f"[Daemon Marker] PID={os.getpid()} logging started to {_chosen}\n")
            except Exception:
                pass
        except Exception:
            _chosen = None

    # always add a console handler so the process still prints to stdout
    _console = logging.StreamHandler(sys.stdout)
    _console.setLevel(logging.INFO)
    _console.setFormatter(_fmt)
    _logger.addHandler(_console)

    # Redirect print()/stdout/stderr into the logger so existing prints are captured
    class _StreamToLogger(object):
        def __init__(self, logger, level=logging.INFO):
            self.logger = logger
            self.level = level
        def write(self, message):
            message = message.rstrip('\n')
            if message:
                try:
                    self.logger.log(self.level, message)
                except Exception:
                    # as a last resort, try to append to /tmp for diagnostics
                    try:
                        with open('/tmp/ov-realtime-daemon-error.log', 'a', encoding='utf-8') as fh:
                            fh.write(message + '\n')
                    except Exception:
                        pass
        def flush(self):
            try:
                for h in self.logger.handlers:
                    h.flush()
            except Exception:
                pass

    sys.stdout = _StreamToLogger(_logger, logging.INFO)
    sys.stderr = _StreamToLogger(_logger, logging.ERROR)

    # expose chosen path for runtime diagnostics
    _logger.debug(f"Logging initialized, chosen_path={_chosen}")

    # loadable normalization config (shared with PHP importer if placed in plugin dir)
    def load_normalization_config():
        defaults = {'enabled': True, 'coverage_threshold': 0.5, 'merge_tolerance_seconds': 120}
        candidates = []
        try:
            wp_conf = find_wp_config()
            if wp_conf:
                webroot = os.path.dirname(wp_conf)
                candidates.append(os.path.join(webroot, 'wp-content', 'plugins', 'ov-halte-importer', 'ovhi_normalization_config.json'))
        except Exception:
            pass
        plugin_dir = os.path.abspath(os.path.dirname(__file__))
        candidates.append(os.path.join(plugin_dir, 'ovhi_normalization_config.json'))
        candidates.append('/tmp/ovhi_normalization_config.json')
        for c in candidates:
            try:
                if os.path.exists(c):
                    with open(c, 'r', encoding='utf-8') as fh:
                        data = json.load(fh)
                        if isinstance(data, dict):
                            defaults.update(data)
                            defaults['enabled'] = bool(defaults.get('enabled', True))
                            defaults['coverage_threshold'] = float(defaults.get('coverage_threshold', 0.5))
                            defaults['merge_tolerance_seconds'] = int(defaults.get('merge_tolerance_seconds', 120))
                            _logger.info(f"Loaded normalization config from {c}")
                            return defaults
            except Exception:
                continue
        _logger.debug('Normalization config not found; using defaults')
        return defaults

except Exception:
    # If logging setup fails catastrophically, fall back to prints — the daemon will still work.
    print('[Logging Setup] failed to initialize robust logging; falling back to stdout/stderr')


class DelayDatabase:
    """Manages MySQL connection and updates for realtime delays."""
    # Ververs de verbinding proactief ruim binnen elke realistische
    # wait_timeout/firewall-idle-limiet (bijv. 2 uur), in plaats van te
    # vertrouwen op ping() om een "stille" dode verbinding te detecteren.
    # Een geblackholede (firewall/NAT weggegooide) TCP-verbinding levert
    # namelijk geen fout op ping() - de read hangt voor onbepaalde tijd,
    # zonder een expliciete socket-timeout hieronder.
    CONNECTION_MAX_AGE_SECONDS = 1800
    SOCKET_TIMEOUT_SECONDS = 10

    def __init__(self, db_config):
        self.config = db_config
        self.table_name = db_config['prefix'] + 'ovhi_realtime_delays'
        self.conn = None
        self.lock = threading.Lock()
        self.connected_at = 0
        self.connect()

    def connect(self):
        try:
            if 'mysql.connector' in sys.modules:
                self.conn = mysql.connector.connect(
                    host=self.config['host'],
                    user=self.config['user'],
                    password=self.config['password'],
                    database=self.config['database'],
                    autocommit=True,
                    connection_timeout=self.SOCKET_TIMEOUT_SECONDS,
                )
                # mysql-connector-python's connection_timeout alleen dekt de
                # initiële TCP-handshake, niet latere reads/writes. Zet ook
                # de onderliggende socket-timeout, zodat een stilgevallen
                # verbinding een fout geeft in plaats van voor altijd te
                # hangen op de eerstvolgende query.
                try:
                    sock = self.conn._socket.sock if hasattr(self.conn, '_socket') else None
                    if sock is not None:
                        sock.settimeout(self.SOCKET_TIMEOUT_SECONDS)
                except Exception:
                    pass
            else:
                self.conn = mysql.connect(
                    host=self.config['host'],
                    user=self.config['user'],
                    password=self.config['password'],
                    database=self.config['database'],
                    autocommit=True,
                    connect_timeout=self.SOCKET_TIMEOUT_SECONDS,
                    read_timeout=self.SOCKET_TIMEOUT_SECONDS,
                    write_timeout=self.SOCKET_TIMEOUT_SECONDS,
                )
            self.connected_at = time.monotonic()
            print(f"[DB] Connected successfully to {self.config['database']}")
        except Exception as e:
            print(f"[DB Error] Connection failed: {e}")
            self.conn = None
            self.connected_at = 0

    def ensure_connection(self):
        if self.conn is None:
            self.connect()
            return
        if (time.monotonic() - self.connected_at) > self.CONNECTION_MAX_AGE_SECONDS:
            # Proactief verversen voordat de verbinding oud genoeg is om
            # door een firewall/NAT/wait_timeout te worden weggegooid.
            self.connect()
            return
        try:
            if hasattr(self.conn, 'ping'):
                self.conn.ping(reconnect=True)
                self.connected_at = time.monotonic()
        except Exception:
            self.connect()

    def upsert_delay(self, journey_ref, stop_code, delay_seconds, is_cancelled, expected_time=None, service_date=None, change_type=0, is_extra_train=False):
        """Insert or update delay record.

        expected_time should be a string in 'YYYY-MM-DD HH:MM:SS' format or None.
        """
        if not journey_ref or not stop_code:
            return
        if not service_date:
            service_date = compute_service_date(expected_time)
        with self.lock:
            self.ensure_connection()
            if not self.conn:
                return
            try:
                cursor = self.conn.cursor()
                sql = f"""
                    INSERT INTO {self.table_name} (journey_ref, stop_code, delay_seconds, is_cancelled, expected_time, service_date, change_type, is_extra_train, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW())
                    ON DUPLICATE KEY UPDATE
                        delay_seconds = VALUES(delay_seconds),
                        is_cancelled = VALUES(is_cancelled),
                        expected_time = VALUES(expected_time),
                        service_date = VALUES(service_date),
                        change_type = VALUES(change_type),
                        is_extra_train = VALUES(is_extra_train),
                        updated_at = NOW()
                """
                params = (journey_ref, stop_code, int(delay_seconds), 1 if is_cancelled else 0, expected_time, service_date, int(change_type or 0), 1 if is_extra_train else 0)
                cursor.execute(sql, params)
                cursor.close()
            except Exception as e:
                # Fallback if optional columns don't exist yet in old schema
                try:
                    cursor = self.conn.cursor()
                    sql_fallback = f"""
                        INSERT INTO {self.table_name} (journey_ref, stop_code, delay_seconds, is_cancelled, expected_time, updated_at)
                        VALUES (%s, %s, %s, %s, %s, NOW())
                        ON DUPLICATE KEY UPDATE
                            delay_seconds = VALUES(delay_seconds),
                            is_cancelled = VALUES(is_cancelled),
                            expected_time = VALUES(expected_time),
                            updated_at = NOW()
                    """
                    cursor.execute(sql_fallback, (journey_ref, stop_code, int(delay_seconds), 1 if is_cancelled else 0, expected_time))
                    cursor.close()
                except Exception as e2:
                    print(f"[DB Error] upsert failed: {e2}")

    def cleanup_old_records(self):
        """Delete records older than 1 hour."""
        with self.lock:
            self.ensure_connection()
            if not self.conn:
                return
            try:
                cursor = self.conn.cursor()
                sql = f"DELETE FROM {self.table_name} WHERE updated_at < NOW() - INTERVAL 1 HOUR"
                cursor.execute(sql)
                deleted = cursor.rowcount
                cursor.close()
                if deleted > 0:
                    print(f"[DB Cleanup] Removed {deleted} records older than 1 hour.")
            except Exception as e:
                print(f"[DB Error] cleanup failed: {e}")


class DelayNormalizer:
    """Buffers updates per journey and suppresses upstream cancellations before DB writes.

    This groups updates arriving close together for the same journey and applies
    the "earliest cancelled stop" heuristic using the timetable stop_order data
    (stop_offsets, scheduled_stops, assignments) before calling DelayDatabase.upsert_delay().
    """
    def __init__(self, db, flush_delay=0.6):
        self.db = db
        self.flush_delay = flush_delay
        self._buffer = {}
        self._lock = threading.Lock()

    def _canonical_journey_key(self, journey_ref):
        """Return a canonical key for grouping journey variants."""
        return str(journey_ref).strip()

    def buffer_entry(self, journey_ref, stop_code, delay_seconds, is_cancelled, expected_time=None, service_date=None, change_type=0, is_extra_train=False, infoplus_structured=False):
        if not journey_ref or not stop_code:
            return
        if not service_date:
            service_date = compute_service_date(expected_time)
        entry = {
            'journey_ref': str(journey_ref),
            'stop_code': str(stop_code),
            'delay_seconds': int(delay_seconds) if delay_seconds is not None else 0,
            'is_cancelled': bool(is_cancelled),
            'expected_time': expected_time,
            'service_date': service_date,
            'change_type': int(change_type or 0),
            'is_extra_train': bool(is_extra_train),
            'infoplus_structured': bool(infoplus_structured)
        }
        key = self._canonical_journey_key(entry['journey_ref'])
        with self._lock:
            item = self._buffer.get(key)
            if item is None:
                timer = threading.Timer(self.flush_delay, self._flush_journey, args=(key,))
                timer.daemon = True
                self._buffer[key] = {'entries': [entry], 'timer': timer}
                timer.start()
            else:
                item['entries'].append(entry)

    def _flush_journey(self, journey_ref):
        with self._lock:
            item = self._buffer.pop(journey_ref, None)
        if not item:
            return
        entries = item['entries']

        # RitInfo already carries station-level change semantics. Do not apply the
        # legacy cancellation-normalisation heuristic to these authoritative rows.
        if entries and all(e.get('infoplus_structured') for e in entries):
            for e in entries:
                self.db.upsert_delay(e['journey_ref'], e['stop_code'], e['delay_seconds'], e['is_cancelled'], e['expected_time'], e.get('service_date'), e.get('change_type', 0), e.get('is_extra_train', False))
            return

        # Attempt to determine stop_order mapping from the timetable for this journey
        try:
            self.db.ensure_connection()
            conn = self.db.conn
            if not conn:
                # Fallback: write raw
                for e in entries:
                    self.db.upsert_delay(e['journey_ref'], e['stop_code'], e['delay_seconds'], e['is_cancelled'], e.get('expected_time'), e.get('service_date'))
                return

            cursor = conn.cursor()
            prefix = self.db.config.get('prefix', '')
            journeys_table = prefix + 'ovhi_journeys'
            stop_offsets_table = prefix + 'ovhi_stop_offsets'
            scheduled_stops_table = prefix + 'ovhi_scheduled_stops'
            assignments_table = prefix + 'ovhi_assignments'

            # Try multiple candidate journey_ref values: the canonical key plus any original
            # journey_ref strings present in the buffered entries. This helps with split
            # services that use variants like '300552' vs '552'.
            candidates = [journey_ref]
            candidates.extend([e['journey_ref'] for e in entries if e['journey_ref'] not in candidates])

            best = None
            best_coverage = 0
            coverage_threshold_ratio = 0.5
            best_stop_rows = None
            best_pattern_ref = None
            best_time_ref = None

            unique_stop_codes = set(e['stop_code'] for e in entries if e.get('stop_code'))
            if not unique_stop_codes:
                # nothing to match on — fallback to raw write
                cursor.close()
                for e in entries:
                    self.db.upsert_delay(e['journey_ref'], e['stop_code'], e['delay_seconds'], e['is_cancelled'], e.get('expected_time'), e.get('service_date'))
                return

            for cand in candidates:
                try:
                    cursor.execute(f"SELECT service_journey_pattern_ref, time_demand_type_ref FROM {journeys_table} WHERE journey_ref = %s LIMIT 1", (cand,))
                    r = cursor.fetchone()
                    if not r or not r[0] or not r[1]:
                        continue
                    patt_ref = r[0]
                    t_ref = r[1]

                    query = f"SELECT so.stop_order, st.user_stop_code, ass.quay_code FROM {stop_offsets_table} so LEFT JOIN {scheduled_stops_table} st ON st.scheduled_stop_point_ref = so.scheduled_stop_point_ref LEFT JOIN {assignments_table} ass ON ass.scheduled_stop_point_ref = so.scheduled_stop_point_ref WHERE so.service_journey_pattern_ref = %s AND so.time_demand_type_ref = %s"
                    cursor.execute(query, (patt_ref, t_ref))
                    stop_rows = cursor.fetchall()

                    if not stop_rows:
                        continue

                    # build map and compute coverage
                    stop_order_map_tmp = {}
                    for sr in stop_rows:
                        stop_order = sr[0]
                        user_stop = sr[1] if len(sr) > 1 else None
                        quay_code = sr[2] if len(sr) > 2 else None
                        if stop_order is None:
                            continue
                        if user_stop:
                            stop_order_map_tmp[str(user_stop)] = int(stop_order)
                        if quay_code:
                            stop_order_map_tmp[str(quay_code)] = int(stop_order)
                            m = re.match(r'^NL:Q:(\\d+)$', str(quay_code), re.IGNORECASE)
                            if m:
                                stop_order_map_tmp[m.group(1)] = int(stop_order)

                    # count how many unique_stop_codes appear in this pattern
                    coverage = sum(1 for sc in unique_stop_codes if sc in stop_order_map_tmp)

                    # prefer candidate with highest coverage
                    if coverage > best_coverage:
                        best_coverage = coverage
                        best = cand
                        best_stop_rows = stop_rows
                        best_pattern_ref = patt_ref
                        best_time_ref = t_ref

                except Exception:
                    continue

            # load normalization config and require sufficient coverage before trusting mapping
            cfg = load_normalization_config()
            if not cfg.get('enabled', True):
                try:
                    cursor.close()
                except Exception:
                    pass
                for e in entries:
                    self.db.upsert_delay(e['journey_ref'], e['stop_code'], e['delay_seconds'], e['is_cancelled'], e['expected_time'])
                return

            coverage_threshold_ratio = float(cfg.get('coverage_threshold', 0.5))
            if best is None or best_coverage < 1 or (best_coverage / max(1, len(unique_stop_codes))) < coverage_threshold_ratio:
                # No reliable mapping found — write raw
                try:
                    cursor.close()
                except Exception:
                    pass
                for e in entries:
                    self.db.upsert_delay(e['journey_ref'], e['stop_code'], e['delay_seconds'], e['is_cancelled'], e['expected_time'])
                return

            # Use the chosen mapping
            pattern_ref = best_pattern_ref
            time_ref = best_time_ref
            stop_rows = best_stop_rows
            logger = logging.getLogger('ov-realtime-daemon')
            logger.debug(f"[Normalizer Debug] chosen candidate={best} coverage={best_coverage}/{len(unique_stop_codes)} pattern_ref={pattern_ref} time_ref={time_ref}")

            cursor.close()

            # Build mapping stop_code -> stop_order from chosen pattern
            stop_order_map = {}
            for sr in stop_rows:
                stop_order = sr[0]
                user_stop = sr[1] if len(sr) > 1 else None
                quay_code = sr[2] if len(sr) > 2 else None
                if stop_order is None:
                    continue
                if user_stop:
                    stop_order_map[str(user_stop)] = int(stop_order)
                if quay_code:
                    stop_order_map[str(quay_code)] = int(stop_order)
                    m = re.match(r'^NL:Q:(\\d+)$', str(quay_code), re.IGNORECASE)
                    if m:
                        stop_order_map[m.group(1)] = int(stop_order)

            # After choosing a pattern_ref we only merge buffered entries that map
            # to the same pattern_ref/time_ref to avoid merging unrelated services.
            to_merge_keys = []
            merged_entries = []
            try:
                cfg = load_normalization_config()
                merge_tolerance_seconds = int(cfg.get('merge_tolerance_seconds', 120))
                with self._lock:
                    for other_key, other_item in list(self._buffer.items()):
                        # skip self
                        if other_key == journey_ref:
                            continue
                        try:
                            c = conn.cursor()
                            c.execute(f"SELECT service_journey_pattern_ref, time_demand_type_ref FROM {journeys_table} WHERE journey_ref = %s LIMIT 1", (other_key,))
                            r2 = c.fetchone()
                            c.close()
                        except Exception:
                            r2 = None
                        if not r2 or not r2[0] or not r2[1]:
                            continue
                        if r2[0] != pattern_ref or r2[1] != time_ref:
                            # different pattern/time — skip merging
                            continue

                        # same pattern/time — check if any entry matches on stop_code + time tolerance
                        keep_other = False
                        for oe in other_item['entries']:
                            code = oe.get('stop_code')
                            if not code or code not in stop_order_map:
                                continue
                            try:
                                oe_dt = datetime.strptime(oe['expected_time'], '%Y-%m-%d %H:%M:%S') if oe.get('expected_time') else None
                            except Exception:
                                oe_dt = None
                            matched = False
                            for me in entries:
                                try:
                                    me_dt = datetime.strptime(me['expected_time'], '%Y-%m-%d %H:%M:%S') if me.get('expected_time') else None
                                except Exception:
                                    me_dt = None
                                if me_dt and oe_dt:
                                    if abs((me_dt - oe_dt).total_seconds()) <= merge_tolerance_seconds:
                                        matched = True
                                        break
                                else:
                                    matched = True
                                    break
                            if matched:
                                keep_other = True
                                break

                        if keep_other:
                            to_merge_keys.append(other_key)
                            merged_entries.extend(other_item['entries'])

                # remove merged keys from buffer and cancel their timers
                for k in to_merge_keys:
                    it = self._buffer.pop(k, None)
                    if it and it.get('timer'):
                        try:
                            it['timer'].cancel()
                        except Exception:
                            pass
            except Exception:
                pass

            if merged_entries:
                entries.extend(merged_entries)
                logger = logging.getLogger('ov-realtime-daemon')
                logger.debug(f"[Normalizer Debug] merged keys={to_merge_keys} into {journey_ref}")


            # Find earliest cancelled stop order among buffered entries where mapping exists
            earliest_cancel_order = None
            for e in entries:
                if not e['is_cancelled']:
                    continue
                code = e['stop_code']
                mapped_order = None
                if code in stop_order_map:
                    mapped_order = stop_order_map[code]
                else:
                    m = re.match(r'^NL:Q:(\\d+)$', code, re.IGNORECASE)
                    if m and m.group(1) in stop_order_map:
                        mapped_order = stop_order_map[m.group(1)]
                    elif re.match(r'^\\d+$', code) and ('NL:Q:' + code) in stop_order_map:
                        mapped_order = stop_order_map['NL:Q:' + code]
                if mapped_order is not None:
                    if earliest_cancel_order is None or mapped_order < earliest_cancel_order:
                        earliest_cancel_order = mapped_order

            if earliest_cancel_order is None:
                # Could not determine a mapped cancelled stop — write raw
                for e in entries:
                    self.db.upsert_delay(e['journey_ref'], e['stop_code'], e['delay_seconds'], e['is_cancelled'], e.get('expected_time'), e.get('service_date'))
                return

            # Clear is_cancelled for upstream stops (stop_order < earliest_cancel_order)
            for e in entries:
                code = e['stop_code']
                mapped_order = None
                if code in stop_order_map:
                    mapped_order = stop_order_map[code]
                else:
                    m = re.match(r'^NL:Q:(\\d+)$', code, re.IGNORECASE)
                    if m and m.group(1) in stop_order_map:
                        mapped_order = stop_order_map[m.group(1)]
                    elif re.match(r'^\\d+$', code) and ('NL:Q:' + code) in stop_order_map:
                        mapped_order = stop_order_map['NL:Q:' + code]
                if mapped_order is not None and mapped_order < earliest_cancel_order and e['is_cancelled']:
                    e['is_cancelled'] = False

            # Upsert all sanitized entries
            for e in entries:
                self.db.upsert_delay(e['journey_ref'], e['stop_code'], e['delay_seconds'], e['is_cancelled'], e.get('expected_time'), e.get('service_date'))

        except Exception as exc:
            logger = logging.getLogger('ov-realtime-daemon')
            logger.error(f"[Normalizer Error] Failed during normalization for {journey_ref}: {exc}")
            # Fallback: write raw entries to ensure data reaches DB even on error
            for e in entries:
                try:
                    self.db.upsert_delay(e['journey_ref'], e['stop_code'], e['delay_seconds'], e['is_cancelled'], e.get('expected_time'), e.get('service_date'))
                except Exception:
                    continue


def parse_iso_duration(duration_str):
    """Parse ISO 8601 duration string like PT2M or PT1H5M into seconds."""
    if not duration_str:
        return 0
    if duration_str.isdigit() or (duration_str.startswith('-') and duration_str[1:].isdigit()):
        return int(duration_str) * 60
    match = re.search(r'PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?', duration_str)
    if not match:
        return 0
    hours = int(match.group(1) or 0)
    minutes = int(match.group(2) or 0)
    seconds = int(match.group(3) or 0)
    return hours * 3600 + minutes * 60 + seconds


def strip_namespace(tag):
    if tag is None:
        return ''
    if '}' in tag:
        return tag.split('}', 1)[1]
    return tag


def find_text_any(element, tag_names):
    """Return the first non-empty descendant text or matching attribute for any of the specified tags."""
    normalized_tags = {name.lower() for name in tag_names}
    for child in element.iter():
        tag_name = strip_namespace(child.tag).lower()
        if tag_name in normalized_tags:
            text = child.text or ''
            if text.strip():
                return text.strip()
        for attr_name, attr_value in child.attrib.items():
            if attr_name.lower() in normalized_tags and attr_value.strip():
                return attr_value.strip()
    return ''


def find_elements_by_names(root, tag_names):
    normalized_tags = {name.lower() for name in tag_names}
    return [el for el in root.iter() if strip_namespace(el.tag).lower() in normalized_tags]


def extract_base_train_number(train_num):
    """Extract the base train number from NS replacement train numbers.

    NS uses prefixed 6-digit numbers for replacement / coupled services:
      1xxxxx, 2xxxxx, 3xxxxx  -> base = xxxxx (strip first digit)
      700000-749999           -> base = xxxxx (strip first digit)
      690000-699999           -> base = xxxx  (strip first two digits)
    Leading zeros in the remainder are stripped.
    Returns the base number as a string, or None if not a prefixed number.
    """
    train_num = str(train_num).strip()
    if len(train_num) == 6 and train_num.isdigit():
        val = int(train_num)
        rest = None
        if 100000 <= val <= 399999 or 700000 <= val <= 749999:
            rest = train_num[1:]
        elif 690000 <= val <= 699999:
            rest = train_num[2:]
        if rest is not None:
            base = rest.lstrip('0')
            if base:
                return base
    return None


class KV78Subscriber(threading.Thread):
    """Subscriber thread for BISON KV78turbo bus feed."""
    def __init__(self, endpoint, db, normalizer=None):
        super().__init__(daemon=True)
        self.endpoint = endpoint
        self.db = db
        # Use shared DelayNormalizer if provided, otherwise create one for compatibility
        self.normalizer = normalizer if normalizer is not None else DelayNormalizer(db)

    def run(self):
        print(f"[KV78] Connecting to {self.endpoint}...")
        ctx = zmq.Context()
        sock = ctx.socket(zmq.SUB)
        sock.connect(self.endpoint)
        sock.setsockopt_string(zmq.SUBSCRIBE, "")

        while True:
            try:
                frames = sock.recv_multipart()
                if len(frames) < 2:
                    continue
                envelope = frames[0].decode('utf-8', errors='ignore')
                raw_payload = frames[1]

                # Decompress if gzipped
                try:
                    payload = gzip.decompress(raw_payload).decode('utf-8', errors='ignore')
                except Exception:
                    payload = raw_payload.decode('utf-8', errors='ignore')

                self.parse_ctx(payload)
            except Exception as e:
                print(f"[KV78 Error] {e}")
                time.sleep(1)

    def parse_ctx(self, payload):
        lines = payload.splitlines()
        current_table = ""
        headers = []

        for line in lines:
            line = line.strip()
            if not line:
                continue
            if line.startswith("\\T"):
                parts = line.split("\\")
                if len(parts) >= 2:
                    current_table = parts[1].strip()
            elif line.startswith("\\L"):
                headers = [h.strip() for h in line[2:].split("|")]
            elif current_table in ("DATEDPASSTIME", "LOCALSERVICEGROUPPASSTIME") and not line.startswith("\\"):
                values = [v.strip() for v in line.split("|")]
                if len(values) == len(headers):
                    row = dict(zip(headers, values))
                    self.process_passtime(row)

    def process_passtime(self, row):
        data_owner = row.get("DataOwnerCode") or row.get("DataOwner") or ""
        line_num = row.get("LinePlanningNumber") or ""
        journey_num = row.get("JourneyNumber") or row.get("LocalServiceLevelCode") or ""
        user_stop = row.get("UserStopCode") or row.get("TimingPointCode") or ""
        status = (row.get("TripStopStatus") or row.get("PasstimeEffect") or "").upper()

        target_dep = row.get("TargetDepartureTime") or row.get("TargetArrivalTime") or ""
        expected_dep = row.get("ExpectedDepartureTime") or row.get("ExpectedArrivalTime") or ""

        if isinstance(user_stop, str):
            user_stop = user_stop.strip()
            m = re.match(r'^(?:nl:q:)(\d+)$', user_stop, re.IGNORECASE)
            if m:
                user_stop = f"NL:Q:{m.group(1)}"

        if not user_stop or not journey_num:
            return

        is_cancelled = status in ("CANCEL", "CANCELLED", "DELETED", "NOTDRIVING")
        delay_seconds = 0

        expected_time = None
        if target_dep and expected_dep:
            try:
                # target_dep/expected_dep are usually time strings HH:MM:SS
                fmt = "%H:%M:%S"
                t_dt = datetime.strptime(target_dep[:8], fmt)
                e_dt = datetime.strptime(expected_dep[:8], fmt)
                delay_seconds = int((e_dt - t_dt).total_seconds())
                if delay_seconds < -43200:
                    delay_seconds += 86400
                elif delay_seconds > 43200:
                    delay_seconds -= 86400
                # derive expected_time as today + expected_dep
                today = datetime.now(AMSTERDAM_TZ)
                exp_dt = today.replace(hour=e_dt.hour, minute=e_dt.minute, second=e_dt.second, microsecond=0)
                expected_time = exp_dt.strftime('%Y-%m-%d %H:%M:%S')
            except Exception:
                pass

        # Normalize journey_num: strip leading zeros so it always matches the
        # short, canonical form used elsewhere (e.g. "07013" -> "7013"). This
        # mirrors the normalization applied to train numbers in
        # ov-trein-dienstregeling.php, so both sides of the match always use
        # the same representation regardless of any zero-padding quirks in
        # the source feed.
        journey_num = str(journey_num).strip()
        if journey_num.isdigit():
            journey_num = str(int(journey_num))

        # Normalize line_num op dezelfde manier (KV78 LinePlanningNumber). Gecontroleerd
        # tegen de EBS/Qbuzz NeTEx-bronbestanden: dit is exact hetzelfde veld/dezelfde
        # waarde als NeTEx's <PrivateCode type="LinePlanningNumber"> op Line-niveau
        # (bv. "4247" voor EBS-lijn 247, "b510" voor Qbuzz-lijn 510) - NIET hetzelfde
        # als het publieke lijnnummer (<PublicCode>, bv. "247"/"510"). Beide kanten
        # (deze daemon en de WordPress-matching) moeten dus consequent met
        # LinePlanningNumber vergelijken, nooit met het publieke lijnnummer.
        line_num = str(line_num).strip()
        if line_num.isdigit():
            line_num = str(int(line_num))

        # Construct candidate journey_ref patterns.
        #
        # BELANGRIJK: JourneyNumber is bij KV78turbo alleen uniek per
        # vervoerder+lijn+dag, niet per vervoerder alleen. Twee lijnen van
        # dezelfde (of zelfs verschillende) vervoerder(s) kunnen hetzelfde
        # ritnummer gebruiken. Komen die toevallig ook langs dezelfde
        # (drukke) halte, dan matchte een kaal ritnummer voorheen de
        # verkeerde lijn/rit (haltes toonden "rijdt niet" voor een andere
        # lijn dan de daadwerkelijk vervallen rit).
        #
        # Daarom is de lijn-gekwalificeerde vorm
        # "<OPERATOR>:<LIJNNUMMER>:<RITNUMMER>" nu de primaire sleutel die
        # we wegschrijven. Alleen als het lijnnummer echt ontbreekt in de
        # feed vallen we terug op "<OPERATOR>:<RITNUMMER>" - nog steeds
        # ambigu tussen lijnen van dezelfde vervoerder, maar beter dan
        # niets. De volledig kale vorm (ritnummer zonder vervoerder/lijn)
        # schrijven we bewust niet meer weg: dat was de meest gevaarlijke
        # variant, want die kan ook nog tussen vervoerders botsen.
        journey_refs = set()

        if data_owner and line_num:
            journey_refs.add(f"{data_owner}:{line_num}:{journey_num}")
        elif data_owner:
            journey_refs.add(f"{data_owner}:{journey_num}")
        else:
            journey_refs.add(str(journey_num))

        for journey_ref in journey_refs:
            # Buffer via normalizer so upstream cancellations are suppressed where appropriate
            self.normalizer.buffer_entry(journey_ref, user_stop, delay_seconds, is_cancelled, expected_time)

            if user_stop.isdigit():
                self.normalizer.buffer_entry(
                    journey_ref,
                    "NL:Q:" + user_stop,
                    delay_seconds,
                    is_cancelled,
                    expected_time
                )


class InfoPlusSubscriber(threading.Thread):
    """Subscriber thread for NS InfoPlus DVS train feed."""
    def __init__(self, endpoint, db, normalizer=None):
        super().__init__(daemon=True)
        self.endpoint = endpoint
        self.db = db
        # Use shared DelayNormalizer if provided, otherwise create one for compatibility
        self.normalizer = normalizer if normalizer is not None else DelayNormalizer(db)
        # Attempt to load configured InfoPlus cancellation codes from the plugin
        self.infoplus_cancel_codes = set((25, 32, 34, 39, 44))
        try:
            plugin_dir = os.path.dirname(__file__)
            codes_file = os.path.join(plugin_dir, 'infoplus_cancel_codes.json')
            if os.path.exists(codes_file):
                import json
                with open(codes_file, 'r', encoding='utf-8') as fh:
                    data = json.load(fh)
                    if isinstance(data, list):
                        codes = set()
                        for v in data:
                            try:
                                codes.add(int(v))
                            except Exception:
                                continue
                        if len(codes) > 0:
                            self.infoplus_cancel_codes = codes
        except Exception:
            # ignore — fallback to built-in defaults
            pass

    def run(self):
        print(f"[InfoPlus] Connecting to {self.endpoint}...")
        ctx = zmq.Context()
        sock = ctx.socket(zmq.SUB)
        sock.connect(self.endpoint)
        sock.setsockopt_string(zmq.SUBSCRIBE, "")

        while True:
            try:
                frames = sock.recv_multipart()
                if len(frames) < 2:
                    continue
                raw_payload = frames[1]

                try:
                    payload = gzip.decompress(raw_payload).decode('utf-8', errors='ignore')
                except Exception:
                    payload = raw_payload.decode('utf-8', errors='ignore')

                self.parse_xml(payload)
            except Exception as e:
                print(f"[InfoPlus Error] {e}")
                time.sleep(1)

    def parse_xml(self, xml_content):
        try:
            root = ET.fromstring(xml_content)
            # RitInfo is the authoritative, full train message. It contains
            # TreinDatum and station/ritdeel WijzigingType per stop.
            for rit_info in find_elements_by_names(root, ['RitInfo']):
                self.process_rit_info(rit_info)
        except Exception as exc:
            print(f"[InfoPlus Error] parse_xml failed: {exc}")

    def process_rit_info(self, rit_info):
        """Process the documented RitInfo hierarchy without guessing statuses.

        InfoPlus identifies a service by TreinDatum + TreinNummer. Change type
        24 means a separate Extra trein; 25 is a cancellation. Other change
        types (notably 34, 36 and 37) are route/platform changes, never cancellations.
        """
        train_num = find_text_any(rit_info, ['TreinNummer', 'RitNummer', 'LogischeRitNummer', 'Trein', 'JourneyRef'])
        train_date = find_text_any(rit_info, ['TreinDatum', 'Treindatum'])
        if not train_num:
            return
        train_num = str(int(train_num)) if train_num.strip().isdigit() else train_num.strip()
        train_date = train_date[:10] if train_date else compute_service_date()

        def direct_change_types(element):
            values = []
            for child in list(element):
                if strip_namespace(child.tag).lower() != 'wijziging':
                    continue
                value = find_text_any(child, ['WijzigingType'])
                if value and value.strip().isdigit():
                    values.append(int(value.strip()))
            return values

        def walk(element, inherited_types):
            change_types = inherited_types + direct_change_types(element)
            if strip_namespace(element.tag).lower() == 'logischeritdeelstation':
                station_code = find_text_any(element, ['StationCode'])
                if station_code:
                    station_code = station_code.strip()
                    m = re.match(r'^(?:nl:q:)(\d+)$', station_code, re.IGNORECASE)
                    if m:
                        station_code = f"NL:Q:{m.group(1)}"
                    else:
                        station_code = station_code.lower()

                    departure_time = find_text_any(element, ['ActueleVertrekTijd', 'GeplandeVertrekTijd', 'VertrekTijd', 'KorteVertrekTijd'])
                    arrival_time = find_text_any(element, ['ActueleAankomstTijd', 'GeplandeAankomstTijd', 'AankomstTijd', 'KorteAankomstTijd'])
                    delay_value = find_text_any(element, ['ExacteVertrekVertraging', 'PresentatieVertrekVertraging', 'GedempteVertrekVertraging', 'ExacteAankomstVertraging', 'PresentatieAankomstVertraging', 'GedempteAankomstVertraging', 'VertrekVertraging', 'AankomstVertraging', 'Vertraging'])
                    delay_sec = parse_iso_duration(delay_value)

                    is_extra = 24 in change_types
                    is_cancelled = (25 in change_types) or any(c in self.infoplus_cancel_codes for c in change_types if c != 34)
                    change_type = 24 if is_extra else (25 if is_cancelled else (change_types[0] if change_types else 0))

                    exp_time = parse_and_format_datetime(departure_time or arrival_time, delay_sec if ('geplande' in (departure_time or arrival_time or '').lower() or 'korte' in (departure_time or arrival_time or '').lower()) else 0)

                    target_train_num = train_num
                    if not is_extra:
                        base_train_num = extract_base_train_number(train_num)
                        if base_train_num:
                            target_train_num = base_train_num

                    self.normalizer.buffer_entry(target_train_num, station_code, delay_sec, is_cancelled, exp_time, service_date=train_date, change_type=change_type, is_extra_train=is_extra, infoplus_structured=True)
                return
            for child in list(element):
                walk(child, change_types)

        # Preserve change types declared at RitInfo, LogischeRit and
        # LogischeRitDeel level for every station below that level.
        walk(rit_info, [])

    def process_dvs(self, dvs):
        """Process DVS messages strictly scoping station code to RitStation."""
        rit_station_el = None
        for child in dvs.iter():
            if strip_namespace(child.tag).lower() == 'ritstation':
                rit_station_el = child
                break
        if rit_station_el is None:
            return
        station_code = find_text_any(rit_station_el, ['StationCode'])
        if not station_code:
            return

        station_code = station_code.strip()
        m = re.match(r'^(?:nl:q:)(\d+)$', station_code, re.IGNORECASE)
        if m:
            station_code = f"NL:Q:{m.group(1)}"
        else:
            station_code = station_code.lower()

        for trein in find_elements_by_names(dvs, ['Trein']):
            train_num = find_text_any(trein, ['TreinNummer', 'RitId', 'RitNummer', 'LogischeRitNummer'])
            if not train_num:
                continue
            train_num = train_num.strip()
            if train_num.isdigit():
                train_num = str(int(train_num))

            status_text = find_text_any(trein, ['WijzigingType', 'RitStatus', 'RitStopStatus', 'InfoStatus']).upper().strip()
            is_cancelled = False
            try:
                if status_text.isdigit():
                    code = int(status_text)
                    if code == 25 or (code in self.infoplus_cancel_codes and code != 34):
                        is_cancelled = True
            except Exception:
                pass

            cancel_statuses = ('CANCEL', 'CANCELLED', 'VERVALLEN', 'GEANNULEERD', 'RIJDETNIET', 'NOTDRIVING', 'DELETED')
            if status_text in cancel_statuses:
                is_cancelled = True

            delay_seconds = 0
            delay_elem = find_text_any(trein, ['ExacteVertrekVertraging', 'PresentatieVertrekVertraging', 'GedempteVertrekVertraging', 'ExacteAankomstVertraging', 'PresentatieAankomstVertraging', 'GedempteAankomstVertraging', 'VertrekVertraging', 'AankomstVertraging', 'Vertraging'])
            if delay_elem:
                delay_seconds = parse_iso_duration(delay_elem)

            dep_time_raw = find_text_any(trein, ['ActueleVertrekTijd', 'GeplandeVertrekTijd', 'VertrekTijd', 'KorteVertrekTijd'])
            arr_time_raw = find_text_any(trein, ['ActueleAankomstTijd', 'GeplandeAankomstTijd', 'AankomstTijd', 'KorteAankomstTijd'])
            time_raw = dep_time_raw if dep_time_raw else arr_time_raw
            expected_time = parse_and_format_datetime(time_raw, delay_seconds if time_raw and ('geplande' in time_raw.lower() or 'korte' in time_raw.lower()) else 0)

            self.normalizer.buffer_entry(train_num, station_code, delay_seconds, is_cancelled, expected_time, infoplus_structured=True)


def main():
    parser = argparse.ArgumentParser(description="Ovalino Realtime Data Daemon")
    parser.add_argument('--wp-config', help="Path to wp-config.php")
    parser.add_argument('--db-host', help="Database host")
    parser.add_argument('--db-user', help="Database user")
    parser.add_argument('--db-pass', help="Database password")
    parser.add_argument('--db-name', help="Database name")
    parser.add_argument('--prefix', help="Database table prefix (e.g. wp_)")
    args = parser.parse_args()

    wp_config_file = args.wp_config or find_wp_config()
    db_config = parse_wp_config(wp_config_file) if wp_config_file else {
        'host': 'localhost', 'user': 'root', 'password': '', 'database': '', 'prefix': 'wp_'
    }

    if args.db_host: db_config['host'] = args.db_host
    if args.db_user: db_config['user'] = args.db_user
    if args.db_pass: db_config['password'] = args.db_pass
    if args.db_name: db_config['database'] = args.db_name
    if args.prefix: db_config['prefix'] = args.prefix

    print(f"Starting Ovalino Realtime Daemon (Host: {db_config['host']}, DB: {db_config['database']}, Prefix: {db_config['prefix']})")

    db = DelayDatabase(db_config)
    # Shared normalizer instance so both subscribers apply the same suppression logic
    normalizer = DelayNormalizer(db)

    kv78_endpoint = "tcp://pubsub.besteffort.ndovloket.nl:7817"
    infoplus_endpoint = "tcp://pubsub.besteffort.ndovloket.nl:7664"

    kv78_thread = KV78Subscriber(kv78_endpoint, db, normalizer)
    infoplus_thread = InfoPlusSubscriber(infoplus_endpoint, db, normalizer)

    kv78_thread.start()
    infoplus_thread.start()

    print("[Daemon] Listening for bus and train realtime updates...")

    try:
        while True:
            time.sleep(300) # Run cleanup every 5 minutes
            db.cleanup_old_records()
    except KeyboardInterrupt:
        print("\n[Daemon] Stopping...")


if __name__ == '__main__':
    main()