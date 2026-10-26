# Corpus de conversão

Cada pasta aqui é um arquivo que passa pelo conversor do Jim (`JIMCA_Converter::convert()`, o mesmo
caminho do plugin) e é conferido de duas formas. O objetivo é descobrir, antes de publicar, se uma
mudança no conversor quebrou algo que já funcionava, e permitir que qualquer pessoa repita os testes.

> **In English:** each folder is a file run through the converter. `expected.json` says what must hold
> (written by hand); `expected-reader.txt` records what the read-aloud player speaks today (written by
> `composer corpus:update`). Run `composer corpus`; results go to `build/corpus/<case>/`.

## O que tem em cada pasta

| Arquivo | Quem escreve | Para quê |
|---|---|---|
| `source.pdf` ou `source.docx` | `make-corpus.py` (ou você, com um arquivo real) | O arquivo de entrada. |
| `expected.json` | Você, à mão | O que é **certo**: frases na ordem, textos que não podem aparecer, quantos `<li>`, `<th>`... |
| `expected-reader.txt` | O teste (`composer corpus:update`) | O que o leitor em voz alta fala **hoje**, um elemento por linha (`p: ...`, `h2: ...`, `li: ...`). |

Cada execução grava em `build/corpus/<caso>/`:

- `actual.html`: o HTML que o conversor gerou (resultado);
- `actual-reader.txt`: o que o leitor falaria (leitor obtido), no mesmo formato de `expected-reader.txt`.

O "leitor" usa o mesmo seletor de `collectElements()` em `assets/js/frontend.js`:
`h1–h6, p, li, blockquote, figcaption` e imagens com `alt`. O que estiver fora disso (texto solto,
célula de tabela sem `<p>`) **não é lido em voz alta**, e o teste mostra isso.

## Rodar

```bash
composer corpus          # só o corpus
composer test            # testes unitários + corpus
```

Quando falha, a mensagem diz o que quebrou: uma regra do `expected.json` ("missing (or broken apart)",
"out of order", "//ol/li: expected 5, found 0") ou uma diferença no texto lido (`- esperado`, `+ obtido`).

## Mudei o conversor de propósito

1. Rode `composer corpus` e leia as diferenças.
2. Se a mudança é a esperada, rode `composer corpus:update`: ele regrava `expected-reader.txt` **só
   dos casos cujas regras passam**.
3. Confira o diff de `expected-reader.txt` no git e faça o commit junto com o código. No pull request,
   explique por que o texto lido mudou.

## Adicionar um caso

1. Crie a pasta `tests/corpus/<formato>-<o-que-testa>/` (por exemplo `pdf-hifenizacao`).
2. Coloque o arquivo como `source.pdf` ou `source.docx`. Use um arquivo seu, de domínio público ou
   gerado por `make-corpus.py`. **Nunca um arquivo com dados pessoais.**
3. Escreva o `expected.json`:

   ```json
   {
       "description": "O que o caso testa e por quê.",
       "options": { "page_markers": false },
       "in_order": ["primeira frase que deve ser lida", "a seguinte, depois dela"],
       "absent": ["texto que não pode ser lido"],
       "absent_patterns": ["/^\\d{1,3}$/m"],
       "xpath": { "//ul/li": 3, "//th[not(@scope='col')]": 0 }
   }
   ```

   - `options`: as opções do conversor (`page_markers`: escrever "Página 1, Página 2…"; `columns`:
     detectar colunas). Padrão: as do plugin.
   - `in_order`: trechos que precisam aparecer **inteiros e nessa ordem** no texto lido. É a melhor
     forma de provar que colunas não se misturaram e que uma frase não foi cortada.
   - `absent` e `absent_patterns`: texto ou regex que não pode aparecer no texto lido.
   - `xpath`: quantos elementos cada expressão encontra no HTML.
   - `words`: quantas palavras o documento tem na contagem do plugin (sem os marcadores "Página N").
     `0` quer dizer que o envio é recusado como PDF sem texto.
   - `images` (só PDF): as imagens que entrariam no documento, como `"LxA"` em pixels e na ordem.
     Prova que fragmentos e decoração ficam de fora e que imagens de verdade ficam.
   - `pending`: para uma **lacuna conhecida**. O caso documenta o objetivo e aparece como "incompleto"
     sem reprovar o CI. Quando passar a funcionar, o teste avisa para tirar o `pending`.

   Em todos os casos o teste também reprova o caractere de substituição (U+FFFD) e os caracteres de uso
   privado (U+E000–U+F8FF, os marcadores das fontes Symbol/Wingdings).

4. Rode `composer corpus:update`, confira o `expected-reader.txt` gerado e faça o commit.

## Casos atuais

| Caso | O que garante |
|---|---|
| `pdf-single-column-outline` | Linha de base: marcadores (outline) viram títulos, sumário impresso vira lista, número de página some. |
| `pdf-two-columns` | Duas colunas gravadas linha a linha: lidas coluna por coluna, título e resumo antes, frase entre páginas reunida. |
| `pdf-two-columns-glyphs` | O mesmo, com uma letra por objeto de texto (como exportadores baseados em Skia): sem espaço dentro das palavras. |
| `pdf-two-columns-stream-order` | Duas colunas já gravadas coluna por coluna (Google Docs): a detecção não pode mexer. |
| `pdf-single-column-glyphs` | Uma coluna, letra a letra: nada de colunas falsas nem de palavras partidas. |
| `pdf-two-columns-interleaved` | Caso 02 da sugestão externa, com 8 linhas por coluna: coluna A inteira antes da B, sem frases coladas. |
| `pdf-hyphens-ligatures` | Palavra cortada no fim da linha ("trans-" + "lineadas") ou já na mesma linha ("com- plexidade") é juntada; ficam o hífen de pronome (encontrá-lo, chama-se), de prefixo (pós-, recém-, guarda-; auto-/anti- só antes de h ou da mesma vogal; "ex-" não, para "explícitas" sair certo) e o hífen suspenso ("pré- e pós-operatório"); ligaduras ﬁ ﬂ ﬀ ﬃ ﬄ viram letras; frase cortada por hífen passa da página 1 para a 2. |
| `pdf-lists-levels` | Caso 04 da sugestão: marcadores •, ◦, ▪ numa lista plana; item quebrado em duas linhas; travessão continua parágrafo; "a)" sozinho depois de lista numerada vira item. |
| `pdf-tables` | *Pendente (roadmap):* tabelas em PDF ainda viram um parágrafo; o objetivo é `<table>` com cabeçalhos. |
| `pdf-lists` | Marcadores (•, e o de uso privado do Word), lista numerada com subitens a), b); título numerado sozinho e falas com travessão continuam parágrafos. |
| `pdf-verses` | Versículos numerados ("1 No começo") não viram lista. |
| `pdf-table-rows` | Tabela de texto solto é lida linha por linha. |
| `pdf-running-header` | *Pendente:* o cabeçalho repetido em cada página ainda é lido e corta a frase. |
| `docx-table-headers` | Linha de cabeçalho vira `<th scope="col">`, a tabela do Google Docs (`tblHeader w:val="0"`) não, células mescladas, sem cor "auto". |
| `pdf-literal-html-tags` | Tutorial de HTML: `<html>`, `<head>`, `<script>…</script>`, `</p>`, comentário e `3 < 5` no texto são lidos como texto, escapados, sem criar elementos nem engolir o texto em volta. |
| `pdf-no-text-layer` | Página da web impressa com "Microsoft Print to PDF": letras desenhadas como contornos, sem texto. Dá 0 palavras (o envio é recusado com a orientação de OCR) e, das 12 imagens, ficam só as faixas largas e o ícone; setas e pedaços de "</" repetidos saem. |
| `docx-literal-html-tags` | O mesmo tutorial de HTML em DOCX. |
| `docx-title-style` | O estilo Título do Word (que o phpword grava como `<h0>`) vira `<h1>`, com os "Título 1" um nível abaixo. |

## Regerar os arquivos sintéticos

```bash
python3 tests/corpus/make-corpus.py
```

Os PDFs e DOCX são escritos objeto por objeto, sem biblioteca: o mesmo script gera sempre os mesmos
bytes. Depois de regerar, rode `composer corpus` e confira as diferenças.
