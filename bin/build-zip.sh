#!/usr/bin/env bash
#
# Gera o pacote instalável do plugin em dist/jim-conversor-acessivel.zip.
#
# O zip já traz a pasta vendor/ (smalot/pdfparser e phpoffice/phpword),
# então quem baixa só precisa ir em Plugins > Adicionar novo > Enviar plugin,
# sem clonar o repositório nem rodar o Composer.
#
# Uso (na raiz do repositório):  bin/build-zip.sh
# Requer: git, composer, zip.
#
# O pacote é montado a partir do último commit (git archive), não da pasta
# de trabalho: alterações não commitadas ficam de fora, e o zip sempre
# corresponde a uma versão que existe no histórico.

set -euo pipefail

SLUG="jim-conversor-acessivel"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

cd "$ROOT"

if [ -n "$(git status --porcelain --untracked-files=no -- . ':!dist')" ]; then
	echo "Aviso: há alterações não commitadas; elas NÃO entram no pacote." >&2
fi

VERSION="$(sed -n 's/^[ *]*Version:[[:space:]]*//p' "$SLUG.php" | head -n 1 | tr -d '[:space:]')"

# 1. Código do plugin, só o que está versionado.
mkdir -p "$BUILD/$SLUG"
git archive HEAD | tar -x -C "$BUILD/$SLUG"

# 2. O que serve ao repositório, não ao plugin instalado.
( cd "$BUILD/$SLUG" && rm -rf .github .wordpress-org .gitignore .gitattributes bin dist docs documentacao _privado README.md CHANGELOG.md CONTRIBUTING.md CODE_OF_CONDUCT.md )

# 3. Dependências de produção, com autoload otimizado.
( cd "$BUILD/$SLUG" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet )

# 4. Das dependências, só o código que roda: sem histórico git, testes,
#    exemplos e documentação (juntos passam de 200 MB quando o Composer
#    instala a partir do código-fonte). Licenças são mantidas.
find "$BUILD/$SLUG/vendor" -mindepth 3 -maxdepth 3 \
	\( -name .git -o -name .github -o -name tests -o -name samples -o -name docs -o -name doc \) \
	-exec rm -rf {} +
find "$BUILD/$SLUG/vendor" -mindepth 3 -maxdepth 3 -type f \
	\( -name '.*' -o -name 'phpunit.xml*' -o -name 'phpstan*' -o -name 'phpcs.xml*' -o -name 'mkdocs.yml' -o -name 'Makefile' -o -name '*.md' ! -iname 'LICENSE*' \) \
	-delete

# 5. Zip com a pasta do plugin na raiz, como o WordPress espera.
mkdir -p "$DIST"
rm -f "$DIST/$SLUG.zip"
( cd "$BUILD" && zip -rq -X "$DIST/$SLUG.zip" "$SLUG" )

echo "Pacote gerado: dist/$SLUG.zip (versão $VERSION, $(du -h "$DIST/$SLUG.zip" | cut -f1))"
