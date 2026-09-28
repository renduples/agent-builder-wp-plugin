#!/usr/bin/env python3
"""Compile .mo and Jed JSON, then rename hashed JSON to WP script-handle names."""
from __future__ import annotations

import json
import shutil
import subprocess
import sys
from pathlib import Path

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

# Source file (as recorded by make-json) → wp_set_script_translations handle.
SOURCE_TO_HANDLE = {
    "src/settings-app/index.js": "agentic-settings-app",
    "src/dashboard-app/index.js": "agentic-dashboard-app",
    "src/admin-pages/index.js": "agentic-admin-pages",
    "src/admin-list/index.js": "agentic-admin-list",
    "src/agent-wizard/index.js": "agentic-agent-wizard",
    "src/deploy-wizard/index.js": "agentic-deploy-wizard",
    "src/knowledge-wizard/index.js": "agentic-knowledge-wizard",
    "src/interface-settings/index.js": "agentic-interface-settings",
    "src/dashboard-activity/index.js": "agentic-dashboard-activity",
    "assets/js/chat-overlay.js": "agentic-chat-overlay",
    "assets/js/gutenberg-blocks.js": "agentic-gutenberg-blocks",
}

# Shared modules are compiled into these handles; merge their Jed strings in.
SHARED_TO_HANDLES = {
    "src/shared/components.js": (
        "agentic-admin-list",
        "agentic-admin-pages",
        "agentic-settings-app",
    ),
    "src/shared/chat-embed.js": (
        "agentic-admin-pages",
        "agentic-settings-app",
    ),
}

ADMIN_PAGES_SHARED_PREFIX = "src/admin-pages/"


def main() -> int:
    lang = Path("languages")
    for loc in LOCALES:
        po = lang / f"agent-builder-{loc}.po"
        mo = lang / f"agent-builder-{loc}.mo"
        subprocess.check_call(["msgfmt", "-o", str(mo), str(po)])
        # Drop previous JS packs for this locale (hashed + handle-named).
        for old in lang.glob(f"agent-builder-{loc}-*.json"):
            old.unlink()
        # wp i18n make-json rewrites the .po in place and drops entries.
        # Snapshot, extract JSON, then restore.
        snapshot = po.read_bytes()
        subprocess.check_call(
            [
                "wp",
                "i18n",
                "make-json",
                str(po),
                str(lang),
                "--pretty-print",
            ],
            stdout=subprocess.DEVNULL,
        )
        po.write_bytes(snapshot)
        subprocess.check_call(["msgfmt", "-o", str(mo), str(po)])
        by_source: dict[str, dict] = {}
        leftover: list[Path] = []
        for jf in list(lang.glob(f"agent-builder-{loc}-*.json")):
            data = json.loads(jf.read_text(encoding="utf-8"))
            source = data.get("source")
            if isinstance(source, str):
                by_source[source] = data
            leftover.append(jf)

        renamed = 0
        for source, data in by_source.items():
            handle = SOURCE_TO_HANDLE.get(source)
            if not handle:
                continue
            dest = lang / f"agent-builder-{loc}-{handle}.json"
            dest.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            renamed += 1

        shared_to_handles = dict(SHARED_TO_HANDLES)
        for source in by_source:
            if source.startswith(ADMIN_PAGES_SHARED_PREFIX) and source not in SOURCE_TO_HANDLE:
                shared_to_handles[source] = ("agentic-admin-pages",)

        merged = 0
        for source, handles in shared_to_handles.items():
            extra = by_source.get(source)
            if not extra:
                continue
            extra_msgs = extra.get("locale_data", {}).get("messages", {})
            for handle in handles:
                dest = lang / f"agent-builder-{loc}-{handle}.json"
                if not dest.exists():
                    continue
                data = json.loads(dest.read_text(encoding="utf-8"))
                msgs = data.setdefault("locale_data", {}).setdefault("messages", {})
                for k, v in extra_msgs.items():
                    if k == "":
                        continue
                    msgs.setdefault(k, v)
                dest.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
                merged += 1

        for jf in leftover:
            # Keep only handle-named files.
            if any(jf.name.endswith(f"-{h}.json") for h in SOURCE_TO_HANDLE.values()):
                continue
            jf.unlink(missing_ok=True)

        unknown = [s for s in by_source if s not in SOURCE_TO_HANDLE and s not in shared_to_handles]
        print(f"{loc:8} mo={mo.stat().st_size:6} json={renamed} merged_shared={merged} unknown={unknown}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
