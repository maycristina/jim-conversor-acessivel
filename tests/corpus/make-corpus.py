#!/usr/bin/env python3
"""
Generates the source files of the conversion corpus: tests/corpus/<case>/source.pdf
or source.docx. The texts are original and made up. Each case also has an
expected.json (written by hand) and an expected-reader.txt (written by the test
in update mode, see tests/corpus/README.md).

Run again only to change the samples:  python3 tests/corpus/make-corpus.py

The PDFs are written by hand, object by object, so each one has exactly the
layout it tests (columns, lists, table rows) and the files are byte-identical
on every run: no library, no date, no random id.
"""
import os
import zipfile
import zlib

HERE = os.path.dirname(os.path.abspath(__file__))


def save(case, name, data):
    folder = os.path.join(HERE, case)
    os.makedirs(folder, exist_ok=True)
    with open(os.path.join(folder, name), "wb") as f:
        f.write(data)


def wrap(text, width):
    words, line, lines = text.split(), "", []
    for w in words:
        if line and len(line) + len(w) + 1 > width:
            lines.append(line)
            line = w
        else:
            line = (line + " " + w).strip()
    if line:
        lines.append(line)
    return lines


def flow(paragraphs, width):
    """Lines of all paragraphs, with an empty line between paragraphs."""
    lines = []
    for p in paragraphs:
        if lines:
            lines.append("")
        lines.extend(wrap(p, width))
    return lines


# ---------------------------------------------------------------------------
# Minimal PDF writer
# ---------------------------------------------------------------------------

TO_UNICODE = b"""/CIDInit /ProcSet findresource begin
12 dict begin
begincmap
/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def
/CMapName /Adobe-Identity-UCS def
/CMapType 2 def
1 begincodespacerange
<00> <FF>
endcodespacerange
1 beginbfchar
<B7> <F0B7>
endbfchar
endcmap
CMapName currentdict /CMap defineresource pop
end
end"""


# Helvetica advance widths (1/1000 em) from its AFM; accented letters as their base letter.
HELVETICA = {" ": 278, ".": 278, ",": 278, ":": 278, ";": 278, "!": 278, "?": 556, "-": 333, "(": 333, ")": 333}
HELVETICA.update(dict(zip("abcdefghijklmnopqrstuvwxyz", [556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500])))
HELVETICA.update(dict(zip("ABCDEFGHIJKLMNOPQRSTUVWXYZ", [667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611])))
HELVETICA.update({c: HELVETICA[b] for c, b in zip("áàâãéêíóôõúçÁÉÍÓÚÇ", "aaaaeeiooouc" "AEIOUC")})


def text_width(s, size):
    return sum(HELVETICA.get(c, 556) for c in s) * size / 1000.0


# F3: codes 01-07 for the characters WinAnsi lacks.
F3_CODES = {"ﬀ": 1, "ﬁ": 2, "ﬂ": 3, "ﬃ": 4, "ﬄ": 5, "◦": 6, "▪": 7}
F3_TO_UNICODE = TO_UNICODE.replace(
    b"1 beginbfchar\n<B7> <F0B7>\nendbfchar",
    b"%d beginbfchar\n" % len(F3_CODES)
    + b"".join(b"<%02X> <%04X>\n" % (code, ord(ch)) for ch, code in F3_CODES.items())
    + b"endbfchar",
)


def pdf_string(raw):
    return raw.replace(b"\\", b"\\\\").replace(b"(", b"\\(").replace(b")", b"\\)")


class Pdf:
    """
    Text placed by absolute coordinates (Tm), one text object per run, in the
    order the runs are added: that order is the order of the content stream,
    which is what the converter reads.

    Fonts: F1 is Helvetica with WinAnsi (cp1252) text. F2 is the Symbol font
    with a ToUnicode map that turns code B7 into U+F0B7, the private-use
    character Word writes for its default bullet.
    """

    def __init__(self):
        self.pages = []
        self.outline = []

    def page(self):
        self.pages.append([])

    def text(self, x, y, s, size=10, font="F1"):
        raw = s.encode("cp1252") if font == "F1" else s.encode("latin-1")
        self.pages[-1].append(
            b"BT /%s %g Tf 1 0 0 1 %g %g Tm (%s) Tj ET" % (font.encode(), size, x, y, pdf_string(raw))
        )

    def mixed(self, x, y, s, size=10):
        """
        Text in F1, with the characters WinAnsi lacks (ligatures, ◦, ▪) as
        pieces in F3, whose ToUnicode map gives them back: how LaTeX and
        InDesign write a "fi" ligature. Returns the x where the text ends.
        """
        buf = ""
        for ch in s + "\0":
            if ch in F3_CODES or ch == "\0":
                if buf:
                    self.text(x, y, buf, size)
                    x += text_width(buf, size)
                    buf = ""
                if ch in F3_CODES:
                    self.pages[-1].append(b"BT /F3 %g Tf 1 0 0 1 %g %g Tm <%02X> Tj ET" % (size, x, y, F3_CODES[ch]))
                    x += 0.55 * size
            else:
                buf += ch
        return x

    def glyphs(self, x, y, s, size=10):
        """
        One text object per letter, spaces included, at Helvetica's real
        advance widths: how Google Docs and other Skia-based exporters write
        text. Word breaks only show as the space pieces.
        """
        for ch in s:
            self.text(x, y, ch, size)
            x += HELVETICA.get(ch, 556) * size / 1000.0

    def draw(self, x, y, s, size=10):
        """
        Capital letters drawn as filled outlines, one small square per dot of
        a 5x7 font, with no text operator: how "Microsoft Print to PDF" writes
        a web page whose fonts it does not embed. On screen it reads; for any
        text extractor the page is empty.
        """
        dot = size / 7.0
        for ch in s:
            for row, bits in enumerate(DOT_FONT.get(ch, DOT_FONT[" "])):
                for col, bit in enumerate(bits):
                    if bit == "#":
                        px = x + col * dot
                        py = y + (6 - row) * dot
                        self.pages[-1].append(
                            b"%.2f %.2f m %.2f %.2f l %.2f %.2f l %.2f %.2f l h f"
                            % (px, py, px + dot, py, px + dot, py + dot, px, py + dot)
                        )
            x += 6 * dot

    def image(self, x, y, w, h, width, height, pixels):
        """An RGB image (Flate), drawn at x, y with w x h points."""
        if not hasattr(self, "images"):
            self.images = []
        name = b"Im%d" % (len(self.images) + 1)
        self.images.append((len(self.pages) - 1, name, width, height, zlib.compress(pixels, 9)))
        self.pages[-1].append(b"q %g 0 0 %g %g %g cm /%s Do Q" % (w, h, x, y, name))

    def bookmark(self, title, page_index, level=0):
        self.outline.append((title, page_index, level))

    def build(self, title):
        objects = {}
        n_pages = len(self.pages)
        # Fixed ids: 1 catalog, 2 pages, 3 F1, 4 F2, 5 ToUnicode, 6 info,
        # then page/content pairs, then the outline.
        page_ids = [7 + 2 * i for i in range(n_pages)]
        content_ids = [8 + 2 * i for i in range(n_pages)]
        next_id = 7 + 2 * n_pages

        objects[3] = b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"
        objects[4] = b"<< /Type /Font /Subtype /Type1 /BaseFont /Symbol /ToUnicode 5 0 R >>"
        objects[5] = b"<< /Length %d >>\nstream\n" % len(TO_UNICODE) + TO_UNICODE + b"\nendstream"
        objects[6] = b"<< /Title (%s) /Author (Jim corpus) >>" % pdf_string(title.encode("latin-1"))

        # F3 only in the PDFs that use it: the others keep their exact bytes.
        fonts = b"/F1 3 0 R /F2 4 0 R"
        if any(op.startswith(b"BT /F3 ") for ops in self.pages for op in ops):
            objects[next_id] = b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode %d 0 R >>" % (next_id + 1)
            objects[next_id + 1] = b"<< /Length %d >>\nstream\n" % len(F3_TO_UNICODE) + F3_TO_UNICODE + b"\nendstream"
            fonts += b" /F3 %d 0 R" % next_id
            next_id += 2

        # Images likewise: ids only in the PDFs that have them.
        xobjects = [b"" for _ in self.pages]
        for page_index, name, width, height, data in getattr(self, "images", []):
            objects[next_id] = (
                b"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB "
                b"/BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n" % (width, height, len(data))
                + data
                + b"\nendstream"
            )
            xobjects[page_index] += b" /%s %d 0 R" % (name, next_id)
            next_id += 1

        for i, ops in enumerate(self.pages):
            stream = b"\n".join(ops)
            objects[content_ids[i]] = b"<< /Length %d >>\nstream\n" % len(stream) + stream + b"\nendstream"
            extra = b" /XObject <<%s >>" % xobjects[i] if xobjects[i] else b""
            objects[page_ids[i]] = (
                b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents %d 0 R "
                b"/Resources << /Font << %s >>%s >> >>" % (content_ids[i], fonts, extra)
            )

        objects[2] = b"<< /Type /Pages /Kids [%s] /Count %d >>" % (
            b" ".join(b"%d 0 R" % p for p in page_ids),
            n_pages,
        )

        outline_ref = b""
        if self.outline:
            root = next_id
            next_id += 1
            # Tree of two levels: each level-1 entry hangs from the last level-0 one.
            nodes = []
            for t, p, level in self.outline:
                node = {"id": next_id, "title": t, "page": p, "children": []}
                next_id += 1
                if level == 0 or not nodes:
                    nodes.append(node)
                else:
                    nodes[-1]["children"].append(node)

            def write_level(items, parent):
                for k, node in enumerate(items):
                    entry = b"<< /Title (%s) /Parent %d 0 R /Dest [%d 0 R /XYZ 0 842 0]" % (
                        pdf_string(node["title"].encode("latin-1")),
                        parent,
                        page_ids[node["page"]],
                    )
                    if k > 0:
                        entry += b" /Prev %d 0 R" % items[k - 1]["id"]
                    if k + 1 < len(items):
                        entry += b" /Next %d 0 R" % items[k + 1]["id"]
                    if node["children"]:
                        entry += b" /First %d 0 R /Last %d 0 R /Count %d" % (
                            node["children"][0]["id"],
                            node["children"][-1]["id"],
                            len(node["children"]),
                        )
                        write_level(node["children"], node["id"])
                    objects[node["id"]] = entry + b" >>"

            write_level(nodes, root)
            objects[root] = b"<< /Type /Outlines /First %d 0 R /Last %d 0 R /Count %d >>" % (
                nodes[0]["id"],
                nodes[-1]["id"],
                len(nodes),
            )
            outline_ref = b" /Outlines %d 0 R /PageMode /UseOutlines" % root

        objects[1] = b"<< /Type /Catalog /Pages 2 0 R%s >>" % outline_ref

        out = bytearray(b"%PDF-1.4\n")
        offsets = {}
        for i in range(1, next_id):
            offsets[i] = len(out)
            out += b"%d 0 obj\n" % i + objects[i] + b"\nendobj\n"
        xref = len(out)
        out += b"xref\n0 %d\n0000000000 65535 f \n" % next_id
        for i in range(1, next_id):
            out += b"%010d 00000 n \n" % offsets[i]
        out += b"trailer\n<< /Size %d /Root 1 0 R /Info 6 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (next_id, xref)
        return bytes(out)


# ---------------------------------------------------------------------------
# pdf-single-column-outline: the case that already works (baseline).
# Bookmarks, a printed table of contents, page numbers in the footer and a
# sentence that runs from one page into the next.
# ---------------------------------------------------------------------------

CH1 = [
    "Este guia explica como transformar um arquivo em uma página que todo mundo consegue ler ou ouvir. "
    "Ele foi escrito para bibliotecas, escolas e prefeituras que publicam documentos com frequência.",
    "Antes de começar, separe o arquivo original e confira se o texto pode ser selecionado com o mouse. "
    "Quando o texto não pode ser selecionado, o arquivo é uma imagem e precisa passar por reconhecimento de caracteres.",
    "A conversão respeita os títulos marcados pelo autor e monta um sumário lateral com eles. "
    "Por isso vale a pena revisar os marcadores do documento antes de enviar o arquivo, porque um título "
    "esquecido some do sumário e deixa a navegação mais difícil para quem usa leitor de tela no "
    "celular ou no computador.",
]
CH2 = [
    "Depois da conversão, abra a página e escute o começo do texto com o leitor em voz alta. "
    "Se uma frase soar estranha, compare com o original e anote a página.",
]
CH2_1 = [
    "Os detalhes de cada formato ficam na documentação do projeto, com exemplos de arquivos que funcionam bem.",
]


def single_column_pdf():
    pdf = Pdf()

    pdf.page()
    pdf.text(50, 780, "Guia de leitura acessível", 18)
    pdf.text(50, 740, "Sumário", 14)
    pdf.text(50, 715, "Capítulo 1: Começo " + "." * 40 + " 2")
    pdf.text(50, 700, "Capítulo 2: Revisão " + "." * 40 + " 3")
    pdf.text(290, 40, "1")

    lines = flow(CH1, 88)
    # The last paragraph is cut in the middle of a sentence: 4 lines on page 2, the rest on page 3.
    cut = len(lines) - 2
    pdf.page()
    pdf.text(50, 780, "Capítulo 1: Começo", 14)
    y = 750
    for ln in lines[:cut]:
        if ln:
            pdf.text(50, y, ln)
        y -= 14
    pdf.text(290, 40, "2")

    pdf.page()
    y = 780
    for ln in lines[cut:]:
        pdf.text(50, y, ln)
        y -= 14
    y -= 14
    pdf.text(50, y, "Capítulo 2: Revisão", 14)
    y -= 30
    for ln in flow(CH2, 88):
        if ln:
            pdf.text(50, y, ln)
        y -= 14
    y -= 14
    pdf.text(50, y, "2.1 Detalhes", 12)
    y -= 24
    for ln in flow(CH2_1, 88):
        pdf.text(50, y, ln)
        y -= 14
    pdf.text(290, 40, "3")

    pdf.bookmark("Capítulo 1: Começo", 1)
    pdf.bookmark("Capítulo 2: Revisão", 2)
    pdf.bookmark("2.1 Detalhes", 2, 1)
    save("pdf-single-column-outline", "source.pdf", pdf.build("Guia de leitura acessivel"))


# ---------------------------------------------------------------------------
# pdf-two-columns: an article in two columns, written line by line from left
# to right (as Word and Canva do): line 1 of the left column, then line 1 of
# the right column, and so on. Read in that order, the columns get mixed.
# ---------------------------------------------------------------------------

ARTICLE = [
    "A leitura em voz alta depende da ordem do texto. Quando a ordem se perde, a frase deixa de fazer sentido "
    "e a pessoa que escuta precisa adivinhar o que o autor quis dizer.",
    "Artigos científicos costumam usar duas colunas para economizar papel. Na tela, essas colunas viram um "
    "desafio, porque o arquivo guarda cada linha em uma posição e não diz qual coluna vem primeiro.",
    "Alguns programas gravam a coluna da esquerda inteira e depois a da direita. Outros gravam a primeira linha "
    "da esquerda, a primeira linha da direita, e assim por diante, como se a página fosse uma única coluna larga.",
    "No segundo caso, um conversor ingênuo junta pedaços de frases que nunca estiveram lado a lado. O resultado "
    "parece uma lista de palavras soltas e o leitor de tela anuncia tudo com a mesma calma, sem perceber o erro.",
    "Para corrigir, o conversor precisa olhar a posição de cada linha. Linhas com a mesma margem esquerda "
    "pertencem à mesma coluna, e o espaço vazio no meio da página mostra onde uma coluna termina.",
    "Títulos e resumos ocupam a largura toda e ficam acima das colunas. Eles devem ser lidos antes, na posição "
    "em que aparecem, e não misturados com a primeira linha de cada coluna.",
    "Rodapés com o número da página ficam abaixo das colunas. O conversor já remove esses números quando eles "
    "aparecem sozinhos na primeira ou na última linha da página.",
    "Uma frase pode começar no fim da coluna da direita e terminar no alto da próxima página. Quando os "
    "marcadores de página estão desligados, as duas partes precisam voltar a formar uma frase só.",
    "Tabelas também têm colunas, mas são lidas linha por linha. Por isso uma célula curta não pode ser "
    "confundida com uma coluna de texto corrido, que tem linhas longas e parecidas.",
    "Com essas regras, a leitura volta a seguir o caminho que o autor imaginou: de cima para baixo na coluna "
    "da esquerda e, depois, de cima para baixo na coluna da direita.",
]
ABSTRACT = (
    "Resumo: este artigo de teste mostra como o Jim deve ler um texto em duas colunas, com título e resumo "
    "na largura toda, frases que mudam de coluna e uma frase que continua na página seguinte."
)


def two_columns_pdf():
    pdf = Pdf()
    lines = flow(ARTICLE, 46)
    per_column = 16
    columns = [lines[i:i + per_column] for i in range(0, len(lines), per_column)]

    for page_number in (1, 2):
        pdf.page()
        top = 800
        if page_number == 1:
            pdf.text(50, 790, "Leitura em duas colunas: um teste do Jim", 16)
            pdf.text(200, 768, "Maria Silva, Universidade Exemplo")
            y = 745
            for ln in wrap(ABSTRACT, 95):
                pdf.text(50, y, ln)
                y -= 13
            top = y - 20
        left = columns[(page_number - 1) * 2] if len(columns) > (page_number - 1) * 2 else []
        right = columns[(page_number - 1) * 2 + 1] if len(columns) > (page_number - 1) * 2 + 1 else []
        for i in range(max(len(left), len(right))):
            y = top - i * 13
            if i < len(left) and left[i]:
                pdf.text(50, y, left[i])
            if i < len(right) and right[i]:
                pdf.text(310, y, right[i])
        pdf.text(295, 40, str(page_number))

    save("pdf-two-columns", "source.pdf", pdf.build("Leitura em duas colunas"))


def glyph_columns_pdf(case, interleaved):
    """
    The same article, one letter per text object. Interleaved: line by line
    across the page (must be reordered). Not interleaved: the left column,
    then the right one (getText() already reads it right: must not change).
    """
    pdf = Pdf()
    lines = flow(ARTICLE, 46)
    per_column = 16
    columns = [lines[i:i + per_column] for i in range(0, len(lines), per_column)]

    for page_number in (1, 2):
        pdf.page()
        top = 780
        if page_number == 1:
            pdf.glyphs(50, 790, "Leitura em duas colunas: um teste do Jim", 16)
            top = 750
        k = (page_number - 1) * 2
        left = columns[k] if len(columns) > k else []
        right = columns[k + 1] if len(columns) > k + 1 else []
        placed = []
        for i in range(max(len(left), len(right))):
            y = top - i * 13
            if i < len(left) and left[i]:
                placed.append((0, i, 50, y, left[i]))
            if i < len(right) and right[i]:
                placed.append((1, i, 310, y, right[i]))
        if not interleaved:
            placed.sort()
        for _, _, x, y, ln in placed:
            pdf.glyphs(x, y, ln)
        pdf.glyphs(295, 40, str(page_number))

    save(case, "source.pdf", pdf.build("Leitura em duas colunas"))


def glyph_single_column_pdf():
    """One column, one letter per text object: no columns to find, no word to split."""
    pdf = Pdf()
    pdf.page()
    y = 790
    for ln in flow(CH1 + CH2, 88):
        if ln:
            pdf.glyphs(50, y, ln)
        y -= 14
    save("pdf-single-column-glyphs", "source.pdf", pdf.build("Uma coluna"))


# ---------------------------------------------------------------------------
# pdf-lists: bullets (WinAnsi and Word's private-use bullet), a numbered list
# with a lettered sublist, a numbered heading that is not a list, and dialogue
# lines with a dash, which must stay paragraphs.
# ---------------------------------------------------------------------------


def lists_pdf():
    pdf = Pdf()
    pdf.page()
    rows = [
        (50, "Materiais para a oficina de leitura:"),
        (50, "• Livros em letra ampliada"),
        (50, "• Fones de ouvido"),
        (50, "• Cadeiras com apoio para os braços e altura"),
        (62, "regulável para cada leitor"),
        (50, "Depois da lista, um parágrafo comum continua o texto e termina com ponto final."),
        (50, "Passos para converter um arquivo:"),
        (50, "1. Abra o painel do Jim"),
        (50, "2. Envie o arquivo"),
        (70, "a) PDF com texto selecionável"),
        (70, "b) DOCX salvo no Word ou no Google Docs"),
        (50, "3. Confira o resultado no leitor"),
        (50, "O resultado aparece em poucos segundos."),
        (None, "Item marcado com o símbolo do Word"),
        (None, "Outro item com o mesmo símbolo"),
        (50, "Fim dos itens com símbolo."),
        (50, "1. Introdução"),
        (50, "Este capítulo apresenta o tema e termina com ponto final."),
        (50, "— Você leu o capítulo? — perguntou Ana."),
        (50, "— Li, sim — respondeu Davi."),
    ]
    y = 790
    for x, text in rows:
        if x is None:
            # Word: the bullet in the Symbol font, the text in another run after a tab.
            pdf.text(50, y, "·", 10, "F2")
            pdf.text(68, y, text)
        else:
            pdf.text(x, y, text)
        y -= 16
    save("pdf-lists", "source.pdf", pdf.build("Listas"))


# ---------------------------------------------------------------------------
# pdf-verses: numbered verses ("1 No princípio") and a short poem. They
# already convert well; the case guards them against the list detection.
# ---------------------------------------------------------------------------

VERSES = [
    "1 No começo havia apenas o silêncio da biblioteca e o barulho distante da chuva no telhado.",
    "2 Então alguém abriu um livro e leu em voz alta a primeira linha, devagar, para que todos ouvissem.",
    "3 E os que estavam longe se aproximaram, porque a voz chegava até o fundo da sala.",
    "4 Assim terminou o primeiro dia da oficina.",
]


def verses_pdf():
    pdf = Pdf()
    pdf.page()
    pdf.text(50, 790, "Capítulo 1", 14)
    y = 760
    for v in VERSES:
        for ln in wrap(v, 80):
            pdf.text(50, y, ln)
            y -= 14
    y -= 20
    for ln in ["A chuva cai na rua,", "o livro aberto espera,", "e a voz segue sozinha."]:
        pdf.text(50, y, ln)
        y -= 14
    pdf.bookmark("Capítulo 1", 0)
    save("pdf-verses", "source.pdf", pdf.build("Versiculos"))


# ---------------------------------------------------------------------------
# pdf-table-rows: a table drawn as loose text (no lines), three short columns.
# It must stay row by row: the column detection is for running text only.
# ---------------------------------------------------------------------------


def table_rows_pdf():
    pdf = Pdf()
    pdf.page()
    pdf.text(50, 790, "Participantes da oficina de leitura.", 12)
    rows = [
        ("Nome", "Cidade", "Livros lidos"),
        ("Ana Souza", "Recife", "12"),
        ("Davi Lima", "Belém", "8"),
        ("Clara Nunes", "Curitiba", "15"),
        ("Bruno Alves", "Natal", "6"),
        ("Rita Gomes", "Manaus", "9"),
        ("Pedro Rocha", "Goiânia", "11"),
    ]
    y = 760
    for a, b, c in rows:
        pdf.text(50, y, a)
        pdf.text(250, y, b)
        pdf.text(420, y, c)
        y -= 16
    pdf.text(50, y - 10, "Os números contam os livros lidos durante o semestre.")
    save("pdf-table-rows", "source.pdf", pdf.build("Tabela"))


# ---------------------------------------------------------------------------
# pdf-two-columns-interleaved: case 02 of the external suggestion, with 8
# lines per column (3 are too few for the column detection, on purpose) and
# lines that fit in their column (the suggestion's ran into the right one).
# ---------------------------------------------------------------------------

INTERLEAVED_A = [
    "o argumento começa aqui,",
    "desce pelo lado esquerdo",
    "e junta as frases do",
    "primeiro bloco, uma por",
    "linha, até chegar ao fim",
    "da coluna sem pular para",
    "o outro lado, onde fica",
    "o bloco que vem depois.",
]
INTERLEAVED_B = [
    "o segundo bloco começa",
    "no alto da direita e só",
    "deve ser lido depois da",
    "coluna da esquerda, sem",
    "misturar uma linha de",
    "cada lado, o erro de quem",
    "segue a ordem bruta do",
    "arquivo nesta página.",
]


def interleaved_pdf():
    pdf = Pdf()
    pdf.page()
    pdf.text(50, 792, "Artigo Científico em Duas Colunas", 16)
    y = 752
    for n, (a, b) in enumerate(zip(INTERLEAVED_A, INTERLEAVED_B), start=1):
        left = "COLUNA A LINHA %d: %s" % (n, a)
        right = "COLUNA B LINHA %d: %s" % (n, b)
        # Each line must fit in its column: the left one ends before the gap.
        assert 50 + text_width(left, 10) < 300 and 310 + text_width(right, 10) < 565, (left, right)
        pdf.text(50, y, left)
        pdf.text(310, y, right)
        y -= 18
    save("pdf-two-columns-interleaved", "source.pdf", pdf.build("Duas colunas intercaladas"))


# ---------------------------------------------------------------------------
# pdf-hyphens-ligatures: words cut at the end of a line ("acessi-" +
# "bilidade") or already on the same line ("com- plexidade") must be joined;
# hyphens that belong to the word (enclitic pronouns, compound prefixes) and
# suspended hyphens ("pré- e pós-") must stay. Ligatures (fi, fl, ff, ffi, ffl)
# become plain letters. A paragraph runs into page 2, cut by a hyphen, with
# "Página 1 de 2" in the footer.
# ---------------------------------------------------------------------------

HYPHEN_LINES = [
    "A implementação de micro-organismos e guarda-chuvas exige que palavras trans-",
    "lineadas no fim da margem sejam reconstruídas sem cortar o fôlego do sintetizador.",
    "Ligaduras: ﬁcção, ﬂuxo, staﬀ, eﬃgy e shuﬄe, com Conceição, órgãos e 1º lugar.",
    "Quem gosta do documento quer lê-lo inteiro e depois encontrá-",
    "lo de novo, e quem o perdeu pede que lhe mandem outra cópia para guardá-",
    "la bem.",
    "Os alunos de pós-",
    "graduação e os recém-",
    "chegados recebem um guarda-",
    "chuva e instruções ex-",
    "plícitas antes da primeira aula, com pelo me-",
    "nos um mapa da cidade.",
    "A com- plexidade do pré- e pós-operatório não assusta ninguém.",
    "Os testes vão executando-",
    "os em ordem, fazendo- a revisão de cada um.",
    "O prédio anti-",
    "go ficou aberto, e a auto-",
    "observação dos leitores mostrou que a acessi-",
    "bilidade melhorou muito.",
]


def hyphens_pdf():
    pdf = Pdf()
    pdf.page()
    y = 790
    for ln in HYPHEN_LINES:
        pdf.mixed(50, y, ln, 11)
        y -= 15
    pdf.text(50, 70, "Este parágrafo começa no fim da primeira página e continua exata-", 11)
    pdf.text(250, 30, "Página 1 de 2", 8)
    pdf.page()
    pdf.text(50, 790, "mente na segunda, sem o rodapé no meio da frase.", 11)
    pdf.text(250, 30, "Página 2 de 2", 8)
    save("pdf-hyphens-ligatures", "source.pdf", pdf.build("Hifenizacao e ligaduras"))


# ---------------------------------------------------------------------------
# pdf-lists-levels: case 04 of the external suggestion. Bullets of two
# levels (•, ◦, ▪) with a wrapped item, a dash line (dialogue in Portuguese
# books: stays a paragraph), and a numbered list followed by a single "a)"
# sub-item.
# ---------------------------------------------------------------------------


def lists_levels_pdf():
    pdf = Pdf()
    pdf.page()
    pdf.text(50, 792, "Checklist de Requisitos de Leitura", 14)
    items = [
        (50, "• Item de primeiro nível com bullet tradicional redondo."),
        (75, "◦ Subitem de segundo nível com círculo vazado e recuo X maior."),
        (75, "◦ Segundo subitem que quebra em duas linhas para testar se a continuação"),
        (85, "não vira um novo parágrafo fora do elemento da lista."),
        (50, "▪ Item com bullet quadrado muito comum no Microsoft Word."),
        (50, "— Item usando travessão como marcador de lista."),
        (50, "1. Primeiro item de uma lista ordenada numérica."),
        (50, "2. Segundo item da lista ordenada numérica."),
        (75, "a) Subitem ordenado alfabético com parêntese."),
        (50, "Fim da lista."),
    ]
    y = 757
    for x, t in items:
        pdf.mixed(x, y, t, 11)
        y -= 20
    save("pdf-lists-levels", "source.pdf", pdf.build("Listas em niveis"))


# ---------------------------------------------------------------------------
# pdf-tables: case 03 of the external suggestion (roadmap, marked
# "pending"): a table with merged header cells and one without borders. A PDF
# only has text at positions; rebuilding <table> from them is not done yet.
# ---------------------------------------------------------------------------


def tables_pdf():
    pdf = Pdf()
    pdf.page()
    pdf.text(72, 760, "Relatório de Desempenho e Acessibilidade", 18)
    y = 720
    for row in (
        ["Critério WCAG", "Status por Formato", ""],
        ["", "DOCX", "PDF"],
        ["1.1.1 Conteúdo Não Textual", "Suportado", "Parcial"],
        ["1.3.1 Informações e Relações", "Suportado", "Em Teste"],
    ):
        for x, cell in zip((100, 300, 400), row):
            if cell:
                pdf.text(x, y, cell)
        y -= 18
    y -= 25
    pdf.text(72, y, "Tabela Financeira Sem Bordas", 14)
    y -= 24
    for row in (["Item", "Quantidade", "Valor Unitário"], ["Licença Open Source", "100", "R$ 0,00"], ["Servidor Cloud", "2", "R$ 45,90"]):
        for x, cell in zip((100, 330, 440), row):
            pdf.text(x, y, cell)
        y -= 18
    save("pdf-tables", "source.pdf", pdf.build("Tabelas"))


# ---------------------------------------------------------------------------
# pdf-running-header: the same title printed at the top of every page. Known
# gap (the case is marked "pending"): the header is read as text and cuts the
# sentence that runs from one page into the next.
# ---------------------------------------------------------------------------

HEADER_TEXT = [
    "O relatório anual reúne os números da biblioteca e as histórias de quem passou por ela. "
    "Cada seção começa com um resumo curto e termina com as metas para o próximo ano.",
    "Os empréstimos cresceram em todos os bairros, e a sala de leitura ficou aberta até mais tarde "
    "nas noites de quinta-feira, quando os estudantes se preparavam para as provas do fim do semestre.",
]


def running_header_pdf():
    pdf = Pdf()
    lines = flow(HEADER_TEXT, 88)
    cut = len(lines) - 2
    for chunk in (lines[:cut], lines[cut:]):
        pdf.page()
        pdf.text(50, 810, "Relatório anual da biblioteca municipal", 8)
        y = 770
        for ln in chunk:
            if ln:
                pdf.text(50, y, ln)
            y -= 14
        pdf.text(290, 40, str(len(pdf.pages)))
    save("pdf-running-header", "source.pdf", pdf.build("Relatorio anual"))


# ---------------------------------------------------------------------------
# docx-table-headers: a header row (w:tblHeader), a Google Docs table whose
# rows say w:tblHeader w:val="0" (NOT headers), merged cells, a cell with
# Word's "auto" shading, and Word numbered and bulleted lists.
# ---------------------------------------------------------------------------

W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"


def xml_text(s):
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def para(text, style=None, num=None):
    ppr = ""
    if style or num:
        ppr = "<w:pPr>"
        if style:
            ppr += '<w:pStyle w:val="%s"/>' % style
        if num:
            ppr += '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="%d"/></w:numPr>' % num
        ppr += "</w:pPr>"
    return '<w:p>%s<w:r><w:t xml:space="preserve">%s</w:t></w:r></w:p>' % (ppr, xml_text(text))


def cell(text, extra=""):
    tcpr = "<w:tcPr>%s</w:tcPr>" % extra if extra else ""
    return "<w:tc>%s%s</w:tc>" % (tcpr, para(text))


def row(cells, header=None):
    trpr = ""
    if header == "on":
        trpr = "<w:trPr><w:tblHeader/></w:trPr>"
    elif header == "off":
        trpr = '<w:trPr><w:tblHeader w:val="0"/></w:trPr>'
    return "<w:tr>%s%s</w:tr>" % (trpr, "".join(cells))


def table(rows, cols):
    grid = "".join('<w:gridCol w:w="3000"/>' for _ in range(cols))
    return (
        '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="0" w:type="auto"/></w:tblPr>'
        "<w:tblGrid>%s</w:tblGrid>%s</w:tbl>" % (grid, "".join(rows))
    )


STYLES = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="%s">'
    '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
    '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="48"/></w:rPr></w:style>'
    '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
    '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/></w:style>'
    "</w:styles>" % W
)

NUMBERING = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="%s">'
    '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%%1."/></w:lvl></w:abstractNum>'
    '<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/></w:lvl></w:abstractNum>'
    '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
    '<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>'
    "</w:numbering>" % W
)


def write_docx(case, body):
    document = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="%s"><w:body>%s'
        '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>' % (W, "".join(body))
    )
    content_types = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        '<Default Extension="xml" ContentType="application/xml"/>'
        '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
        "</Types>"
    )
    rels = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        "</Relationships>"
    )
    doc_rels = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
        "</Relationships>"
    )

    path = os.path.join(HERE, case, "source.docx")
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        for name, data in (
            ("[Content_Types].xml", content_types),
            ("_rels/.rels", rels),
            ("word/document.xml", document),
            ("word/styles.xml", STYLES),
            ("word/numbering.xml", NUMBERING),
            ("word/_rels/document.xml.rels", doc_rels),
        ):
            # Fixed date: the same input gives the same bytes.
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, data.encode("utf-8"))


# ---------------------------------------------------------------------------
# docx-title-style: Word's "Title" style, which phpword writes as <h0>, a tag
# that does not exist. It must become a heading above the "Heading 1" ones.
# ---------------------------------------------------------------------------


def docx_title():
    write_docx(
        "docx-title-style",
        [
            para("Relatório da oficina de leitura", "Title"),
            para("A oficina reuniu quarenta pessoas ao longo de três sábados."),
            para("Resultados", "Heading1"),
            para("Cada participante leu pelo menos um livro inteiro em voz alta."),
        ],
    )


def docx_tables():
    body = []

    body.append(para("Tabela com cabeçalho", "Heading1"))
    body.append(
        table(
            [
                row([cell("Livro"), cell("Autor"), cell("Ano")], "on"),
                row([cell("Dom Casmurro"), cell("Machado de Assis", '<w:shd w:val="clear" w:color="auto" w:fill="auto"/>'), cell("1899")]),
                row([cell("Iracema"), cell("José de Alencar"), cell("1865")]),
            ],
            3,
        )
    )

    body.append(para("Tabela exportada do Google Docs", "Heading1"))
    body.append(
        table(
            [
                row([cell("Bairro"), cell("Empréstimos")], "off"),
                row([cell("Centro"), cell("340")], "off"),
                row([cell("Várzea"), cell("215")], "off"),
            ],
            2,
        )
    )

    body.append(para("Tabela com células mescladas", "Heading1"))
    body.append(
        table(
            [
                row([cell("Região"), cell("Visitas por ano", '<w:gridSpan w:val="2"/>')], "on"),
                row([cell("Norte", '<w:vMerge w:val="restart"/>'), cell("2025"), cell("120")]),
                row([cell("", "<w:vMerge/>"), cell("2026"), cell("150")]),
            ],
            3,
        )
    )

    body.append(para("Listas do Word", "Heading1"))
    body.append(para("Primeiro passo numerado", num=1))
    body.append(para("Segundo passo numerado", num=1))
    body.append(para("Item com marcador", num=2))
    body.append(para("Outro item com marcador", num=2))
    body.append(para("Fim do documento."))

    write_docx("docx-table-headers", body)


# ---------------------------------------------------------------------------
# pdf-literal-html-tags / docx-literal-html-tags: an HTML tutorial. Tags
# written in the text ("<html>", "<script>", "</p>") are words to read, not
# markup: they must come out escaped, as visible text, without opening
# elements or swallowing the text around them.
# ---------------------------------------------------------------------------

HTML_TUTORIAL = [
    ("h", "Estrutura básica de uma página"),
    ("p", "Todo documento começa com <!DOCTYPE html> e a tag <html>, que envolve o resto."),
    ("p", "Dentro dela ficam <head>, com o <title> e as tags <meta>, e <body>, com o conteúdo."),
    ("p", "Os títulos vão de <h1> a <h6>, e cada parágrafo fica entre <p> e </p>."),
    ("p", "Um script entra assim: <script>alert('oi')</script> e não deve rodar."),
    ("p", "Comparações também usam os sinais: 3 < 5 e 7 > 2, e a seta -> aparece em código."),
    ("p", "Um comentário se escreve <!-- assim --> e o texto depois dele continua."),
    ("p", "Fim do tutorial."),
]


def literal_tags_pdf():
    pdf = Pdf()
    pdf.page()
    y = 790
    for kind, text in HTML_TUTORIAL:
        size = 14 if kind == "h" else 10
        for ln in wrap(text, 90):
            pdf.text(50, y, ln, size)
            y -= 16
        y -= 10
    save("pdf-literal-html-tags", "source.pdf", pdf.build("Tutorial de HTML"))


# ---------------------------------------------------------------------------
# pdf-no-text-layer: a web tutorial printed with "Microsoft Print to PDF"
# (like teste-3.pdf, a real page that cannot go in the repository). The
# letters are outlines and there is no text to extract; a few lines became
# wide images, and carousel arrows and bits of colored code ("</") became
# dozens of small images, each with its own bytes. Expected: no words (the
# upload is refused with the OCR message) and, of the images, only the
# strips and the broken-image icon.
# ---------------------------------------------------------------------------

DOT_FONT = {
    " ": ["....."] * 7,
    "A": [".###.", "#...#", "#...#", "#####", "#...#", "#...#", "#...#"],
    "C": [".###.", "#...#", "#....", "#....", "#....", "#...#", ".###."],
    "D": ["####.", "#...#", "#...#", "#...#", "#...#", "#...#", "####."],
    "E": ["#####", "#....", "#....", "####.", "#....", "#....", "#####"],
    "G": [".###.", "#...#", "#....", "#.###", "#...#", "#...#", ".###."],
    "H": ["#...#", "#...#", "#...#", "#####", "#...#", "#...#", "#...#"],
    "I": [".###.", "..#..", "..#..", "..#..", "..#..", "..#..", ".###."],
    "L": ["#....", "#....", "#....", "#....", "#....", "#....", "#####"],
    "M": ["#...#", "##.##", "#.#.#", "#.#.#", "#...#", "#...#", "#...#"],
    "N": ["#...#", "##..#", "#.#.#", "#..##", "#...#", "#...#", "#...#"],
    "O": [".###.", "#...#", "#...#", "#...#", "#...#", "#...#", ".###."],
    "P": ["####.", "#...#", "#...#", "####.", "#....", "#....", "#...."],
    "R": ["####.", "#...#", "#...#", "####.", "#.#..", "#..#.", "#...#"],
    "S": [".####", "#....", "#....", ".###.", "....#", "....#", "####."],
    "T": ["#####", "..#..", "..#..", "..#..", "..#..", "..#..", "..#.."],
    "U": ["#...#", "#...#", "#...#", "#...#", "#...#", "#...#", ".###."],
}


def pixels(width, height, paint, background=(255, 255, 255)):
    """RGB bytes; paint(x, y) gives a color or None for the background."""
    out = bytearray()
    for y in range(height):
        for x in range(width):
            out += bytes(paint(x, y) or background)
    return bytes(out)


def strip(seed):
    """A line of text turned into an image: dark word-shaped bars on light gray."""
    words = [(14 + 37 * k + (seed * 11 + k * 7) % 9, 20 + (seed * 5 + k * 13) % 14) for k in range(15)]

    def paint(x, y):
        if 14 <= y < 28 and any(start <= x < start + length for start, length in words):
            return (38, 50, 56)
        return (245, 245, 245)

    return pixels(600, 40, paint)


def chevron(variant):
    """A carousel arrow; the variant changes one corner pixel, as each new JPEG encoding does."""
    def paint(x, y):
        if (x, y) == (0, 0):
            return (250 - variant, 250, 250)
        if abs(x - 8 - abs(y - 28) * 0.8) < 3:
            return (150, 120, 130)
        return None

    return pixels(39, 56, paint)


def code_piece(width, variant):
    """The "</" of a colored code sample, sliced as an image."""
    def paint(x, y):
        if (x, y) == (0, 0):
            return (250 - variant, 250, 250)
        if x < 50 and abs(x - 8 - abs(y - 40) * 0.9) < 3:
            return (150, 120, 130)
        if x >= 55 and abs((x - 55) - (80 - y) * 0.45) < 3:
            return (150, 120, 130)
        return None

    return pixels(width, 81, paint)


def broken_icon():
    """The browser's broken-image icon: a frame, sky and a green hill."""
    def paint(x, y):
        if x < 2 or y < 2 or x > 97 or y > 97:
            return (120, 120, 120)
        if (x - 50) ** 2 + (y - 120) ** 2 < 45 ** 2:
            return (90, 170, 60)
        return (200, 220, 240)

    return pixels(101, 100, paint)


def no_text_layer_pdf():
    pdf = Pdf()
    pdf.page()
    pdf.draw(50, 780, "TUTORIAL DE HTML", 18)
    pdf.draw(50, 740, "ESTRUTURA DA PAGINA", 10)
    pdf.draw(50, 720, "TAGS DE TITULO E PARAGRAFO", 10)
    pdf.image(50, 680, 495, 33, 600, 40, strip(1))
    pdf.page()
    pdf.draw(50, 780, "CODIGO DE EXEMPLO", 12)
    pdf.image(50, 680, 75, 74, 101, 100, broken_icon())
    for k in range(6):
        pdf.image(140 + 30 * k, 700, 20, 28, 39, 56, chevron(k))
    pdf.image(50, 600, 48, 40, 96, 81, code_piece(96, 1))
    pdf.image(110, 600, 48, 40, 97, 81, code_piece(97, 2))
    pdf.image(170, 600, 48, 40, 97, 81, code_piece(97, 3))
    pdf.image(50, 540, 495, 33, 600, 40, strip(2))
    pdf.draw(50, 500, "PARTE DOIS", 10)
    save("pdf-no-text-layer", "source.pdf", pdf.build("Tutorial impresso"))


def docx_literal_tags():
    write_docx(
        "docx-literal-html-tags",
        [para(text, "Heading1" if kind == "h" else None) for kind, text in HTML_TUTORIAL],
    )


single_column_pdf()
two_columns_pdf()
glyph_columns_pdf("pdf-two-columns-glyphs", True)
glyph_columns_pdf("pdf-two-columns-stream-order", False)
glyph_single_column_pdf()
lists_pdf()
verses_pdf()
table_rows_pdf()
interleaved_pdf()
hyphens_pdf()
lists_levels_pdf()
tables_pdf()
running_header_pdf()
docx_tables()
docx_title()
literal_tags_pdf()
docx_literal_tags()
no_text_layer_pdf()
print("Corpus written to", HERE)
