#!/usr/bin/env bash
#
# Cria a versão minificada (nome.min.js / nome.min.css) de cada CSS e JS do
# plugin, ao lado do original. O plugin usa a .min quando ela existe e o
# WordPress não está em SCRIPT_DEBUG (ver jimca_asset_url()); o código legível
# continua no pacote.
#
# Uso:  bin/minify-assets.sh [pasta-do-plugin]     (padrão: a raiz do repositório)
# Requer: Node.js (usa o esbuild, baixado pelo npx).

set -euo pipefail

ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
ESBUILD="esbuild@0.25.0"

cd "$ROOT/assets"

count=0
for file in js/*.js css/*.css; do
	case "$file" in
		*.min.js|*.min.css) continue ;;
	esac
	out="${file%.*}.min.${file##*.}"
	npx --yes "$ESBUILD" "$file" --minify --legal-comments=none --log-level=error --outfile="$out"
	count=$((count + 1))
done

echo "Minificados: $count arquivos em assets/."
