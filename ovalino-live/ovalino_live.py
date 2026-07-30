import asyncio
import gzip
import json
import logging
import os
import re
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from typing import Dict, List, Optional

import zmq
import zmq.asyncio
import yaml
from fastapi import FastAPI, HTTPException
from fastapi.middleware.gzip import GZipMiddleware
from lxml import etree
from pydantic import BaseModel

logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
logger = logging.getLogger('ovalino-live')

APP_DIR = os.path.dirname(os.path.abspath(__file__))
DEFAULT_CONFIG_PATH = os.path.join(APP_DIR, 'config.yaml')


class GeneralConfig(BaseModel):
    listen_host: str = '0.0.0.0'
    listen_port: int = 8000
    data_retention_seconds: int = 7200
    snapshot_path: str = './ovalino_live_snapshot.json'


class ZeroMQConfig(BaseModel):
    bison_url: str
    info_plus_url: str
    bison_subscriptions: List[str]
    infoplus_subscriptions: List[str]


class WordPressConfig(BaseModel):
    enabled: bool = False
    push_url: str = ''
    push_token: str = ''
    timeout_seconds: int = 10


class Config(BaseModel):
    general: GeneralConfig
    zero_mq: ZeroMQConfig
    wordpress: WordPressConfig


@dataclass
class DelayRecord:
    journey_ref: str
    stop_code: str
    delay_seconds: int
    is_cancelled: bool
    source: str
    updated_at: str

    def as_dict(self):
        return asdict(self)


class DelayStore:
    def __init__(self, retention_seconds: int, snapshot_path: Optional[str] = None):
        self.retention_seconds = retention_seconds
        self.snapshot_path = snapshot_path
        self._records: Dict[str, DelayRecord] = {}
        self._lock = asyncio.Lock()

    @staticmethod
    def _make_key(journey_ref: str, stop_code: str) -> str:
        return f'{journey_ref}::{stop_code}'

    async def load_snapshot(self):
        if not self.snapshot_path or not os.path.exists(self.snapshot_path):
            return
        try:
            with open(self.snapshot_path, 'r', encoding='utf-8') as fh:
                raw = json.load(fh)
            for item in raw:
                record = DelayRecord(**item)
                self._records[self._make_key(record.journey_ref, record.stop_code)] = record
            logger.info('Snapshot geladen: %d records', len(self._records))
        except Exception as exc:
            logger.warning('Kon snapshot niet laden: %s', exc)

    async def save_snapshot(self):
        if not self.snapshot_path:
            return
        try:
            with open(self.snapshot_path, 'w', encoding='utf-8') as fh:
                json.dump([r.as_dict() for r in self._records.values()], fh, ensure_ascii=False, indent=2)
            logger.info('Snapshot opgeslagen: %d records', len(self._records))
        except Exception as exc:
            logger.warning('Kon snapshot niet opslaan: %s', exc)

    async def add(self, record: DelayRecord):
        async with self._lock:
            key = self._make_key(record.journey_ref, record.stop_code)
            self._records[key] = record

    async def query(self, journey_ref: Optional[str] = None, stop_code: Optional[str] = None, active_only: bool = False, limit: int = 0):
        async with self._lock:
            values = list(self._records.values())
            if journey_ref:
                values = [r for r in values if r.journey_ref == journey_ref]
            if stop_code:
                values = [r for r in values if r.stop_code == stop_code]
            if active_only:
                values = [r for r in values if r.delay_seconds != 0 or r.is_cancelled]
            if limit > 0:
                values = values[:limit]
            return [r.as_dict() for r in values]

    async def cleanup_expired(self):
        async with self._lock:
            self._trim_expired()

    def _trim_expired(self):
        if self.retention_seconds <= 0:
            return
        cutoff = datetime.now(timezone.utc).timestamp() - self.retention_seconds
        keys_to_delete = []
        for key, record in self._records.items():
            try:
                updated_ts = datetime.fromisoformat(record.updated_at).timestamp()
            except Exception:
                updated_ts = 0
            if updated_ts < cutoff:
                keys_to_delete.append(key)
        for key in keys_to_delete:
            del self._records[key]


def load_config(path: str = DEFAULT_CONFIG_PATH) -> Config:
    if not os.path.exists(path):
        raise FileNotFoundError(f'Config bestand niet gevonden: {path}')
    with open(path, 'r', encoding='utf-8') as fh:
        data = yaml.safe_load(fh)
    return Config(**data)


def strip_namespace(tag: str) -> str:
    return tag.split('}')[-1].lower() if '}' in tag else tag.lower()


def decode_payload_bytes(body: bytes) -> bytes:
    if not body:
        return b''
    if body[:2] == b'\x1f\x8b':
        try:
            return gzip.decompress(body)
        except Exception:
            pass
    return body


def parse_iso_duration(duration_str: str) -> int:
    if not duration_str:
        return 0
    duration_str = duration_str.strip()
    if duration_str.isdigit() or (duration_str.startswith('-') and duration_str[1:].isdigit()):
        try:
            return int(duration_str)
        except ValueError:
            return 0
    match = re.search(r'(-)?PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?', duration_str, re.IGNORECASE)
    if not match:
        return 0
    is_neg = bool(match.group(1))
    hours = int(match.group(2) or 0)
    minutes = int(match.group(3) or 0)
    seconds = int(match.group(4) or 0)
    total = hours * 3600 + minutes * 60 + seconds
    return -total if is_neg else total


def parse_infoplus_message(raw_bytes: bytes, envelope: str) -> List[DelayRecord]:
    records: List[DelayRecord] = []
    if not raw_bytes:
        return records

    try:
        root = etree.fromstring(raw_bytes)
    except Exception as exc:
        try:
            cleaned_text = raw_bytes.decode('utf-8', errors='ignore')
            cleaned_text = re.sub(r'<\?xml[^>]+\?>', '', cleaned_text)
            root = etree.fromstring(cleaned_text.encode('utf-8'))
        except Exception as exc2:
            logger.debug('InfoPlus payload kon niet worden geparseerd als XML: %s / %s', exc, exc2)
            return records

    now_iso = datetime.now(timezone.utc).isoformat()

    journey_ref = None
    stop_code = None
    delay_seconds = 0
    is_cancelled = False

    delay_tags = (
        'exactevertrekvertraging', 'exacteaankomstvertraging',
        'gedemptevertrekvertraging', 'gedempteaankomstvertraging',
        'presentatievertrekvertraging', 'presentatieaankomstvertraging',
        'vertrekvertraging', 'aankomstvertraging', 'vertraging', 'delay'
    )

    for element in root.iter():
        tag = strip_namespace(element.tag)
        value = (element.text or '').strip()
        if not value:
            continue
        if tag in ('treinnummer', 'logischeritnummer', 'ritnummer', 'ritid', 'trainid', 'journeyref') and not journey_ref:
            journey_ref = value
        elif tag in ('stationcode', 'stopcode', 'haltecode') and not stop_code:
            stop_code = value.lower()
        elif tag in delay_tags:
            parsed_d = parse_iso_duration(value)
            if parsed_d != 0 or delay_seconds == 0:
                delay_seconds = parsed_d
        elif tag in ('treinstatus', 'wijzigingtype', 'status', 'vervallen', 'tekst'):
            lowered = value.lower()
            if lowered.isdigit() and int(lowered) == 32:
                is_cancelled = True
            if any(tok in lowered for tok in ('vervallen', 'cancelled', 'canceled', 'niet gereden', 'nietopgevoerd', 'rijdetniet', 'rijdt niet', 'notdriving', 'deleted')):
                is_cancelled = True

    if journey_ref and stop_code:
        records.append(DelayRecord(
            journey_ref=journey_ref,
            stop_code=stop_code,
            delay_seconds=delay_seconds,
            is_cancelled=is_cancelled,
            source=envelope,
            updated_at=now_iso,
        ))

    return records


def parse_bison_kv17_message(payload: str, envelope: str) -> List[DelayRecord]:
    records: List[DelayRecord] = []
    fields: Dict[str, str] = {}
    for raw_line in payload.splitlines():
        line = raw_line.strip()
        if '=' not in line:
            continue
        key, value = line.split('=', 1)
        fields[key.strip().lower()] = value.strip()

    journey_ref = fields.get('journey_ref') or fields.get('journeyref') or fields.get('tripid')
    stop_code = fields.get('stop_code') or fields.get('stopcode') or fields.get('station_code')
    delay_seconds = 0
    is_cancelled = False

    if journey_ref and stop_code:
        raw_delay = fields.get('delay_seconds') or fields.get('delay') or fields.get('lateness')
        if raw_delay:
            delay_seconds = parse_iso_duration(raw_delay)
        status = fields.get('status', '').lower()
        if any(tok in status for tok in ('cancelled', 'canceled', 'vervallen', 'rijdetniet')):
            is_cancelled = True

        records.append(DelayRecord(
            journey_ref=journey_ref,
            stop_code=stop_code,
            delay_seconds=delay_seconds,
            is_cancelled=is_cancelled,
            source=envelope,
            updated_at=datetime.now(timezone.utc).isoformat(),
        ))

    return records


def parse_kv78_message(payload: str, envelope: str) -> List[DelayRecord]:
    records: List[DelayRecord] = []
    current_table = ''
    headers: List[str] = []

    def row_value(row: Dict[str, str], keys: List[str]) -> str:
        for key in keys:
            value = row.get(key.lower())
            if value:
                return value
        return ''

    def parse_time_to_seconds(value: str) -> Optional[int]:
        value = value.strip()
        if not value:
            return None
        parts = value.split(':')
        if len(parts) < 2:
            return None
        try:
            hours = int(parts[0])
            minutes = int(parts[1])
            sec_str = parts[2][:2] if len(parts) > 2 else '0'
            seconds = int(sec_str) if sec_str.isdigit() else 0
        except ValueError:
            return None
        if minutes < 0 or minutes > 59 or seconds < 0 or seconds > 59 or hours < 0:
            return None
        return hours * 3600 + minutes * 60 + seconds

    def normalize_row(raw: Dict[str, str]) -> Dict[str, str]:
        return {k.strip().lower(): v.strip() for k, v in raw.items()}

    def parse_passtime(row: Dict[str, str]):
        row = normalize_row(row)
        data_owner = row_value(row, ['DataOwnerCode', 'DataOwner'])
        journey_num = row_value(row, ['JourneyNumber', 'LinePlanningNumber', 'LocalServiceLevelCode'])
        user_stop = row_value(row, ['UserStopCode', 'TimingPointCode', 'StopCode'])
        status = row_value(row, ['TripStopStatus', 'PasstimeEffect', 'Status']).upper()

        if not user_stop or not journey_num:
            return

        target_dep = row_value(row, ['TargetDepartureTime', 'TargetArrivalTime'])
        expected_dep = row_value(row, ['ExpectedDepartureTime', 'ExpectedArrivalTime'])
        delay_seconds = 0
        if target_dep and expected_dep:
            target_seconds = parse_time_to_seconds(target_dep)
            expected_seconds = parse_time_to_seconds(expected_dep)
            if target_seconds is not None and expected_seconds is not None:
                delay_seconds = expected_seconds - target_seconds
                if delay_seconds < -43200:
                    delay_seconds += 86400
                elif delay_seconds > 43200:
                    delay_seconds -= 86400

        is_cancelled = any(token in status for token in ('CANCEL', 'CANCELLED', 'DELETED', 'NOTDRIVING', 'VERVALLEN', 'RIJDETNIET'))
        journey_ref = f"{data_owner}:{journey_num}" if data_owner else journey_num

        now_iso = datetime.now(timezone.utc).isoformat()
        refs = [journey_ref]
        if data_owner and journey_num and journey_num != journey_ref:
            refs.append(journey_num)

        stops = [user_stop]
        if user_stop.isdigit():
            stops.append(f'NL:Q:{user_stop}')
        elif user_stop.startswith('NL:Q:'):
            stops.append(user_stop[5:])

        for ref in refs:
            for st in stops:
                records.append(DelayRecord(
                    journey_ref=ref,
                    stop_code=st,
                    delay_seconds=delay_seconds,
                    is_cancelled=is_cancelled,
                    source=envelope,
                    updated_at=now_iso,
                ))

    for raw_line in payload.splitlines():
        line = raw_line.strip()
        if not line:
            continue
        if line.startswith('\\T'):
            current_table = line[2:].split('|', 1)[0].strip().upper()
            continue
        if line.startswith('\\L'):
            header_text = line[2:]
            if header_text.endswith('CRLF'):
                header_text = header_text[:-4]
            headers = [h.strip() for h in header_text.split('|')]
            continue
        if (current_table.endswith('PASSTIME') or current_table in ('DATEDPASSTIME', 'LOCALSERVICEGROUPPASSTIME', 'PASSTIME')) and not line.startswith('\\'):
            values = [v.strip() for v in line.split('|')]
            if values and values[-1].endswith('CRLF'):
                values[-1] = values[-1][:-4].strip()
            if len(values) == len(headers):
                row = dict(zip(headers, values))
                parse_passtime(row)

    return records


def parse_envelope(envelope: str, body: bytes) -> List[DelayRecord]:
    envelope = envelope.strip()
    raw_bytes = decode_payload_bytes(body)

    if b'\\T' in raw_bytes[:300] or b'\\L' in raw_bytes[:300] or 'KV78' in envelope or 'GOVI' in envelope or 'BISON' in envelope:
        payload_str = raw_bytes.decode('utf-8', errors='replace').strip()
        return parse_kv78_message(payload_str, envelope)

    if '/KV17' in envelope or 'cvlinfo' in envelope.lower():
        payload_str = raw_bytes.decode('utf-8', errors='replace').strip()
        return parse_bison_kv17_message(payload_str, envelope)

    if '/InfoPlus' in envelope or '/RIG/' in envelope or b'<PutReizigersInformatieBoodschap' in raw_bytes or b'<Passthrough' in raw_bytes or b'<?xml' in raw_bytes[:100]:
        return parse_infoplus_message(raw_bytes, envelope)

    return []


class ZeroMQWorker:
    def __init__(self, config: Config, store: DelayStore):
        self.config = config
        self.store = store
        self.ctx = zmq.asyncio.Context()
        self.tasks: List[asyncio.Task] = []

    async def start(self):
        self.tasks.append(asyncio.create_task(self._run_subscription(
            self.config.zero_mq.bison_url,
            self.config.zero_mq.bison_subscriptions,
            'bison',
        )))
        self.tasks.append(asyncio.create_task(self._run_subscription(
            self.config.zero_mq.info_plus_url,
            self.config.zero_mq.infoplus_subscriptions,
            'infoplus',
        )))

    async def stop(self):
        for task in self.tasks:
            task.cancel()
        await asyncio.gather(*self.tasks, return_exceptions=True)
        self.ctx.term()

    async def _run_subscription(self, url: str, subscriptions: List[str], source: str):
        socket = self.ctx.socket(zmq.SUB)
        socket.setsockopt(zmq.RCVHWM, 1000)
        socket.setsockopt(zmq.LINGER, 0)
        socket.connect(url)
        for topic in subscriptions:
            logger.info('SUBSCRIBE %s -> %s', source, topic)
            socket.setsockopt_string(zmq.SUBSCRIBE, topic)

        while True:
            try:
                parts = await socket.recv_multipart()
                if not parts:
                    continue
                envelope = parts[0].decode('utf-8', errors='replace').strip()
                body = parts[1] if len(parts) > 1 else b''
                logger.debug('Received %s message: %s (%d bytes)', source, envelope, len(body))
                records = parse_envelope(envelope, body)
                if records:
                    logger.info('Ontvangen %d realtime vertraging(s) uit %s via %s', len(records), envelope, source)
                for record in records:
                    await self.store.add(record)
            except asyncio.CancelledError:
                break
            except Exception as exc:
                logger.exception('Fout bij ZeroMQ %s: %s', source, exc)
                await asyncio.sleep(5)


class DelayQueryParams(BaseModel):
    journey_ref: Optional[str] = None
    stop_code: Optional[str] = None
    limit: int = 100


app = FastAPI(title='Ovalino Live', version='0.1.0')
app.add_middleware(GZipMiddleware, minimum_size=500)
config: Optional[Config] = None
store: Optional[DelayStore] = None
worker: Optional[ZeroMQWorker] = None


@app.on_event('startup')
async def startup_event():
    global config, store, worker
    config = load_config()
    store = DelayStore(retention_seconds=config.general.data_retention_seconds,
                       snapshot_path=config.general.snapshot_path)
    await store.load_snapshot()
    worker = ZeroMQWorker(config=config, store=store)
    await worker.start()
    asyncio.create_task(_background_snapshot_loop())
    logger.info('Ovalino Live gestart')


@app.on_event('shutdown')
async def shutdown_event():
    global worker, store
    if worker:
        await worker.stop()
    if store:
        await store.save_snapshot()


@app.get('/api/health')
async def health():
    return {'status': 'ok', 'time': datetime.now(timezone.utc).isoformat()}


@app.get('/api/delays')
async def get_delays(journey_ref: Optional[str] = None, stop_code: Optional[str] = None, active_only: bool = False, limit: int = 0):
    if not store:
        raise HTTPException(status_code=500, detail='Store niet geïnitialiseerd')
    if limit < 0 or limit > 100000:
        raise HTTPException(status_code=400, detail='Limit moet tussen 0 en 100000 liggen')
    results = await store.query(journey_ref=journey_ref, stop_code=stop_code, active_only=active_only, limit=limit)
    return {'count': len(results), 'results': results}


async def _background_snapshot_loop():
    while True:
        await asyncio.sleep(30)
        if store:
            await store.cleanup_expired()
            await store.save_snapshot()


def main():
    import uvicorn
    cfg = load_config()
    uvicorn.run('ovalino_live:app', host=cfg.general.listen_host, port=cfg.general.listen_port, log_level='info')


if __name__ == '__main__':
    main()
