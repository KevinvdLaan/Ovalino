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

    def upsert_delay(self, journey_ref, stop_code, delay_seconds, is_cancelled):
        """Insert or update delay record."""
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
                cursor.execute(sql, (journey_ref, stop_code, int(delay_seconds), 1 if is_cancelled else 0))
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

        if not user_stop or not journey_num:
            return

        is_cancelled = status in ("CANCEL", "CANCELLED", "DELETED", "NOTDRIVING")
        delay_seconds = 0

        if target_dep and expected_dep:
            try:
                fmt = "%H:%M:%S"
                t_dt = datetime.strptime(target_dep[:8], fmt)
                e_dt = datetime.strptime(expected_dep[:8], fmt)
                delay_seconds = int((e_dt - t_dt).total_seconds())
                if delay_seconds < -43200:
                    delay_seconds += 86400
                elif delay_seconds > 43200:
                    delay_seconds -= 86400
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
            self.db.upsert_delay(journey_ref, user_stop, delay_seconds, is_cancelled)

            if user_stop.isdigit():
                self.db.upsert_delay(
                    journey_ref,
                    "NL:Q:" + user_stop,
                    delay_seconds,
                    is_cancelled
                )


class InfoPlusSubscriber(threading.Thread):
    """Subscriber thread for NS InfoPlus DVS train feed."""
    def __init__(self, endpoint, db):
        super().__init__(daemon=True)
        self.endpoint = endpoint
        self.db = db

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
            # Strip namespaces to simplify parsing
            xml_clean = re.sub(r'xmlns="[^"]+"', '', xml_content)
            xml_clean = re.sub(r'xmlns:\w+="[^"]+"', '', xml_clean)
            xml_clean = re.sub(r'\w+:', '', xml_clean)

            root = ET.fromstring(xml_clean)
            for dvs in root.findall('.//DynamischeVertrekStaat'):
                self.process_dvs(dvs)
        except Exception:
            pass

    def process_dvs(self, dvs):
        train_num = dvs.findtext('.//TreinNummer') or dvs.findtext('.//RitId')
        station_code = dvs.findtext('.//StationCode')
        if not train_num or not station_code:
            return

        station_code = station_code.strip().lower()
        train_num = train_num.strip()
        # Normalize to the canonical form without leading zeros. NS/InfoPlus
        # normally sends unpadded numbers already ("3617"), but this keeps
        # the daemon consistent with the zero-stripped train_number stored
        # by ov-trein-dienstregeling.php's IFF import (which otherwise
        # preserves fixed-width padding like "03617"), regardless of which
        # side ends up padding in the future.
        if train_num.isdigit():
            train_num = str(int(train_num))

        status_text = (dvs.findtext('.//TreinStatus') or dvs.findtext('.//WijzigingType') or '').upper()
        is_cancelled = 'CANCEL' in status_text or 'VERVALLEN' in status_text or 'RIJDETNIET' in status_text

        delay_seconds = 0
        delay_elem = dvs.findtext('.//VertrekVertraging') or dvs.findtext('.//Vertraging')
        if delay_elem:
            delay_seconds = parse_iso_duration(delay_elem)

        self.db.upsert_delay(train_num, station_code, delay_seconds, is_cancelled)


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
