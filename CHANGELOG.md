# Changelog

## 2.2.0 (2026-10-13)

### Novo
- **Barra de leitura redesenhada:** a busca e a paginação saem do topo do documento e entram na barra flutuante, na ordem
  **menu (≡) · ajustes (⚙) · Aa · play/pause · velocidade · parar · paginação · ocultar**. O **menu** abre um popover à esquerda
  com **Buscar no documento** (a busca vira um painel sobre o texto) e **Sumário** (só se o documento tiver títulos). **Ajustes**
  reúne a **voz** (idioma, tipo de voz feminina/masculina quando o navegador revela e narrador) e o **tema** (Claro, Sépia, Escuro
  e Alto contraste, que agora é uma das opções). **Aa** abre `[ A- ] 100% [ A+ ]`. **Play/pause** é um botão só (o nome muda para
  Ouvir/Pausar/Continuar; sem `aria-pressed` por cima, como pede a WCAG para controle de mídia). **Velocidade** não abre menu:
  cada clique passa para o próximo valor (1× → 1,25× → 1,5× → 2× → 1×) e o valor aparece no botão. A **paginação** (`‹ 2/93 ›`)
  só existe em documento em partes; tocar no número abre a lista de partes. **Ocultar** continua como estava. Os popovers abrem
  com mola, uma bolha desliza atrás do botão aberto (referência: barras inferiores com efeito elástico/líquido) e tudo respeita
  `prefers-reduced-motion`. Sem JavaScript, ou no modo "post de blog", os links de partes e a busca continuam na página.
  Testado no navegador (Chrome) em WordPress real, em 1280, 390 e 320 px, e com 24 testes automáticos novos
  (`documentacao/testes/barra.test.js`).
- **Site e documentação (pasta docs):** a página inicial ganhou o protótipo do leitor na seção de recursos, cards com ícones animados
  (no lugar dos números) e novos recursos (compartilhar trechos, exportar, publicar e agendar), o texto do assistente de IA com a lista
  de provedores, o texto "É para quem" renovado, os limites do lote, uma ilustração animada do fluxo antes/depois do Jim e a gravação
  real do Jim em uso. Tudo respeita o botão "Reduzir movimento" (imagens animadas viram quadros parados). A documentação (pt-BR) tem
  uma seção nova, "Painéis, barra de leitura e limites", com GIFs e capturas das telas; as outras seis línguas receberam a página
  inicial atualizada e a correção do texto do lote (a seção nova da documentação ainda não foi traduzida). Imagens otimizadas (GIFs
  de gravações, WebP, vídeo MP4 de 0,9 MB para 74 s).
- **Erro do provedor sem a chave:** a mensagem de erro do provedor (ou do cURL) que vai para o log e para o aviso de "Testar conexão"
  agora tem a chave trocada por `••••` (como enviada, codificada em URL ou escapada em JSON) e é cortada em 300 caracteres, para o caso
  de um serviço "Outro" devolver a requisição no erro. O leitor já recebia só uma frase genérica. 6 testes de PHPUnit; conferido em
  WordPress real com um servidor que devolve o cabeçalho `Authorization`.
- **Segurança das páginas:** por padrão o plugin envia `Content-Security-Policy: frame-ancestors 'self'` e `X-Frame-Options: SAMEORIGIN`
  nas páginas que imprimem o shortcode e nas telas do Jim no painel, para outro site não poder emoldurá-las em um `<iframe>`
  (clickjacking). Só controla quem emoldura: não afeta `admin-ajax.php`, a busca, o tutor nem a chamada do servidor ao provedor de IA.
  Quem incorpora os documentos de propósito libera o endereço com o filtro `jimca_frame_ancestors` (ou retorna `''` para desligar).
  (Um `.htaccess` na pasta do plugin foi tentado e retirado: o WordPress.org não o aceita no pacote. A listagem de diretório continua
  barrada pelos `index.php` de cada pasta e os `.php` saem pelo guarda `ABSPATH`.) Passo 4 do tour das Configurações recomenda chave só para o
  site e limite de gasto no provedor. Novo `documentacao/SEGURANCA.md` (chave de IA, o que protege e o que não, cabeçalhos, listagem de diretório).
- **Textos rápidos do assistente editáveis:** em Configurações > JIM > Avançado > **Textos rápidos** (as sugestões do início do
  chat e do botão "=") o administrador escolhe quais aparecem (caixa "Mostrar" por linha), renomeia, muda o texto que cai no campo,
  marca "Enviar na hora" (para desafios), adiciona os próprios (até 12) ou desliga todos (some também o botão "="). "Restaurar os
  textos rápidos padrão ao salvar" volta aos cinco originais; a lista igual à padrão é guardada vazia, então os textos padrão
  continuam seguindo o idioma do site. Linhas sem nome ou sem texto são descartadas ao salvar.
  **Tutorial e tour:** o tutorial explica o recurso, a página Configurações ganhou o botão "Fazer o tour guiado" com um tour próprio
  de 7 passos (regras de arquivos, conexão do JIM, opções avançadas e textos rápidos; abre o bloco "Avançado" sozinho) e o último
  passo do tour de Novo documento (continua com 9 passos) cita o assistente e leva ao tour das Configurações. Traduções nos 7
  idiomas (sem revisão nativa). Testes: 6 de PHPUnit (limpeza, ocultar, remover, limite, restaurar) e 1 de jsdom (tour abre o
  `<details>`); conferido no navegador em WordPress real.
- **Barra de leitura refinada:** o play/pause fica sempre no centro da tela (a barra é uma grade de três colunas: controles
  antes do play, play, controles depois; as colunas laterais têm a mesma largura). Em telas de até 520 px, com paginação, a
  paginação desce para uma segunda linha, centralizada, e a primeira linha fica simétrica (3 controles · play · 3 controles).
  Velocidade e parar ficam mais juntos, a paginação encolheu (botões de 36 px de altura) e o painel de busca ganhou o mesmo
  desenho dos outros popovers (cantos de 28 px, entrada com mola, campo e botão em pílula, resultados sem moldura).
- **Envio à prova de firewall:** o navegador agora manda cada arquivo com um nome neutro (`jim-1-xxxx.txt`) e o nome real
  codificado em um campo à parte (base64url, `jimca_name`); o servidor decodifica, confere a extensão e usa o nome real no
  título e no registro. Firewalls de hospedagem (ModSecurity/LiteSpeed) que recusavam, com 403 antes do WordPress, nomes com
  palavras do alfabeto latino não têm mais o que ler. Vários arquivos vão **um por requisição, um depois do outro**
  (também PDF e DOCX), com progresso por arquivo e resumo no fim; um bloqueio ou erro afeta só aquele arquivo. Sem JavaScript,
  continua o envio comum. Um 403 sem JSON vira aviso na tela com o texto para mandar à hospedagem.
- **Chat do tutor:** visual novo (orbe em três tons de azul, campo em pílula); altura pelo viewport visível no celular.
- **JIM - Assistente em um bloco só:** os cartões "Assistente de IA (chaves de API)" e "Tutor de IA para leitores" viram um
  único formulário em três passos (1. Conectar: provedor e chave, com teste; 2. Escolher o modelo; 3. Ligar). Só aparecem os campos do
  provedor escolhido. Nome, personalidade, instruções, saudação, limites e respostas salvas ficam em "Avançado".
  As opções "Ligar o tutor neste site" e "Mostrar em todos os documentos" saíram: o JIM aparece em todos os documentos assim que a IA
  está ligada com uma chave salva, e `tutor="no"` no shortcode o esconde em uma página. Quem tinha o tutor desligado e a IA ligada passa
  a vê-lo; para tirá-lo, desligue o JIM.
- **Barra de seleção junto do texto:** ao selecionar um trecho, a barra aparece colada nele (acima no mouse, abaixo no toque, sem sair
  da tela) em vez de ficar no topo. Novas ações: **Compartilhar como imagem** (cartão PNG com o trecho, o título do livro e o endereço da
  página, feito no navegador; em celulares abre o compartilhamento do sistema) e **Baixar como texto** (.txt só com o trecho). Marcar
  continua, com as marcações guardadas no navegador.
- **Chat:** botão para **baixar a conversa** em .txt e dois botões de desafio ("Me desafie" e "Jogo de memória") que enviam na hora e nunca
  usam resposta salva, para cada leitor receber uma pergunta nova.
- **Textos rápidos no chat:** botão "=" no canto esquerdo do campo abre um menu com as mesmas sugestões do início (Explicar uma
  palavra, Resumir uma parte, Dar um exemplo, Me desafie, Jogo de memória), a qualquer momento da conversa. Setas, Esc e foco
  funcionam pelo teclado; o que a pessoa já digitou não se perde.
- **Limites da fila de importação, documentados e impostos:** até 20 arquivos por vez, tamanho por arquivo e **tamanho máximo de PDF
  calculado pela memória do servidor** aparecem no texto da tela; o que passa do limite é recusado pelo nome, sem ser enviado. O
  servidor também recusa um PDF que a memória não comporta, em vez de falhar no meio. O resumo da fila avisa quando o texto de um PDF
  ficou ilegível, e o JIM não aparece em documentos assim. Medições e regras em `documentacao/CAPACIDADE-E-LIMITES.md`.
- **Revisão da conversão mais à mão:** a caixa saiu do fim da página de edição e foi para a barra lateral, logo abaixo de Tags.
  A lista de documentos ganhou a coluna "Revisão da conversão" com o botão **Revisar**, que roda a revisão sem abrir o documento,
  mostra quantos problemas achou e, havendo algum, o link "Abrir para corrigir" (leva à caixa na edição).
- **Log da revisão:** toda revisão disparada entra no Registro de atividade (`review.run`) com o documento, o número de problemas e a
  **origem**: "document edit screen" (botão da página de edição) ou "documents list" (acesso rápido da lista). A revisão que roda
  sozinha depois de "Corrigir" não é registrada de novo; falha de rede avisa na tela e não registra.
- **Documentos longos em partes (como um leitor de e-book):** a partir de ~350 KB de HTML (cerca de 50 mil palavras) o documento é
  **sempre** cortado em partes de ~60 KB, em limites de bloco e de preferência antes de um título (nunca no meio de uma lista ou
  tabela). A página carrega só uma parte; o leitor troca de parte com o `‹ 2/93 ›` da barra, a lista de partes que ele abre ou
  o sumário, sem recarregar. Cada parte é buscada na hora (a seguinte é buscada antes) e só as últimas ficam na memória, então o DOM não
  cresce com o livro. A leitura em voz alta passa sozinha para a parte seguinte; sumário, marcações e imagens acompanham a parte.
  Sem JavaScript os mesmos links recarregam a página na parte (`?jimca_part_ID=N`). Em documentos curtos é opcional:
  `[documento_acessivel id="1" pages="yes"]` (só a partir de ~120 KB; `pages="no"` não desfaz o caso obrigatório).
  Medido num texto de 1,3 MB: página de 172 KB e 336 parágrafos no DOM (antes: 1,3 MB e 7.200), primeira montagem 1,1 s, depois 0,03 s.
- **Busca no documento:** campo "Buscar no documento" (por palavra, trecho ou parágrafo; sem diferenciar maiúsculas nem acentos). Em
  documento em partes a busca roda **no servidor, no livro inteiro** (0,34 s em 7.200 parágrafos), lista os resultados com o trecho e a
  parte e, ao clicar, abre a parte e leva ao parágrafo (Mostrar mais resultados: 20 por vez). Limite de 30 buscas por minuto por
  visitante. Em documento curto procura no texto da página. Sem bibliotecas externas (FlexSearch/Lunr).
- **Nome e instruções padrão:** "JIM - Assistente"; respostas de no máximo 3 frases, diretas e **sem spoilers** (se a resposta revelaria o
  que vem depois, o JIM só aponta a seção do texto). A regra fixa de "menos de 200 palavras" saiu; o limite é o das instruções.
- **QA:** GitHub Actions com PHPCS, PHPStan, PHPUnit, Infection e testes jsdom (ver CONTRIBUTING).

### Correção
- O log registrava o início de uma conversão em dobro quando o arquivo ia pelo envio com JavaScript.

## 2.1.0 (2026-10-11)

### Novo
- **Modo de exibição na importação:** a tela Novo Documento ganhou "Modo de exibição" (**Leitor acessível** ou
  **Padrão blog**, nomes que substituem "Leitor (com barra de leitura e voz)" e "Post de blog (página HTML
  convertida)" em todo o plugin), que antes só mudava na Edição rápida da lista. A Edição rápida continua valendo. Ver D32.
- **Também publicar em:** na tela Novo Documento, a pessoa escolhe um tipo de conteúdo (post, página ou
  tipo personalizado). Cada arquivo importado vira um documento no painel do Jim **e** um post nesse tipo
  com o shortcode já dentro (bloco de shortcode no editor de blocos). Status: publicar, rascunho,
  pendente, privado ou agendar (com intervalo em horas entre os arquivos de um lote). O endereço (slug)
  é o nome do arquivo. Categorias e tags do WordPress também classificam os documentos do Jim (coluna e
  filtro na lista); os campos mudam conforme o tipo escolhido. Ver D32.
- **Tour guiado** da tela Novo Documento (9 passos, `assets/js/tour.js` + `includes/class-tour.php`): abre sozinho na
  primeira visita depois de uma instalação nova (opção `jimca_tour_pending`, por pessoa em `jimca_tour_done`), com
  convite no Painel e em Plugins; reabre pelo botão da própria tela, pelo botão "Clique aqui para abrir o tutorial" da aba
  Tutorial ou por `&jimca_tour=1`. Diálogo não modal que recebe o foco a cada passo, Esc sai, contorno no campo (também em
  alto contraste), sem animação. 14 testes em `documentacao/testes/tour.test.js`. Ver D33.
- **Tutorial atualizado:** cartões novos (Tour guiado, Publicar em posts/páginas/tipos personalizados, Agendamento).
- **Agendamento pelo Action Scheduler:** "Agendar" não usa mais o status `future` do WordPress. Cada arquivo vira
  um evento (grupo "Jim import AAAA-MM-DD HH:MM #xxxx") em Ferramentas > Scheduled Actions, onde dá para ver,
  executar ou cancelar; o post só é criado na hora marcada. O aviso depois do envio traz o nome do evento e o link.
  Sem a biblioteca, cai no agendamento nativo do WordPress.
- **Correção:** a data do agendamento era lida no fuso do site, mas escolhida pelo relógio do navegador (com fuso
  diferente a data "futura" era recusada e o envio não terminava). O formulário agora mostra o relógio do site, limita a
  data mínima e a mensagem de erro diz que horas são no site.
- **Correção:** a lista "Também publicar em" trazia tipos internos (grupos de campos do ACF, modelos, etc.). Agora só
  entram posts, páginas e tipos personalizados públicos com editor.

## 2.0.1 (2026-10-09)

### Novo e corrigido
- **Correção:** o botão **Parar** não interrompia a leitura: ela seguia falando e voltava ao início.
  Agora corta de vez (também depois de Pausar, de trocar a velocidade ou de Ouvir de novo). Ver D31.
- **Testes de acessibilidade** (W3C, WebAIM, WCAG 2.1 AA) e de regressão do leitor em
  `documentacao/testes/` (44 testes) e roteiro em `documentacao/TESTES-DE-ACESSIBILIDADE.md`. Ver D31.
- **Correção:** o botão Ouvir começa no trecho em que a pessoa está (texto selecionado, título com foco
  ou primeiro parágrafo na tela), em vez de voltar ao início da página. Ver D30.
- **Links de atalho** no topo do documento (conteúdo, controles de leitura e sumário), visíveis só
  com o foco do teclado (WCAG 2.4.1). Ver D30.
- **Revisão da conversão** na tela de edição do documento ("Identificou problemas na conversão? Clique aqui"): aponta títulos que são só negrito, texto fora de `<p>`, título com parágrafo dentro, parágrafos vazios, `<br><br>` como espaçamento e saltos de nível de título, e corrige no editor. Ver D29.
- **Correção:** o editor clássico gravava o documento sem as tags `<p>` e a barra de leitura não achava o que ler (caso do PDF Sapiens). Os parágrafos voltam na hora de exibir. Ver D29.
- **Correção (celular):** barra e menus de leitura não passam mais da tela quando o tema alarga a página. Ver D29.

## 2.0.0 (em desenvolvimento, branch `v2.0`)

### Novo
- **Textos-fonte em inglês** e traduções **pt_BR, es_ES, fr_FR, zh_CN, hi_IN, ru_RU e de_DE** (painel e front), com cartão "Idiomas e traduções" e botão **Revisar tradução**. Ver D18.
- Convite para dar uma estrela no GitHub (Tutorial, readme). Ver D22.
- **Log de atividade** em Configurações, ao vivo, com limpar e exportar CSV. Ver D20.
- **IA com OpenRouter**: chave criptografada, campo de modelo e botão "Testar conexão". Ver D19.
- **Tutorial refeito** e **texto sugerido de política de privacidade**. Ver D21.
- **Modo de exibição por documento** (leitor ou post de blog), configurado só em
  Documentos Acessíveis › Edição rápida.
- **Modo post** com barra de tempo de leitura, ouvir e compartilhar.
- **Imagens na Biblioteca de Mídia**, ligadas ao documento e apagadas com ele.
- **Lightbox** nas imagens do documento (setas, descrição, contador, Esc).
- **Markdown (.md)** como formato aceito.
- **Envio de vários arquivos leves** (TXT/MD, até 20) com **lista de shortcodes**.
- **Limites de imagens** (quantidade e tamanho total) e guardas de tempo e memória.
- **Aviso com a causa de cada imagem que ficou de fora** (também guardado no documento).
- **Quadro "Requisitos do servidor"** em Configurações.
- **SEO automático** (descrição, Open Graph, Twitter Card, JSON-LD) na página do shortcode.
- **Marcador visual** (marca-texto em quatro cores, salvo no navegador).
- **Chave de API de IA** guardada criptografada (o assistente ainda não existe).
- **Mensagens específicas** para arquivo vazio, formatos não suportados e conteúdo que não
  bate com a extensão.
- Pasta `documentacao/` com requisitos e testes.

### Corrigido
- PDFs do Microsoft Office com imagens de paleta (espaço de cor em objeto separado):
  as imagens agora entram.
- PDF escaneado e DOCX vazio não dão mais "sucesso" com documento vazio.
- Envio de mais arquivos do que o PHP aceita (`max_file_uploads`) não perde arquivos em
  silêncio.
- Plugin Check: 0 erros (3 corrigidos).

### Removido
- Formato `badge` do `[jimca_instalacoes]`: carregava uma imagem de `img.shields.io` no
  navegador do visitante (envio do IP a terceiro). Ver D17.

### Mudou
- Versão 2.0.0 no cabeçalho, em `JIMCA_VERSION` e no `Stable tag`; `readme.txt` reescrito
  (imagens na Biblioteca, novos recursos, privacidade, código-fonte, sem passo do Composer).
- Aviso de "vendor/ ausente": só para quem ativa plugins, só nas telas Plugins e Jim, dispensável.
- O atributo `modo=` do shortcode não existe; o modo vem do painel.
- Documentos novos guardam imagens na Biblioteca; os da v1.x mantêm a pasta antiga.
- Título do documento agora é opcional no envio.

### Pendente
- Testar o tutor com os demais provedores disponíveis no sistema (Claude, OpenAI, Gemini, DeepSeek e "Outro"); a OpenRouter já foi testada com chave real e funciona.
