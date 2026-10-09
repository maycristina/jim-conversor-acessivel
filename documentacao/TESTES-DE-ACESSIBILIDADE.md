# Testes de acessibilidade (W3C, WebAIM e WCAG)

Protocolo de testes de acessibilidade do visualizador do Jim: o que é verificado, com qual
critério e qual técnica, e o que ainda precisa ser conferido à mão.

Referências:

- **WCAG 2.1 AA** (nível alvo do projeto; dois critérios da 2.2 entram como bônus: 2.5.8 e 2.4.11):
  <https://www.w3.org/TR/WCAG21/>
- **Bypass Blocks (2.4.1):** <https://www.w3.org/WAI/WCAG21/Understanding/bypass-blocks.html>
- **W3C WAI, In-page navigation:** <https://www.w3.org/WAI/tutorials/page-structure/in-page-navigation/>
- **W3C Techniques:** G1 (link para o conteúdo principal), G123 (link no início da página),
  G124 (links para as áreas da página), ARIA11/ARIA12 (marcos e títulos), H42 (títulos), C6/C15
  (CSS de foco), SCR (scripts acessíveis).
- **WebAIM, Skip Navigation Links:** <https://webaim.org/techniques/skipnav/>

## Como rodar

```
npm install --no-save jsdom
node --test "documentacao/testes/*.test.js"
```

São 3 arquivos, 44 testes, sem PHP e sem navegador:

| Arquivo | O que faz |
|---|---|
| `a11y.test.js` | Lê CSS, template e JS e confere critérios WCAG/W3C/WebAIM (tabela abaixo). |
| `leitor.test.js` | Roda o `frontend.js` de verdade num DOM (jsdom) com um sintetizador de voz que age como o do Chrome (`cancel()` dispara `error` depois). Testa Parar, Pausar, Ouvir e o ponto de partida. |
| `auditor.test.js` | Revisão de semântica pós-conversão (PROT-A11Y-CONV-001 e PROT-A11Y-JS-001). |

Para ver o teste pegar o defeito, aponte para um `frontend.js` antigo:
`JIMCA_FRONTEND_JS=/caminho/antigo.js node --test documentacao/testes/leitor.test.js`
(com o arquivo anterior à D31, 5 dos 6 testes falham).

## Critérios cobertos automaticamente (`a11y.test.js`)

| Critério WCAG | Técnica / referência | O que o teste confere |
|---|---|---|
| 1.4.3 Contraste (mínimo) | G18 | Texto 4,5:1 nos 4 temas (claro, sépia, escuro, alto contraste): texto/fundo, botão/destaque, ícones/superfície do player, texto/marca-texto |
| 1.4.11 Contraste não textual | G195, G209 | Anel de foco e borda do marca-texto 3:1 contra o fundo, nos 4 temas |
| 2.4.1 Bypass Blocks | G1, G123, G124, WebAIM | Links de atalho são os primeiros Tab do visualizador, dentro de um `<nav>` com nome; cada alvo existe |
| 2.4.1 / WebAIM | WebAIM "hidden until focus" | Escondidos com `clip` fora da tela (não `display:none`, que tira do Tab) e visíveis com `:focus-within` |
| 2.4.1 | W3C in-page navigation | Alvos com `tabindex="-1"` (o próximo Tab continua dali); com JS o link dos controles aparece e o do sumário sai |
| 2.1.1 Teclado | G202, H91 | Nenhum `tabindex` positivo; controles são `<button>`/`<a>`; sem `onclick` em `div`/`span` |
| 4.1.2 Nome, função, valor | ARIA14, ARIA16 | Todo botão tem texto ou `aria-label`; barra com `role="toolbar"` e nome; todo `<nav>` com nome; `aria-pressed`/`aria-expanded` nos botões de estado |
| 4.1.3 Mensagens de status | ARIA22 | Estado da leitura e resultado da revisão em `role="status"` `aria-live="polite"` |
| 2.4.7 Foco visível | G149, C15 | Contorno de 3 px em tudo; `outline: none` só no contêiner do texto ou onde há borda de 2 px |
| 2.5.8 Tamanho do alvo (2.2) | Material 3 | Botões da barra com 48 px de altura e pelo menos 24 px de largura |
| 2.3.3 Animação por interação | C39 | `prefers-reduced-motion` no CSS e na rolagem do leitor |
| 1.4.10 Refluxo | C32 | `overflow-wrap`, mídia com `max-width: 100%`, largura real da tela (`--jimca-vw`) |
| 1.4.2 Controle de áudio, 2.2.2 Pausar, parar, ocultar | G170, G60 | Existem Ouvir/Pausar e Parar, e Parar corta de vez (comportamento no `leitor.test.js`) |
| 1.3.1 Informações e relações | ARIA11, H42 | Região com `aria-labelledby` e título do documento |
| 1.1.1 Conteúdo não textual | H2, ARIA10 | Ícones com `aria-hidden` e `focusable="false"`; o botão traz o nome |

## Comportamento do leitor (`leitor.test.js`)

| Caso | Critério | Resultado esperado |
|---|---|---|
| Parar interrompe e nada mais é falado | 1.4.2, 2.2.2 | Nenhum trecho novo depois do Parar, sem destaque e com o aviso "Reading stopped." |
| Parar não volta ao início | 2.2.2 | Só aparecem trechos de onde a pessoa estava |
| Ouvir começa no trecho em tela | 2.4.3 Ordem do foco, 3.2.2 | Começa no primeiro parágrafo visível, e de novo depois de Parar |
| Pausar e depois Parar | 2.2.2 | Nada é falado depois |
| Parar e Ouvir de imediato | 2.2.2 | Uma só cadeia, em ordem, sem trecho repetido |
| Troca de velocidade / estado parado | 2.2.2 | Sem trecho extra |

## Lista manual (o que a automação não cobre)

Registrar data, leitor/navegador e resultado de cada item. **Ainda não executada** (ver "Não verificado").

| # | Teste | Como |
|---|---|---|
| M1 | Skip links com Tab | Recarregar, apertar Tab: aparecem os links; Enter em "Pular para o conteúdo" leva ao texto; o próximo Tab segue dali |
| M2 | Skip link dos controles | Enter em "Pular para os controles de leitura": foco na barra; setas/Tab percorrem os botões |
| M3 | NVDA + Firefox/Chrome (Windows) | Tecla H passa de título em título; a barra é anunciada como "barra de ferramentas"; Ouvir/Parar são anunciados com nome e estado |
| M4 | VoiceOver + Safari (macOS/iOS), TalkBack (Android) | Mesmo roteiro do M3, em tela de celular |
| M5 | Leitura em voz alta real | Ouvir, Pausar, Continuar, Parar: o áudio para na hora e Ouvir recomeça do ponto certo (voz do sistema, não simulada) |
| M6 | Ampliação de 200% e 400% (1.4.4, 1.4.10) | Sem rolagem horizontal e sem perda de função |
| M7 | Só teclado | Todos os menus (tamanho, tema, voz, velocidade, sumário) abrem, navegam e fecham com Esc, devolvendo o foco |
| M8 | Celular físico | Barra e menus dentro da tela, também com o tema que alarga a página |
| M9 | Ferramentas | axe DevTools, WAVE, Lighthouse e o validador HTML do W3C na página com o shortcode |

## Teste em tela de celular emulada (2026-10-09)

Chrome no Windows, WordPress Playground, página com o shortcode e 40 parágrafos, dentro de um
`<iframe>` de **390 × 844 px** (a janela não pôde ser redimensionada; o iframe faz `innerWidth`
e as media queries valerem 390 px), com `ontouchstart` definido e voz simulada.

| Verificação | Resultado |
|---|---|
| `--jimca-vw` e rolagem horizontal | `390px`; sem rolagem horizontal, também no fim da página |
| Barra de leitura | 8 a 367 px: inteira dentro da tela; 7 botões, 48 px de altura e 40 px de largura (mínimo WCAG 2.2: 24 px); nenhum fora da tela |
| Menus de tamanho, voz e velocidade | Todos dentro da tela (8–116, 76–197, 185–293 px) |
| Ouvir com o parágrafo 14 no topo | Começou no 14, não no início |
| Parar | 0 trechos falados depois; aviso "Reading stopped."; sem destaque |
| Links de atalho com foco | Aparecem (283 × 91 px, dentro da tela): conteúdo e controles; "controles" leva ao `toolbar` |

**Limites:** é emulação, não aparelho. Faltam toque real, teclado virtual, voz do sistema, o tema
do site que alarga a página (o caso do commit `e56a6a9`) e Safari/iOS. M8 segue **pendente**.

## Não verificado

- Nenhum leitor de tela real (M3 a M5) e nenhum celular físico (M4, M8) nesta rodada.
- Os testes lêem o código-fonte do template (PHP) por texto: não renderizam a página. A página
  renderizada foi conferida no Playground com o Chrome (D30 e D31), mas sem axe/WAVE (M9).
- Contraste só dos tokens dos temas; cores definidas pelo tema do site não são medidas.
