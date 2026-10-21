# Segurança do Jim

Resumo do que o plugin faz (e não faz) para proteger o site, a chave de IA e as páginas.

## Chave de IA (JIM - Assistente)

- **Cifra:** libsodium `secretbox` (cifra e autentica), nonce aleatório novo a cada vez que a chave é salva.
  O resultado fica em base64 na opção `jimca_ai`, no banco.
- **Chave de cifragem:** derivada dos salts do `wp-config.php` (`wp_salt('secure_auth')`), que não ficam no banco.
- **Exibição:** a chave nunca volta para a tela (campo de senha, sempre vazio; só os 4 últimos caracteres aparecem).
  Não vai para o JavaScript do leitor nem para o log.
- **Uso:** é decifrada na memória só na hora de chamar o provedor, por HTTPS, no servidor (`wp_remote_post`/cURL).
  O cabeçalho (`Authorization: Bearer …` ou `x-api-key: …`) nunca passa pelo navegador do leitor.
- **Mensagens de erro:** o leitor nunca vê o erro do provedor (recebe uma frase genérica). O texto de erro que vai para o log e para o
  aviso do botão "Testar conexão" (só administradores) passa por `JIMCA_AI::redact()`: a chave (como enviada, codificada em URL ou
  escapada em JSON) vira `••••`, caso um serviço ou proxy devolva a requisição, e o texto é cortado em 300 caracteres. A chave vai só
  em cabeçalho, nunca na URL, então mensagens de rede (cURL) também não a carregam. Verificado com um servidor de teste que devolve o
  cabeçalho `Authorization` no erro.
- **Salts trocados:** a chave não pode mais ser lida; a tela avisa para digitá-la de novo.

### O que protege e o que não protege

| Situação | Protegida? |
| --- | --- |
| Vazamento só do banco (dump, backup do banco, SQL injection que leia `wp_options`) | Sim: sem os salts o texto cifrado não serve |
| Arquivos + banco juntos (backup completo do site) | Não |
| Código rodando no WordPress (plugin vulnerável, administrador que edita código) | Não: quem executa PHP pode pedir a chave decifrada |
| `wp-config.php` sem salts definidos (o WordPress guarda salts no banco) | Fraca: a chave de cifragem fica ao lado do texto cifrado |
| Provedor "Outro" com endereço `http://` | A chave viaja sem criptografia; use HTTPS fora de testes locais |

### Recomendação

Use uma chave **só para este site**, com **limite de gasto** no provedor, e troque-a se houver suspeita de vazamento.
Confira que o `wp-config.php` define os oito salts.

## Páginas dentro de `<iframe>` (clickjacking)

O plugin envia, por padrão, `Content-Security-Policy: frame-ancestors 'self'` e `X-Frame-Options: SAMEORIGIN`
(para navegadores antigos) em:

- as páginas do site que imprimem o shortcode `[documento_acessivel]`;
- as telas do Jim no painel (o WordPress já envia `X-Frame-Options` em todo o painel, e as versões recentes também o
  `frame-ancestors`; o plugin garante o mesmo nas versões antigas).

Isso só diz **quem pode emoldurar** a página. Não afeta as requisições que a página faz (`admin-ajax.php`, busca, tutor),
nem a chamada do servidor ao provedor de IA. Quem incorpora os documentos de propósito (plataforma de cursos, por
exemplo) libera o endereço com o filtro:

```php
add_filter( 'jimca_frame_ancestors', function () {
	return "'self' https://cursos.exemplo.com.br";
} );
```

Retornar `''` desliga o cabeçalho. Se o tema, o servidor ou outro plugin já enviar uma política própria, os
navegadores aplicam todas (vale a mais restritiva).

## Listagem de diretório e acesso direto a arquivos

O WordPress.org **não aceita `.htaccess` dentro do pacote do plugin**, então o Jim não traz um. A proteção que cabe no plugin:

- todas as pastas do plugin têm um `index.php` ("Silence is golden"), que impede a listagem de diretório;
- todo arquivo `.php` começa com `if ( ! defined( 'ABSPATH' ) ) { exit; }`, então abri-lo pelo endereço não executa nada;
- a pasta de envios (`uploads/jimca-documentos`) recebe, ao ser criada, um `.htaccess` (sem execução de scripts, sem listagem)
  e um `index.php` próprios; isso é gerado em tempo de execução, fora do pacote.

Quem administra o servidor pode reforçar (opcional): em Apache/LiteSpeed, `Options -Indexes` e negar `*.php` em
`/wp-content/plugins/jim-conversor-acessivel/`; em nginx, `autoindex off;` e `location ~* ^/wp-content/plugins/jim-conversor-acessivel/.*\.php$ { deny all; }`.

## Originais guardados e buscadores (`noindex`)

Por padrão o original é apagado depois da conversão. Quando ele é guardado (opção desligada em Configurações), o Jim, por padrão, pede aos
buscadores que **não indexem** esses arquivos, para que o PDF original e a página convertida não concorram nos resultados com o mesmo
texto. A opção é **Originais e buscadores** (`noindex_originals`, ligada por padrão).

- **Apache e LiteSpeed:** o `.htaccess` da pasta `uploads/jimca-documentos` ganha `Header set X-Robots-Tag "noindex, nofollow"` (dentro de
  `<IfModule mod_headers.c>`). O arquivo é reescrito quando a opção muda e na próxima conversão, também para os envios antigos.
- **nginx** (não lê `.htaccess`): acrescente ao servidor
  ```nginx
  location ^~ /wp-content/uploads/jimca-documentos/ {
      add_header X-Robots-Tag "noindex, nofollow" always;
  }
  ```
- Não é um bloqueio: quem tem o endereço continua abrindo o arquivo. Só orienta os buscadores. Se o servidor não permitir `Header` em `.htaccess`
  (`AllowOverride FileInfo`), a pasta responde com erro 500 e o jeito é desligar a opção e usar a regra do servidor.

## Envio de arquivos

- **No navegador:** a lista dos arquivos escolhidos mostra tipo, tamanho e um selo "Válido"/"Erro", e o botão de converter fica desligado
  com arquivo inválido. É conforto, não segurança: tudo é conferido de novo no servidor.
- **No servidor (`JIMCA_Admin::receive_file()`):** nome limpo com `sanitize_file_name()`, extensão permitida (pdf, docx, txt, md),
  conteúdo conferido com `wp_check_filetype_and_ext()` (que usa `finfo`) para garantir que a extensão corresponde ao arquivo,
  tamanho dentro do limite configurado e, em PDF, dentro do que a memória do servidor converte; no máximo 20 arquivos por envio. Cada
  arquivo falha ou passa sozinho, com mensagem própria. O nome guardado leva um sufixo aleatório, então dois envios do mesmo arquivo
  nunca se sobrescrevem.
- **Chamadas AJAX da caixa de SEO** (`jimca_seo_save`, `jimca_seo_detect`, `jimca_seo_headings`): todas exigem nonce e a capacidade
  `edit_post` do documento; o conteúdo que volta do editor passa por `wp_kses_post()` antes de ser salvo.
