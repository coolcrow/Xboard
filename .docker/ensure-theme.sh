#!/bin/sh
# bundle 镜像启动钩子：确保内置主题就位并启用（幂等）。
# 覆盖两个历史痛点：
#   1. 容器重建清空 /www/public/theme 下的主题副本 → web.php 探测不到主题时
#      会把 frontend_theme 回退为官方主题 → 此处先补齐副本再放行启动
#   2. frontend_theme 设置被回退后无人恢复 → 此处无条件恢复为镜像内置主题
set -e
THEME_NAME="${THEME_NAME:-xboard-web}"

if [ -d "/www/theme/$THEME_NAME" ] && [ ! -d "/www/public/theme/$THEME_NAME" ]; then
    echo "[ensure-theme] initializing public copy of $THEME_NAME"
    cp -r "/www/theme/$THEME_NAME" "/www/public/theme/$THEME_NAME"
    chown -R www:www "/www/public/theme/$THEME_NAME" || true
fi

php /www/artisan tinker --execute="admin_setting(['frontend_theme' => '$THEME_NAME']);" >/dev/null 2>&1 \
    && echo "[ensure-theme] frontend_theme = $THEME_NAME" \
    || echo "[ensure-theme] warning: could not set frontend_theme (first boot before migrations?)"
