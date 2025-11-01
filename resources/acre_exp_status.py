#!/opt/spc-venv/bin/python3
# -*- coding: utf-8 -*-
"""SPC status helper used by the Jeedom ACRE EXP plugin."""

from __future__ import annotations

import argparse
import json
import logging
import os
import re
import sys
import time
import unicodedata
from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Mapping, Optional

try:
    import yaml  # type: ignore
except Exception as exc:  # pragma: no cover - dependency missing
    sys.stderr.write(f"[ERREUR] Module PyYAML requis : {exc}\n")
    raise SystemExit(1)

try:
    import requests
except Exception as exc:  # pragma: no cover - dependency missing
    sys.stderr.write(f"[ERREUR] Module requests requis : {exc}\n")
    raise SystemExit(1)

try:
    from bs4 import BeautifulSoup  # type: ignore
except Exception as exc:  # pragma: no cover - dependency missing
    sys.stderr.write(f"[ERREUR] Module beautifulsoup4 requis : {exc}\n")
    raise SystemExit(1)

from http.cookiejar import MozillaCookieJar
from urllib.parse import urljoin


LOGGER = logging.getLogger("acre_exp_status")


def load_cfg(path: str) -> Dict[str, Any]:
    with open(path, "r", encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}


def ensure_dir(path: str) -> None:
    os.makedirs(path, mode=0o770, exist_ok=True)


@dataclass
class SpcConfig:
    host: str
    user: str
    pin: str
    lang: str = "253"
    session_cache_dir: str = "."
    min_login_interval: int = 60
    debug: bool = False

    @classmethod
    def from_mapping(cls, mapping: Mapping[str, Any], debug: bool) -> "SpcConfig":
        spc = mapping.get("spc", {})
        if not isinstance(spc, Mapping):
            raise ValueError("Section 'spc' manquante ou invalide")

        host = str(spc.get("host", "")).strip()
        user = str(spc.get("user", "")).strip()
        pin = str(spc.get("pin", "")).strip()
        lang = str(spc.get("language", 253))
        cache_dir = str(spc.get("session_cache_dir", "")).strip() or "/var/lib/acre_exp"
        min_login = int(spc.get("min_login_interval_sec", 60) or 60)

        if not host or not user or not pin:
            missing = [name for name, value in (("host", host), ("user", user), ("pin", pin)) if not value]
            raise ValueError("Valeurs manquantes : " + ", ".join(missing))

        ensure_dir(cache_dir)
        return cls(host=host, user=user, pin=pin, lang=lang, session_cache_dir=cache_dir, min_login_interval=min_login, debug=debug)


class SPCClient:
    def __init__(self, cfg: Mapping[str, Any], debug: bool = False):
        self.config = SpcConfig.from_mapping(cfg, debug)
        self.debug = debug or self.config.debug
        self.host = self.config.host.rstrip("/")
        self.user = self.config.user
        self.pin = self.config.pin
        self.lang = self.config.lang
        self.cache = self.config.session_cache_dir
        self.min_login_interval = self.config.min_login_interval

        ensure_dir(self.cache)
        self.session_file = os.path.join(self.cache, "spc_session.json")
        self.cookie_file = os.path.join(self.cache, "spc_cookies.jar")

        self.session = requests.Session()
        self.session.verify = False
        self.session.headers.update({
            "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0 Safari/537.36",
            "Connection": "keep-alive",
        })
        self.cookiejar = MozillaCookieJar(self.cookie_file)
        self._load_cookies()

    # Cookie/session helpers -------------------------------------------------
    def _load_cookies(self) -> None:
        try:
            if os.path.exists(self.cookie_file):
                self.cookiejar.load(ignore_discard=True, ignore_expires=True)
            self.session.cookies = self.cookiejar
        except Exception:
            try:
                os.remove(self.cookie_file)
            except Exception:
                pass
            self.session.cookies = MozillaCookieJar()

    def _save_cookies(self) -> None:
        try:
            self.cookiejar.save(ignore_discard=True, ignore_expires=True)
        except Exception:
            pass

    def _cookie_lookup(self, name: str) -> str:
        jar = getattr(self.session, "cookies", None)
        if jar is None:
            return ""
        wanted = name.lower()
        for cookie in jar:
            cname = getattr(cookie, "name", "") or ""
            if cname.lower() != wanted:
                continue
            value = getattr(cookie, "value", "")
            if value:
                return str(value)
        return ""

    def _load_session_cache(self) -> Dict[str, Any]:
        if not os.path.exists(self.session_file):
            return {}
        try:
            with open(self.session_file, "r", encoding="utf-8") as handle:
                return json.load(handle)
        except Exception:
            return {}

    def _save_session_cache(self, sid: str) -> None:
        try:
            with open(self.session_file, "w", encoding="utf-8") as handle:
                json.dump({"session": sid, "time": time.time()}, handle)
        except Exception:
            pass

    # HTTP helpers -----------------------------------------------------------
    def _get(self, url: str, referer: Optional[str] = None) -> requests.Response:
        headers = {"Referer": referer} if referer else {}
        response = self.session.get(url, timeout=8, headers=headers, allow_redirects=True)
        response.raise_for_status()
        response.encoding = "utf-8"
        return response

    def _post(self, url: str, data: Mapping[str, Any], referer: Optional[str] = None, allow_redirects: bool = True) -> requests.Response:
        headers = {"Referer": referer} if referer else {}
        response = self.session.post(url, data=data, allow_redirects=allow_redirects, timeout=8, headers=headers)
        response.raise_for_status()
        response.encoding = "utf-8"
        return response

    # Session helpers --------------------------------------------------------
    def _extract_session(self, text_or_url: str) -> str:
        if not text_or_url:
            return ""
        pattern = r"[?&]session=([-_0-9A-Za-z]+)"
        match = re.search(pattern, text_or_url, flags=re.IGNORECASE)
        if match:
            return match.group(1)
        match = re.search(r"secure\.htm\?[^\"'>]*session=([-_0-9A-Za-z]+)", text_or_url, flags=re.IGNORECASE)
        if match:
            return match.group(1)
        return ""

    @staticmethod
    def _normalize_label(text: str) -> str:
        if not text:
            return ""
        normalized = unicodedata.normalize("NFKD", text)
        return "".join(ch for ch in normalized if not unicodedata.combining(ch)).lower().strip()

    def _is_login_response(self, resp_text: str, resp_url: str, expect_table: bool) -> bool:
        if resp_url and "login.htm" in resp_url.lower():
            return True
        if not expect_table:
            return False
        low = resp_text.lower()
        has_user = ('name="userid"' in low) or ('id="userid"' in low) or ("id='userid'" in low)
        has_pass = ('name="password"' in low) or ('id="password"' in low) or ("id='password'" in low)
        if has_user and has_pass:
            return True
        return "utilisateur déconnecté" in low

    def _do_login(self) -> str:
        if self.debug:
            LOGGER.debug("Connexion SPC...")
        try:
            self._get(urljoin(self.host, "/login.htm"))
        except Exception:
            pass
        url = f"{self.host}/login.htm?action=login&language={self.lang}"
        try:
            response = self._post(url, {"userid": self.user, "password": self.pin}, allow_redirects=True)
        except Exception as exc:
            if self.debug:
                LOGGER.debug("POST login échoué: %s", exc, exc_info=True)
            return ""

        sid = self._extract_session(getattr(response, "url", "")) or self._extract_session(response.text)
        if not sid:
            # Certains firmwares ne renvoient le SID que via les cookies.
            for key in ("session", "Session", "SESSION"):
                cookie_val = self._cookie_lookup(key)
                if cookie_val:
                    sid = cookie_val
                    break
        if not sid:
            # Dernier recours : vérifier les cookies spécifiques à SPC.
            jar = getattr(self.session, "cookies", None)
            if jar is not None:
                for cookie in jar:
                    name = getattr(cookie, "name", "")
                    if name and name.lower().startswith("session") and getattr(cookie, "value", ""):
                        sid = str(cookie.value)
                        break
        if self.debug:
            LOGGER.debug("Login SID=%s", sid or "(aucun)")
        if sid:
            self._save_session_cache(sid)
            self._save_cookies()
        return sid

    def _session_valid(self, sid: str) -> bool:
        if not sid:
            return False
        try:
            url = f"{self.host}/secure.htm?session={sid}&page=spc_home"
            response = self._get(url, referer=f"{self.host}/secure.htm?session={sid}&page=spc_home")
        except Exception:
            if self.debug:
                LOGGER.debug("Validation session %s impossible", sid, exc_info=True)
            return False
        if self._is_login_response(response.text, getattr(response, "url", ""), True):
            if self.debug:
                LOGGER.debug("Session %s invalide", sid)
            return False
        return True

    def _last_login_too_recent(self) -> bool:
        try:
            data = self._load_session_cache()
            last = float(data.get("time", 0) or 0)
        except Exception:
            last = 0.0
        delta = time.time() - last
        too_recent = delta < self.min_login_interval
        if too_recent and self.debug:
            LOGGER.debug("Dernière tentative de login il y a %.1fs", delta)
        return too_recent

    def get_or_login(self) -> str:
        cached = self._load_session_cache()
        sid = cached.get("session", "")
        if sid and self._session_valid(sid):
            return sid

        if self._last_login_too_recent():
            time.sleep(2)
            if sid and self._session_valid(sid):
                return sid

        return self._do_login()

    # Parsing helpers --------------------------------------------------------
    @staticmethod
    def _attr_values(node) -> Iterable[str]:
        if not node:
            return []
        values: List[str] = []
        for _, val in node.attrs.items():
            if not val:
                continue
            if isinstance(val, (list, tuple, set)):
                values.extend(str(v) for v in val if v)
            else:
                values.append(str(val))
        return values

    @staticmethod
    def _guess_zone_state_label(token: str) -> str:
        norm = SPCClient._normalize_label(token)
        if not norm:
            return ""
        if any(k in norm for k in ("ferm", "close", "ferme", "locked", "normal")):
            return "Fermée"
        if any(k in norm for k in ("ouvr", "open", "unlock")):
            return "Ouverte"
        if any(k in norm for k in ("isol", "isole", "isolee", "isolation", "separe")):
            return "Isolée"
        if any(k in norm for k in ("inhib", "bypass", "shunt")):
            return "Inhibée"
        if any(k in norm for k in ("trou", "fault", "defaut", "defa", "anomal")):
            return "Trouble"
        if any(k in norm for k in ("alarm", "alarme", "alert")):
            return "Alarme"
        if any(k in norm for k in ("vert", "green")):
            return "Fermée"
        if any(k in norm for k in ("roug", "red")):
            return "Ouverte"
        if any(k in norm for k in ("orang", "amber")):
            return "Isolée"
        if any(k in norm for k in ("bleu", "blue")):
            return "Inhibée"
        return ""

    @staticmethod
    def _guess_area_state_label(token: str) -> str:
        norm = SPCClient._normalize_label(token)
        if not norm:
            return ""
        if any(k in norm for k in ("mes totale", "total", "totale", "tot")):
            return "MES Totale"
        if any(k in norm for k in ("mes part", "partiel", "partial", "part")):
            return "MES Partielle"
        if any(k in norm for k in ("mhs", "desarm", "desactive", "off", "ready")):
            return "MHS"
        if any(k in norm for k in ("alarm", "alarme", "alert")):
            return "Alarme"
        if any(k in norm for k in ("trou", "fault", "defaut", "defa")):
            return "Trouble"
        return ""

    @staticmethod
    def _find_column(headers: Iterable[str], keywords: Iterable[str], default: Optional[int] = None) -> Optional[int]:
        for idx, label in enumerate(headers or []):
            for kw in keywords:
                if kw in label:
                    return idx
        return default

    @staticmethod
    def _extract_state_text(td) -> str:
        if td is None:
            return ""
        try:
            text = td.get_text(" ", strip=True)
            if text:
                return text
        except Exception:
            pass

        pieces: List[str] = []
        try:
            pieces = [s.strip() for s in td.stripped_strings if s.strip()]
        except Exception:
            pieces = []
        if pieces:
            return " ".join(pieces)

        for tag_name in ("img", "span", "i", "font"):
            node = td.find(tag_name)
            if not node:
                continue
            txt = (node.get_text(" ", strip=True) or "").strip()
            if txt:
                return txt
            for attr in ("alt", "title", "data-state"):
                val = (node.get(attr) or "").strip()
                if val:
                    return val

        for attr in ("data-state", "title", "aria-label"):
            val = (td.get(attr) or "").strip()
            if val:
                return val

        for attr_val in SPCClient._attr_values(td):
            guess = SPCClient._guess_zone_state_label(attr_val)
            if guess:
                return guess
            attr_val = attr_val.strip()
            if attr_val:
                return attr_val

        for child in td.find_all(True):
            for attr_val in SPCClient._attr_values(child):
                guess = SPCClient._guess_zone_state_label(attr_val)
                if guess:
                    return guess
                attr_val = attr_val.strip()
                if attr_val:
                    return attr_val
        return ""

    @staticmethod
    def _color_hint(td) -> str:
        if td is None:
            return ""
        node = td.find("font") or td.find("span") or td
        color = (node.get("color") or "").lower()
        if color:
            return color
        style = (node.get("style") or "").lower()
        if "color" in style:
            match = re.search(r"color\s*:\s*([^;]+)", style)
            if match:
                return match.group(1).strip()
        return ""

    @classmethod
    def _infer_entree(cls, td, entree_txt: str, etat_txt: str):
        code = cls._map_entree(entree_txt)
        if code != -1:
            return code, entree_txt

        color = cls._color_hint(td)
        if color:
            if "green" in color or "#008000" in color:
                return 0, entree_txt or "Fermée"
            if "red" in color or "#ff0000" in color:
                return 1, entree_txt or "Ouverte"
            if any(c in color for c in ("orange", "#ffa500", "#ff9900")):
                return 2, entree_txt or "Isolée"
            if any(c in color for c in ("blue", "#0000ff")):
                return 3, entree_txt or "Inhibée"

        etat_code = cls._map_zone_state(etat_txt)
        if etat_code == 2:
            return 2, entree_txt or "Isolée"
        if etat_code == 3:
            return 3, entree_txt or "Inhibée"
        if etat_code == 1:
            return 1, entree_txt or "Ouverte"
        if etat_code == 0:
            return 0, entree_txt or "Fermée"
        if etat_code == 4:
            return 1, entree_txt or "Trouble"
        return -1, entree_txt

    @staticmethod
    def _map_entree(txt: str) -> int:
        s = (txt or "").strip().lower()
        if not s:
            return -1
        if "isol" in s:
            return 2
        if "inhib" in s:
            return 3
        if "ferm" in s:
            return 0
        if "ouvr" in s:
            return 1
        return -1

    @staticmethod
    def _map_zone_state(txt: str) -> int:
        s = (txt or "").strip().lower()
        if not s:
            return -1
        if "isol" in s:
            return 2
        if "inhib" in s:
            return 3
        if "ouvr" in s or "open" in s:
            return 1
        if "ferm" in s or "clos" in s or "close" in s:
            return 0
        if "activ" in s or "alarm" in s or "alarme" in s or "alert" in s:
            return 1
        if "normal" in s or "repos" in s or "rest" in s:
            return 0
        if "trouble" in s or "defaut" in s or "défaut" in s:
            return 4
        return -1

    @staticmethod
    def zone_id_from_name(name) -> str:
        if isinstance(name, dict):
            name = name.get("zone") or name.get("zname") or name.get("name") or ""
        match = re.match(r"^\s*(\d+)\b", name or "")
        if match:
            return match.group(1)
        slug = re.sub(r"[^a-zA-Z0-9]+", "_", name or "").strip("_").lower()
        return slug or "unknown"

    @staticmethod
    def zone_name(zone) -> str:
        if isinstance(zone, dict):
            return zone.get("zone") or zone.get("zname") or ""
        return str(zone or "")

    @staticmethod
    def zone_sector(zone) -> str:
        if isinstance(zone, dict):
            return zone.get("secteur") or zone.get("sect") or ""
        return ""

    @staticmethod
    def zone_input(zone) -> int:
        if isinstance(zone, dict):
            entree = zone.get("entree")
            if isinstance(entree, int) and entree in (0, 1, 2, 3):
                return entree
            entree_txt = zone.get("entree_txt")
            etat_val = zone.get("etat") if isinstance(zone.get("etat"), int) else None
        else:
            entree_txt = zone
            etat_val = None

        s = SPCClient._normalize_label(entree_txt or "")
        if "isol" in s:
            return 2
        if "inhib" in s:
            return 3
        if "ferm" in s:
            return 0
        if "ouvr" in s:
            return 1
        if etat_val is not None:
            if etat_val == 2:
                return 2
            if etat_val == 3:
                return 3
            if etat_val == 1:
                return 1
            if etat_val == 0:
                return 0
            if etat_val >= 4:
                return 1
        return -1

    @classmethod
    def zone_bin(cls, zone) -> int:
        if isinstance(zone, dict):
            etat = zone.get("etat")
            if isinstance(etat, int):
                if etat == 1:
                    return 1
                if etat in (0, 2, 3):
                    return 0
                if etat >= 4:
                    return 1
            etat_txt = zone.get("etat_txt")
        else:
            etat_txt = zone

        s = cls._normalize_label(etat_txt or "")
        if any(x in s for x in ("activ", "alarm", "alarme", "trouble", "défaut", "defaut")):
            return 1
        if any(x in s for x in ("normal", "repos", "isol", "inhib")):
            return 0
        return -1

    @classmethod
    def area_num(cls, area) -> int:
        if isinstance(area, dict):
            etat = area.get("etat")
            if isinstance(etat, int) and etat >= 0:
                return etat
            etat_txt = area.get("etat_txt")
        else:
            etat_txt = area

        s = cls._normalize_label(etat_txt or "")
        if "mes totale" in s:
            return 2
        if "mes partiel" in s:
            return 3
        if "alarme" in s:
            return 4
        if "mhs" in s or "désarm" in s or "desarm" in s:
            return 1
        return 0

    @staticmethod
    def area_id(area) -> str:
        if isinstance(area, dict):
            sid = area.get("sid")
            if sid:
                return str(sid).strip()
            label = area.get("secteur") or ""
            match = re.match(r"^\s*(\d+)\b", label)
            if match:
                return match.group(1)
            name = area.get("nom")
            if name:
                return SPCClient.zone_id_from_name(name)
        return ""

    @staticmethod
    def _map_area_state(txt: str) -> int:
        s = (txt or "").lower()
        if "mes totale" in s or "total" in s or "totale" in s:
            return 2
        if "mes partiel" in s or "partiel" in s or "partial" in s:
            return 3
        if "mhs" in s or "désarm" in s or "desarm" in s or "off" in s or "ready" in s:
            return 1
        if "alarme" in s or "alarm" in s or "alert" in s:
            return 4
        if "trouble" in s or "defaut" in s or "défaut" in s or "fault" in s:
            return 4
        return 0

    # Public API -------------------------------------------------------------
    def get_or_fetch(self) -> Dict[str, Any]:
        sid = self.get_or_login()
        if not sid:
            raise RuntimeError("Impossible d'obtenir une session")

        def fetch_page(page: str, referer_page: str) -> requests.Response:
            url = f"{self.host}/secure.htm?session={sid}&page={page}"
            referer = f"{self.host}/secure.htm?session={sid}&page={referer_page}"
            return self._get(url, referer=referer)

        resp_zones = fetch_page("status_zones", "status_zones")
        zones = self.parse_zones(resp_zones.text)
        if len(zones) == 0 and self._is_login_response(resp_zones.text, getattr(resp_zones, "url", ""), True):
            new_sid = self._do_login()
            if new_sid:
                sid = new_sid
                resp_zones = fetch_page("status_zones", "status_zones")
                zones = self.parse_zones(resp_zones.text)

        resp_areas = fetch_page("system_summary", "controller_status")
        areas = self.parse_areas(resp_areas.text)
        if len(areas) == 0 and self._is_login_response(resp_areas.text, getattr(resp_areas, "url", ""), True):
            new_sid = self._do_login()
            if new_sid:
                sid = new_sid
                resp_areas = fetch_page("system_summary", "controller_status")
                areas = self.parse_areas(resp_areas.text)

        self._save_cookies()
        self._save_session_cache(sid)
        return {"zones": zones, "areas": areas}

    def parse_zones(self, html: str) -> List[Dict[str, Any]]:
        soup = BeautifulSoup(html, "html.parser")
        grid = soup.find("table", {"class": "gridtable"})
        zones: List[Dict[str, Any]] = []
        if not grid:
            return zones

        zone_idx, sect_idx, entree_idx, etat_idx = 0, 1, 4, 5
        header_labels: List[str] = []
        for tr in grid.find_all("tr"):
            header_cells = tr.find_all("th")
            if header_cells:
                header_labels = [self._normalize_label(th.get_text(" ", strip=True)) for th in header_cells]
                zone_idx = self._find_column(header_labels, ("zone", "libelle", "nom"), zone_idx)
                sect_idx = self._find_column(header_labels, ("secteur", "partition", "area"), sect_idx)
                entree_idx = self._find_column(header_labels, ("entree", "entrée", "input"), entree_idx)
                etat_idx = self._find_column(header_labels, ("etat", "état", "state", "statut"), etat_idx)
                continue

            tds = tr.find_all("td")
            if len(tds) < 2:
                continue

            zone_td = tds[zone_idx] if zone_idx is not None and zone_idx < len(tds) else tds[0]
            sect_td = tds[sect_idx] if sect_idx is not None and sect_idx < len(tds) else (tds[1] if len(tds) > 1 else tds[0])

            entree_td = None
            etat_td = None
            if len(tds) >= 6:
                entree_td = tds[entree_idx] if entree_idx is not None and entree_idx < len(tds) else tds[-2]
                etat_td = tds[etat_idx] if etat_idx is not None and etat_idx < len(tds) else tds[-1]
            elif len(tds) >= 4:
                entree_td = tds[-2]
                etat_td = tds[-1]

            zname = zone_td.get_text(strip=True)
            sect = sect_td.get_text(strip=True)
            entree_txt = self._extract_state_text(entree_td) if entree_td else ""
            etat_txt = self._extract_state_text(etat_td) if etat_td else ""

            entree_code, entree_txt = self._infer_entree(entree_td, entree_txt, etat_txt)
            if not etat_txt:
                etat_txt = entree_txt
            etat_code = self._map_zone_state(etat_txt)
            if etat_code == -1 and entree_code in (0, 1, 2, 3):
                etat_code = entree_code

            if zname:
                zone_data: Dict[str, Any] = {
                    "zone": zname,
                    "secteur": sect,
                    "entree_txt": entree_txt,
                    "etat_txt": etat_txt,
                    "entree": entree_code,
                    "etat": etat_code,
                    "id": self.zone_id_from_name(zname),
                }
                zones.append(zone_data)
        return zones

    def parse_areas(self, html: str) -> List[Dict[str, Any]]:
        soup = BeautifulSoup(html, "html.parser")
        areas: List[Dict[str, Any]] = []
        for tr in soup.find_all("tr"):
            tds = tr.find_all("td")
            if len(tds) < 3:
                continue
            label = tds[1].get_text(strip=True)
            state = self._extract_state_text(tds[2])
            if not state:
                state = self._guess_area_state_label(" ".join(self._attr_values(tds[2])))
            if label.lower().startswith("secteur"):
                match = re.match(r"^Secteur\s+(\d+)\s*:\s*(.+)$", label, re.I)
                if match:
                    num, nom = match.groups()
                    area_state = self._map_area_state(state)
                    areas.append({
                        "secteur": f"{num} {nom}",
                        "nom": nom,
                        "etat_txt": state,
                        "etat": area_state,
                        "sid": num,
                    })
        return areas


def configure_logging(debug: bool) -> None:
    level = logging.DEBUG if debug else logging.INFO
    logging.basicConfig(stream=sys.stderr, level=level, format="%(levelname)s:%(message)s")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("-c", "--config", default="/etc/acre_exp/config.yml")
    parser.add_argument("--debug", action="store_true")
    args = parser.parse_args()

    configure_logging(args.debug)

    try:
        cfg = load_cfg(args.config)
        client = SPCClient(cfg, debug=args.debug)
        data = client.get_or_fetch()
        sys.stdout.write(json.dumps(data, ensure_ascii=False, indent=2) + "\n")
    except Exception as exc:
        if args.debug:
            LOGGER.exception("Erreur lors de la récupération du statut")
        sys.stdout.write(json.dumps({"error": str(exc)}) + "\n")
        sys.exit(1)


if __name__ == "__main__":
    try:
        sys.stdout.reconfigure(encoding="utf-8")  # type: ignore[attr-defined]
    except Exception:
        pass
    main()
