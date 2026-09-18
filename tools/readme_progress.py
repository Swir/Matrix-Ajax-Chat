from __future__ import annotations

import argparse
import math
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets" / "readme"
PROJECT = "Matrix AJAX Chat"
SOURCE = "No authoritative product roadmap/checklist exists in the repository."

CARD = """<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1200\" height=\"180\" viewBox=\"0 0 1200 180\" role=\"img\" aria-labelledby=\"title desc\"><title id=\"title\">Matrix AJAX Chat product progress</title><desc id=\"desc\">Product roadmap progress is N/A because no authoritative measurable roadmap is defined.</desc><defs><linearGradient id=\"bg\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"#02050A\"/><stop offset=\"1\" stop-color=\"#07111C\"/></linearGradient><linearGradient id=\"accent\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"0\"><stop offset=\"0\" stop-color=\"#0088FF\"/><stop offset=\"1\" stop-color=\"#62E5FF\"/></linearGradient></defs><rect width=\"1200\" height=\"180\" rx=\"24\" fill=\"url(#bg)\"/><rect x=\"1\" y=\"1\" width=\"1198\" height=\"178\" rx=\"23\" fill=\"none\" stroke=\"#62E5FF\" stroke-opacity=\".28\"/><text x=\"50\" y=\"44\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"17\" font-weight=\"700\" letter-spacing=\"2.5\" fill=\"#62E5FF\">SWIR PROGRESS SVG PRO</text><text x=\"50\" y=\"82\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"30\" font-weight=\"800\" fill=\"#F4FAFF\">Matrix AJAX Chat</text><text x=\"50\" y=\"111\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"16\" fill=\"#8DA8B8\">Measured scope: product roadmap completion</text><rect x=\"50\" y=\"132\" width=\"1100\" height=\"16\" rx=\"8\" fill=\"#0D1A25\" stroke=\"#62E5FF\" stroke-opacity=\".18\"/><text x=\"1150\" y=\"65\" text-anchor=\"end\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"36\" font-weight=\"800\" fill=\"url(#accent)\">N/A</text><text x=\"1150\" y=\"93\" text-anchor=\"end\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"15\" font-weight=\"700\" fill=\"#F4FAFF\">STATUS · MAINTAINED</text><text x=\"1150\" y=\"116\" text-anchor=\"end\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"14\" fill=\"#8DA8B8\">Counter · no canonical roadmap</text><text x=\"50\" y=\"167\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"13\" fill=\"#8DA8B8\">No fill: release versions, CI and feature count are not product-completion evidence.</text></svg>"""

MINI = """<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"900\" height=\"72\" viewBox=\"0 0 900 72\" role=\"img\" aria-labelledby=\"title desc\"><title id=\"title\">Matrix AJAX Chat compact progress</title><desc id=\"desc\">Product roadmap progress N/A; no authoritative measurable roadmap exists.</desc><defs><linearGradient id=\"bg\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"0\"><stop offset=\"0\" stop-color=\"#02050A\"/><stop offset=\"1\" stop-color=\"#07111C\"/></linearGradient></defs><rect width=\"900\" height=\"72\" rx=\"18\" fill=\"url(#bg)\"/><rect x=\"1\" y=\"1\" width=\"898\" height=\"70\" rx=\"17\" fill=\"none\" stroke=\"#62E5FF\" stroke-opacity=\".28\"/><text x=\"24\" y=\"29\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"15\" font-weight=\"700\" fill=\"#F4FAFF\">Product roadmap</text><text x=\"24\" y=\"51\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"13\" fill=\"#8DA8B8\">No canonical measurable scope</text><rect x=\"170\" y=\"27\" width=\"700\" height=\"14\" rx=\"7\" fill=\"#0D1A25\" stroke=\"#62E5FF\" stroke-opacity=\".18\"/><text x=\"870\" y=\"58\" text-anchor=\"end\" font-family=\"Segoe UI,Arial,sans-serif\" font-size=\"13\" font-weight=\"700\" fill=\"#62E5FF\">N/A</text></svg>"""


def _validate(svg: str) -> None:
    root = ET.fromstring(svg)
    if root.tag.rsplit("}", 1)[-1] != "svg" or not root.attrib.get("viewBox"):
        raise SystemExit("invalid SVG root/viewBox")
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
                raise SystemExit(f"invalid geometry: {key}={value}")


def outputs() -> dict[Path, str]:
    return {OUT / "progress-card.svg": CARD, OUT / "progress-mini.svg": MINI}


def main() -> int:
    parser = argparse.ArgumentParser(description=f"Generate {PROJECT} README progress SVGs. Source: {SOURCE}")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    for text in outputs().values():
        _validate(text)
    if args.check:
        stale = [str(path.relative_to(ROOT)) for path, expected in outputs().items() if not path.exists() or path.read_text(encoding="utf-8") != expected]
        if stale:
            raise SystemExit("stale progress assets: " + ", ".join(stale))
        template = OUT / "progress-template.svg"
        if not template.exists():
            raise SystemExit("missing assets/readme/progress-template.svg")
        _validate(template.read_text(encoding="utf-8"))
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        for required in ("assets/readme/progress-card.svg", "assets/readme/progress-mini.svg", "Product progress | **N/A**"):
            if required not in readme:
                raise SystemExit(f"README missing required progress reference: {required}")
        if any(token in readme for token in ("████", "░░", "[####", "[====")):
            raise SystemExit("legacy text progress meter detected")
        print(f"OK: {PROJECT}; product progress N/A; SVG-only presentation")
        return 0
    OUT.mkdir(parents=True, exist_ok=True)
    for path, text in outputs().items():
        path.write_text(text, encoding="utf-8")
    print(f"Generated {PROJECT} progress assets: N/A ({SOURCE})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
