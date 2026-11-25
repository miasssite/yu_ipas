# IP Anti Spam (yu_ipas)

Кастомный модуль Drupal 11: блокировка доступа к сайту по IP, странам и областям России.

## Возможности

- **Два режима**: blacklist (блокировать выбранные страны/области) и whitelist (разрешать только выбранные области РФ) — переключаются в настройках.
- **Правила**: отдельный IP, CIDR-диапазон, целая страна (ISO), область РФ (ISO 3166-2:RU, полный чек-лист 85 регионов в модуле).
- **Исключения**: IP, CIDR, маски User-Agent. Предустановлены боты Yandex/Google/Bing и SEO-сервисы (Ahrefs, Semrush, MJ12). Для поисковых ботов включена проверка по forward-confirmed reverse DNS — защита от подмены User-Agent.
- **Статистика**: переходы/блокировки/исключения, % блокировок, топ стран и областей, топ IP, последние события.
- **Меню**: отдельный раздел «IP Anti Spam» в админке (`/admin/ipas`) с табами: Statistics, Rules, Exceptions, Settings.

## Технические детали

- Блокировка — HTTP middleware с приоритетом **300** (выше `page_cache`), поэтому заблокированный IP не может получить кешированную анонимную страницу. Ответ — 403.
- Пользователи с правом **bypass yu_ipas** никогда не блокируются.
- Гео-данные — MaxMind GeoLite2-City (локальная база, кеш на 30 дней на IP).
- **Загрузка базы из нескольких источников** (без регистрации): официальной MaxMind (нужен ключ),
  зеркала P3TERX (GitHub) и jsDelivr (CDN), DB-IP Lite (без регистрации), произвольный URL.
  Скачанный файл проверяется читателем MaxMind перед установкой (защита от битых/подменённых файлов).
- Лог: `yu_ipas_log`; «passed» логируется с семплированием (первый визит IP за день), чистка по cron (`log_retention_days`).

## Защита от случайной блокировки (аварийный доступ)

- **«Никогда не блокировать администраторов»** (вкл. по умолчанию): пользователь №1, роль `administrator`
  и обладатели права «Обход блокировки IP Anti Spam» не блокируются никакими правилами.
- **Аварийный URL**: `/yu-ipas/rescue/<токен>` (показывается в Настройки → Аварийный доступ).
  Работает с любого IP, даже заблокированного: отключает блокировку и добавляет IP посетителя
  в доверенные исключения. Сохраните URL в менеджере паролей. На странице настроек можно
  перегенерировать токен.
- **Drush-команды**: `drush yu-ipas:disable` (мгновенно снять блокировку) и `drush yu-ipas:enable`.

## Установка

1. `composer require maxmind-db/reader` (уже подключено в корне проекта).
2. Включить модуль: `drush en yu_ipas`.
3. Скачать гео-базу одним из способов (см. «Источники базы» ниже):
   - без регистрации: `drush yu-ipas:download-db --source=p3terx`
   - с бесплатным ключом MaxMind: `drush yu-ipas:download-db --key=ВАШ_КЛЮЧ`
   - кнопкой **«Скачать базу сейчас»** на странице `/admin/ipas/settings`.
4. На странице `/admin/ipas/settings` включить блокировку, выбрать режим и добавить правила через «Bulk import».

## Руссификация

Все строки интерфейса проходят через `t()`/`dt()` и переводимы. Русский перевод в комплекте:
`translations/yu_ipas.ru.po`. При включённом модуле **Locale** и добавленном языке «Русский»
перевод импортируется автоматически при установке модуля. Переимпортировать вручную:

```bash
drush locale:import ru translations/yu_ipas.ru.po --type=replace --override=none
drush cr
```

## Источники базы

Выбор источника — в настройках (`/admin/ipas/settings`, раздел GeoIP) или опцией `--source` Drush-команды.
По умолчанию — официальный MaxMind; для продакшена без ключа удобно зеркало.

| Источник | Регистрация | Формат | Обновление | URL |
|----------|-------------|--------|------------|-----|
| `maxmind` | нужен бесплатный ключ | tar.gz | еженедельно | `download.maxmind.com/geoip/databases/GeoLite2-City/download` |
| `p3terx` | не нужна | .mmdb | каждые 2–3 дня | `raw.githubusercontent.com/P3TERX/GeoLite.mmdb/download/GeoLite2-City.mmdb` |
| `jsdelivr` | не нужна | .mmdb.gz | с npm-пакетом | `cdn.jsdelivr.net/npm/geolite2-city/GeoLite2-City.mmdb.gz` |
| `dbip` | не нужна | .mmdb.gz (месячный) | ежемесячно | `download.db-ip.com/free/dbip-city-lite-YYYY-MM.mmdb.gz` |
| `custom` | не нужна | .mmdb / .mmdb.gz | — | любой прямой URL (своё зеркало) |

Примеры:

```bash
drush yu-ipas:download-db --source=p3terx          # зеркало GitHub, без ключа
drush yu-ipas:download-db --source=jsdelivr        # CDN-зеркало
drush yu-ipas:download-db --source=dbip            # DB-IP Lite за текущий месяц
drush yu-ipas:download-db --key=КЛЮЧ               # официальный MaxMind
drush yu-ipas:download-db --source=custom          # URL из настроек модуля
```

Примечания:
- Все источники должны отдавать базу в формате MaxMind .mmdb (City) — модуль читает её
  через `maxmind-db/reader`. Формат определяется по magic bytes: raw .mmdb, .mmdb.gz и tar.gz.
- Скачанный файл всегда валидируется читателем MaxMind (тестовый lookup 8.8.8.8) перед
  заменой действующей базы; при ошибке старая база сохраняется.
- **IP2Location LITE** (нужна регистрация, свой формат .DAT/.BIN) и **Sypex Geo**
  (формат SxGeo.dat без регистрации) не поддерживаются напрямую — нужен отдельный
  движок чтения. Если нужен другой провайдер — выложите его .mmdb и используйте источник `custom`.

## Drush-команды

- `yu-ipas:download-db [--source=maxmind|p3terx|jsdelivr|dbip|custom] [--key=КЛЮЧ]` — скачать и установить GeoLite2-City.mmdb из выбранного источника.
- `yu-ipas:status` — статус базы, список источников (текущий помечен `*`) и блокировки.
- `yu-ipas:disable` / `yu-ipas:enable` — аварийное выключение/включение блокировки.

## Русификация

Полный перевод интерфейса на русский язык — файл
[translations/yu_ipas.ru.po](translations/yu_ipas.ru.po) (около 120 строк).
Перевод импортируется автоматически при включении русского языка
(Interface Translation сканирует каталог `translations/`), либо вручную:

```bash
drush locale:import ru /var/www/html/web/modules/custom/yu_ipas/translations/yu_ipas.ru.po --type=customized --override=customized
```

## Структура

- `yu_ipas_rules` — правила (ip / cidr / country / region)
- `yu_ipas_exceptions` — исключения (ip / cidr / ua + rDNS-верификация)
- `yu_ipas_log` — статистика решений
