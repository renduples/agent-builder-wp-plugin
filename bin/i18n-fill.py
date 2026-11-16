#!/usr/bin/env python3
"""One-shot fill of msgmerged POs. Load once, save once per locale."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

import polib

LOCALES = [
    "de_DE",
    "es_ES",
    "fr_FR",
    "it_IT",
    "ja",
    "ko_KR",
    "nl_NL",
    "pl_PL",
    "pt_BR",
    "ru_RU",
    "zh_CN",
]

ASSISTANT_TO_AGENT = {
    "de_DE": [("Assistenten", "Agenten"), ("assistenten", "agenten"), ("Assistentin", "Agentin"), ("Assistent", "Agent"), ("assistent", "agent")],
    "es_ES": [("Asistentes", "Agentes"), ("asistentes", "agentes"), ("Asistente", "Agente"), ("asistente", "agente")],
    "fr_FR": [("Assistants", "Agents"), ("assistants", "agents"), ("Assistant", "Agent"), ("assistant", "agent")],
    "it_IT": [("Assistenti", "Agenti"), ("assistenti", "agenti"), ("Assistente", "Agente"), ("assistente", "agente")],
    "ja": [("アシスタント", "エージェント")],
    "ko_KR": [("어시스턴트", "에이전트")],
    "nl_NL": [("Assistenten", "Agenten"), ("assistenten", "agenten"), ("Assistent", "Agent"), ("assistent", "agent")],
    "pl_PL": [("asystentów", "agentów"), ("Asystenci", "Agenci"), ("asystenci", "agenci"), ("asystenta", "agenta"), ("Asystent", "Agent"), ("asystent", "agent")],
    "pt_BR": [("Assistentes", "Agentes"), ("assistentes", "agentes"), ("Assistente", "Agente"), ("assistente", "agente")],
    "ru_RU": [
        ("Ассистентов", "Агентов"), ("ассистентов", "агентов"), ("Ассистенты", "Агенты"), ("ассистенты", "агенты"),
        ("Ассистента", "Агента"), ("ассистента", "агента"), ("Ассистент", "Агент"), ("ассистент", "агент"),
        ("Помощников", "Агентов"), ("помощников", "агентов"), ("Помощники", "Агенты"), ("помощники", "агенты"),
        ("Помощника", "Агента"), ("помощника", "агента"), ("Помощник", "Агент"), ("помощник", "агент"),
    ],
    "zh_CN": [("AI 助手", "智能体"), ("AI助手", "智能体"), ("助手", "智能体"), ("助理", "智能体")],
}

PLURALS = {
    "%1$d agent %2$s.": {
        "de_DE": ["%1$d Agent %2$s.", "%1$d Agenten %2$s."],
        "es_ES": ["%1$d agente %2$s.", "%1$d agentes %2$s."],
        "fr_FR": ["%1$d agent %2$s.", "%1$d agents %2$s."],
        "it_IT": ["%1$d agente %2$s.", "%1$d agenti %2$s."],
        "ja": ["%1$d 件のエージェント %2$s."],
        "ko_KR": ["에이전트 %1$d개 %2$s."],
        "nl_NL": ["%1$d agent %2$s.", "%1$d agents %2$s."],
        "pl_PL": ["%1$d agent %2$s.", "%1$d agentów %2$s.", "%1$d agentów %2$s."],
        "pt_BR": ["%1$d agente %2$s.", "%1$d agentes %2$s."],
        "ru_RU": ["%1$d агент %2$s.", "%1$d агента %2$s.", "%1$d агентов %2$s."],
        "zh_CN": ["%1$d 个智能体 %2$s。"],
    },
    "%d tool available.": {
        "de_DE": ["%d Tool verfügbar.", "%d Tools verfügbar."],
        "es_ES": ["%d herramienta disponible.", "%d herramientas disponibles."],
        "fr_FR": ["%d outil disponible.", "%d outils disponibles."],
        "it_IT": ["%d strumento disponibile.", "%d strumenti disponibili."],
        "ja": ["%d 個のツールが利用可能。"],
        "ko_KR": ["도구 %d개 사용 가능."],
        "nl_NL": ["%d tool beschikbaar.", "%d tools beschikbaar."],
        "pl_PL": ["%d narzędzie dostępne.", "%d narzędzia dostępne.", "%d narzędzi dostępnych."],
        "pt_BR": ["%d ferramenta disponível.", "%d ferramentas disponíveis."],
        "ru_RU": ["%d инструмент доступен.", "%d инструмента доступно.", "%d инструментов доступно."],
        "zh_CN": ["%d 个工具可用。"],
    },
}

MAP_FILES = [
    Path("/tmp/ab-trans-de-nl-pl.json"),
    Path("/tmp/ab-trans-romance.json"),
    Path("/tmp/ab-trans-cjk.json"),
    Path("/tmp/ab-trans-ru.json"),
]


def en_agentize(s: str) -> str:
    s = s.replace("Assistants", "Agents").replace("assistants", "agents")
    return s.replace("Assistant", "Agent").replace("assistant", "agent")


def strip_punct(s: str) -> str:
    return re.sub(r"[\s.!?…]+$", "", s).strip()


def apply_pairs(s: str, pairs: list[tuple[str, str]]) -> str:
    for a, b in pairs:
        s = s.replace(a, b)
    return s


def tweak_ending(dst: str, new_en: str, old_en: str) -> str:
    if new_en.endswith("...") and not dst.endswith("..."):
        return dst.rstrip(".!") + "..."
    if new_en.endswith("…") and not dst.endswith("…"):
        return dst.rstrip(".!") + "…"
    if new_en.endswith(".") and not old_en.endswith(".") and not dst.endswith("."):
        return dst + "."
    if new_en.endswith("?") and not dst.endswith("?"):
        return dst.rstrip(".!") + "?"
    return dst


def is_safe_assistant(prev: str, new: str) -> bool:
    return en_agentize(prev) == new


def is_safe_punct(prev: str, new: str) -> bool:
    if strip_punct(prev) == strip_punct(new):
        return True
    if strip_punct(prev).lower() == strip_punct(new).lower():
        return True
    prev2 = re.sub(r"^(\\u25[a-zA-Z0-9]{2}|[▼▶►▾▸•·]+)\s*", "", prev)
    return strip_punct(prev2).lower() == strip_punct(new).lower()


def load_maps() -> dict[str, dict[str, str]]:
    out: dict[str, dict[str, str]] = {}
    for p in MAP_FILES:
        data = json.loads(p.read_text(encoding="utf-8"))
        for loc, mapping in data.items():
            out.setdefault(loc, {}).update(mapping)
    return out


def nplurals_of(po: polib.POFile) -> int:
    pf = po.metadata.get("Plural-Forms", "")
    if "nplurals=1" in pf:
        return 1
    if "nplurals=3" in pf:
        return 3
    return 2


def fill(path: Path, locale: str, mapping: dict[str, str]) -> None:
    po = polib.pofile(str(path), wrapwidth=0, encoding="utf-8")
    pairs = ASSISTANT_TO_AGENT[locale]
    npl = nplurals_of(po)
    for entry in po:
        if not entry.msgid:
            continue
        if entry.obsolete:
            continue

        if entry.msgid_plural:
            if entry.msgid in PLURALS:
                forms = PLURALS[entry.msgid][locale]
                if len(forms) < npl:
                    forms = forms + [forms[-1]] * (npl - len(forms))
                entry.msgstr_plural = {i: forms[i] for i in range(npl)}
                entry.msgstr = ""
                entry.flags = [f for f in entry.flags if f != "fuzzy"]
            continue

        prev = entry.previous_msgid
        if entry.fuzzy and prev and is_safe_assistant(prev, entry.msgid) and entry.msgstr:
            entry.msgstr = tweak_ending(apply_pairs(entry.msgstr, pairs), entry.msgid, prev)
            entry.flags = [f for f in entry.flags if f != "fuzzy"]
            continue
        if entry.fuzzy and prev and is_safe_punct(prev, entry.msgid) and entry.msgstr:
            entry.msgstr = tweak_ending(re.sub(r"^[▼▶►▾▸•·]+\s*", "", entry.msgstr), entry.msgid, prev)
            entry.flags = [f for f in entry.flags if f != "fuzzy"]
            continue
        if entry.fuzzy:
            entry.msgstr = mapping.get(entry.msgid, "")
            entry.flags = [f for f in entry.flags if f != "fuzzy"]
            continue
        if not entry.translated() and entry.msgid in mapping:
            entry.msgstr = mapping[entry.msgid]

    po.metadata["Project-Id-Version"] = "Agent Builder 3.3.86"
    po.metadata["PO-Revision-Date"] = "2026-08-20 16:00+0000"
    po.save(str(path))


def main() -> int:
    maps = load_maps()
    for loc in LOCALES:
        path = Path(f"languages/siteloom-{loc}.po")
        before = path.read_text(encoding="utf-8").count("\nmsgid ")
        fill(path, loc, maps[loc])
        after = path.read_text(encoding="utf-8").count("\nmsgid ")
        print(f"{loc:8} msgid {before} -> {after}")
        if after < before - 5:
            print("  WARNING: msgid count dropped", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
