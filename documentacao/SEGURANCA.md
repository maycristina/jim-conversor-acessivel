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
