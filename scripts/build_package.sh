#!/usr/bin/env bash
# kappstore で配布するzipを作る。設定の実物・デモデータ・社員マスタは入れない。
set -euo pipefail
cd "$(dirname "$0")/.."

php scripts/check_khrpost.php >/dev/null || { echo "自己テスト失敗→パッケージ作成中止" >&2; exit 1; }

mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/khrpost-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/khrpost.php public/khrpost_config.php.example \
  public/khrpost_assets public/khrpost_data/.htaccess \
  scripts/check_khrpost.php scripts/seed_demo.php \
  skills README.md DESIGN.md LICENSE \
  -x '*.sqlite' -x '*.json' -x '*.log' >/dev/null

# 実値が混ざっていないことを確認してから世に出す
if unzip -p "$zip" 'public/*' 'scripts/*' 2>/dev/null | grep -qiE 'demo2026|staff2026|FTP_PASS|password.*=.*[a-z0-9]{8}'; then
  echo "配布物に実値らしき文字列が入っています→中止" >&2; rm -f "$zip"; exit 1
fi

echo "built: $zip ($(du -h "$zip" | cut -f1))"
unzip -l "$zip" | tail -n +4 | head -n -2 | awk '{print "  " $4}'
