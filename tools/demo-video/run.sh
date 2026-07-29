#!/usr/bin/env bash
#
# Собирает демо-видео флоу оператора.
#
# Playwright запускается разовым контейнером и ходит на приложение по
# порту хоста. От хоста не нужно ничего, кроме docker; зависимости
# приложения не меняются.
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

PLAYWRIGHT_IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.62.0-noble}"
APP_CONTAINER="${DEMO_APP_CONTAINER:-$(basename "$project_dir")-app-1}"
DEMO_EMAIL="${DEMO_EMAIL:-demo-operator@example.com}"
DEMO_PASSWORD="${DEMO_PASSWORD:-demo-operator}"

if [ -f .env ]; then
    OPENAI_API_KEY="${OPENAI_API_KEY:-$(grep -E '^OPENAI_API_KEY=' .env | cut -d= -f2- | tr -d '"'"'"'')}"
fi

if [ -z "${OPENAI_API_KEY:-}" ]; then
    echo "OPENAI_API_KEY не задан — озвучивать нечем." >&2
    exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -qx "$APP_CONTAINER"; then
    echo "Контейнер $APP_CONTAINER не запущен — поднимите стек: make up" >&2
    exit 1
fi

# Ходим на приложение через порт хоста, а не по имени сервиса: Chromium
# принудительно поднимает http:// до https:// для любого хоста, кроме
# localhost, и адрес вида http://app:8000 обрывается на SSL.
APP_PORT="${APP_PORT:-$(grep -E '^APP_PORT=' .env | cut -d= -f2- | tr -d '"'"'"'')}"
APP_PORT="${APP_PORT:-8800}"

# Оператор для демо: отдельная учётка, чтобы не трогать реальные и не
# спрашивать чужой пароль. Команда идемпотентна.
echo "→ Проверяю демо-оператора ($DEMO_EMAIL)"
docker exec -e XDG_CONFIG_HOME=/tmp "$APP_CONTAINER" php artisan tinker --execute \
    "\App\Models\User::updateOrCreate(['email' => '${DEMO_EMAIL}'], ['name' => 'Демо-оператор', 'password' => bcrypt('${DEMO_PASSWORD}')]);"

docker run --rm -i \
    --network host \
    --ipc=host \
    -v "$project_dir:/work" \
    -w /work/tools/demo-video \
    -e OPENAI_API_KEY="$OPENAI_API_KEY" \
    -e DEMO_BASE_URL="${DEMO_BASE_URL:-http://localhost:$APP_PORT}" \
    -e DEMO_EMAIL="$DEMO_EMAIL" \
    -e DEMO_PASSWORD="$DEMO_PASSWORD" \
    -e DEMO_TTS_VOICE="${DEMO_TTS_VOICE:-onyx}" \
    -e HOST_OWNER="$(id -u):$(id -g)" \
    "$PLAYWRIGHT_IMAGE" \
    bash -lc '
        set -euo pipefail

        if ! command -v ffmpeg >/dev/null; then
            echo "→ Ставлю ffmpeg"
            apt-get update -qq && apt-get install -y -qq ffmpeg >/dev/null
        fi

        if [ ! -d node_modules/playwright ]; then
            echo "→ Ставлю playwright"
            npm install --no-audit --no-fund --loglevel=error
        fi

        echo "→ Озвучка"
        node tts.mjs

        echo "→ Запись экрана"
        node record.mjs

        echo "→ Сборка"
        node build.mjs

        # Контейнер работает root-ом (ffmpeg ставится apt-ом), поэтому
        # результат отдаём владельцу проекта — иначе видео не удалить и
        # не перезаписать без sudo.
        chown -R "$HOST_OWNER" /work/storage/app/demo-video node_modules
    '

echo
echo "Готово: storage/app/demo-video/operator-flow.mp4"
