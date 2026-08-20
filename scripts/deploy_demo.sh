#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/khrpost/
# 自己テスト→サンプルデータ生成→FTPアップロード。実値(パスワード)は .env から注入する。
set -euo pipefail
cd "$(dirname "$0")/.."

php scripts/check_khrpost.php >/dev/null || { echo "自己テスト失敗→デプロイ中止" >&2; exit 1; }

set -a; . /home/kojima/work/aixec/.env; set +a
PW=$(grep -m1 '^KHRPOST_DEMO_PASSWORD=' .env | cut -d= -f2)
SP=$(grep -m1 '^KHRPOST_STAFF_PASSWORD=' .env | cut -d= -f2)
[ -n "$PW" ] && [ -n "$SP" ] || { echo ".env に KHRPOST_DEMO_PASSWORD / KHRPOST_STAFF_PASSWORD がありません" >&2; exit 1; }

# デモ用データを一時ディレクトリに作り直す（毎回まっさらにする）
seed=$(mktemp -d)
php scripts/seed_demo.php "$seed" >/dev/null

remote="/web/proto_exbridge_jp/khrpost"
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }

cfg=$(mktemp)
sed -e "s/__KHP_DEMO_PASSWORD__/${PW}/" -e "s/__KHP_STAFF_PASSWORD__/${SP}/" demo/khrpost_config.php > "$cfg"

up public/khrpost.php khrpost.php
up "$cfg" khrpost_config.php
up demo/index.php index.php
up demo/.htaccess .htaccess
up public/khrpost_data/.htaccess khrpost_data/.htaccess
up "$seed/khrpost.sqlite" khrpost_data/khrpost.sqlite
up "$seed/employees.json" khrpost_data/employees.json   # 社員マスタ（kvgwcの代わり。デモ用）
up public/khrpost_assets/kurage_mascot.png khrpost_assets/kurage_mascot.png

rm -f "$cfg"; rm -rf "$seed"
echo "published: https://proto.exbridge.jp/khrpost/"
