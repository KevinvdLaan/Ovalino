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
from datetime import datetime, timedelta

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


class DelayDatabase:
    """Manages MySQL connection and updates for realtime delays."""
    def __init__(self, db_config):
        self.config = db_config
        self.table_name = db_config['prefix'] + 'ovhi_realtime_delays'
        self.conn = None
        self.lock = threading.Lock()
        self.connect()

    def connect(self):
        try:
            if 'mysql.connector' in sys.modules:
                self.conn = mysql.connector.connect(
                    host=self.config['host'],
                    user=self.config['user'],
                    password=self.config['password'],
                    database=self.config['database'],
                    autocommit=True
                )
            else:
                self.conn = mysql.connect(
                    host=self.config['host'],
                    user=self.config['user'],
                    password=self.config['password'],
                    database=self.config['database'],
                    autocommit=True
                )
            print(f"[DB] Connected successfully to {self.config['database']}")
        except Exception as e:
            print(f"[DB Error] Connection failed: {e}")
            self.conn = None

    def ensure_connection(self):
        if self.conn is None:
            self.connect()
            return
        try:
            if hasattr(self.conn, 'ping'):
                self.conn.ping(reconnect=True)
        except Exception:
            self.connect()

    def upsert_delay(self, journey_ref, stop_code, delay_seconds, is_cancelled, expected_time=None):
        """Insert or update delay record.

        expected_time should be a string in 'YYYY-MM-DD HH:MM:SS' format or None.
        """
        if not journey_ref or not stop_code:
            return
        with self.lock:
            self.ensure_connection()
            if not self.conn:
                return
            try:
                cursor = self.conn.cursor()
                sql = f"""
                    INSERT INTO {self.table_name} (journey_ref, stop_code, delay_seconds, is_cancelled, updated_at)
                    VALUES (%s, %s, %s, %s, NOW())
                    ON DUPLICATE KEY UPDATE
                        delay_seconds = VALUES(delay_seconds),
                        is_cancelled = VALUES(is_cancelled),
                        updated_at = NOW()
                """
                params = (journey_ref, stop_code, int(delay_seconds), 1 if is_cancelled else 0)
                cursor.execute(sql, params)
                cursor.close()
            except Exception as e:
                print(f"[DB Error] upsert failed: {e}")

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


def token_in_text(text, tokens):
    """Return True if any token is found as a whole word in text (case-insensitive)."""
    if not text:
        return False
    for token in tokens:
        # Use word boundaries to avoid matching substrings inside other words/numbers
        try:
            if re.search(r"\b" + re.escape(token) + r"\b", text, re.IGNORECASE):
                return True
        except re.error:
            # Fallback simple containment if regex fails for any token
            if token.upper() in text.upper():
                return True
    return False


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


class KV78Subscriber(threading.Thread):
    """Subscriber thread for BISON KV78turbo bus feed."""
    def __init__(self, endpoint, db):
        super().__init__(daemon=True)
        self.endpoint = endpoint
        self.db = db

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
                today = datetime.now()
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

        # Construct candidate journey_ref patterns
        # NeTEx journey_ref format: NL:<OPERATOR>:ServiceJourney:<LINE>-<VARIANT>-<JOURNEY_NUM>-<AVAIL>
        # Or simple fallback: JourneyNumber
        journey_refs = set()

        if data_owner:
            journey_refs.add(f"{data_owner}:{journey_num}")

        journey_refs.add(str(journey_num))

        for journey_ref in journey_refs:
            self.db.upsert_delay(journey_ref, user_stop, delay_seconds, is_cancelled, expected_time)

            if user_stop.isdigit():
                self.db.upsert_delay(
                    journey_ref,
                    "NL:Q:" + user_stop,
                    delay_seconds,
                    is_cancelled,
                    expected_time
                )


class InfoPlusSubscriber(threading.Thread):
    """Subscriber thread for NS InfoPlus DVS train feed."""
    def __init__(self, endpoint, db):
        super().__init__(daemon=True)
        self.endpoint = endpoint
        self.db = db
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
            for dvs in find_elements_by_names(root, ['DynamischeVertrekStaat']):
                self.process_dvs(dvs)
            for rit_info in find_elements_by_names(root, ['RitInfo']):
                self.process_rit_info(rit_info)
        except Exception as exc:
            print(f"[InfoPlus Error] parse_xml failed: {exc}")

    def process_rit_info(self, rit_info):
        train_num = find_text_any(rit_info, ['TreinNummer', 'RitId', 'RitNummer', 'LogischeRitNummer', 'JourneyRef'])
        # Prefer explicit stop identifiers when present. If only an ambiguous
        # Station field is present, treat the stop as non-precise so that a
        # cancellation message that lacks a precise stop won't be blindly
        # applied to the original departure station.
        preferred_stop_tags = ['UserStopCode','StopPointRef','ScheduledStopPointRef','TimingPointCode','QuayCode','StationCode','StopCode','HalteCode','Station']
        station_code = find_text_any(rit_info, preferred_stop_tags)
        if not train_num or not station_code:
            return

        # Determine whether the message includes an explicit precise stop tag
        try:
            raw_xml_check = ET.tostring(rit_info, encoding='utf-8', method='xml').decode('utf-8').lower()
        except Exception:
            raw_xml_check = ''
        precise_tags = ['userstopcode','stoppointref','scheduledstoppointref','timingpointcode','quaycode','stationcode','stopcode','haltecode']
        precise_stop_found = any((('<' + t) in raw_xml_check) or (t + '=') in raw_xml_check for t in precise_tags)

        station_code = station_code.strip()
        m = re.match(r'^(?:nl:q:)(\d+)$', station_code, re.IGNORECASE)
        if m:
            station_code = f"NL:Q:{m.group(1)}"
        else:
            station_code = station_code.lower()
        train_num = train_num.strip()
        if train_num.isdigit():
            train_num = str(int(train_num))

        status_text = find_text_any(rit_info, ['RitWijzigingType', 'TreinStatus', 'WijzigingType', 'Status', 'RitStatus', 'RitStopStatus', 'TripStopStatus', 'PasstimeEffect', 'InfoStatus']).upper()
        detail_text = find_text_any(rit_info, ['RitWijzigingTekst', 'Tekst', 'Text', 'Bericht', 'BerichtTekst', 'Uiting']).upper()

        is_cancelled = False
        # Numeric status codes from InfoPlus: treat known codes as cancellation
        try:
            if status_text.isdigit():
                code = int(status_text)
                if code in self.infoplus_cancel_codes:
                    is_cancelled = True
        except Exception:
            pass

        cancel_tokens = ('CANCEL', 'VERVALLEN', 'RIJDETNIET', 'RIJDT NIET', 'NOTDRIVING', 'DELETED')
        # Use whole-word/token matching to reduce false positives from unrelated fields
        status_has_cancel = token_in_text(status_text, cancel_tokens)
        detail_has_cancel = token_in_text(detail_text, cancel_tokens)

        # Collect numeric IDs mentioned in the element (e.g. '300778') to avoid applying
        # a cancellation message that explicitly targets a different train number.
        raw_text_all = ''.join((child.text or '') + ''.join(child.attrib.values()) for child in rit_info.iter())
        numeric_ids = re.findall(r"\b(\d{3,})\b", raw_text_all)

        def cancel_appears_to_target_train(train_id, numeric_list):
            """Heuristic: if the message contains explicit numeric IDs, treat the
            cancellation as applying only when one of those IDs matches this train
            (or a common variant like '300'+train_id). If no numeric IDs are
            present, fall back to token-only matching and allow it to apply.
            """
            if numeric_list:
                try:
                    t = str(int(train_id))
                except Exception:
                    t = train_id
                variants = {t, '300' + t}
                for n in numeric_list:
                    if n in variants:
                        return True
                return False
            return True

        # Only apply cancellation if the message appears to target this train
        # and the message either contains explicit numeric ids (e.g. 300778) or a
        # precise stop identifier was found in the payload. This avoids blindly
        # applying ambiguous notices to the original departure station.
        if (status_has_cancel or detail_has_cancel) and cancel_appears_to_target_train(train_num, numeric_ids) and (numeric_ids or precise_stop_found):
            is_cancelled = True

        if not is_cancelled:
            # search raw_text with token_in_text which uses word-boundary regex
            if token_in_text(raw_text_all, cancel_tokens) and cancel_appears_to_target_train(train_num, numeric_ids) and (numeric_ids or precise_stop_found):
                is_cancelled = True
            if not is_cancelled:
                for child in rit_info.iter():
                    for attr_value in child.attrib.values():
                        if attr_value.isdigit() and int(attr_value) in self.infoplus_cancel_codes:
                            is_cancelled = True
                            break
                    if is_cancelled:
                        break

        delay_seconds = 0
        delay_elem = find_text_any(rit_info, ['ExacteVertrekVertraging', 'PresentatieVertrekVertraging', 'GedempteVertrekVertraging', 'ExacteAankomstVertraging', 'PresentatieAankomstVertraging', 'GedempteAankomstVertraging', 'VertrekVertraging', 'AankomstVertraging', 'Vertraging'])
        if delay_elem:
            delay_seconds = parse_iso_duration(delay_elem)

        # Try to extract an expected time if present in the rit_info element
        expected_time = None
        try:
            raw_xml = ET.tostring(rit_info, encoding='utf-8', method='xml').decode('utf-8')
            m = re.search(r'(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})', raw_xml)
            if m:
                # ISO timestamp
                dt = datetime.fromisoformat(m.group(1))
                expected_time = dt.strftime('%Y-%m-%d %H:%M:%S')
            else:
                # look for time-only like HH:MM or HH:MM:SS
                m2 = re.search(r'(\d{2}:\d{2}(?::\d{2})?)', raw_xml)
                if m2:
                    today = datetime.now()
                    t = datetime.strptime(m2.group(1), '%H:%M:%S') if ':' in m2.group(1) and m2.group(1).count(':')==2 else datetime.strptime(m2.group(1), '%H:%M')
                    dt = today.replace(hour=t.hour, minute=t.minute, second=getattr(t,'second',0), microsecond=0)
                    expected_time = dt.strftime('%Y-%m-%d %H:%M:%S')
        except Exception:
            expected_time = None

        # If feed provides an expected time but not an explicit delay, try to
        # derive delay_seconds from a scheduled/planned time present in the
        # same message (without persisting expected_time). This keeps
        # frontend behaviour relying on delay_seconds while avoiding storing
        # the fragile expected_time value.
        if delay_seconds == 0 and expected_time is not None:
            try:
                sched_text = find_text_any(rit_info, ['PlannedDepartureTime','PlannedTime','PlannedArrivalTime','TargetDepartureTime','TargetArrivalTime','ScheduledDepartureTime','ScheduledTime','VertrekTijd','GeplandeVertrekTijd'])
                if sched_text:
                    # parse scheduled time (accept ISO or HH:MM[:SS])
                    try:
                        if re.match(r'\d{4}-\d{2}-\d{2}T', sched_text):
                            sched_dt = datetime.fromisoformat(re.search(r'(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})', sched_text).group(1))
                        elif re.match(r'\d{2}:\d{2}(:\d{2})?$', sched_text):
                            t = datetime.strptime(sched_text if sched_text.count(':')==2 else sched_text + ':00', '%H:%M:%S')
                            today = datetime.now()
                            sched_dt = today.replace(hour=t.hour, minute=t.minute, second=getattr(t,'second',0), microsecond=0)
                        else:
                            sched_dt = None
                        if sched_dt is not None:
                            exp_dt = datetime.strptime(expected_time, '%Y-%m-%d %H:%M:%S')
                            delta = int((exp_dt - sched_dt).total_seconds())
                            # Adjust for day rollovers
                            if delta < -43200:
                                delta += 86400
                            elif delta > 43200:
                                delta -= 86400
                            delay_seconds = delta
                    except Exception:
                        pass
            except Exception:
                pass

        self.db.upsert_delay(train_num, station_code, delay_seconds, is_cancelled, expected_time)

    def process_dvs(self, dvs):
        train_num = find_text_any(dvs, ['TreinNummer', 'RitId', 'RitNummer', 'LogischeRitNummer'])
        station_code = find_text_any(dvs, ['StationCode'])
        if not train_num or not station_code:
            return

        station_code = station_code.strip()
        try:
            raw_xml_check = ET.tostring(dvs, encoding='utf-8', method='xml').decode('utf-8').lower()
        except Exception:
            raw_xml_check = ''
        precise_tags = ['userstopcode','stoppointref','scheduledstoppointref','timingpointcode','quaycode','stationcode','stopcode','haltecode']
        precise_stop_found = any((('<' + t) in raw_xml_check) or (t + '=') in raw_xml_check for t in precise_tags)

        m = re.match(r'^(?:nl:q:)(\d+)$', station_code, re.IGNORECASE)
        if m:
            station_code = f"NL:Q:{m.group(1)}"
        else:
            station_code = station_code.lower()
        train_num = train_num.strip()
        # Normalize to the canonical form without leading zeros. NS/InfoPlus
        # normally sends unpadded numbers already ("3617"), but this keeps
        # the daemon consistent with the zero-stripped train_number stored
        # by ov-trein-dienstregeling.php's IFF import (which otherwise
        # preserves fixed-width padding like "03617"), regardless of which
        # side ends up padding in the future.
        if train_num.isdigit():
            train_num = str(int(train_num))

        status_text = find_text_any(dvs, ['TreinStatus', 'WijzigingType', 'RitStatus', 'RitStopStatus', 'InfoStatus']).upper()
        detail_text = find_text_any(dvs, ['Tekst', 'Text', 'Bericht', 'BerichtTekst', 'Uiting']).upper()

        is_cancelled = False
        # Numeric status codes from InfoPlus: treat known codes as cancellation
        try:
            if status_text.isdigit():
                code = int(status_text)
                if code in self.infoplus_cancel_codes:
                    is_cancelled = True
        except Exception:
            pass

        cancel_tokens = ('CANCEL', 'VERVALLEN', 'RIJDETNIET', 'RIJDT NIET', 'NOTDRIVING', 'DELETED')
        # Use whole-word/token matching to reduce false positives from unrelated fields
        status_has_cancel = token_in_text(status_text, cancel_tokens)
        detail_has_cancel = token_in_text(detail_text, cancel_tokens)

        # Collect numeric IDs mentioned in the element (e.g. '300778') to avoid applying
        # a cancellation message that explicitly targets a different train number.
        raw_text_all = ''.join((child.text or '') + ''.join(child.attrib.values()) for child in dvs.iter())
        numeric_ids = re.findall(r"\b(\d{3,})\b", raw_text_all)

        def cancel_appears_to_target_train(train_id, numeric_list):
            if numeric_list:
                try:
                    t = str(int(train_id))
                except Exception:
                    t = train_id
                variants = {t, '300' + t}
                for n in numeric_list:
                    if n in variants:
                        return True
                return False
            return True

        # Only apply cancellation if the message appears to target this train
        # and the message either contains explicit numeric ids (e.g. 300778) or a
        # precise stop identifier was found in the payload.
        if (status_has_cancel or detail_has_cancel) and cancel_appears_to_target_train(train_num, numeric_ids) and (numeric_ids or precise_stop_found):
            is_cancelled = True

        if not is_cancelled:
            # search raw_text with token_in_text which uses word-boundary regex
            if token_in_text(raw_text_all, cancel_tokens) and cancel_appears_to_target_train(train_num, numeric_ids) and (numeric_ids or precise_stop_found):
                is_cancelled = True
            if not is_cancelled:
                for child in dvs.iter():
                    for attr_value in child.attrib.values():
                        if attr_value.isdigit() and int(attr_value) in self.infoplus_cancel_codes:
                            is_cancelled = True
                            break
                    if is_cancelled:
                        break

        delay_seconds = 0
        delay_elem = find_text_any(dvs, ['ExacteVertrekVertraging', 'PresentatieVertrekVertraging', 'GedempteVertrekVertraging', 'ExacteAankomstVertraging', 'PresentatieAankomstVertraging', 'GedempteAankomstVertraging', 'VertrekVertraging', 'AankomstVertraging', 'Vertraging'])
        if delay_elem:
            delay_seconds = parse_iso_duration(delay_elem)

        # Try to extract an expected time if present in the dvs element
        expected_time = None
        try:
            raw_xml = ET.tostring(dvs, encoding='utf-8', method='xml').decode('utf-8')
            m = re.search(r'(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})', raw_xml)
            if m:
                dt = datetime.fromisoformat(m.group(1))
                expected_time = dt.strftime('%Y-%m-%d %H:%M:%S')
            else:
                m2 = re.search(r'(\d{2}:\d{2}(?::\d{2})?)', raw_xml)
                if m2:
                    today = datetime.now()
                    try:
                        t = datetime.strptime(m2.group(1), '%H:%M:%S')
                    except Exception:
                        t = datetime.strptime(m2.group(1), '%H:%M')
                    dt = today.replace(hour=t.hour, minute=t.minute, second=getattr(t,'second',0), microsecond=0)
                    expected_time = dt.strftime('%Y-%m-%d %H:%M:%S')
        except Exception:
            expected_time = None

        # If expected_time is present but no explicit delay, try to derive delay_seconds
        if delay_seconds == 0 and expected_time is not None:
            try:
                sched_text = find_text_any(dvs, ['PlannedDepartureTime','PlannedTime','PlannedArrivalTime','TargetDepartureTime','TargetArrivalTime','ScheduledDepartureTime','ScheduledTime','VertrekTijd','GeplandeVertrekTijd'])
                if sched_text:
                    try:
                        if re.match(r'\d{4}-\d{2}-\d{2}T', sched_text):
                            sched_dt = datetime.fromisoformat(re.search(r'(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})', sched_text).group(1))
                        elif re.match(r'\d{2}:\d{2}(:\d{2})?$', sched_text):
                            t = datetime.strptime(sched_text if sched_text.count(':')==2 else sched_text + ':00', '%H:%M:%S')
                            today = datetime.now()
                            sched_dt = today.replace(hour=t.hour, minute=t.minute, second=getattr(t,'second',0), microsecond=0)
                        else:
                            sched_dt = None
                        if sched_dt is not None:
                            exp_dt = datetime.strptime(expected_time, '%Y-%m-%d %H:%M:%S')
                            delta = int((exp_dt - sched_dt).total_seconds())
                            if delta < -43200:
                                delta += 86400
                            elif delta > 43200:
                                delta -= 86400
                            delay_seconds = delta
                    except Exception:
                        pass
            except Exception:
                pass

        self.db.upsert_delay(train_num, station_code, delay_seconds, is_cancelled, expected_time)


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

    kv78_endpoint = "tcp://pubsub.besteffort.ndovloket.nl:7817"
    infoplus_endpoint = "tcp://pubsub.besteffort.ndovloket.nl:7664"

    kv78_thread = KV78Subscriber(kv78_endpoint, db)
    infoplus_thread = InfoPlusSubscriber(infoplus_endpoint, db)

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
