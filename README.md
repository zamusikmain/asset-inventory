# Asset Inventory — учёт оборудования, лицензий и инвентаризации

**[Русский](#русский)** · **[English](#english)**

---

# Русский

Внутренняя корпоративная веб-система по ТЗ (Алматы, 2026): реестр IT-оборудования и лицензий, выдача/возврат с
PDF-актами и ЭЦП, инвентаризация (USB-сканер + мобильная PWA с offline), импорт Excel, планы офисов, аудит, резервное
копирование.

## 1. Что это и зачем

IT-отдел ведёт в системе всё оборудование и лицензии компании: где каждая единица находится, за кем закреплена, в
каком состоянии. Выдача и возврат оформляются PDF-актом, который сотрудник подписывает ЭЦП по ссылке из письма.
Инвентаризация проводится кампаниями: комиссия сканирует метки USB-сканером или телефоном (работает и без сети),
система сама находит расхождения и формирует итоговый акт.

## 2. Возможности

- **Реестр активов:** все поля ТЗ, настраиваемые атрибуты по типам, комплекты, 14 статусов с матрицей переходов,
  перемещения, документы, этикетки PDF для Brother QL-810W (62×29, 62×40, 29×90), штрихкоды SVG, экспорт.
- **Сотрудники:** UUID, Entra Object ID, UPN, ФИО рус/англ, должность, подразделение, руководитель, статус, ID 1С;
  уведомление сотрудника о закреплённом за ним оборудовании.
- **Выдача и возврат:** проверка доступности, операция + PDF-акт (SHA-256), письмо, подписание через NCALayer
  (CMS/CAdES detached), проверка подписи (NCANode или базовая OpenSSL), подтверждение, срок подписания.
- **Инвентаризация:** кампании с 9 статусами, охват (офисы/помещения/категории), комиссия, снимок ожидаемого состава,
  сканирование с 11 результатами, расхождения, подтверждение комиссией, отчёт и PDF-акт.
- **PWA и offline:** установка из браузера, камера (BarcodeDetector; ZXing для iOS), Service Worker + IndexedDB,
  идемпотентная синхронизация, конфликты → расхождения для ручной проверки.
- **Импорт Excel:** загрузка → поиск заголовков → сопоставление → анализ → предварительный отчёт → правка строк →
  запись только с подтверждением → протокол.
- **Локации и планы:** страна → город → БЦ → офис → этаж → помещение, планы SVG/PNG с областями помещений.
- **Лицензии:** места занято/всего, назначения сотрудникам и оборудованию, документы, зашифрованный ключ.
- **Секреты (recovery/лицензионные ключи):** libsodium, ключ вне БД, маскирование, отдельная роль + повторный ввод
  пароля, аудит каждого просмотра. По умолчанию выключено.
- **Интеграция с 1С:** outbox `integration_events`, файловый коннектор, API выгрузки.
- **Уведомления:** SMTP через очередь Messenger, журнал и повторная отправка.
- **Безопасность:** локальные учётки, политика паролей (12+), блокировка после 5 неудачных входов на 15 мин +
  rate limit по IP, тайм-аут сессии, RBAC, CSRF, ограничение по сети (nginx geo), аудит, защищённый триггером.
- **Администрирование:** пользователи, аудит, журнал ошибок, уведомления, интеграции, справочник соответствий.
- **Резервное копирование:** ежедневный зашифрованный дамп, ротация, копия на другое устройство, тестовое
  восстановление.

## 3. Стек технологий

| Слой | Технологии |
| --- | --- |
| Backend | PHP 8.3, Symfony 7.4 LTS, Doctrine ORM 3, Symfony Messenger (Doctrine transport), Symfony Security, Validator, Mailer |
| Шаблоны и UI | Twig, PWA (Service Worker + Cache API + IndexedDB), BarcodeDetector / ZXing |
| Документы | Dompdf 3 (PDF-акты, этикетки), PhpSpreadsheet 5 (импорт/экспорт Excel) |
| Криптография | libsodium (секреты), OpenSSL / NCANode (проверка ЭЦП), NCALayer (подписание) |
| База данных | PostgreSQL 16 (trigram-индексы для поиска, триггер защиты журнала аудита) |
| Веб-сервер | Nginx 1.27 |
| Почта (dev) | Mailpit |
| Инфраструктура | Docker Compose: `php`, `worker`, `nginx`, `db`, `mailpit`; `compose.prod.yaml` для TEST/PROD |
| Тесты | PHPUnit 12 (37 тестов) |

## 4. Архитектура

```text
Браузер / PWA (телефон)            USB-сканер (режим клавиатуры)
        │                                   │
        ▼                                   ▼
     Nginx ──► PHP-FPM (Symfony 7.4) ◄──────┘
                 ├─ контроллеры: активы, сотрудники, выдача, инвентаризация, импорт, лицензии,
                 │                локации, админка, API v1, PWA, подписание
                 ├─ сервисы: AssetService, HandoverService, InventoryService, Import, Integration,
                 │           LabelService, PdfRenderer, SecretVault, Signature, AuditLogger
                 ├─ Doctrine ORM ──► PostgreSQL 16
                 └─ Messenger (Doctrine transport) ──► worker ──► SMTP (уведомления)
Сотрудник ──ссылка из письма──► /sign/{id}/{token} ──► NCALayer (на его ПК) ──► проверка NCANode/OpenSSL
Cron: интеграции 1С, истечение подписей, backup
```

## 5. Структура проекта

```text
src/
  Controller/      Admin, Api, Asset, Catalog, Dashboard, Employee, Handover, Import, Inventory,
                   License, Location, Pwa, Security, Sign
  Entity/          Asset, AssetAssignment, AssetAttributeValue, AssetBundle, AssetMovement, AssetType,
                   AttributeDefinition, AuditLog, Department, DigitalSignature, Employee, HandoverDocument,
                   ImportJob, ImportRow, IntegrationEvent, InventoryCampaign, InventoryCommissionMember,
                   InventoryDiscrepancy, InventoryScan, License, LicenseAssignment, Location, Notification,
                   OfficePlan, SecretValue, StoredFile, SystemError, User, ValueMapping
  Enum/            AssetStatus, CampaignStatus, LocationType, RoomType, ScanResult
  Service/         AssetService, HandoverService, InventoryService, LabelService, NotificationService,
                   PdfRenderer, SecretVault, SignLinkService, FileStorage, AuditLogger, Import/, Integration/, Signature/
  Security/        PasswordPolicy, LoginSubscriber, SessionGuardSubscriber, UserChecker
  Command/         app:user:create, app:user:password, app:seed, app:secrets:generate-key,
                   app:integration:process, app:signatures:expire
  Form/, Repository/, Message/, MessageHandler/, EventSubscriber/, Twig/, Util/
templates/         admin, asset, catalog, dashboard, employee, handover, import, inventory, license, location,
                   pdf (акты, этикетки), pwa, scan, security, sign, email
migrations/        Doctrine-миграции
docker/            php (Dockerfile, php.ini), nginx (dev/prod), backup (backup.sh, restore.sh)
tests/             PHPUnit
compose.yaml, compose.prod.yaml, start.ps1/start.cmd, deploy.ps1/deploy.cmd
```

## 6. Модель данных

| Группа | Сущности |
| --- | --- |
| Активы | `Asset`, `AssetType`, `AttributeDefinition`, `AssetAttributeValue`, `AssetBundle`, `AssetAssignment`, `AssetMovement` |
| Сотрудники | `Employee`, `Department`, `User` |
| Выдача | `HandoverDocument`, `DigitalSignature` |
| Инвентаризация | `InventoryCampaign`, `InventoryCommissionMember`, `InventoryScan`, `InventoryDiscrepancy` |
| Лицензии | `License`, `LicenseAssignment` |
| Локации | `Location`, `OfficePlan` |
| Импорт | `ImportJob`, `ImportRow`, `ValueMapping` |
| Служебные | `AuditLog`, `Notification`, `IntegrationEvent`, `SecretValue`, `StoredFile`, `SystemError` |

**Статусы актива (14):** Created, Review, InStock, Reserved, Assigned, Installed, InTransit, Repair, TempIssued,
NotFound, Lost, Damaged, WrittenOff, Archived. Списанный выдаётся только после восстановления админом, в ремонте — не
закрепляется.

**Статусы кампании (9):** Draft, Preparation, Started, InProgress, AwaitingReview, WithCommission, Approved, Closed,
Cancelled.

**Результаты сканирования (11):** FoundExpected, FoundOtherRoom, FoundOtherOffice, NotFound, Unknown, Damaged,
LabelDamaged, NeedsClarification, WrittenOffFound, Duplicate, InvalidCode.

**Локации:** Country, City, BusinessCenter, Office, Floor, Room; типы помещений: Cabinet, Warehouse, ServerRoom,
Technical, Meeting, Other.

## 7. Роли

| Роль | Код |
|---|---|
| Администратор | `ROLE_ADMIN` |
| IT-специалист | `ROLE_IT` |
| Инициатор инвентаризации | `ROLE_INVENTORY_INITIATOR` |
| Член комиссии | `ROLE_COMMISSION` |
| Доступ к recovery/лицензионным ключам | `ROLE_SECRET_VIEWER` |
| Получатель оборудования | без учётной записи: подписывает акт по ссылке из письма |

## 8. Маршруты

| Раздел | Пути |
| --- | --- |
| Главная и здоровье | `/`, `/health` |
| Вход | `/login`, `/logout`, `/password` |
| Активы | `/assets`, `/assets/new`, `/assets/{id}`, `.../edit`, `.../status`, `.../move`, `.../documents`, `.../label`, `.../secret`, `/assets/labels`, `/assets/export`, `/assets/barcode/{code}.svg` |
| Справочники | `/catalog/types`, `/catalog/types/{id}/attributes`, `/bundles`, `/bundles/{id}` |
| Сотрудники | `/employees`, `/employees/new`, `/employees/{id}`, `.../edit`, `.../notify` |
| Выдача и акты | `/handover/issue`, `/handover/return`, `/documents`, `/documents/act/{id}` (+ `/pdf`, `/resend`, `/sign`), `/documents/signature/{id}/confirm` |
| Подписание (без входа) | `/sign/{id}/{token}`, `.../pdf`, `.../data`, `.../submit` |
| Инвентаризация | `/inventory`, `/inventory/new`, `/inventory/{id}`, `.../status`, `.../scan`, `.../mark`, `.../confirm`, `.../report`, `.../act`, `/inventory/discrepancies/{id}/resolve` |
| Импорт | `/import`, `/import/{id}/mapping`, `.../report`, `.../commit`, `.../cancel`, `.../protocol` |
| Лицензии | `/licenses`, `/licenses/new`, `/licenses/{id}`, `.../assign`, `.../documents`, `.../secret` |
| Локации | `/locations`, `/locations/{id}`, `.../plans`, `/locations/plans/{id}` (+ `/image`, `/regions`) |
| PWA | `/m`, `/scan`, `/manifest.webmanifest`, `/offline` |
| Администрирование | `/admin/users`, `/admin/audit`, `/admin/errors`, `/admin/notifications`, `/admin/integrations`, `/admin/mappings` |
| API v1 | `/api/v1/lookup`, `/api/v1/assets`, `/api/v1/assets/{code}`, `/api/v1/employees`, `/api/v1/inventory/campaigns`, `/api/v1/inventory/{id}/pack`, `/api/v1/inventory/{id}/scan`, `/api/v1/inventory/sync`, `/api/v1/integration/1c/assets` |

## 9. Конфигурация (`.env`, на сервере — `.env.local`)

| Переменная | Назначение |
| --- | --- |
| `APP_ENV`, `APP_SECRET` | Окружение и секрет Symfony |
| `DEFAULT_URI` | Адрес системы (ссылки в письмах) |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `DATABASE_URL` | База данных |
| `MESSENGER_TRANSPORT_DSN` | Очередь (Doctrine) |
| `MAILER_DSN`, `MAIL_FROM` | Почта |
| `ORG_NAME`, `IT_CONTACTS` | Организация и контакты IT в письмах и актах |
| `STORAGE_DIR`, `UPLOAD_MAX_BYTES` | Хранилище файлов и лимит загрузки |
| `SECRETS_KEY_FILE` | Ключ шифрования секретов (вне БД) |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCK_MINUTES`, `SESSION_IDLE_TIMEOUT` | Блокировка входа и тайм-аут сессии |
| `NCANODE_URL`, `SIGNATURE_ACCEPT_BASIC`, `SIGN_DEADLINE_DAYS` | Проверка ЭЦП и срок подписания |
| `ADMIN_ALERT_EMAIL` | Почта для технических ошибок |

## 10. Быстрый старт (DEV)

Работает только через Docker (PHP на хосте не нужен). На Windows можно запустить
`powershell -ExecutionPolicy Bypass -File .\start.ps1` (`-Rebuild` — пересобрать, `-NoBrowser` — без браузера).

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate -n
docker compose exec php bin/console app:seed --demo            # справочники + демо-данные
docker compose exec php bin/console app:user:create admin "Администратор"   # выводит временный пароль
```

- Приложение: http://localhost:8080 (при первом входе требуется смена пароля)
- Письма (Mailpit): http://localhost:8025
- Пример файла импорта: `bin/console app:seed --excel=/var/www/storage/sample_import.xlsx`
- Тесты: `docker compose exec php bin/phpunit` (БД `assets_test`: `bin/console doctrine:database:create --env=test`
  и `doctrine:migrations:migrate --env=test -n`)

**Особенности окружения:**

- После пересоздания контейнера `php` выполните `docker compose restart nginx` (иначе 502).
- `vendor/` и `var/` лежат в именованных томах (bind mount на Windows слишком медленный).
- Если в Docker закончились адресные пулы, compose использует подсеть `DOCKER_SUBNET` (10.231.10.0/24).
- Если Docker Desktop завис («no route to host»): `wsl --shutdown` и перезапуск Docker Desktop.

### Перенос на другой компьютер (с данными)

В архиве переноса папка `transfer/` содержит дамп БД (`db.dump`) и файлы хранилища (`storage.tgz`, включая
`keys/secrets.key`). На новом компьютере: установить и запустить Docker Desktop, распаковать архив в путь без
кириллицы и пробелов, запустить `deploy.cmd`. Скрипт соберёт контейнеры, восстановит базу и файлы, установит
зависимости и откроет http://localhost:8080. Учётные записи и пароли — те же.

## 11. Развёртывание (TEST / PROD)

Выделенная VM (4 vCPU, 8 ГБ RAM, 100 ГБ SSD + отдельный диск под backup), Ubuntu Server LTS, Docker Compose.

1. Код в `/opt/asset-inventory`; на сервере создать `.env.local`: `APP_ENV=prod`, `APP_SECRET`, `POSTGRES_PASSWORD`,
   `DATABASE_URL`, `MAILER_DSN=smtp://<внутренний SMTP>`, `DEFAULT_URI=https://assets.corp.local`,
   `ADMIN_ALERT_EMAIL`, `ORG_NAME`, `IT_CONTACTS`.
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
7. Мониторинг: `GET /health` (БД, очередь). Технические ошибки пишутся в «Администрирование → Ошибки» и
   отправляются на `ADMIN_ALERT_EMAIL`.

### Резервное копирование (п. 20 ТЗ)

`docker/backup/backup.sh` — ежедневный дамп PostgreSQL + архив документов, шифрование AES-256 (пароль в
`/etc/asset-inventory/backup.pass`), ротация 14/8/12, копия на другое устройство (`BACKUP_REMOTE`, rsync).
`docker/backup/restore.sh <файл>` — тестовое восстановление в отдельную БД `assets_restore_test` (ежеквартальная
проверка). Ключ шифрования секретов (`keys/secrets.key`) в архив не входит — храните его копию отдельно.

## 12. Соответствие ТЗ

| Раздел ТЗ | Реализация |
|---|---|
| 5.1 Авторизация | локальные учётные записи, политика паролей (12+ символов), смена первоначального пароля, блокировка после 5 неудачных входов на 15 мин + rate limit по IP, аудит входов, автоблокировка сессии по бездействию (`SESSION_IDLE_TIMEOUT`) |
| 5.2 Сотрудник | UUID, Entra Object ID, UPN, email, DisplayName, ФИО рус/англ, должность, подразделение, компания, офис/город, руководитель, статус, источник, дата синхронизации, ID 1С, extensionAttribute |
| 6 Модель активов | все обязательные поля; настраиваемые атрибуты типов (`asset_types`, `attribute_definitions`, `asset_attribute_values`); комплекты |
| 7 Статусы | 14 статусов, матрица переходов (`App\Enum\AssetStatus`); списанный выдаётся только после восстановления админом, в ремонте — не закрепляется |
| 8 Импорт Excel | загрузка → структура (авто-поиск строки заголовков) → сопоставление → анализ → предварительный отчёт (строки, новые, совпадения, дубликаты, некорректные номера/даты, неизвестные статусы/места/сотрудники, старое→новое, действие) → правка/исключение строк, справочник соответствий → запись только с подтверждением → протокол |
| 9 Recovery keys | по умолчанию не переносятся (только признак, место хранения, ID устройства, дата проверки); при включении — отдельная таблица, libsodium, ключ вне БД, маскирование, отдельная роль + повторный ввод пароля, аудит каждого просмотра, в экспорт не попадают |
| 10 Сканирование и этикетки | USB-сканер в режиме клавиатуры с удержанием фокуса; результаты: найден / не найден / уже сканирован / другой офис / другое помещение / списан / некорректный код; блокировка повтора с временем и пользователем; этикетки PDF для Brother QL-810W (62×29, 62×40, 29×90) |
| 11 PWA и offline | установка из браузера, камера (BarcodeDetector; при наличии `public/js/vendor/zxing.min.js` — ZXing для iOS), Service Worker + Cache API, IndexedDB, UUID операций, статусы «сохранено локально / ожидает / синхронизировано / конфликт / ошибка», идемпотентная синхронизация, конфликты → расхождения для ручной проверки |
| 12 Инвентаризация | кампании с 9 статусами, охват (офисы/помещения/категории), комиссия, ответственные IT, снимок ожидаемого состава, расхождения, смена места только после подтверждения IT, авто «не найден», подтверждение комиссией, сводный отчёт и итоговый PDF-акт |
| 13 Выдача/возврат и ЭЦП | выбор сотрудника с текущим перечнем, проверка доступности, операция + PDF-акт (SHA-256), уведомление, подписание через NCALayer (CMS/CAdES detached), проверка подписи (NCANode или базовая OpenSSL), подтверждение закрепления, срок подписания |
| 14 Уведомления | внутренний SMTP через очередь Messenger: операция, дата, полный перечень, выдано/возвращено, ссылка на акт, статус подписи, контакты IT; журнал и повтор отправки |
| 15 Локации и планы | иерархия страна→город→БЦ→офис→этаж→помещение, типы помещений, планы SVG/PNG≥2000px, области помещений (редактирует IT), количество активов и расхождений, переход к списку по клику |
| 16 Лицензии | все поля, места занято/всего, назначения сотрудникам и оборудованию, документы, зашифрованный ключ |
| 17 1С | поля `external_1c_id, source_system, sync_status, last_sync_at, sync_version, last_sync_error`; outbox `integration_events`, отключаемый модуль, файловый коннектор, API выгрузки |
| 18 Аудит и вложения | пользователь, время, действие, объект, было/стало, IP, сессия, источник, комментарий, документ; журнал защищён триггером от изменения/удаления; вложения PDF/JPG/PNG/DOCX/XLSX, проверка расширения и MIME, лимит, хранение вне public |
| 19 Нефункциональные | HTTPS, RBAC, CSRF (формы + заголовок для API), ORM/параметризованные запросы, ограничение по сети (nginx geo), trigram-индексы для поиска, журнал ошибок + уведомление администратора, среды DEV/TEST/PROD |

## 13. Статус и что требует решений заказчика (п. 24 ТЗ)

| Ветка | Что в ней |
| --- | --- |
| `main` | Актуальный код (в Git с 2026-11-15) |

Все разделы ТЗ 5–19 реализованы; прототипные места и открытые вопросы:

- **ЭЦП:** прототип через NCALayer (модуль `basics`, фолбэк `commonUtils`). Для полной проверки цепочки НУЦ РК и
  отзыва (OCSP) нужен NCANode (`NCANODE_URL`). Без него ГОСТ-подписи получают статус «Требуется проверка» и
  подтверждаются администратором вручную. Формат (CMS над PDF) — согласовать.
- **1С:** формат обмена не определён — сейчас файловый коннектор (`storage/exchange/1c/out`).
- **Entra ID / SSO:** модель данных готова; OpenID Connect подключается после регистрации приложения.
- **extensionAttribute:** поле есть, номер атрибута не определён.
- **Recovery keys:** хранение выключено, пока ИБ не согласует (`bin/console app:secrets:generate-key` включает).
- **Этикетки:** размер/шаблон уточняются; прямая печать через Brother SDK — отдельный этап.
- **iOS-камера:** Safari не поддерживает BarcodeDetector — положите сборку ZXing в `public/js/vendor/zxing.min.js`
  (или используйте Bluetooth-сканер / ручной ввод).

## 14. Планы

- [ ] Подключить NCANode для полной проверки ЭЦП.
- [ ] Согласовать формат обмена с 1С.
- [ ] Подключить Entra ID (OpenID Connect).
- [ ] Развернуть TEST/PROD на выделенной VM.
- [ ] Прямая печать этикеток через Brother SDK.

---

# English

An internal corporate web system built to a specification (Almaty, 2026): a registry of IT equipment and licenses,
issue/return with PDF acts and e-signatures, stocktaking (USB scanner + an offline-capable mobile PWA), Excel import,
office floor plans, audit and backups.

## 1. What it is and why

The IT department keeps all company equipment and licenses in the system: where each item is, who it is assigned to and
its condition. Issue and return produce a PDF act that the employee signs with an e-signature via a link in an email.
Stocktaking runs as campaigns: a commission scans labels with a USB scanner or a phone (works offline too), and the
system finds discrepancies and produces the final act.

## 2. Features

- **Asset registry:** all specification fields, per-type custom attributes, bundles, 14 statuses with a transition
  matrix, moves, documents, PDF labels for Brother QL-810W (62×29, 62×40, 29×90), SVG barcodes, export.
- **Employees:** UUID, Entra Object ID, UPN, Russian/English full names, position, department, manager, status, 1C ID;
  notifying an employee about their assigned equipment.
- **Issue and return:** availability check, operation + PDF act (SHA-256), email, signing via NCALayer (CMS/CAdES
  detached), signature verification (NCANode or basic OpenSSL), confirmation, signing deadline.
- **Stocktaking:** campaigns with 9 statuses, scope (offices/rooms/categories), commission, expected-inventory snapshot,
  scanning with 11 results, discrepancies, commission approval, report and PDF act.
- **PWA and offline:** install from the browser, camera (BarcodeDetector; ZXing for iOS), Service Worker + IndexedDB,
  idempotent sync, conflicts → discrepancies for manual review.
- **Excel import:** upload → header detection → mapping → analysis → preview report → row editing → write only after
  confirmation → protocol.
- **Locations and plans:** country → city → business center → office → floor → room, SVG/PNG plans with room regions.
- **Licenses:** seats used/total, assignments to employees and equipment, documents, encrypted key.
- **Secrets (recovery/license keys):** libsodium, key outside the database, masking, a dedicated role + password
  re-entry, audit of every view. Off by default.
- **1C integration:** `integration_events` outbox, file connector, export API.
- **Notifications:** SMTP through a Messenger queue, log and resend.
- **Security:** local accounts, password policy (12+), lockout after 5 failed sign-ins for 15 min + IP rate limit,
  session idle timeout, RBAC, CSRF, network restriction (nginx geo), trigger-protected audit log.
- **Administration:** users, audit, error log, notifications, integrations, value mappings.
- **Backups:** daily encrypted dump, rotation, off-device copy, test restore.

## 3. Tech stack

| Layer | Technologies |
| --- | --- |
| Backend | PHP 8.3, Symfony 7.4 LTS, Doctrine ORM 3, Symfony Messenger (Doctrine transport), Symfony Security, Validator, Mailer |
| Templates and UI | Twig, PWA (Service Worker + Cache API + IndexedDB), BarcodeDetector / ZXing |
| Documents | Dompdf 3 (PDF acts, labels), PhpSpreadsheet 5 (Excel import/export) |
| Cryptography | libsodium (secrets), OpenSSL / NCANode (signature verification), NCALayer (signing) |
| Database | PostgreSQL 16 (trigram indexes for search, audit-log protection trigger) |
| Web server | Nginx 1.27 |
| Email (dev) | Mailpit |
| Infrastructure | Docker Compose: `php`, `worker`, `nginx`, `db`, `mailpit`; `compose.prod.yaml` for TEST/PROD |
| Tests | PHPUnit 12 (37 tests) |

## 4. Architecture

```text
Browser / PWA (phone)              USB scanner (keyboard mode)
        │                                   │
        ▼                                   ▼
     Nginx ──► PHP-FPM (Symfony 7.4) ◄──────┘
                 ├─ controllers: assets, employees, handover, inventory, import, licenses,
                 │                locations, admin, API v1, PWA, signing
                 ├─ services: AssetService, HandoverService, InventoryService, Import, Integration,
                 │           LabelService, PdfRenderer, SecretVault, Signature, AuditLogger
                 ├─ Doctrine ORM ──► PostgreSQL 16
                 └─ Messenger (Doctrine transport) ──► worker ──► SMTP (notifications)
Employee ──email link──► /sign/{id}/{token} ──► NCALayer (on their PC) ──► NCANode/OpenSSL verification
Cron: 1C integration, signature expiry, backup
```

## 5. Project structure

```text
src/
  Controller/      Admin, Api, Asset, Catalog, Dashboard, Employee, Handover, Import, Inventory,
                   License, Location, Pwa, Security, Sign
  Entity/          Asset, AssetAssignment, AssetAttributeValue, AssetBundle, AssetMovement, AssetType,
                   AttributeDefinition, AuditLog, Department, DigitalSignature, Employee, HandoverDocument,
                   ImportJob, ImportRow, IntegrationEvent, InventoryCampaign, InventoryCommissionMember,
                   InventoryDiscrepancy, InventoryScan, License, LicenseAssignment, Location, Notification,
                   OfficePlan, SecretValue, StoredFile, SystemError, User, ValueMapping
  Enum/            AssetStatus, CampaignStatus, LocationType, RoomType, ScanResult
  Service/         AssetService, HandoverService, InventoryService, LabelService, NotificationService,
                   PdfRenderer, SecretVault, SignLinkService, FileStorage, AuditLogger, Import/, Integration/, Signature/
  Security/        PasswordPolicy, LoginSubscriber, SessionGuardSubscriber, UserChecker
  Command/         app:user:create, app:user:password, app:seed, app:secrets:generate-key,
                   app:integration:process, app:signatures:expire
  Form/, Repository/, Message/, MessageHandler/, EventSubscriber/, Twig/, Util/
templates/         admin, asset, catalog, dashboard, employee, handover, import, inventory, license, location,
                   pdf (acts, labels), pwa, scan, security, sign, email
migrations/        Doctrine migrations
docker/            php (Dockerfile, php.ini), nginx (dev/prod), backup (backup.sh, restore.sh)
tests/             PHPUnit
compose.yaml, compose.prod.yaml, start.ps1/start.cmd, deploy.ps1/deploy.cmd
```

## 6. Data model

| Group | Entities |
| --- | --- |
| Assets | `Asset`, `AssetType`, `AttributeDefinition`, `AssetAttributeValue`, `AssetBundle`, `AssetAssignment`, `AssetMovement` |
| Employees | `Employee`, `Department`, `User` |
| Handover | `HandoverDocument`, `DigitalSignature` |
| Stocktaking | `InventoryCampaign`, `InventoryCommissionMember`, `InventoryScan`, `InventoryDiscrepancy` |
| Licenses | `License`, `LicenseAssignment` |
| Locations | `Location`, `OfficePlan` |
| Import | `ImportJob`, `ImportRow`, `ValueMapping` |
| Service | `AuditLog`, `Notification`, `IntegrationEvent`, `SecretValue`, `StoredFile`, `SystemError` |

**Asset statuses (14):** Created, Review, InStock, Reserved, Assigned, Installed, InTransit, Repair, TempIssued,
NotFound, Lost, Damaged, WrittenOff, Archived. A written-off asset can be issued only after an admin restores it; an
asset in repair cannot be assigned.

**Campaign statuses (9):** Draft, Preparation, Started, InProgress, AwaitingReview, WithCommission, Approved, Closed,
Cancelled.

**Scan results (11):** FoundExpected, FoundOtherRoom, FoundOtherOffice, NotFound, Unknown, Damaged, LabelDamaged,
NeedsClarification, WrittenOffFound, Duplicate, InvalidCode.

**Locations:** Country, City, BusinessCenter, Office, Floor, Room; room types: Cabinet, Warehouse, ServerRoom,
Technical, Meeting, Other.

## 7. Roles

| Role | Code |
|---|---|
| Administrator | `ROLE_ADMIN` |
| IT specialist | `ROLE_IT` |
| Stocktaking initiator | `ROLE_INVENTORY_INITIATOR` |
| Commission member | `ROLE_COMMISSION` |
| Access to recovery/license keys | `ROLE_SECRET_VIEWER` |
| Equipment recipient | no account: signs the act via a link in an email |

## 8. Routes

| Section | Paths |
| --- | --- |
| Home and health | `/`, `/health` |
| Sign-in | `/login`, `/logout`, `/password` |
| Assets | `/assets`, `/assets/new`, `/assets/{id}`, `.../edit`, `.../status`, `.../move`, `.../documents`, `.../label`, `.../secret`, `/assets/labels`, `/assets/export`, `/assets/barcode/{code}.svg` |
| Catalogs | `/catalog/types`, `/catalog/types/{id}/attributes`, `/bundles`, `/bundles/{id}` |
| Employees | `/employees`, `/employees/new`, `/employees/{id}`, `.../edit`, `.../notify` |
| Handover and acts | `/handover/issue`, `/handover/return`, `/documents`, `/documents/act/{id}` (+ `/pdf`, `/resend`, `/sign`), `/documents/signature/{id}/confirm` |
| Signing (no sign-in) | `/sign/{id}/{token}`, `.../pdf`, `.../data`, `.../submit` |
| Stocktaking | `/inventory`, `/inventory/new`, `/inventory/{id}`, `.../status`, `.../scan`, `.../mark`, `.../confirm`, `.../report`, `.../act`, `/inventory/discrepancies/{id}/resolve` |
| Import | `/import`, `/import/{id}/mapping`, `.../report`, `.../commit`, `.../cancel`, `.../protocol` |
| Licenses | `/licenses`, `/licenses/new`, `/licenses/{id}`, `.../assign`, `.../documents`, `.../secret` |
| Locations | `/locations`, `/locations/{id}`, `.../plans`, `/locations/plans/{id}` (+ `/image`, `/regions`) |
| PWA | `/m`, `/scan`, `/manifest.webmanifest`, `/offline` |
| Administration | `/admin/users`, `/admin/audit`, `/admin/errors`, `/admin/notifications`, `/admin/integrations`, `/admin/mappings` |
| API v1 | `/api/v1/lookup`, `/api/v1/assets`, `/api/v1/assets/{code}`, `/api/v1/employees`, `/api/v1/inventory/campaigns`, `/api/v1/inventory/{id}/pack`, `/api/v1/inventory/{id}/scan`, `/api/v1/inventory/sync`, `/api/v1/integration/1c/assets` |

## 9. Configuration (`.env`, on servers `.env.local`)

| Variable | Purpose |
| --- | --- |
| `APP_ENV`, `APP_SECRET` | Symfony environment and secret |
| `DEFAULT_URI` | System address (links in emails) |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `DATABASE_URL` | Database |
| `MESSENGER_TRANSPORT_DSN` | Queue (Doctrine) |
| `MAILER_DSN`, `MAIL_FROM` | Email |
| `ORG_NAME`, `IT_CONTACTS` | Organization and IT contacts in emails and acts |
| `STORAGE_DIR`, `UPLOAD_MAX_BYTES` | File storage and upload limit |
| `SECRETS_KEY_FILE` | Secret encryption key (outside the database) |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCK_MINUTES`, `SESSION_IDLE_TIMEOUT` | Sign-in lockout and session timeout |
| `NCANODE_URL`, `SIGNATURE_ACCEPT_BASIC`, `SIGN_DEADLINE_DAYS` | Signature verification and signing deadline |
| `ADMIN_ALERT_EMAIL` | Address for technical error alerts |

## 10. Quick start (DEV)

Runs only through Docker (no PHP on the host). On Windows you can run
`powershell -ExecutionPolicy Bypass -File .\start.ps1` (`-Rebuild` to rebuild, `-NoBrowser` to skip the browser).

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate -n
docker compose exec php bin/console app:seed --demo            # catalogs + demo data
docker compose exec php bin/console app:user:create admin "Administrator"   # prints a temporary password
```

- App: http://localhost:8080 (password change required on first sign-in)
- Email (Mailpit): http://localhost:8025
- Sample import file: `bin/console app:seed --excel=/var/www/storage/sample_import.xlsx`
- Tests: `docker compose exec php bin/phpunit` (database `assets_test`: `bin/console doctrine:database:create --env=test`
  and `doctrine:migrations:migrate --env=test -n`)

**Environment notes:**

- After recreating the `php` container run `docker compose restart nginx` (otherwise 502).
- `vendor/` and `var/` live in named volumes (bind mounts on Windows are too slow).
- If Docker runs out of address pools, compose uses the `DOCKER_SUBNET` subnet (10.231.10.0/24).
- If Docker Desktop hangs ("no route to host"): `wsl --shutdown` and restart Docker Desktop.

### Moving to another computer (with data)

The transfer archive's `transfer/` folder holds the database dump (`db.dump`) and storage files (`storage.tgz`,
including `keys/secrets.key`). On the new computer: install and start Docker Desktop, unpack the archive to a path
without Cyrillic characters or spaces, run `deploy.cmd`. The script builds the containers, restores the database and
files, installs dependencies and opens http://localhost:8080. Accounts and passwords stay the same.

## 11. Deployment (TEST / PROD)

A dedicated VM (4 vCPU, 8 GB RAM, 100 GB SSD + a separate backup disk), Ubuntu Server LTS, Docker Compose.

1. Code in `/opt/asset-inventory`; create `.env.local` on the server: `APP_ENV=prod`, `APP_SECRET`,
   `POSTGRES_PASSWORD`, `DATABASE_URL`, `MAILER_DSN=smtp://<internal SMTP>`, `DEFAULT_URI=https://assets.corp.local`,
   `ADMIN_ALERT_EMAIL`, `ORG_NAME`, `IT_CONTACTS`.
2. Corporate CA TLS certificate: `/etc/asset-inventory/tls/{fullchain,privkey}.pem`.
3. `docker compose -f compose.yaml -f compose.prod.yaml up -d --build`
4. `docker compose exec php composer install --no-dev -o && bin/console doctrine:migrations:migrate -n && bin/console cache:warmup`
5. Corporate network ranges go in `geo $corporate_net` (`docker/nginx/prod.conf`).
6. Host cron:
   ```
   */10 * * * *  cd /opt/asset-inventory && docker compose exec -T php bin/console app:integration:process
   15 1 * * *    cd /opt/asset-inventory && docker compose exec -T php bin/console app:signatures:expire
   30 2 * * *    cd /opt/asset-inventory && ./docker/backup/backup.sh >> /var/log/asset-backup.log 2>&1
   ```
7. Monitoring: `GET /health` (database, queue). Technical errors go to "Administration → Errors" and are emailed to
   `ADMIN_ALERT_EMAIL`.

### Backups (spec section 20)

`docker/backup/backup.sh` — daily PostgreSQL dump + document archive, AES-256 encryption (password in
`/etc/asset-inventory/backup.pass`), 14/8/12 rotation, copy to another device (`BACKUP_REMOTE`, rsync).
`docker/backup/restore.sh <file>` — test restore into a separate `assets_restore_test` database (quarterly check). The
secret encryption key (`keys/secrets.key`) is not in the archive — keep a separate copy.

## 12. Specification compliance

| Spec section | Implementation |
|---|---|
| 5.1 Authentication | local accounts, password policy (12+ characters), initial password change, lockout after 5 failed sign-ins for 15 min + IP rate limit, sign-in audit, idle session lock (`SESSION_IDLE_TIMEOUT`) |
| 5.2 Employee | UUID, Entra Object ID, UPN, email, DisplayName, Russian/English full name, position, department, company, office/city, manager, status, source, sync date, 1C ID, extensionAttribute |
| 6 Asset model | all mandatory fields; per-type custom attributes (`asset_types`, `attribute_definitions`, `asset_attribute_values`); bundles |
| 7 Statuses | 14 statuses, transition matrix (`App\Enum\AssetStatus`); written-off issued only after admin restore, in repair cannot be assigned |
| 8 Excel import | upload → structure (automatic header row detection) → mapping → analysis → preview report (rows, new, matches, duplicates, invalid numbers/dates, unknown statuses/locations/employees, old→new, action) → row edit/exclusion, value mappings → write only after confirmation → protocol |
| 9 Recovery keys | not migrated by default (only a flag, storage place, device ID, check date); when enabled — a separate table, libsodium, key outside the database, masking, a dedicated role + password re-entry, audit of every view, never exported |
| 10 Scanning and labels | USB scanner in keyboard mode with focus retention; results: found / not found / already scanned / other office / other room / written off / invalid code; repeat blocking with time and user; PDF labels for Brother QL-810W (62×29, 62×40, 29×90) |
| 11 PWA and offline | install from the browser, camera (BarcodeDetector; ZXing for iOS if `public/js/vendor/zxing.min.js` is present), Service Worker + Cache API, IndexedDB, operation UUIDs, "saved locally / pending / synced / conflict / error" states, idempotent sync, conflicts → discrepancies for manual review |
| 12 Stocktaking | campaigns with 9 statuses, scope (offices/rooms/categories), commission, responsible IT staff, expected-inventory snapshot, discrepancies, location change only after IT confirmation, automatic "not found", commission approval, summary report and final PDF act |
| 13 Issue/return and e-signature | employee picker with current holdings, availability check, operation + PDF act (SHA-256), notification, signing via NCALayer (CMS/CAdES detached), verification (NCANode or basic OpenSSL), assignment confirmation, signing deadline |
| 14 Notifications | internal SMTP through the Messenger queue: operation, date, full list, issued/returned, act link, signature status, IT contacts; log and resend |
| 15 Locations and plans | country→city→business center→office→floor→room hierarchy, room types, SVG/PNG plans ≥ 2000 px, room regions (edited by IT), asset and discrepancy counts, click-through to the list |
| 16 Licenses | all fields, seats used/total, assignments to employees and equipment, documents, encrypted key |
| 17 1C | fields `external_1c_id, source_system, sync_status, last_sync_at, sync_version, last_sync_error`; `integration_events` outbox, switchable module, file connector, export API |
| 18 Audit and attachments | user, time, action, object, before/after, IP, session, source, comment, document; log protected by a trigger against change/deletion; PDF/JPG/PNG/DOCX/XLSX attachments, extension and MIME checks, size limit, storage outside public |
| 19 Non-functional | HTTPS, RBAC, CSRF (forms + API header), ORM/parameterized queries, network restriction (nginx geo), trigram search indexes, error log + admin alert, DEV/TEST/PROD environments |

## 13. Status and open customer decisions (spec section 24)

| Branch | Contents |
| --- | --- |
| `main` | Current code (in Git since 2026-11-15) |

All specification sections 5–19 are implemented; prototype parts and open questions:

- **E-signature:** a prototype via NCALayer (`basics` module, `commonUtils` fallback). Full verification of the
  Kazakhstan national CA chain and revocation (OCSP) needs NCANode (`NCANODE_URL`). Without it GOST signatures get
  "Verification required" and are confirmed by an admin manually. The format (CMS over PDF) needs agreement.
- **1C:** the exchange format is undefined — currently a file connector (`storage/exchange/1c/out`).
- **Entra ID / SSO:** the data model is ready; OpenID Connect is connected after app registration.
- **extensionAttribute:** the field exists, the attribute number is undefined.
- **Recovery keys:** storage is off until security approves (`bin/console app:secrets:generate-key` enables it).
- **Labels:** size/template to be clarified; direct printing via the Brother SDK is a separate stage.
- **iOS camera:** Safari lacks BarcodeDetector — put a ZXing build in `public/js/vendor/zxing.min.js` (or use a
  Bluetooth scanner / manual entry).

## 14. Roadmap

- [ ] Connect NCANode for full e-signature verification.
- [ ] Agree on the 1C exchange format.
- [ ] Connect Entra ID (OpenID Connect).
- [ ] Deploy TEST/PROD on a dedicated VM.
- [ ] Direct label printing via the Brother SDK.
