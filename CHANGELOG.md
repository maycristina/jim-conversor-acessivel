# Changelog

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
- Assistente de IA em si.
