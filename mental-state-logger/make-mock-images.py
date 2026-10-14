#!/usr/bin/env python3
"""Mock status images devices/status-image-<DEVICE>.png (180x180) drawn with ../eeg-activities/eegact/eegact-headmap.
Needs Pillow:  ~/sessions/wa-link/venv/bin/python make-mock-images.py"""
import random
from importlib.machinery import SourceFileLoader
from pathlib import Path

from PIL import Image, ImageDraw

HERE = Path(__file__).resolve().parent
hm = SourceFileLoader("hm", str(HERE.parent / "eeg-activities/eegact/eegact-headmap")).load_module()
EPOC_CH, EPOC_POS = list(hm.CHANNELS), dict(hm.POS)
MUSE_CH = ["TP9", "AF7", "AF8", "TP10"]
MUSE_POS = {"TP9": (-0.92, -0.45), "AF7": (-0.55, 0.72), "AF8": (0.55, 0.72), "TP10": (0.92, -0.45)}


def frame(path, label, channels, pos, seed):
    random.seed(seed)
    hm.CHANNELS[:], hm.POS = channels, pos
    conn = {ch: random.choices([0, 1, 2, 3], [1, 1, 2, 8])[0] for ch in channels}
    sig = {ch: None if conn[ch] == 0 else round(random.random() ** 0.5 * conn[ch] / 3, 2) for ch in channels}
    conn[channels[0]], sig[channels[0]] = 3, 1.0
    img = Image.new("RGB", (180, 180), "#ffffff")
    hm.draw(hm.PilPainter(img), conn, sig)
    ImageDraw.Draw(img).text((3, 2), label, fill="#757575")
    img.save(path)


frame(HERE / "devices/status-image-epocx1.png", "EPOC X #1 (mock)", EPOC_CH, EPOC_POS, 4)
frame(HERE / "devices/status-image-epocx2.png", "EPOC X #2 (mock)", EPOC_CH, EPOC_POS, 11)
frame(HERE / "devices/status-image-muse1.png", "Muse (mock)", MUSE_CH, MUSE_POS, 5)
img = Image.new("RGB", (180, 180), "#eeeeee")
d = ImageDraw.Draw(img)
d.text((90, 90), "no device selected", fill="#757575", anchor="mm")
img.save(HERE / "devices/no-device.png")
