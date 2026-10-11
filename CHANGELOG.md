# Changelog

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
