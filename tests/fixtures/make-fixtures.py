#!/usr/bin/env python3
"""
Generates the small sample files the SEO audit converts (see
.github/workflows/jim-seo-audit.yml). The texts are original and made up.
Run again only to change the samples:  python3 tests/fixtures/make-fixtures.py
"""
import os
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))

SENTENCES_PT = [
    "A cidade guardava, em cada esquina, uma pequena história que os moradores contavam uns aos outros nas tardes de domingo.",
    "Quem chegava de fora logo percebia que o ritmo das conversas seguia o das marés, lento na vazante e animado na cheia.",
    "No mercado, as bancas abriam cedo e fechavam quando o último freguês terminava de contar o que tinha visto na estrada.",
    "A biblioteca ficava no alto da rua principal, e seus corredores estreitos cheiravam a papel velho e a café recém-passado.",
    "Os professores diziam que ler em voz alta era a melhor maneira de entender um texto difícil e de perdoar um autor teimoso.",
    "Durante o inverno, as janelas embaçavam e as pessoas escreviam pequenas palavras no vidro, apagadas pela manhã seguinte.",
]

SENTENCES_FR = [
    "Il y avait dans ce village un vieux moulin dont les ailes tournaient encore quand le vent du soir descendait de la colline.",
    "Les enfants s'asseyaient près de la fontaine pour écouter les histoires que racontait la boulangère en pétrissant sa pâte.",
    "Chaque automne, les habitants se réunissaient sur la place pour partager les récoltes et chanter les chansons de leurs grands-parents.",
    "Le facteur connaissait chaque chemin, chaque porte et chaque chien, et il saluait tout le monde avec la même politesse tranquille.",
    "Au printemps, les fenêtres s'ouvraient toutes en même temps, comme si le village entier respirait après un long silence.",
    "On disait que la rivière gardait les secrets de ceux qui s'y étaient penchés pour y chercher leur propre visage.",
]


def paragraphs(sentences, count):
    out = []
    for i in range(count):
        out.append(" ".join(sentences[(i + k) % len(sentences)] for k in range(4)))
    return out


def write(name, data, mode="w"):
    path = os.path.join(HERE, name)
    if mode == "wb":
        with open(path, "wb") as f:
            f.write(data)
    else:
        with open(path, "w", encoding="utf-8", newline="\n") as f:
            f.write(data)


# ---- TXT: a public-domain style header (banner, data sheet, notice with an address) before the text.
header = (
    "***  NOTICE ABOUT THIS FILE  ***\n\n"
    "Title: Le moulin du village\n"
    "Author: Anonyme\n"
    "Release date: October 1, 2003 [file #4565]\n"
    "Language: French\n\n"
    "=========================================\n\n"
    "This file may be copied and shared at no cost and with almost no restrictions. "
    "Read the terms of use at www.example.org/terms before using it.\n\n"
    "=========================================\n\n"
)
body = "\n\n".join(paragraphs(SENTENCES_FR, 40))
write("moulin.txt", header + "Le moulin du village\n\n" + body + "\n")

# ---- MD: long title, headings, prose.
md = ["# Manual de atendimento ao cidadão com orientações completas para todos os setores da prefeitura\n"]
for n in range(1, 7):
    md.append("\n## Capítulo %d\n" % n)
    md.extend("\n" + p + "\n" for p in paragraphs(SENTENCES_PT, 6))
    md.append("\n### Detalhes do capítulo %d\n\n%s\n" % (n, paragraphs(SENTENCES_PT, 1)[0]))
write("manual.md", "".join(md))

# ---- DOCX: heading styles, document properties (author, subject, keywords, language).
W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"


def para(text, style=None):
    ppr = '<w:pPr><w:pStyle w:val="%s"/></w:pPr>' % style if style else ""
    text = text.replace("&", "&amp;").replace("<", "&lt;")
    return '<w:p>%s<w:r><w:t xml:space="preserve">%s</w:t></w:r></w:p>' % (ppr, text)


doc = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="%s"><w:body>' % W]
doc.append(para("Relatório anual de atividades da biblioteca municipal", "Title"))
for n in range(1, 5):
    doc.append(para("Seção %d: resultados do período" % n, "Heading1"))
    for p in paragraphs(SENTENCES_PT, 5):
        doc.append(para(p))
    doc.append(para("Detalhamento da seção %d" % n, "Heading2"))
    doc.append(para(paragraphs(SENTENCES_PT, 1)[0]))
doc.append("</w:body></w:document>")

styles = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="%s">'
    '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
    '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="48"/></w:rPr></w:style>'
    '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
    '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
    "</w:styles>" % W
)

core = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
    'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/">'
    "<dc:title>Relatório anual de atividades da biblioteca municipal</dc:title>"
    "<dc:creator>Maria Silva</dc:creator>"
    "<dc:subject>Resumo das atividades, do acervo e dos programas de leitura oferecidos pela biblioteca municipal ao longo do ano.</dc:subject>"
    "<cp:keywords>biblioteca, leitura, relatório</cp:keywords>"
    "<dc:language>pt-BR</dc:language>"
    "</cp:coreProperties>"
)

content_types = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    '<Default Extension="xml" ContentType="application/xml"/>'
    '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
    "</Types>"
)

rels = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
    "</Relationships>"
)

doc_rels = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    "</Relationships>"
)

path = os.path.join(HERE, "relatorio.docx")
with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
    z.writestr("[Content_Types].xml", content_types)
    z.writestr("_rels/.rels", rels)
    z.writestr("word/document.xml", "".join(doc))
    z.writestr("word/styles.xml", styles)
    z.writestr("word/_rels/document.xml.rels", doc_rels)
    z.writestr("docProps/core.xml", core)

# ---- PDF: a few pages of text, with Author and Subject in the information dictionary.
def pdf_text(s):
    return s.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def wrap(text, width=88):
    words, line, lines = text.split(), "", []
    for w in words:
        if len(line) + len(w) + 1 > width:
            lines.append(line)
            line = w
        else:
            line = (line + " " + w).strip()
    if line:
        lines.append(line)
    return lines


# Latin-1 text (WinAnsi) in a standard font.
page_streams = []
paras = ["Guia de leitura acessível para a comunidade"] + paragraphs(SENTENCES_PT, 14)
lines = []
for i, p in enumerate(paras):
    lines.extend(wrap(p, 70 if i == 0 else 88))
    lines.append("")
per_page = 40
for start in range(0, len(lines), per_page):
    chunk = lines[start:start + per_page]
    ops = ["BT", "/F1 11 Tf", "14 TL", "50 780 Td"]
    for ln in chunk:
        ops.append("(%s) Tj T*" % pdf_text(ln))
    ops.append("ET")
    page_streams.append("\n".join(ops).encode("cp1252", "replace"))

objects = []


def add(b):
    objects.append(b)
    return len(objects)


font = add(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>")
pages_id = len(objects) + 1 + 2 * len(page_streams)  # placeholder, fixed below
content_ids, page_ids = [], []
for s in page_streams:
    content_ids.append(add(b"<< /Length %d >>\nstream\n" % len(s) + s + b"\nendstream"))
    page_ids.append(None)
pages_obj_index = len(objects) + len(page_streams) + 1
for i, cid in enumerate(content_ids):
    page_ids[i] = add(
        ("<< /Type /Page /Parent %d 0 R /MediaBox [0 0 595 842] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>"
         % (pages_obj_index, cid, font)).encode()
    )
kids = " ".join("%d 0 R" % p for p in page_ids)
pages = add(("<< /Type /Pages /Kids [%s] /Count %d >>" % (kids, len(page_ids))).encode())
assert pages == pages_obj_index
catalog = add(("<< /Type /Catalog /Pages %d 0 R >>" % pages).encode())
info = add(
    b"<< /Title (Guia de leitura acessivel para a comunidade) /Author (Maria Silva) "
    b"/Subject (Orientacoes de leitura para a comunidade, com dicas de acessibilidade e de organizacao do tempo de estudo.) >>"
)

out = bytearray(b"%PDF-1.4\n")
offsets = []
for i, body in enumerate(objects, start=1):
    offsets.append(len(out))
    out += b"%d 0 obj\n" % i + body + b"\nendobj\n"
xref = len(out)
out += b"xref\n0 %d\n0000000000 65535 f \n" % (len(objects) + 1)
for off in offsets:
    out += b"%010d 00000 n \n" % off
out += b"trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (len(objects) + 1, catalog, info, xref)
write("guia.pdf", bytes(out), "wb")

# ---- PNG: 512x512 icon, set as the site icon in the CI (the fallback og:image).
import struct
import zlib


def chunk(kind, data):
    body = kind + data
    return struct.pack(">I", len(data)) + body + struct.pack(">I", zlib.crc32(body) & 0xFFFFFFFF)


rows = bytearray()
for y in range(512):
    rows.append(0)
    rows.extend(bytes((30, 90 + (y // 4) % 100, 160)) * 512)
signature = bytes((137, 80, 78, 71, 13, 10, 26, 10))
png = signature + chunk(b"IHDR", struct.pack(">IIBBBBB", 512, 512, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(bytes(rows), 9)) + chunk(b"IEND", b"")
write("icone.png", png, "wb")

print("Fixtures written to", HERE)
