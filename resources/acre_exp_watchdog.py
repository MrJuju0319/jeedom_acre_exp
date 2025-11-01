#!/opt/spc-venv/bin/python3
# -*- coding: utf-8 -*-
"""MQTT watchdog bridge for Jeedom ACRE EXP plugin."""

from __future__ import annotations

import argparse
import logging
import signal
import sys
import time
import warnings
from typing import Dict

try:
    import yaml  # type: ignore
except Exception as exc:  # pragma: no cover - dependency missing
    sys.stderr.write(f"[ERREUR] Module PyYAML requis : {exc}\n")
    raise SystemExit(1)

try:
    from paho.mqtt import client as mqtt  # type: ignore
except Exception as exc:  # pragma: no cover - dependency missing
    sys.stderr.write("[ERREUR] paho-mqtt non disponible : /opt/spc-venv/bin/pip install 'paho-mqtt>=2,<3'\n")
    raise SystemExit(1)

try:
    from paho.mqtt.client import CallbackAPIVersion  # type: ignore
except Exception:
    CallbackAPIVersion = None  # paho-mqtt < 1.6

from acre_exp_status import SPCClient


LOGGER = logging.getLogger("acre_exp_watchdog")


def load_cfg(path: str):
    with open(path, "r", encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}

class MQ:
    def __init__(self, cfg: dict):
        m = cfg.get("mqtt", {}) if isinstance(cfg, dict) else {}
        self.host = m.get("host", "127.0.0.1")
        self.port = int(m.get("port", 1883))
        self.user = m.get("user", "")
        self.pwd = m.get("pass", "")
        self.base = str(m.get("base_topic", "spc")).strip("/")
        self.qos = int(m.get("qos", 0))
        self.retain = bool(m.get("retain", True))
        self.client_id = m.get("client_id", "acreexp-watchdog")
        proto = str(m.get("protocol", "v311")).lower()
        self.protocol = mqtt.MQTTv5 if proto in ("v5", "mqttv5", "5") else mqtt.MQTTv311

        client_kwargs = {
            "client_id": self.client_id,
            "protocol": self.protocol,
        }

        callback_version = None
        if CallbackAPIVersion is not None:
            for attr in ("V5", "V311", "V3"):
                ver = getattr(CallbackAPIVersion, attr, None)
                if ver is not None:
                    callback_version = ver
                    client_kwargs["callback_api_version"] = ver
                    break
        if callback_version is None:
            LOGGER.warning("[MQTT] API callbacks V3 utilisée (paho-mqtt ancien)")

        with warnings.catch_warnings():
            if callback_version is None:
                warnings.filterwarnings(
                    "ignore",
                    message="Callback API version 1 is deprecated, update to latest version",
                    category=DeprecationWarning,
                    module="paho.mqtt.client",
                )
            self.client = mqtt.Client(**client_kwargs)

        def _normalize_reason_code(code):
            if code is None:
                return 0
            value = getattr(code, "value", code)
            try:
                return int(value)
            except Exception:
                return 0

        def _on_connect(client, userdata, flags, reason_code=0, *rest):
            rc = _normalize_reason_code(reason_code)
            self._set_conn(rc == 0, rc)

        def _on_disconnect(client, userdata, reason_code=0, *rest):
            rc = _normalize_reason_code(reason_code)
            self._unset_conn(rc)

        if self.user:
            self.client.username_pw_set(self.user, self.pwd)

        self.connected = False
        self.client.on_connect = _on_connect
        self.client.on_disconnect = _on_disconnect

    def _set_conn(self, ok: bool, rc: int) -> None:
        self.connected = ok
        if ok:
            LOGGER.info("[MQTT] Connecté")
        else:
            LOGGER.error("[MQTT] Connexion échouée rc=%s", rc)

    def _unset_conn(self, rc: int) -> None:
        self.connected = False
        LOGGER.warning("[MQTT] Déconnecté (rc=%s)", rc)

    def connect(self) -> None:
        while True:
            try:
                self.client.connect(self.host, self.port, keepalive=30)
                self.client.loop_start()
                for _ in range(30):
                    if self.connected:
                        return
                    time.sleep(0.2)
            except Exception as exc:
                LOGGER.error("[MQTT] Erreur: %s", exc)
            time.sleep(2)

    def pub(self, topic: str, payload: str) -> None:
        full = f"{self.base}/{topic}".strip("/")
        try:
            self.client.publish(full, payload=str(payload), qos=self.qos, retain=self.retain)
        except Exception as exc:
            LOGGER.error("[MQTT] publish ERR %s: %s", full, exc)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("-c", "--config", default="/etc/acre_exp/config.yml")
    parser.add_argument("--debug", action="store_true")
    args = parser.parse_args()

    logging.basicConfig(stream=sys.stderr, level=(logging.DEBUG if args.debug else logging.INFO), format="%(levelname)s:%(message)s")

    cfg = load_cfg(args.config)
    wd = cfg.get("watchdog", {}) if isinstance(cfg, dict) else {}
    interval = int(wd.get("refresh_interval", 2))
    log_changes = bool(wd.get("log_changes", True))

    client = SPCClient(cfg, debug=args.debug)
    mq = MQ(cfg)

    LOGGER.info("[SPC→MQTT] Démarrage (refresh=%ss) — Broker %s:%s", interval, mq.host, mq.port)
    mq.connect()

    last_z: Dict[str, int] = {}
    last_z_in: Dict[str, int] = {}
    last_a: Dict[str, int] = {}

    running = True

    def stop(*_):
        nonlocal running
        running = False

    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)

    # Snapshot initial
    try:
        snap = client.get_or_fetch()
    except Exception as exc:
        LOGGER.error("[SPC] snapshot initial impossible: %s", exc)
        snap = {"zones": [], "areas": []}

    for z in snap.get("zones", []):
        zid = client.zone_id_from_name(z)
        zname = client.zone_name(z) if hasattr(client, "zone_name") else z.get("zone", "")
        if not zid or not zname:
            continue
        mq.pub(f"zones/{zid}/name", zname)
        mq.pub(f"zones/{zid}/secteur", client.zone_sector(z) if hasattr(client, "zone_sector") else z.get("secteur", ""))
        b = client.zone_bin(z) if hasattr(client, "zone_bin") else z.get("etat")
        if isinstance(b, int) and b in (0, 1):
            last_z[zid] = b
            mq.pub(f"zones/{zid}/state", b)
        entree = client.zone_input(z) if hasattr(client, "zone_input") else z.get("entree")
        if isinstance(entree, int) and entree in (0, 1, 2, 3):
            last_z_in[zid] = entree
            mq.pub(f"zones/{zid}/entree", entree)

    for a in snap.get("areas", []):
        sid = client.area_id(a) if hasattr(client, "area_id") else a.get("sid")
        if not sid:
            continue
        mq.pub(f"secteurs/{sid}/name", a.get("nom", ""))
        s = client.area_num(a) if hasattr(client, "area_num") else a.get("etat")
        if isinstance(s, int) and s >= 0:
            last_a[sid] = s
            mq.pub(f"secteurs/{sid}/state", s)

    LOGGER.info("[SPC→MQTT] État initial publié.")

    while running:
        tick = time.strftime("%H:%M:%S")
        try:
            data = client.get_or_fetch()
        except Exception as exc:
            LOGGER.error("[SPC] fetch ERR: %s", exc)
            time.sleep(interval)
            continue

        for z in data.get("zones", []):
            zid = client.zone_id_from_name(z)
            zname = client.zone_name(z) if hasattr(client, "zone_name") else z.get("zone", "")
            if not zid or not zname:
                continue
            b = client.zone_bin(z) if hasattr(client, "zone_bin") else z.get("etat")
            if isinstance(b, int) and b in (0, 1):
                old = last_z.get(zid)
                if old is None or b != old:
                    mq.pub(f"zones/{zid}/state", b)
                    last_z[zid] = b
                    if log_changes:
                        LOGGER.info("[%s] Zone '%s' → %s", tick, zname, b)

            entree = client.zone_input(z) if hasattr(client, "zone_input") else z.get("entree")
            if isinstance(entree, int) and entree in (0, 1, 2, 3):
                old_in = last_z_in.get(zid)
                if old_in is None or entree != old_in:
                    mq.pub(f"zones/{zid}/entree", entree)
                    last_z_in[zid] = entree
                    if log_changes:
                        state_txt = {
                            0: "fermée",
                            1: "ouverte",
                            2: "isolée",
                            3: "inhibée",
                        }.get(entree, str(entree))
                        LOGGER.info("[%s] Entrée zone '%s' → %s", tick, zname, state_txt)

        for a in data.get("areas", []):
            sid = client.area_id(a) if hasattr(client, "area_id") else a.get("sid")
            if not sid:
                continue
            s = client.area_num(a) if hasattr(client, "area_num") else a.get("etat")
            if isinstance(s, int):
                old = last_a.get(sid)
                if old is None or s != old:
                    mq.pub(f"secteurs/{sid}/state", s)
                    last_a[sid] = s
                    if log_changes:
                        LOGGER.info("[%s] Secteur '%s' → %s", tick, a.get("nom", sid), s)

        time.sleep(interval)

    mq.client.loop_stop()
    try:
        mq.client.disconnect()
    except Exception:
        pass
    LOGGER.info("[SPC→MQTT] Arrêt propre.")


if __name__ == "__main__":
    try:
        sys.stdout.reconfigure(encoding="utf-8")  # type: ignore[attr-defined]
    except Exception:
        pass
    main()
