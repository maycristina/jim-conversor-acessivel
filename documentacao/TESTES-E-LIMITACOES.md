# Testes de fluxos, formatos não aceitos e limitações técnicas

Registro dos testes feitos na v2.0 e do que eles revelaram. Ambiente: WordPress 7.1.3,
PHP 8.3, WordPress Playground (PHP em WebAssembly) no Windows.

> **Escopo da validação:** tudo abaixo foi observado no Playground. **Nada foi testado
> em servidor real (Hostinger) nem com PHP nativo.** Tempos e consumo de memória no
> Playground não representam um servidor de produção.

## 1. Formatos

| Formato | Resultado | Mensagem / observação |
|---|---|---|
| PDF com texto | Aceito | Converte texto, sumário, títulos e imagens (ver limites abaixo) |
| PDF só imagem (escaneado) | **Recusado** | "Este PDF não tem texto selecionável… O plugin não faz OCR." (antes dava "sucesso" com documento vazio) |
| PDF corrompido / sem estrutura | Recusado | "Não foi possível ler o PDF. Causas comuns: corrompido, protegido por senha ou fora do padrão." + detalhe técnico |
| PDF protegido por senha | Não testado | A biblioteca informa "Secured pdf file are currently not supported" |
| DOCX | Aceito | Títulos, parágrafos, tabelas, imagens com texto alternativo |
| DOCX sem texto | Recusado | "O documento não tem nenhum texto para converter." |
| DOCX falso (renomeado, não é zip) | Recusado | "O conteúdo de X não corresponde à extensão .docx…" |
| TXT (UTF-8 e Latin-1) | Aceito | Latin-1 é convertido para UTF-8 sem perder acentos |
| MD (Markdown) | Aceito | Também `.markdown` e extensão em maiúsculas (`.MD`) |
| `.doc` (Word antigo) | **Recusado** | Explica como salvar como `.docx` |
| `.odt`, `.rtf` | **Recusado** | Explica como salvar como `.docx` |
| `.xlsx`, `.xls`, `.pptx`, `.ppt`, `.epub`, `.html` | **Recusado** | Mensagem específica sugerindo PDF/DOCX/TXT/MD |
| Imagens soltas (`.png`, `.jpg`…) | **Recusado** | "O plugin converte texto. Para PDF escaneado, rode um OCR antes." |
| `.php` e outras extensões | Recusado | "Formatos aceitos: PDF, DOCX, TXT e MD" |
| Arquivo vazio (0 bytes) | Recusado | "O arquivo X está vazio (0 bytes)." (antes: mensagem em inglês do WordPress ou "tipo não permitido") |
| Arquivo acima do limite configurado | Recusado | "Arquivo maior que o limite de N MB." |
| Dupla extensão (`x.php.txt`) | Aceito como TXT | O WordPress renomeia; o conteúdo é só texto; a pasta tem `.htaccess` que bloqueia scripts |

### Formatos que o plugin NÃO suporta (por decisão ou limitação)
`.doc`, `.odt`, `.rtf`, planilhas, apresentações, EPUB, HTML, imagens soltas, PDF
escaneado (sem OCR), PDF protegido por senha, legendas/fórmulas como objeto especial do Word.

## 2. Envio em lote

| Cenário | Resultado |
|---|---|
| 3 arquivos TXT/MD | 3 documentos + lista de shortcodes |
| 20 arquivos TXT | 20 documentos + lista |
| 21 arquivos | **Recusado**, com explicação: o PHP descarta em silêncio o que passa de `max_file_uploads` (20). O formulário informa quantos arquivos escolheu e o servidor compara |
| TXT + PDF no mesmo envio | Recusado: "PDF e DOCX precisam ser enviados sozinhos" |
| TXT + `.doc` | Recusado, mesma explicação |
| Um arquivo do lote falha | Os outros convertem; o aviso lista o que falhou e por quê (fluxo implementado; testado com lotes válidos) |

## 3. Limites e configurações

| Cenário | Resultado |
|---|---|
| Limite de 1 MB, arquivo de 2 MB | Recusado ("maior que o limite de 1 MB") |
| Tipo MD desativado em Configurações | Recusado: "o tipo .md está desativado…" |
| Imagens desligadas | DOCX converte sem imagens e sem relatório |
| Máximo de 2 imagens, DOCX com 3 | 2 incluídas, 1 recusada: "limite de imagens atingido" |
| Máximo de 40 imagens, DOCX com 60 | 40 incluídas, 20 recusadas, com a causa |
| Tamanho total 3 MB, 12 fotos de ~0,6 MB | 5 incluídas, 7 recusadas: "limite de tamanho total" |

## 4. Imagens de PDF: causas de recusa vistas nos arquivos reais

| Arquivo | Resultado |
|---|---|
| "Test Driven Development…" (2 MB) | 14 imagens recusadas: **JPEG 2000** (não suportado) |
| "Banco de Dados.e-book.pdf" (5,7 MB, gerado pela Microsoft) | **Antes da correção: 0 imagens.** 35 JPEG 2000 + 36 "paleta" ilegível. **Defeito corrigido:** nesse tipo de PDF a tabela de cores da imagem fica num objeto separado (referência indireta) e o plugin só lia quando estava dentro do dicionário da imagem. **Depois: 36 imagens incluídas** (tabelas, diagramas ER, com texto legível); sobram 35 em JPEG 2000 |
| PDF 1-bit (fax, CCITT) | Recusadas com a causa "Fax CCITT" |

## 5. Exibição

| Cenário | Resultado |
|---|---|
| Shortcode com `id` inexistente / vazio / não numérico | Administrador vê "documento não encontrado"; visitante não vê nada |
| Documento na lixeira | Igual: some para o visitante |
| Três shortcodes na mesma página (um repetido) | Três visualizadores com `id` únicos |
| Modo post / modo leitor pela Edição rápida | Funciona; `modo=` no shortcode é ignorado |
| SEO | Meta description, Open Graph, Twitter Card e JSON-LD gerados; `<script>` do arquivo sai escapado (`<`) |
| Marcador (marca-texto) | Marcar, trocar de cor, remover e persistir ao recarregar: OK |
| Lightbox | Abrir, navegar, contador: OK. `Esc`/foco: **não verificado** (aba oculta no Chrome de teste) |

## 6. Segurança

| Cenário | Resultado |
|---|---|
| Envio sem login | Redireciona; nenhum documento criado |
| Envio logado sem nonce | Recusado pelo WordPress |
| MD com `<script>`, `<img onerror>`, `<iframe>`, `javascript:`/`data:`/`vbscript:` | Tudo vira texto; nenhum link perigoso |
| MD com byte nulo (binário renomeado) | Recusado |
| Chave de IA | Formato inválido recusado; nunca aparece no HTML; em branco mantém a salva; "remover" apaga |

## 7. Ciclo de vida

| Cenário | Resultado |
|---|---|
| Ativar | OK |
| Desativar | Não apaga nada (documentos e mídia permanecem) |
| Excluir o plugin (desinstalar) | Plugin removido; **as imagens da Biblioteca foram apagadas**; o tipo de documento deixa de existir. A remoção das opções (`jimca_settings`, `jimca_ai`) está no `uninstall.php` mas **não foi observada diretamente** |

## 8. Plugin Check (WordPress.org)

Ferramenta oficial de conferência de plugins (plugin-check 2.1.0), rodada contra o plugin
nas cinco categorias (geral, repositório, segurança, desempenho, acessibilidade).

| Rodada | Erros | Avisos |
|---|---|---|
| 1ª (antes dos ajustes) | 3 | 4 |
| Final | **0** | **2** |

Corrigido: saída de exceção sem escape (2), `rmdir()` direto (1: trocado por
`WP_Filesystem`), leitura de `$_FILES`/`$_POST` fora do trecho com nonce.

**Avisos que restam (2):**

1. `InputNotSanitized` em `admin/class-admin.php` (leitura de `$_FILES['jimca_file']`): o
   array é lido logo depois da verificação do nonce e cada campo é tratado em seguida
   (nome com `sanitize_file_name()`, tamanho convertido para inteiro, caminho temporário
   vindo do PHP e validado por `wp_handle_upload()`). Não há como "sanitizar" o array
   inteiro de uma vez sem quebrá-lo; o aviso fica.
2. **`set_time_limit()`** é "desencorajada" pelo Plugin Check;
o plugin a chama (protegida por `function_exists`) para dar até 300 s à conversão de PDFs
grandes. Sem isso, o limite padrão de 30 s do PHP derruba conversões longas.

**Cinco verificações não rodaram no ambiente de teste** (`enqueued_scripts_size`,
`enqueued_styles_size`, `enqueued_styles_scope`, `enqueued_scripts_scope`,
`non_blocking_scripts`): elas carregam páginas do site e o Playground responde `0`.
Conferência manual: os scripts e estilos do plugin só são carregados nas páginas com o
shortcode, ficam no rodapé e somam cerca de 70 KB (JS: `frontend.js` 25 KB, `lightbox.js` 5 KB, `marker.js` 7 KB,
`post.js` 5 KB; CSS: `frontend.css` 25 KB). **Rodar o Plugin Check num WordPress normal antes de publicar.**

Para rodar o Plugin Check no Playground foi preciso contornar duas limitações do
próprio ambiente (não do plugin): (1) o sistema de arquivos do Playground não suporta
travas de escrita usadas pelo PHPCS; removida a trava, só na cópia descartável do
Plugin Check; (2) o Plugin Check apaga o `object-cache.php` ao terminar e isso quebra a
instância; o servidor de teste precisa ser reiniciado a cada rodada.

## 9. Limitações técnicas encontradas

1. **JPEG 2000 não é decodificado.** Comum em PDFs de editoras e do Microsoft Word/Office.
   Sem OCR nem biblioteca de JPEG 2000 em PHP puro, as imagens ficam de fora (o aviso diz
   quantas). Suporte só seria possível onde o servidor tiver a extensão Imagick.
2. **Sem OCR.** PDF escaneado não vira texto.
3. **PDF de livro leva minutos.** No Playground: 55–125 s para PDFs de 2–6 MB. Em servidor
   real deve ser mais rápido, mas **não foi medido**. A conversão é uma única requisição.
4. **`max_file_uploads` do PHP (padrão 20)** limita o lote; arquivos acima disso são
   descartados em silêncio pelo PHP.
5. **Listas do Word** feitas só com estilo ("Lista com marcadores") saem como parágrafos
   simples; o estilo "Título" (não "Título 1") não vira cabeçalho; tabelas saem sem bordas
   nem cabeçalho (`<td>` em vez de `<th>`). Observado com um DOCX gerado por script
   (python-docx); **falta testar com DOCX criados no Word**.
6. **Marcações do marca-texto ficam só no navegador** de cada leitor (localStorage). Trocar
   de navegador ou limpar os dados as perde. Se o documento for editado, as marcações que
   não batem mais com o texto são descartadas.
7. **Cores do marca-texto** não são preservadas no modo de alto contraste (uma só cor,
   com sublinhado).
8. **SEO só no `<head>` da página com o shortcode**; o documento em si não tem URL.
   Desliga sozinho se Yoast, Rank Math, All in One SEO ou SEOPress estiverem ativos.
9. **Bot de IA:** só a chave é guardada (criptografada, libsodium + salts do
   `wp-config.php`). Quem tem acesso ao servidor inteiro (arquivos + banco) ainda consegue
   usar a chave; se os salts mudarem, é preciso digitá-la de novo. O assistente em si não
   existe ainda.
10. **Hospedagem compartilhada** que encerra processos longos pode derrubar o site durante
    uma conversão grande, mesmo com os limites. Os limites de imagens, tempo e memória
    reduzem o risco, mas não o eliminam.
11. **Playground não persiste dados** e tem filesystem sem travas; por isso alguns testes
    (desinstalação completa, medição de desempenho) ficam para um WordPress real.
