# Capacidade e limites da conversão

Auditoria feita em 2026-10-12 com os PDFs e TXTs reais do projeto. Os números abaixo vêm do conversor do
próprio plugin rodando em PHP 8.3 (linha de comando, `memory_limit` 256 MB, sem extração de imagens), não de
uma hospedagem: uma hospedagem compartilhada costuma ser mais lenta e ter menos memória, por isso os limites
da tela se calculam a partir da memória do servidor onde o plugin está.

## Como a fila funciona

- Cada arquivo é **uma requisição própria**, e os arquivos vão **um depois do outro** (nunca dois ao mesmo
  tempo a partir da mesma tela). Um erro ou bloqueio afeta só aquele arquivo; os demais continuam.
- Cada arquivo é convertido em passos de até 20 s que salvam onde pararam (`JIMCA_Conversion_Job`), então
  um livro longo não depende de uma requisição única. A tela precisa ficar aberta até o último terminar.
- Sem JavaScript, o envio comum manda todos os arquivos numa requisição só; nesse caso só TXT e MD podem ir em lote.

## Limites que o plugin impõe

| Limite | Valor | Onde aparece |
|---|---|---|
| Arquivos por vez | **20** | Texto da tela de importação; a lista de arquivos marca do 21º em diante com "Erro" e o botão de converter fica desligado até removê-los |
| Tamanho por arquivo | Configuração "Tamanho máximo (MB)" (1 a 100), limitada pelo `upload_max_filesize` / `post_max_size` do servidor | Texto da tela; arquivo maior é recusado pelo nome, sem ser enviado |
| Tamanho de **PDF** | Calculado pela memória do PHP: (memória − 20 MB) ÷ 5,5. Com 256 MB: ~42 MB. Com 128 MB: ~19 MB. Com 512 MB: ~89 MB | Texto da tela ("PDF: cerca de X neste servidor"); PDF maior é recusado pelo nome |
| Imagens por documento | Configurações "máx. de imagens" (até 300) e "máx. MB de imagens" | Aviso depois da conversão |
| Texto ilegível | PDF cujas fontes não dizem qual letra é cada caractere (`looks_unreadable`) | Aviso no resumo da fila; o documento é criado, mas o JIM não aparece nele |
| PDF sem texto (digitalizado) | Recusado: "No text could be read…" (precisa de OCR) | Erro daquele arquivo |

## Medições

| Arquivo | Tamanho | Páginas | Resultado | Tempo | Pico de memória |
|---|---|---|---|---|---|
| Banco de Dados (e-book) | 5,5 MB | 140 | 32 mil palavras, legível | 2 s | 42 MB |
| Bíblia (A Igreja de Jesus Cristo…) | 17,2 MB | 2.055 | 909 mil palavras, legível | 162 s | 170 MB |
| `biblia.pdf` | 7,7 MB | 3.364 | **texto ilegível** (fontes sem mapa Unicode), detectado | 181 s | 170 MB |
| Capítulo 02 | 24,8 MB | 30 | **sem texto** (páginas são imagens) | < 1 s | 84 MB |
| Capítulo 04 | 9,2 MB | 25 | **sem texto** (páginas são imagens) | < 1 s | 34 MB |
| PDF de 51 MB | 50,9 MB | 48 | converteu, mas sobrou quase nada | 2 s | **248 MB (de 256)** |
| The History of Pottery, vol. 2 | 0,1 MB | 1 | 263 palavras, legível | 1 s | 4 MB |

Regras práticas tiradas daí:

- **Memória** ≈ 3,4 a 5,5 vezes o tamanho do PDF, mais ~20 MB. É o que decide o tamanho máximo de PDF. O
  texto em si pesa pouco: o livro de 17 MB e 909 mil palavras passou com 170 MB.
- **Tempo** ≈ 50 a 80 ms por página de texto (PDF de 2.000 a 3.000 páginas: 2,5 a 3 minutos aqui). Três
  livros grandes em fila levam alguns minutos, e a tela tem de ficar aberta.
- **O número de arquivos não é o gargalo**: como vão em sequência, a memória é a de **um** arquivo por vez.
  O limite de 20 existe para a fila não passar de um tempo razoável e porque o envio comum (sem JavaScript) já
  tinha esse teto.

## Os dois PDFs que o JIM não conseguiu ler

- **A Bíblia (`biblia.pdf`)**: o PDF mostra letras na tela, mas por baixo cada letra é um código sem relação
  com Unicode (a "fonte sem mapa"). O texto extraído sai como símbolos. Isso não é erro da conversão nem da fila:
  o plugin detecta e avisa. Agora o aviso aparece também no resumo da fila e o JIM deixa de aparecer nesse
  documento, em vez de responder a partir de símbolos. Solução do lado de quem envia: outra cópia do PDF (a
  versão de `biblia83800_por.pdf` converte bem) ou OCR.
- **The History of Pottery, vol. 2**: o arquivo de teste converte e é legível (263 palavras, só notas de rodapé).
  Se o JIM falhou nele, a causa não está na leitura do arquivo; vale repetir o teste e me mandar a pergunta feita.

## O que não foi testado

- **Vários administradores ao mesmo tempo** (cada um com uma fila): cada fila usa memória de um arquivo, mas não
  há um teto global. Duas filas simultâneas de PDFs grandes numa hospedagem pequena podem se atrapalhar.
- **PDF com muitas imagens** na hospedagem real: as imagens têm limites próprios (quantidade e MB) e guardas de
  tempo e memória, mas as medições acima foram sem extração de imagens.
- **Tempo na hospedagem real**: os números são do computador, não do servidor.

## Exibição de documentos longos

A conversão da Bíblia deu certo, mas colocar todos os parágrafos na página (dezenas de milhares) travava o navegador: o DOM gigante consome memória e processamento, e atrapalha leitores de tela. Por isso o leitor mostra um documento longo em **partes**.

| Aspecto | Como é |
|---|---|
| Quando | Obrigatório a partir de 350 KB de HTML (~50 mil palavras); opcional (`pages="yes"`) a partir de ~120 KB |
| Tamanho da parte | ~60 KB; corta antes de um título (h1 a h3) e, sem títulos, em qualquer limite de bloco ao chegar a 120 KB |
| O que carrega | Uma parte por vez; a seguinte vem antes, e só as 3 últimas ficam na memória do navegador |
| Técnica | Paginação (a que o texto de pesquisa recomenda para leitor de tela), não rolagem virtual: o texto não monta e desmonta enquanto a pessoa ouve |
| Navegação | Na barra flutuante: `‹ 2/93 ›` (o número abre a lista de partes) e, no menu ≡, Buscar e Sumário |
| Sem JavaScript | Os links "Parte anterior / Próxima parte" na página e o sumário recarregam a página na parte (`?jimca_part_ID=N`) |
| Busca | No servidor, no livro todo, sem diferenciar maiúsculas e acentos; 20 resultados por vez; 30 buscas por minuto por visitante |
| IA (JIM) | Não muda: o tutor continua escolhendo os trechos a partir do documento inteiro, no servidor |

Medições (WordPress 6, PHP 8.2, texto sintético de 1,3 MB com 7.200 parágrafos): a página passou de 1,3 MB para 172 KB (já com um sumário de 640 entradas) e de 7.200 para 336 parágrafos no DOM; cortar o documento em 23 partes leva 1,1 s na primeira exibição e 0,03 s depois (partes em cache por 7 dias, por versão do documento); buscar em todo o texto leva 0,34 s. Num texto de 8,4 MB e 81 mil parágrafos o corte leva 0,1 s e 20 MB de memória, e a busca 0,3 s.
