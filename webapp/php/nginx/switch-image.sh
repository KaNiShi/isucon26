#!/usr/bin/env bash
set -euo pipefail

mode="${1:?usage: switch-image.sh central|local}"
dir="$(cd "$(dirname "$0")" && pwd)"
site=/etc/nginx/sites-available/isureal.conf
include=/etc/nginx/isureal-image-location.conf
upstream=/etc/nginx/conf.d/isureal-image-upstream.conf

if ! sudo grep -q isureal-image-location "$site"; then
  sudo sed -i 's#^  location /api/ {#  include /etc/nginx/isureal-image-location.conf;\n\n  location /api/ {#' "$site"
fi

case "$mode" in
  central)
    sudo cp "$dir/image-upstream.conf" "$upstream"
    sudo cp "$dir/image-location.conf" "$include"
    ;;
  local)
    sudo rm -f "$upstream"
    : | sudo tee "$include" >/dev/null
    ;;
  *)
    echo "unknown mode: $mode" >&2
    exit 1
    ;;
esac

sudo nginx -t
sudo systemctl reload nginx
echo "image mode: $mode"
