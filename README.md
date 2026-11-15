# Система учета оборудования, лицензий и инвентаризации

Внутренняя корпоративная веб-система по ТЗ (Алматы, 2026): реестр IT-оборудования и лицензий,
выдача/возврат с PDF-актами и ЭЦП, инвентаризация (USB-сканер + мобильная PWA с offline),
импорт Excel, планы офисов, аудит, резервное копирование.

**Стек:** Symfony 7.4 LTS · PHP 8.3 · PostgreSQL 16 · Doctrine ORM · Symfony Messenger (Doctrine transport) ·
Twig · Dompdf · PhpSpreadsheet · Nginx · Docker Compose · PWA (Service Worker + IndexedDB).

## Быстрый старт (DEV)

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate -n
docker compose exec php bin/console app:seed --demo            # справочники + демо-данные
docker compose exec php bin/console app:user:create admin "Администратор"   # выводит временный пароль
```

- Приложение: http://localhost:8080 (при первом входе требуется смена пароля)
- Письма (mailpit): http://localhost:8025
- Пример файла импорта: `bin/console app:seed --excel=/var/www/storage/sample_import.xlsx`
- Тесты: `docker compose exec php bin/phpunit` (БД `assets_test` создается командами
  `bin/console doctrine:database:create --env=test` и `doctrine:migrations:migrate --env=test -n`)

После пересоздания контейнера `php` выполните `docker compose restart nginx`.

### Перенос на другой компьютер (с данными)

В архиве переноса папка `transfer/` содержит дамп БД (`db.dump`) и файлы хранилища (`storage.tgz`,
включая `keys/secrets.key`). На новом компьютере: установить и запустить Docker Desktop, распаковать архив
в путь без кириллицы и пробелов, запустить `deploy.cmd`. Скрипт соберет контейнеры, восстановит базу и
файлы, установит зависимости и откроет http://localhost:8080. Учетные записи и пароли — те же, что были.

## Развертывание (TEST / PROD)

Выделенная VM (4 vCPU, 8 ГБ RAM, 100 ГБ SSD + отдельный диск под backup), Ubuntu Server LTS, Docker Compose.

1. Код в `/opt/asset-inventory`; на сервере создать `.env.local`:
   `APP_ENV=prod`, `APP_SECRET`, `POSTGRES_PASSWORD`, `DATABASE_URL`, `MAILER_DSN=smtp://<внутренний SMTP>`,
   `DEFAULT_URI=https://assets.corp.local`, `ADMIN_ALERT_EMAIL`, `ORG_NAME`, `IT_CONTACTS`.
2. TLS-сертификат корпоративного УЦ: `/etc/asset-inventory/tls/{fullchain,privkey}.pem`.
3. `docker compose -f compose.yaml -f compose.prod.yaml up -d --build`
4. `docker compose exec php composer install --no-dev -o && bin/console doctrine:migrations:migrate -n && bin/console cache:warmup`
5. Диапазоны корпоративной сети — в `geo $corporate_net` (`docker/nginx/prod.conf`).
6. Cron на хосте:
   ```
   */10 * * * *  cd /opt/asset-inventory && docker compose exec -T php bin/console app:integration:process
   15 1 * * *    cd /opt/asset-inventory && docker compose exec -T php bin/console app:signatures:expire
   30 2 * * *    cd /opt/asset-inventory && ./docker/backup/backup.sh >> /var/log/asset-backup.log 2>&1
   ```
7. Мониторинг: `GET /health` (БД, очередь). Технические ошибки пишутся в «Администрирование → Ошибки»
   и отправляются на `ADMIN_ALERT_EMAIL`.

### Резервное копирование (п. 20)
`docker/backup/backup.sh` — ежедневный дамп PostgreSQL + архив документов, шифрование AES-256 (пароль в
`/etc/asset-inventory/backup.pass`), ротация 14/8/12, копия на другое устройство (`BACKUP_REMOTE`, rsync).
`docker/backup/restore.sh <файл>` — тестовое восстановление в отдельную БД `assets_restore_test`
(ежеквартальная проверка). Ключ шифрования секретов (`keys/secrets.key`) в архив не входит —
храните его копию отдельно.

## Роли
| Роль | Код |
|---|---|
| Администратор | `ROLE_ADMIN` |
| IT-специалист | `ROLE_IT` |
| Инициатор инвентаризации | `ROLE_INVENTORY_INITIATOR` |
| Член комиссии | `ROLE_COMMISSION` |
| Доступ к recovery/лицензионным ключам | `ROLE_SECRET_VIEWER` |
| Получатель оборудования | без учетной записи: подписывает акт по ссылке из письма |

## Соответствие ТЗ

| Раздел ТЗ | Реализация |
|---|---|
| 5.1 Авторизация | локальные учетные записи, политика паролей (12+ символов), смена первоначального пароля, блокировка после 5 неудачных входов на 15 мин + rate limit по IP, аудит входов, автоблокировка сессии по бездействию (`SESSION_IDLE_TIMEOUT`) |
| 5.2 Сотрудник | UUID, Entra Object ID, UPN, email, DisplayName, ФИО рус/англ, должность, подразделение, компания, офис/город, руководитель, статус, источник, дата синхронизации, ID 1С, extensionAttribute |
| 6 Модель активов | все обязательные поля; настраиваемые атрибуты типов (`asset_types`, `attribute_definitions`, `asset_attribute_values`); комплекты |
| 7 Статусы | 14 статусов, матрица переходов (`App\Enum\AssetStatus`); списанный выдается только после восстановления админом, в ремонте — не закрепляется |
| 8 Импорт Excel | загрузка → структура (авто-поиск строки заголовков) → сопоставление → анализ → предварительный отчет (строки, новые, совпадения, дубликаты, некорректные номера/даты, неизвестные статусы/места/сотрудники, старое→новое, действие) → правка/исключение строк, справочник соответствий → запись только с подтверждением → протокол |
| 9 Recovery keys | по умолчанию не переносятся (только признак, место хранения, ID устройства, дата проверки); при включении — отдельная таблица, libsodium, ключ вне БД, маскирование, отдельная роль + повторный ввод пароля, аудит каждого просмотра, в экспорт не попадают |
| 10 Сканирование и этикетки | USB-сканер в режиме клавиатуры с удержанием фокуса; результаты: найден / не найден / уже сканирован / другой офис / другое помещение / списан / некорректный код; блокировка повтора с временем и пользователем; этикетки PDF для Brother QL-810W (62×29, 62×40, 29×90) |
| 11 PWA и offline | установка из браузера, камера (BarcodeDetector; при наличии `public/js/vendor/zxing.min.js` — ZXing для iOS), Service Worker + Cache API, IndexedDB, UUID операций, статусы «сохранено локально / ожидает / синхронизировано / конфликт / ошибка», идемпотентная синхронизация, конфликты → расхождения для ручной проверки |
| 12 Инвентаризация | кампании с 9 статусами, охват (офисы/помещения/категории), комиссия, ответственные IT, снимок ожидаемого состава, расхождения, смена места только после подтверждения IT, авто «не найден», подтверждение комиссией, сводный отчет и итоговый PDF-акт |
| 13 Выдача/возврат и ЭЦП | выбор сотрудника с текущим перечнем, проверка доступности, операция + PDF-акт (SHA-256), уведомление, подписание через NCALayer (CMS/CAdES detached), проверка подписи (NCANode или базовая OpenSSL), подтверждение закрепления, срок подписания |
| 14 Уведомления | внутренний SMTP через очередь Messenger: операция, дата, полный перечень, выдано/возвращено, ссылка на акт, статус подписи, контакты IT; журнал и повтор отправки |
| 15 Локации и планы | иерархия страна→город→БЦ→офис→этаж→помещение, типы помещений, планы SVG/PNG≥2000px, области помещений (редактирует IT), количество активов и расхождений, переход к списку по клику |
| 16 Лицензии | все поля, места занято/всего, назначения сотрудникам и оборудованию, документы, зашифрованный ключ |
| 17 1С | поля `external_1c_id, source_system, sync_status, last_sync_at, sync_version, last_sync_error`; outbox `integration_events`, отключаемый модуль, файловый коннектор, API выгрузки |
| 18 Аудит и вложения | пользователь, время, действие, объект, было/стало, IP, сессия, источник, комментарий, документ; журнал защищен триггером от изменения/удаления; вложения PDF/JPG/PNG/DOCX/XLSX, проверка расширения и MIME, лимит, хранение вне public |
| 19 Нефункциональные | HTTPS, RBAC, CSRF (формы + заголовок для API), ORM/параметризованные запросы, ограничение по сети (nginx geo), trigram-индексы для поиска, журнал ошибок + уведомление администратора, среды DEV/TEST/PROD |

## Что требует решений заказчика (п. 24 ТЗ) и прототипные места

- **ЭЦП:** реализован прототип через NCALayer (модуль `basics`, фолбэк `commonUtils`). Для полной проверки
  цепочки НУЦ РК и отзыва (OCSP) нужен NCANode (`NCANODE_URL`). Без него ГОСТ-подписи получают статус
  «Требуется проверка» и подтверждаются администратором вручную. Формат (CMS над PDF) — согласовать.
- **1С:** формат обмена не определен — сейчас файловый коннектор (`storage/exchange/1c/out`).
- **Entra ID / SSO:** модель данных готова; OpenID Connect подключается после регистрации приложения.
- **extensionAttribute:** поле есть, номер атрибута не определен.
- **Recovery keys:** хранение выключено, пока ИБ не согласует (`bin/console app:secrets:generate-key` включает).
- **Этикетки:** размер/шаблон уточняются; прямая печать через Brother SDK — отдельный этап.
- **iOS-камера:** Safari не поддерживает BarcodeDetector — положите сборку ZXing в `public/js/vendor/zxing.min.js`
  (или используйте Bluetooth-сканер / ручной ввод).
