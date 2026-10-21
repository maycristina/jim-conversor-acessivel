# SEO, nota de qualidade e ferramentas de teste

Como o Jim prepara a página publicada para os buscadores, como a nota de 0 a 100 é calculada e como conferir tudo sem
ferramentas web com limite de uso.

## O que o Jim escreve na página

Na página (ou post) que tem o shortcode, no `<head>` (`JIMCA_SEO`):

| Dado | Como é montado |
|---|---|
| `<title>` | O do tema; até ~75 caracteres. Título longo perde o nome do site e, se ainda passar, é cortado em palavra inteira. O título completo continua na página. |
| Meta description / `og:description` | Resumo do documento (campo "Resumo" da caixa de SEO ou assunto do arquivo, com 40 caracteres ou mais) ou o primeiro parágrafo de **prosa**. Se não tiver nenhuma palavra do título, começa por ele. Máximo de 155 caracteres. |
| Open Graph e Twitter Card | Título, URL, tipo, idioma (`og:locale` segue o idioma do documento), datas e `og:updated_time`; imagem: a primeira imagem do documento com tamanho útil ou o ícone do site. |
| JSON-LD `Article` | Autor (`Person`), editor, datas, palavras, `timeRequired`, palavras-chave, `inLanguage` e as propriedades de acessibilidade do schema.org (`accessMode`, `accessModeSufficient`, `accessibilityFeature`, `accessibilityControl`). Só declara o que a página tem: sumário só com 2 ou mais títulos, `alternativeText` só se nenhuma imagem estiver sem descrição, controles só no modo leitor. |

Com Yoast SEO, Rank Math, All in One SEO ou SEOPress ativo o Jim **não escreve tags próprias**: entrega a descrição (quando o
plugin não tem uma) e as propriedades de acessibilidade pelos filtros deles (`wpseo_metadesc`, `wpseo_schema_webpage`,
`rank_math/frontend/description`, `rank_math/json_ld`, `aioseo_description`, `seopress_titles_desc`).

### Como a descrição é escolhida (sem lista de palavras)

`JIMCA_SEO::prose()` aceita um parágrafo como prosa quando: não começa com faixa de símbolos (`===`, `***`); não é uma ficha de
linhas curtas "Chave: valor"; não tem endereço (`http`, `www`, e-mail, domínio); tem pelo menos 8 palavras e a maior parte
é letra; e, se tem menos de 20 palavras, termina em pontuação de frase. Divisores repetidos são removidos do resultado.

### De onde vêm o autor, o idioma e o resumo

1. O que a pessoa digita no formulário de importação ("Autor padrão do lote", "Idioma do documento") ou na caixa de SEO do documento.
2. As propriedades do arquivo (PDF: Author, Subject, Keywords; DOCX: `docProps/core.xml`). Autores genéricos ("Microsoft Word", "admin") são ignorados.
3. O texto, só para o autor, e só quando declara explicitamente ("Autor:", "Author:", "Escrito por"…).
4. O idioma do site.

## Nota de SEO e acessibilidade (0 a 100)

`JIMCA_Scorecard`: 11 verificações fixas, calculadas no servidor a partir do documento, sem serviço externo.

| Grupo | Verificação | Pontos |
|---|---|---|
| Metadados | Título claro (10 a 70 caracteres) | 5 |
| | Resumo para os resultados de busca (70 caracteres ou mais) | 10 |
| | Autor conhecido | 10 |
| Estrutura | 2 ou mais títulos | 10 |
| | Níveis de título sem pular (0 saltos = 15; até 2 = 8) | 15 |
| Conteúdo | Texto suficiente (300 palavras ou mais) | 10 |
| | Texto legível (não saiu como símbolos) | 5 |
| | Imagens com descrição | 10 |
| Publicação | Exibido em página publicada | 15 |
| | Página pode ser indexada (sem `noindex` do site, do Yoast ou do Rank Math) | 5 |
| | Tags de SEO ligadas (ou plugin de SEO ativo) | 5 |

Documento com menos de 300 palavras fica limitado a 70 pontos. 85 ou mais é "Bom"; de 60 a 84, "Precisa de atenção"; abaixo, "Ruim".

### Corrigir na própria tela (caixa "Nota de SEO e acessibilidade")

Cada botão salva por AJAX (`jimca_seo_save`, com nonce e `edit_post`), recalcula a nota e redesenha a caixa.

- **Autor:** campo, "Usar meu nome" (nome de exibição de quem está logado) e "Salvar autor". Também salva pelo "Atualizar" da página.
- **Título e resumo:** campo com contador (10 a 70 e 70 a 155 caracteres). O título salvo também vai para o campo de título do editor, para o "Atualizar" não trazer o antigo de volta. O resumo fica em `_jimca_meta[subject]`.
- **Detectar a partir do texto:** sugere autor, título e resumo do início do texto (1.500 caracteres); não salva nada.
- **Corrigir títulos automaticamente:** `JIMCA_Autofill::fix_headings()` transforma em `<h2>`/`<h3>` as linhas numeradas ("1. Introdução", "2.1 Método"), "Capítulo N", em maiúsculas e as linhas curtas sem ponto que abrem um parágrafo longo. Desiste quando mais de 40% dos parágrafos parecem títulos (verso, lista). O texto anterior fica nas revisões.

### Onde cada documento é usado

`JIMCA_Usage` guarda em `_jimca_usado_em` (no documento) e `_jimca_documentos_usados` (na página) as páginas que mostram o documento. É
atualizado em `save_post`, ao enviar para a lixeira, ao apagar, ao salvar pelo Elementor e quando o shortcode é exibido. Um documento
antigo, sem referências, faz uma única busca no banco (LIKE e conferência exata do ID em PHP, inclusive em `_elementor_data`) no primeiro acesso.

## Um só H1 por página

O tema já mostra o título da página em `<h1>`. Quando o documento tem `<h1>` próprio (Título 1 do Word, `#` do Markdown), todos os
títulos descem um nível na exibição (o conteúdo guardado não muda). Quando o título do documento é igual ao da página, ele aparece
como parágrafo, com o mesmo visual, para não haver dois títulos iguais.

## Scripts do front-end

Só o que a página usa, todos com `defer`: barra e busca (`frontend.js`, `pager.js`) no modo leitor; `post.js` no modo post; o
visualizador de imagens só se o documento tiver imagens; `marker.js` nos dois. O pacote traz CSS e JS minificados (`.min`),
gerados por `bin/minify-assets.sh`; com `SCRIPT_DEBUG` ligado o plugin usa os arquivos legíveis.

## Conferir sem limite de uso

```bash
# Passa um arquivo pelo conversor do Jim e publica numa página (WP-CLI):
wp jimca convert tests/fixtures/moulin.txt --mode=post --language=fr --author="Nome" --slug=moulin

# Audita a página (ou várias, ou um .html salvo): ~45 regras, nota de 0 a 100.
node bin/seo-audit.js http://localhost:8888/moulin/ --strict
```

`bin/seo-audit.js` confere título (até 75), meta description (70 a 160, sem divisores, com palavra do título), canonical, `lang`,
`noindex`, um só H1, títulos sem pular nem repetir o H1, Open Graph e Twitter Card, JSON-LD (autor real, `timeRequired`,
`accessibilityFeature`, `inLanguage` igual ao `lang` do texto), imagens com `alt`, links, scripts (adiados, minificados, só os
do modo) e, no modo post, ausência de busca e de paginação. `--strict` sai com código 1 quando algo falha; `--require=og:image,minified`
promove avisos a falhas. A CI (`.github/workflows/jim-seo-audit.yml`) converte os arquivos de `tests/fixtures/` num WordPress de teste
(wp-env) e roda a auditoria nas páginas geradas; o SEOmator roda depois, só como relatório.

O que a ferramenta **não** vê, e os verificadores web veem: `www` e sem `www`, `robots.txt`, sitemap, backlinks, velocidade do servidor.
