"""Build both partner-help PDFs from the same JSON chapters used by HTML Help.

python scripts/build-partner-guide.py --updated 2026-11-27
Only local demo images are read; no live system or previous PDF is required.
"""
import argparse
import json
import shutil
from datetime import date
from pathlib import Path
from xml.sax.saxutils import escape

from PIL import Image as PILImage
from pypdf import PdfReader
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    Image, KeepTogether, PageBreak, Paragraph, SimpleDocTemplate, Spacer,
    Table, TableStyle,
)

ROOT = Path(__file__).resolve().parents[1]
BLUE = colors.HexColor('#164e63')
INK = colors.HexColor('#172a3a')
LIGHT = colors.HexColor('#edf6f8')
WIDTH = A4[0] - 88
LABELS = {
    'hu': {
        'title': 'Partnerportál\nFelhasználói kézikönyv',
        'edition': 'Magyar kiadás', 'updated': 'Frissítve',
        'intro': 'Útmutató a rendelések létrehozásához, a mennyiségek rögzítéséhez, az Excel-fájlok kezeléséhez és a rendelések beküldéséhez.',
        'demo': 'Az ábrák helyi mintaadatokat mutatnak, nem valódi partneradatokat vagy megrendeléseket. A leírás a portál Súgó hivatkozásával HTML-ben is olvasható.',
        'contents': 'Tartalomjegyzék', 'note': 'Fontos', 'checklist': 'Ellenőrzőlista',
        'footer': 'HEAVY TOOLS | Partnerportál', 'summary': 'Rendelések listája',
    },
    'en': {
        'title': 'Partner portal\nUser guide',
        'edition': 'English edition', 'updated': 'Updated',
        'intro': 'A practical guide to creating orders, entering quantities, working with Excel files and submitting your order.',
        'demo': 'The illustrations use local demonstration data, not real customer details or orders. The same guide is available as HTML through Help in the portal.',
        'contents': 'Contents', 'note': 'Important', 'checklist': 'Checklist',
        'footer': 'HEAVY TOOLS | Partner portal', 'summary': 'Order list',
    },
}


class GuideDocument(SimpleDocTemplate):
    def afterFlowable(self, flowable):
        if hasattr(flowable, 'guide_anchor'):
            self.canv.bookmarkPage(flowable.guide_anchor)
            self.canv.addOutlineEntry(flowable.getPlainText(), flowable.guide_anchor, level=0)


def build_guide(locale, chapters, images_root, output, updated):
    labels = LABELS[locale]
    styles = {
        name: ParagraphStyle(
            name, fontName='GuideBold' if bold else 'Guide', fontSize=size,
            leading=leading, textColor=BLUE if bold else INK, spaceAfter=8,
            keepWithNext=name in ['Chapter', 'Label'],
        )
        for name, size, leading, bold in [
            ('Body', 10.2, 14.2, False), ('Title', 29, 35, True),
            ('Chapter', 20, 25, True), ('Label', 11.5, 15, True),
            ('Small', 8.3, 11, False),
        ]
    }

    def p(text, style='Body'):
        text = str(text).translate({code: '-' for code in range(0x2010, 0x2016)})
        return Paragraph(escape(text).replace('\n', '<br/>'), styles[style])

    def footer(canvas, doc):
        canvas.saveState()
        canvas.setStrokeColor(colors.HexColor('#cbd5e1'))
        canvas.line(44, 35, A4[0] - 44, 35)
        canvas.setFillColor(colors.HexColor('#475569'))
        canvas.setFont('Guide', 8)
        canvas.drawString(44, 23, f"{labels['footer']} | {updated.isoformat()}")
        canvas.drawRightString(A4[0] - 44, 23, str(doc.page))
        canvas.restoreState()

    def callout(texts):
        box = Table([[p(labels['note'], 'Label')]] + [[p(text)] for text in texts], colWidths=[WIDTH])
        box.setStyle(TableStyle([
            ('BACKGROUND', (0, 0), (-1, -1), LIGHT),
            ('BOX', (0, 0), (-1, -1), .6, BLUE),
            ('LEFTPADDING', (0, 0), (-1, -1), 10),
            ('RIGHTPADDING', (0, 0), (-1, -1), 10),
            ('TOPPADDING', (0, 0), (-1, -1), 5),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 3),
        ]))
        return box

    story = [
        Spacer(1, 24), p('HEAVY TOOLS', 'Label'), Spacer(1, 24),
        p(labels['title'], 'Title'),
        p(f"{labels['edition']} | {labels['updated']}: {updated.isoformat()}", 'Label'),
        Spacer(1, 18), p(labels['intro']), p(labels['demo']), Spacer(1, 18),
        callout([
            'Két fül: Rendelés generálása/megnyitása és Rendelések listája. Ha van elérhető rendelés, a lista nyílik meg; rendelésből visszatérve mindig ide érkezel.'
            if locale == 'hu' else
            'Two tabs: Generate/open order and Order list. If accessible orders exist, the list opens initially. Returning from an order always opens the list.'
        ]),
        PageBreak(), p(labels['contents'], 'Chapter'),
    ]
    for index, chapter in enumerate(chapters, 1):
        story.append(Paragraph(
            f'<link href="#chapter-{index}" color="#164e63">{index:02d}  {escape(chapter["title"])}</link>',
            styles['Body'],
        ))
    story.append(PageBreak())

    for index, chapter in enumerate(chapters, 1):
        heading = p(f'{index:02d}  {chapter["title"]}', 'Chapter')
        heading.guide_anchor = f'chapter-{index}'
        story.append(heading)
        if chapter.get('intro'):
            story.append(p(chapter['intro']))
        images = chapter.get('images', [[chapter['image'], chapter.get('caption', '')]] if chapter.get('image') else [])
        for image in images:
            source = images_root / locale / (Path(image[0]).stem + '.jpg')
            if not source.is_file():
                raise FileNotFoundError(f'Missing published help image: {source}')
            with PILImage.open(source) as original:
                width, height = original.size
            scale = min(WIDTH / width, 215 / height, 1)
            story.append(KeepTogether([
                Image(str(source), width=width * scale, height=height * scale, hAlign='LEFT'),
                p(image[1] if len(image) > 1 else '', 'Small'), Spacer(1, 3),
            ]))
        for number, text in enumerate(chapter.get('steps', []), 1):
            story.append(p(f'{number}. {text}'))
        for text in chapter.get('paragraphs', []):
            story.append(p(text))
        if chapter.get('table'):
            headers, rows = chapter['table']
            table = Table(
                [[p(cell, 'Label') for cell in headers]] + [[p(cell) for cell in row] for row in rows],
                colWidths=[WIDTH / len(headers)] * len(headers), repeatRows=1, hAlign='LEFT',
            )
            table.setStyle(TableStyle([
                ('GRID', (0, 0), (-1, -1), .5, colors.HexColor('#94a3b8')),
                ('BACKGROUND', (0, 0), (-1, 0), LIGHT),
                ('VALIGN', (0, 0), (-1, -1), 'TOP'),
                ('LEFTPADDING', (0, 0), (-1, -1), 8),
                ('RIGHTPADDING', (0, 0), (-1, -1), 8),
                ('TOPPADDING', (0, 0), (-1, -1), 6),
            ]))
            story += [table, Spacer(1, 10)]
        for question, answer in chapter.get('faqs', []):
            story += [p(question, 'Label'), p(answer)]
        if chapter.get('notes'):
            story += [Spacer(1, 3), callout(chapter['notes'])]
        if chapter.get('checklist'):
            story.append(p(labels['checklist'], 'Label'))
            story += [p('- ' + item) for item in chapter['checklist']]
        if index < len(chapters):
            story.append(PageBreak())
    GuideDocument(
        str(output), pagesize=A4, rightMargin=44, leftMargin=44,
        topMargin=45, bottomMargin=47, title=labels['title'].replace('\n', ' - '),
        author='Heavy Tools', pageCompression=1,
    ).build(story, onFirstPage=footer, onLaterPages=footer)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--updated', type=date.fromisoformat, required=True)
    parser.add_argument('--screenshots-root', type=Path, default=ROOT / 'public/help/partner-guide')
    parser.add_argument('--delivery', type=Path, default=ROOT / 'output/pdf')
    parser.add_argument('--font-dir', type=Path, default=Path('C:/Windows/Fonts'))
    args = parser.parse_args()
    pdfmetrics.registerFont(TTFont('Guide', str(args.font_dir / 'arial.ttf')))
    pdfmetrics.registerFont(TTFont('GuideBold', str(args.font_dir / 'arialbd.ttf')))
    pdfmetrics.registerFontFamily('Guide', normal='Guide', bold='GuideBold')
    args.delivery.mkdir(parents=True, exist_ok=True)
    for locale in ['hu', 'en']:
        chapters = json.loads((ROOT / f'resources/help/content/{locale}.json').read_text(encoding='utf-8'))
        target = ROOT / f'resources/help/partner-guide-{locale}.pdf'
        build_guide(locale, chapters, args.screenshots_root, target, args.updated)
        delivered = args.delivery / target.name
        if delivered.resolve() != target.resolve():
            shutil.copy2(target, delivered)
        reader = PdfReader(target)
        print(f'{locale}: {len(reader.pages)} pages; {target.stat().st_size} bytes')


if __name__ == '__main__':
    main()
