#!/usr/bin/env python3
"""Rasterize the plugin's brand assets.

The design itself lives in scripts/assets/frame.html - one frame of the mark,
sized and posed by its query string. This script only drives a browser over it
and collects the output, so the thing that decides how the mark looks is SVG
and CSS rendered by a real engine, not shapes plotted by this file.

    python scripts/make-assets.py

Needs Chrome and ffmpeg on the machine. Writes into
packages/wordpress/.wordpress-org/ - the directory the plugin directory's
deploy action reads, and one the plugin zip never sees, because
build-plugin.sh copies an explicit allowlist rather than excluding things.

Everything is drawn from geometry and type, which matters twice over: it is
provably the project's own work rather than a licensed image, which is what the
plugin directory asks of every file it hosts, and it carries no third party's
logo, which is what OpenAI's marks being OpenAI's marks asks of us.

The screenshots are NOT produced here. They are photographs of the plugin
running, taken from a real site with the integrations installed, cropped to the
plugin's own screens and staged with example values rather than whatever the
machine happened to hold. Retaking them means standing a site up again.
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from urllib.parse import urlencode

try:
    from PIL import Image
except ImportError:  # pragma: no cover - a missing dependency explains itself
    sys.exit("This needs Pillow: python -m pip install Pillow")

ROOT = Path(__file__).resolve().parent.parent
FRAME = ROOT / "scripts" / "assets" / "frame.html"
OUT = ROOT / "packages" / "wordpress" / ".wordpress-org"

CHROME_CANDIDATES = [
    Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe"),
    Path(r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"),
    Path("/usr/bin/google-chrome"),
    Path("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"),
]

# 2.4 seconds: long enough that the pulse reads as deliberate rather than a
# flicker, short enough to loop in a plugin listing without nagging.
FRAMES = 24
FRAME_MS = 100

# Drawn larger and shrunk down. Chrome antialiases well, but a Gaussian glow
# resolves better with pixels to spare.
SUPERSAMPLE = 2


def chrome() -> Path:
    for path in CHROME_CANDIDATES:
        if path.exists():
            return path

    found = shutil.which("chrome") or shutil.which("chromium") or shutil.which("google-chrome")

    if found:
        return Path(found)

    sys.exit("Chrome not found. It renders the design; see the module docstring.")


def url(**params) -> str:
    return FRAME.as_uri() + "?" + urlencode(params)


def shoot(browser: Path, target: Path, width: int, height: int, **params) -> None:
    """One screenshot of the frame at an exact pixel size."""
    subprocess.run(
        [
            str(browser),
            "--headless=new",
            "--disable-gpu",
            "--hide-scrollbars",
            "--force-device-scale-factor=1",
            # The page waits on webfonts; this lets the clock run ahead so the
            # screenshot is taken after they land rather than before.
            "--virtual-time-budget=8000",
            f"--window-size={width},{height}",
            f"--screenshot={target}",
            url(w=width, h=height, **params),
        ],
        check=True,
        capture_output=True,
    )

    if not target.exists():
        sys.exit(f"Chrome wrote nothing for {target.name}")


def render(browser: Path, target: Path, width: int, height: int, **params) -> None:
    """A screenshot at `SUPERSAMPLE` size, brought back down."""
    with tempfile.TemporaryDirectory() as tmp:
        big = Path(tmp) / "big.png"
        shoot(browser, big, width * SUPERSAMPLE, height * SUPERSAMPLE, **params)
        Image.open(big).convert("RGB").resize((width, height), Image.LANCZOS).save(target, optimize=True)


def render_svg(browser: Path, target: Path) -> None:
    """The same mark as a vector, taken from the page rather than rewritten.

    Dumped from the rendered DOM so the file cannot drift away from what the
    PNGs show: there is one description of this mark, and it is frame.html.
    """
    result = subprocess.run(
        [str(browser), "--headless=new", "--disable-gpu", "--dump-dom", url(mode="icon", w=256, h=256, static=1)],
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )

    match = re.search(r"<svg\b.*?</svg>", result.stdout, re.DOTALL)

    if not match:
        sys.exit("No <svg> in the dumped DOM")

    svg = match.group(0)

    if 'xmlns=' not in svg:
        svg = svg.replace("<svg", '<svg xmlns="http://www.w3.org/2000/svg"', 1)

    target.write_text(svg + "\n", encoding="utf-8")


def check_fonts(browser: Path) -> None:
    """Refuse to build a banner set in the fallback face.

    The type is half the banner, and a webfont that failed to arrive does not
    announce itself - it just quietly renders in something else, and nobody
    notices until it is published.
    """
    result = subprocess.run(
        [str(browser), "--headless=new", "--disable-gpu", "--virtual-time-budget=9000",
         "--dump-dom", url(mode="banner", w=772, h=250)],
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )

    if 'data-fonts="true"' not in result.stdout:
        sys.exit("Sora and JetBrains Mono did not load. Check the network, then rebuild.")


def animate(browser: Path, target: Path) -> None:
    """The loop, as a GIF.

    ffmpeg rather than Pillow: a glow over a gradient is exactly the case where
    a naive 256-colour reduction bands visibly, and palettegen/paletteuse pick a
    palette from the whole sequence and dither against it.
    """
    ffmpeg = shutil.which("ffmpeg")

    if not ffmpeg:
        sys.exit("ffmpeg not found. It assembles the GIF; see the module docstring.")

    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)

        for i in range(FRAMES):
            render(browser, tmp / f"f{i:03d}.png", 256, 256, mode="icon", t=round(i / FRAMES, 5))

        palette = tmp / "palette.png"
        rate = 1000 / FRAME_MS

        subprocess.run(
            [ffmpeg, "-y", "-loglevel", "error", "-framerate", str(rate), "-i", str(tmp / "f%03d.png"),
             "-vf", "palettegen=max_colors=192:stats_mode=full", str(palette)],
            check=True,
        )

        subprocess.run(
            [ffmpeg, "-y", "-loglevel", "error", "-framerate", str(rate), "-i", str(tmp / "f%03d.png"),
             "-i", str(palette), "-lavfi", "paletteuse=dither=sierra2_4a", "-loop", "0", str(target)],
            check=True,
        )


def main() -> None:
    if not FRAME.exists():
        sys.exit(f"Missing {FRAME.relative_to(ROOT)}")

    browser = chrome()
    OUT.mkdir(parents=True, exist_ok=True)
    written = []

    for size in (256, 128):
        path = OUT / f"icon-{size}x{size}.png"
        render(browser, path, size, size, mode="icon")
        written.append(path)

    check_fonts(browser)

    for width, height in ((772, 250), (1544, 500)):
        path = OUT / f"banner-{width}x{height}.png"
        # bare: the banner paints its own ground, so the mark must not bring a
        # square one of its own and sit in a visible card.
        render(browser, path, width, height, mode="banner", bare=1)
        written.append(path)

    path = OUT / "icon.svg"
    render_svg(browser, path)
    written.append(path)

    # Not a plugin-directory format - it takes PNG, JPG and SVG only - so this
    # is for the readme on GitHub and anywhere else that renders a GIF.
    path = OUT / "mark-animated.gif"
    animate(browser, path)
    written.append(path)

    for item in written:
        print(f"{item.relative_to(ROOT)}  {item.stat().st_size / 1024:.0f} KB")


if __name__ == "__main__":
    main()
