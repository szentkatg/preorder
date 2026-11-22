"""Build public partner-help PDFs from anonymised screenshots and the previous HU guide.

Example: python scripts/build-partner-guide.py --screenshots .tmp/partner-manual-v2/en
    --hungarian-source PATH_TO_PREVIOUS_HU_PDF --delivery PATH_TO_OUTPUT_PDF_FOLDER
Requires reportlab, Pillow and pypdf; no network or live application access.
"""

import argparse
import json
import shutil
from pathlib import Path
from xml.sax.saxutils import escape

from PIL import Image as PILImage
from pypdf import PdfReader, PdfWriter
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    Image, PageBreak, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle,
)

ROOT = Path(__file__).resolve().parents[1]
BLUE = colors.HexColor('#164e63')
INK = colors.HexColor('#172a3a')
LIGHT = colors.HexColor('#edf6f8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--screenshots', type=Path, required=True)
    parser.add_argument('--hungarian-source', type=Path, required=True)
    parser.add_argument('--delivery', type=Path, required=True)
    parser.add_argument('--font-dir', type=Path, default=Path('C:/Windows/Fonts'))
    args = parser.parse_args()
    pdfmetrics.registerFont(TTFont('Guide', str(args.font_dir / 'arial.ttf')))
    pdfmetrics.registerFont(TTFont('GuideBold', str(args.font_dir / 'arialbd.ttf')))
    pdfmetrics.registerFontFamily('Guide', normal='Guide', bold='GuideBold')
    styles = getSampleStyleSheet()
    for name, size, leading, colour in [
        ('Body', 10.5, 14.5, INK), ('TitleGuide', 31, 36, BLUE),
        ('Chapter', 21, 26, BLUE), ('SmallGuide', 8.5, 11, colors.HexColor('#475569')),
        ('LabelGuide', 12, 16, BLUE),
    ]:
        styles.add(ParagraphStyle(name, fontName='GuideBold' if name in ['Chapter', 'TitleGuide', 'LabelGuide'] else 'Guide',
                                  fontSize=size, leading=leading, textColor=colour, spaceAfter=8))
    def p(text, style='Body'):
        return Paragraph(escape(text).replace('\n', '<br/>'), styles[style])
    def callout(text):
        box = Table([[p(text)]], colWidths=[507])
        box.setStyle(TableStyle([('BACKGROUND', (0, 0), (-1, -1), LIGHT), ('BOX', (0, 0), (-1, -1), .6, BLUE),
                                ('LEFTPADDING', (0, 0), (-1, -1), 11), ('RIGHTPADDING', (0, 0), (-1, -1), 11),
                                ('TOPPADDING', (0, 0), (-1, -1), 9), ('BOTTOMPADDING', (0, 0), (-1, -1), 3)]))
        return box
    def footer(canvas, doc):
        canvas.saveState()
        canvas.setStrokeColor(colors.HexColor('#cbd5e1'))
        canvas.line(44, 35, A4[0] - 44, 35)
        canvas.setFillColor(colors.HexColor('#475569'))
        canvas.setFont('Guide', 8)
        canvas.drawString(44, 23, 'HEAVY TOOLS | Partner portal | 22 November 2026')
        canvas.drawRightString(A4[0] - 44, 23, str(doc.page))
        canvas.restoreState()
    out = ROOT / 'resources/help'
    scratch = ROOT / '.tmp/partner-guide-pdf'
    out.mkdir(parents=True, exist_ok=True)
    scratch.mkdir(parents=True, exist_ok=True)
    def build(target, story):
        SimpleDocTemplate(str(target), pagesize=A4, rightMargin=44, leftMargin=44, topMargin=45, bottomMargin=47,
                          title='Heavy Tools Partner Portal - User Guide', author='Heavy Tools',
                          pageCompression=1).build(story, onFirstPage=footer, onLaterPages=footer)

    chapters = json.loads((ROOT / 'resources/help/content/en.json').read_text(encoding='utf-8'))
    story = [Spacer(1, 20), p('HEAVY TOOLS', 'LabelGuide'), Spacer(1, 25),
             p('Partner portal\nUser guide', 'TitleGuide'), p('English edition | Updated 22 November 2026', 'LabelGuide'),
             Spacer(1, 14), p('A practical guide to creating orders, entering quantities, working with Excel files and submitting your order. Representative-only tools have their own chapters.'),
             p('The screenshots show the current application with demonstration data. Names, addresses, references, prices and quantities shown are examples, not real customer orders.'),
             Spacer(1, 12), p('Quick start', 'Chapter')]
    for i, text in enumerate(['Sign in, read the update notice and choose a season.', 'Select an address, brand and order sheet.',
                              'Open an existing order or create one with a unique reference.', 'Enter quantities online or in Excel.',
                              'Review Aggregated data, then submit the order.'], 1):
        story.append(p(f'{i}. {text}'))
    story += [Spacer(1, 10), callout('Before importing an older export, check the order reference in the portal. Enter it in F1, or fill the missing-reference field in the import preview if F1 is empty.'),
              Spacer(1, 14), p('Access depends on your account', 'LabelGuide'),
              p('Address users work with their assigned addresses. Partner administrators work with accessible addresses of their partner. Sales representatives work with partners in their access scope and have additional representative actions.'),
              p('Use the Help link at the top of portal pages to reopen the guide. The selected interface language determines which version opens.', 'SmallGuide'), PageBreak(),
              p('Contents', 'Chapter')]
    for i, chapter in enumerate(chapters, 1):
        story.append(p(f'{i:02d}  {chapter["title"]}'))
    story += [Spacer(1, 18), callout('Always check the browser address and the season shown in the header. The live portal and any test site are separate environments.'), PageBreak()]
    for i, chapter in enumerate(chapters, 1):
        story += [p(f'{i:02d}  {chapter["title"]}', 'Chapter'), p(chapter['intro'])]
        if chapter.get('image'):
            source = args.screenshots / chapter['image']
            with PILImage.open(source) as original:
                original = original.convert('RGB')
                width, height = original.size
                # Isolate the search field, but keep form buttons and table columns intact.
                if chapter.get('crop_right') and width > 800:
                    original = original.crop((width - 800, 0, width, height))
                target = scratch / f'en-{i:02d}.jpg'
                original.save(target, quality=88, optimize=True)
                width, height = original.size
            scale = min(507 / width, 215 / height, 1)
            story += [Image(str(target), width=width * scale, height=height * scale, hAlign='LEFT'),
                      p(chapter['caption'], 'SmallGuide'), Spacer(1, 4)]
        for j, text in enumerate(chapter.get('steps', []), 1):
            story.append(p(f'{j}. {text}'))
        for text in chapter.get('paragraphs', []):
            story.append(p(text))
        if chapter.get('notes'):
            story += [Spacer(1, 3), callout('\n'.join(chapter['notes']))]
        if i < len(chapters):
            story.append(PageBreak())
    build(out / 'partner-guide-en.pdf', story)

    # Preserve the previously approved illustrated HU manual and prepend only the new guidance.
    supplement = [p('Partnerportál - frissítési tudnivalók', 'Chapter'),
        p('Kiegészítés a 2026. november 22-i változásokhoz. A következő oldalakon a korábban elkészült, képernyőképes felhasználói kézikönyv olvasható.'),
        p('Belépés és szezonválasztás', 'LabelGuide'),
        p('Sikeres belépés után először a frissítési tájékoztató jelenhet meg, majd külön ablakban választasz szezont. A Ne jelenjen meg újra jelöléssel a tájékoztatót a saját felhasználódnál tartósan elrejtheted. Ez másik böngészőben is érvényes.'),
        p('A szezon később a Szezonváltás gombbal, újabb belépés nélkül módosítható. A Súgó minden partneroldal tetejéről megnyitja a kiválasztott nyelvű leírást.'),
        p('Hivatkozási szám és rendeléstípus', 'LabelGuide'),
        p('Minden rendeléshez kötelező hivatkozási szám tartozik. A korábbi rendelésekhez ezt már kitöltöttük; az aktuális értéket a rendeléslistában ellenőrizd. Új rendelésnél válassz rendeléstípust, ha a címhez többféle is engedélyezett.'),
        p('Korábbi Excel-fájlok visszatöltése', 'LabelGuide'),
        callout('Ellenőrizd a célrendelés hivatkozási számát, majd írd az importálandó fájl F1 cellájába. Ha F1 üres, a hiányzó hivatkozást az import előnézet soronkénti beviteli mezőjében is pótolhatod. Nem üres, hibás F1 esetén a fájlt kell javítanod.'),
        Spacer(1, 12),
        p('A rendelésválasztó Rendelés import gombjával is indíthatsz betöltést. Az Import előnézet után az oldal az eredményhez gördül. A hiányzó hivatkozás gépelés közben ellenőrződik: figyeld a mező melletti piros hibaüzenetet vagy zöld Importálható jelzést.'),
        p('Csak az importálható sor jelölőnégyzete választható; az érvényes sor automatikusan kijelölődik. Ellenőrizd a meglévő és új mennyiségeket és értékeket, majd nyomd meg a Kijelöltek importálása gombot.'),
        p('A célrendelésnek előre léteznie kell, szerkeszthetőnek kell lennie, és jogosultságod kell legyen a címéhez. Az import nem hoz létre új rendelést. Az üres mennyiségcella nem módosít, a 0 törli az adott mennyiséget.')]
    build(scratch / 'hu-update.pdf', supplement)
    writer = PdfWriter()
    writer.append(scratch / 'hu-update.pdf')
    writer.append(args.hungarian_source)
    writer.add_metadata({'/Title': 'Heavy Tools - Partnerportál felhasználói kézikönyv', '/Author': 'Heavy Tools'})
    with (out / 'partner-guide-hu.pdf').open('wb') as stream:
        writer.write(stream)
    args.delivery.mkdir(parents=True, exist_ok=True)
    for locale in ['hu', 'en']:
        source = out / f'partner-guide-{locale}.pdf'
        shutil.copy2(source, args.delivery / source.name)
        reader = PdfReader(source)
        print(f'{locale}: {len(reader.pages)} pages; {source.stat().st_size} bytes')


if __name__ == '__main__':
    main()
