from __future__ import annotations

import argparse
import math
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets" / "readme"
PROJECT = "Matrix AJAX Chat"
SOURCE = "No authoritative product roadmap/checklist exists in the repository."

CARD = (OUT / "progress-card.svg")
MINI = (OUT / "progress-mini.svg")


def validate(path: Path) -> None:
    root = ET.fromstring(path.read_text(encoding="utf-8"))
    if root.tag.rsplit("}", 1)[-1] != "svg" or not root.attrib.get("viewBox"):
        raise SystemExit(f"invalid SVG: {path}")
    for node in root.iter():
        for key in ("x", "y", "width", "height", "rx", "ry"):
            value = node.attrib.get(key)
            if value is None:
                continue
            try:
                number = float(value)
            except ValueError:
                continue
            if not math.isfinite(number) or number < 0:
                raise SystemExit(f"invalid geometry {key}={value} in {path}")


def main() -> int:
    parser = argparse.ArgumentParser(description=f"Check {PROJECT} README progress assets. Source: {SOURCE}")
    parser.add_argument("--check", action="store_true", help="verify committed deterministic N/A assets")
    args = parser.parse_args()
    if not args.check:
        print("N/A is intentional; no roadmap-derived percentage can be generated. Use --check to validate committed assets.")
        return 0
    for path in (CARD, MINI, OUT / "progress-template.svg"):
        if not path.exists():
            raise SystemExit(f"missing {path.relative_to(ROOT)}")
        validate(path)
    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    for required in ("assets/readme/progress-card.svg", "assets/readme/progress-mini.svg", "Product progress | **N/A**"):
        if required not in readme:
            raise SystemExit(f"README missing required progress reference: {required}")
    if any(token in readme for token in ("████", "░░", "[####", "[====")):
        raise SystemExit("legacy text progress meter detected")
    print(f"OK: {PROJECT}; product progress N/A; {SOURCE}; SVG-only presentation")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
