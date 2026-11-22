"""Publish optimised demo screenshots for HTML help; never capture live customer data.

Usage: python scripts/prepare-partner-help-images.py --screenshots-root .tmp/partner-manual-v2
Each locale subdirectory must contain the PNGs named in resources/help/content/*.json.
"""
import argparse
import json
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--screenshots-root', type=Path, required=True)
args = parser.parse_args()
for locale in ['hu', 'en']:
    chapters = json.loads((ROOT / f'resources/help/content/{locale}.json').read_text(encoding='utf-8'))
    folder = ROOT / f'public/help/partner-guide/{locale}'
    folder.mkdir(parents=True, exist_ok=True)
    names = set()
    for chapter in chapters:
        images = chapter.get('images', [[chapter['image']]] if chapter.get('image') else [])
        for image in images:
            name = image[0]
            if name in names:
                continue
            names.add(name)
            with Image.open(args.screenshots_root / locale / name) as source:
                source = source.convert('RGB')
                source.thumbnail((1800, 3500))
                source.save(folder / (Path(name).stem+'.jpg'), quality=87, optimize=True, progressive=True)
    print(f'{locale}: {len(names)} optimised screenshots')
