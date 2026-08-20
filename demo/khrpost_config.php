<?php
// デモ用設定（deploy_demo.sh がプレースホルダを .env の値へ置換して配置する）
define('KHP_TITLE', 'Kurage HR Post');
define('KHP_BRAND_COLOR', '#0089a1');
define('KHP_PASSWORD', '__KHP_DEMO_PASSWORD__');
define('KHP_STAFF_PASSWORD', '__KHP_STAFF_PASSWORD__');
// デモは kvgwc を持たないので、同じ形式の employees.json を置いて読ませている
define('KHP_EMPLOYEES_DIR', __DIR__ . '/khrpost_data');
define('KHP_GRADE_TABLE', 'S:110,A:100,B:85,C:70');
define('KHP_RATE_CAP', 120);
define('KHP_DEMO', true);
define('KHP_DATA_DIR', __DIR__ . '/khrpost_data');
