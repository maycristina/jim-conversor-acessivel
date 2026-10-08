# Requisitos do sistema recomendados

Como o Jim converte (importante para os requisitos): a conversão é **100% em PHP**,
com `smalot/pdfparser` (PDF) e `phpoffice/phpword` (DOCX), mais conversores próprios
para TXT e Markdown. **Não usa programas externos** (Poppler/`pdftohtml`,
Ghostscript, LibreOffice). Isso torna o plugin instalável em hospedagem
compartilhada, mas faz da **memória** e do **tempo de execução do PHP** o gargalo.

## Resumo

| Item | Mínimo | Recomendado | Por quê |
|---|---|---|---|
| WordPress | 6.0 | versão atual | Requisito declarado do plugin |
| PHP | 7.4 | **8.1 ou superior** (8.3 ideal) | As bibliotecas aceitam 7.1+; 7.4 já não recebe correções de segurança |
| `memory_limit` | 256 MB | **512 MB** | O leitor de PDF monta o arquivo inteiro em memória; imagens somam |
| `max_execution_time` | 120 s | **300 s** | Livros grandes levam minutos |
| `upload_max_filesize` e `post_max_size` | 8 MB | **32 MB** | PDFs de livros passam de 10 MB com facilidade |
| Extensões obrigatórias | `zlib`, `iconv`, `mbstring` (PDF); `zip`, `dom`, `xml`, `mbstring` (DOCX) | + `gd` | `gd` converte BMP/TIFF do Word em PNG |
| HTTPS | — | **sim** | Botões Compartilhar/copiar link só funcionam em contexto seguro |
| Programas externos | nenhum | nenhum | Não há dependência de binários |

O quadro **Configurações › Requisitos do servidor** compara o PHP do site com esta
tabela e mostra OK / Atenção / Faltando.

## O que o plugin faz para se proteger

- Pede mais memória e tempo ao PHP quando o servidor deixa
  (`wp_raise_memory_limit('admin')`, `set_time_limit(300)`).
- Limita as imagens: 100 por documento e 60 MB no total (configurável; era 40/30 até D24).
- Converte imagens JPEG 2000 só quando o PHP tem a extensão **Imagick** com suporte a JPEG 2000 (opcional).
- Para a extração de imagens ao chegar a 60% do tempo ou 70% da memória do PHP.
- Só aceita vários arquivos por envio quando são leves (TXT e MD).
- Antes de ler um arquivo, confere se as extensões do PHP necessárias existem e explica
  o que falta, em vez de um erro fatal.

## Comparação com a recomendação genérica (conversão PDF→HTML com Poppler)

A recomendação recebida para "plugin de PDF para HTML" serve de ponto de partida, mas
parte de premissas que não são as do Jim:

| Ponto da recomendação | Vale para o Jim? | Decisão |
|---|---|---|
| PHP mínimo 8.0, alvo 8.3 | **Em parte.** 8.3 como alvo sim; mínimo 8.0 ainda não | Manter 7.4 declarado na v2.0 para não quebrar sites em produção; recomendar 8.1+; subir o mínimo para 8.0 quando a produção confirmar PHP ≥ 8.0 |
| Memória 256 MB mín., 512 MB recomendado | **Sim** | Adotado |
| `max_execution_time` 120 s mín., 300 s recomendado | **Sim** | Adotado |
| Upload mín. 32 MB | **Em parte.** O limite do plugin é 10 MB por arquivo (configurável); 32 MB no servidor é o recomendado | Adotado como recomendação |
| Extensões `dom`, `mbstring`, `json`, `curl` | **Parcial.** Reais: `zlib`, `iconv`, `mbstring`, `zip`, `dom`, `xml`; `gd` opcional. `json` já vem no PHP; `curl` só entra via WordPress (contador de instalações) | Lista real documentada acima |
| Poppler (`pdftohtml`) e aviso de binário ausente | **Não.** O Jim não usa binários | Nada a detectar; é um requisito a menos em hospedagem compartilhada |
| Biblioteca `prinsfrank/pdfparser` | **Não.** O Jim usa `smalot/pdfparser` | — |
| HTTPS recomendado | **Sim**, e por um motivo concreto: Compartilhar/copiar link | Documentado no quadro |
| Documentar requisitos no `readme.txt` | **Sim** | Feito (`readme.txt`) |

## Por que o servidor pode cair e o que fazer

Causas, da mais comum para a menos comum:

1. **Memória do PHP esgotada** ao ler um PDF grande ou decodificar muitas imagens.
2. **Tempo máximo estourado** em PDFs de centenas de páginas.
3. **Hospedagem compartilhada** que mata processos que usam muita CPU/memória por
   muito tempo (o site inteiro pode ficar fora por alguns minutos).

O que fazer, nesta ordem: manter os limites de imagens baixos em hospedagem
compartilhada; pedir à hospedagem `memory_limit` 512 MB e `max_execution_time` 300;
converter livros muito grandes em partes (dividir o PDF); em caso de dúvida, olhar o
quadro de requisitos e o aviso depois da conversão, que diz o que ficou de fora e por quê.

## O que ainda não foi medido

Os tempos de conversão deste projeto foram medidos no WordPress Playground (PHP em
WebAssembly), que é bem mais lento que um servidor real: o PDF "Test Driven
Development…" (2 MB) levou cerca de 2 minutos. **Não há medição em servidor de
produção.** Antes de prometer um número de páginas por servidor, medir na Hostinger.
