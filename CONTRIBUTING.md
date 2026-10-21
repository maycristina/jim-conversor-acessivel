# Como contribuir com o Jim — Conversor Acessível

Obrigada pelo interesse! O Jim existe para que documentos virem páginas que todo mundo consegue ler
ou ouvir, então toda contribuição é avaliada primeiro pela pergunta: **isso deixa a leitura mais
acessível?**

> **In English:** issues and pull requests are welcome in English or Portuguese. Code, comments and
> interface strings are in English; project docs in `documentacao/` are in Portuguese. The sections
> below cover setup, coding rules, translations, testing and how to report security issues.

## Formas de ajudar

- **Relatar um problema:** abra uma [issue](https://github.com/maycristina/jim-conversor-acessivel/issues)
  com a versão do plugin, do WordPress e do PHP, o que você fez, o que esperava e o que aconteceu. Se
  for uma conversão, diga o tipo e o tamanho do arquivo e, se puder, anexe a exportação do log
  (**Configurações › Log de atividade › Exportar CSV**): ele nunca contém o texto dos documentos.
  Não anexe arquivos com dados pessoais.
- **Traduzir ou revisar uma tradução:** veja [Traduções](#traduções).
- **Melhorar a acessibilidade:** testes com leitor de tela (NVDA, JAWS, VoiceOver, TalkBack), teclado
  e alto contraste são especialmente bem-vindos.
- **Código:** correções e melhorias, de preferência combinadas antes numa issue quando forem grandes.

## Ambiente de desenvolvimento

Requisitos: PHP 7.4 ou superior, WordPress 6.0 ou superior e [Composer](https://getcomposer.org/).

```bash
git clone https://github.com/maycristina/jim-conversor-acessivel.git
cd jim-conversor-acessivel
composer install --no-dev
```

Coloque (ou crie um link simbólico para) a pasta em `wp-content/plugins/` de um WordPress local e
ative o plugin. Qualquer ambiente serve: [WordPress Playground](https://wordpress.org/playground/),
Local, wp-env, Docker ou o PHP embutido com SQLite.

## Verificações de qualidade (QA)

O GitHub Actions (`.github/workflows/qa.yml`) roda em todo Pull Request e em todo commit nas branches principais:

| Ferramenta | O que confere | Rodar localmente |
|---|---|---|
| PHPCS (`phpcs.xml.dist`) | WordPress Coding Standards (segurança, escape, i18n) e compatibilidade com PHP 7.4+ | `composer lint` |
| PHPStan (`phpstan.neon.dist`) | Análise estática, nível 5 | `composer analyse` |
| PHPUnit (`tests/unit/`) | Testes unitários (Brain Monkey, sem WordPress) | `composer test` |
| Infection (`infection.json5`) | Testes de mutação; informativo por enquanto | só no CI |
| jsdom (`documentacao/testes/`) | Leitor, tutor, tour e testes WCAG | `npm install --no-save jsdom` e `node --test "documentacao/testes/*.test.js"` |

`composer install` (sem `--no-dev`) instala as ferramentas; `composer qa` roda as três de PHP. A matriz do CI testa PHP 7.4, 8.0, 8.1, 8.2 e 8.3. Avisos do PHPCS aparecem no Pull Request mas não reprovam; erros reprovam. Erros antigos do PHPStan ficam em `phpstan-baseline.neon`: código novo não deve entrar nele.

Para gerar o pacote instalável (com `vendor/`) a partir do último commit: `bin/build-zip.sh`
(requer `git`, `composer` e `zip`).

## Regras do código

- **Padrão do WordPress:** siga os [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
  (PHP, JavaScript, CSS). Antes de enviar, rode o [Plugin Check](https://wordpress.org/plugins/plugin-check/)
  e, se tiver, o PHPCS com o padrão `WordPress`. Um `phpcs:ignore` só com justificativa na mesma linha.
- **Compatibilidade:** o código precisa rodar em PHP 7.4 (nada de sintaxe exclusiva do PHP 8) e em
  hospedagem compartilhada: sem programas externos, sem depender de extensões opcionais (como Imagick)
  para funcionar.
- **Segurança:** escape toda saída (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), sanitize toda
  entrada, confira nonce e capacidade em toda ação. Texto vindo de arquivos enviados ou da IA nunca vira
  HTML sem passar por isso.
- **Acessibilidade:** WCAG 2.1 AA no mínimo. Todo controle funciona por teclado, tem nome acessível e
  foco visível; nada depende só de cor; mudanças importantes são anunciadas (`aria-live`) sem
  "tagarelar".
- **Textos:** em inglês, sempre com o text domain `jim-conversor-acessivel`, e com comentário
  `/* translators: */` quando houver `%s`/`%d`.
- **Comentários:** em inglês e só para explicar o *porquê* (uma limitação de biblioteca, uma regra de
  acessibilidade, uma decisão de segurança ou desempenho). Não descreva o óbvio e não deixe histórico
  ("antes era…", datas, código comentado): isso é papel do git e do `CHANGELOG.md`.
- **Privacidade:** nenhum recurso novo pode enviar dados a terceiros sem estar descrito no `readme.txt`
  (seção *Privacy*) e em `includes/class-privacy.php`. Nunca registre no log texto de documentos,
  perguntas de leitores ou chaves.
- **Segredos:** nunca faça commit de chaves de API, senhas ou tokens, nem em testes.

## Traduções

As frases do plugin estão em inglês. As traduções em `languages/` (pt_BR, es_ES, fr_FR, zh_CN, hi_IN,
ru_RU, de_DE) foram feitas com ajuda de máquina e precisam de revisão por falantes nativos.

- O caminho preferido é o [translate.wordpress.org](https://translate.wordpress.org/), assim que o
  plugin estiver lá: as traduções oficiais têm prioridade sobre as do pacote.
- Para corrigir uma tradução do pacote, edite o `.po` do idioma e gere o `.mo` (com Poedit, por
  exemplo), ou abra uma issue com a frase original, a atual e a sugerida.
- Um idioma novo começa a partir de `languages/jim-conversor-acessivel.pot`.

## Testes

O projeto ainda não tem testes automatizados; contribuições nesse sentido são muito bem-vindas.
Hoje cada mudança é testada à mão, seguindo os casos de
[`documentacao/TESTES-E-LIMITACOES.md`](documentacao/TESTES-E-LIMITACOES.md). No pull request, diga:

- o que você testou (formatos e tamanhos de arquivo, navegadores, leitores de tela) e o resultado;
- se a mudança afeta a conversão, compare o resultado antes e depois com os mesmos arquivos;
- se mexeu em textos, se rodou a verificação de sintaxe (`php -l`) e o Plugin Check.

### Conferir o SEO e a acessibilidade de uma página (sem limite de uso)

```bash
# Passa um arquivo pelo conversor do Jim e publica numa página (WP-CLI, em qualquer WordPress com o plugin ativo):
wp jimca convert tests/fixtures/moulin.txt --mode=post --language=fr --slug=moulin

# Confere a página (ou vários endereços, ou um .html salvo). Lista só o que não passou; --verbose mostra tudo.
node bin/seo-audit.js http://localhost:8888/moulin/ --strict
```

`bin/seo-audit.js` reúne o que os verificadores web olham na página (título, descrição, títulos H1-H6, Open Graph, JSON-LD,
idioma, imagens, scripts) mais as regras do plugin (modo post sem busca nem partes, scripts adiados e só os necessários).
A CI roda os dois com os arquivos de `tests/fixtures/` (`.github/workflows/jim-seo-audit.yml`).

## Commits e pull requests

1. Crie um branch a partir do branch principal (`git checkout -b corrige-tabelas-docx`).
2. Faça commits pequenos, com mensagem que diga **o que** mudou e **por quê** (português ou inglês).
3. Abra o pull request descrevendo o problema, a solução, como testou e prints ou GIFs se a mudança
   for visual.
4. Atualize `readme.txt` (Changelog e, se for o caso, Privacy) e, para decisões de arquitetura,
   explique o porquê na descrição do pull request.

## Segurança

Não abra issue pública para falhas de segurança. Use o
[relato privado de vulnerabilidades do GitHub](https://github.com/maycristina/jim-conversor-acessivel/security/advisories/new)
com os passos para reproduzir. A resposta vem assim que possível, e o crédito é seu se quiser.

## Código de conduta

Ao participar, você concorda com o [Código de Conduta](CODE_OF_CONDUCT.md).

## Licença

Contribuições são aceitas sob a mesma licença do projeto, GPL v2 ou posterior ([LICENSE](LICENSE)).
