<?php

namespace App\Service\Import;

use App\Entity\Asset;
use App\Entity\Employee;
use App\Entity\ImportJob;
use App\Entity\ImportRow;
use App\Entity\Location;
use App\Entity\User;
use App\Entity\ValueMapping;
use App\Enum\AssetStatus;
use App\Exception\BusinessException;
use App\Repository\AssetRepository;
use App\Repository\EmployeeRepository;
use App\Repository\LocationRepository;
use App\Service\AssetService;
use App\Service\AuditLogger;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Импорт Excel (п. 8 ТЗ, Приложение А):
 * загрузка → проверка структуры → сопоставление колонок → анализ → предварительный отчет →
 * исправление/исключение строк → запись ТОЛЬКО после явного подтверждения → протокол.
 */
class ExcelImportService
{
    /** Системные поля и синонимы заголовков Excel */
    public const FIELDS = [
        'row_no' => ['label' => '№', 'aliases' => ['№', 'n', 'no', '#', 'номер п/п', 'п/п']],
        'date' => ['label' => 'Дата', 'aliases' => ['дата', 'date']],
        'full_inventory_number' => ['label' => 'Инвентарный номер Полный', 'aliases' => ['инвентарный номер полный', 'полный инвентарный номер', 'инв. номер полный']],
        'inventory_number' => ['label' => 'Инвентарный номер', 'aliases' => ['инвентарный номер', 'инв. номер', 'инв номер', 'inventory number'], 'required' => true],
        'name' => ['label' => 'Наименование', 'aliases' => ['наименование', 'name', 'название'], 'required' => true],
        'employee_en' => ['label' => 'ФИО ENG', 'aliases' => ['фио eng', 'фио en', 'full name', 'displayname']],
        'employee_ru' => ['label' => 'ФИО RUS', 'aliases' => ['фио rus', 'фио ru', 'фио']],
        'status' => ['label' => 'Статус', 'aliases' => ['статус', 'status']],
        'location' => ['label' => 'Расположение', 'aliases' => ['расположение', 'местоположение', 'location']],
        'comment' => ['label' => 'Примечание', 'aliases' => ['примечание', 'комментарий', 'comment']],
        'recovery_key' => ['label' => 'BitLocker/FileVault Key', 'aliases' => ['bitlocker/filevault key', 'bitlocker', 'filevault', 'recovery key', 'bitlocker key']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileStorage $storage,
        private readonly AssetRepository $assets,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly AssetService $assetService,
        private readonly AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ 1–2. загрузка и структура

    public function upload(UploadedFile $file, User $by): ImportJob
    {
        $stored = $this->storage->storeUpload($file, 'imports', FileStorage::IMPORT_TYPES);
        $job = new ImportJob($stored['name'], $stored['path'], $stored['sha256']);
        $job->setCreatedBy($by);

        $book = $this->load($job);
        $sheet = $book->getSheet(0);
        $job->setSheetName($sheet->getTitle());
        [$headerRow, $headers] = $this->detectHeader($sheet->toArray(null, false, false, false));
        if (!$headers) {
            throw new BusinessException('Не удалось найти строку заголовков (ожидается столбец «Инвентарный номер»)');
        }
        $job->setHeaderRow($headerRow);
        $job->setHeaders($headers);
        $job->setColumnMap($this->autoMap($headers));

        $this->em->persist($job);
        $this->audit->log('import.upload', ['objectType' => 'import_job', 'objectLabel' => $stored['name'], 'comment' => 'SHA-256 ' . $stored['sha256']]);
        $this->em->flush();

        return $job;
    }

    /** @return list<string> */
    public function sheetNames(ImportJob $job): array
    {
        return $this->load($job)->getSheetNames();
    }

    public function changeSheet(ImportJob $job, string $sheetName, ?int $headerRow = null): void
    {
        $sheet = $this->load($job)->getSheetByName($sheetName) ?? throw new BusinessException('Лист не найден');
        $rows = $sheet->toArray(null, false, false, false);
        if ($headerRow) {
            $headers = $this->headersOf($rows[$headerRow - 1] ?? []);
        } else {
            [$headerRow, $headers] = $this->detectHeader($rows);
        }
        $job->setSheetName($sheetName)->setHeaderRow($headerRow)->setHeaders($headers)->setColumnMap($this->autoMap($headers));
    }

    private function load(ImportJob $job): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = $this->storage->absolute($job->getStoragePath());
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->load($path);
    }

    /** @return array{0:int, 1:array<int,string>} */
    private function detectHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 15, true) as $i => $row) {
            $headers = $this->headersOf($row);
            foreach ($headers as $h) {
                if (str_contains(mb_strtolower($h), 'инвентарный номер') || mb_strtolower($h) === 'inventory number') {
                    return [$i + 1, $headers];
                }
            }
        }

        return [1, $this->headersOf($rows[0] ?? [])];
    }

    private function headersOf(array $row): array
    {
        $out = [];
        foreach ($row as $i => $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $out[$i] = $v;
            }
        }

        return $out;
    }

    /** Автоматическое сопоставление по точному совпадению с синонимами. */
    public function autoMap(array $headers): array
    {
        $map = [];
        foreach (self::FIELDS as $field => $def) {
            foreach ($headers as $idx => $h) {
                $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $h)));
                if (in_array($norm, $def['aliases'], true) && !in_array($idx, $map, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    /** @return list<string> отсутствующие обязательные поля */
    public function missingRequired(array $map): array
    {
        $missing = [];
        foreach (self::FIELDS as $f => $def) {
            if (!empty($def['required']) && !isset($map[$f])) {
                $missing[] = $def['label'];
            }
        }

        return $missing;
    }

    // ------------------------------------------------------------------ 4–5. анализ

    public function analyze(ImportJob $job, array $columnMap): void
    {
        if (!$job->isEditable()) {
            throw new BusinessException('Импорт уже завершен');
        }
        $columnMap = array_map('intval', array_filter($columnMap, fn ($v) => $v !== '' && $v !== null));
        if ($missing = $this->missingRequired($columnMap)) {
            throw new BusinessException('Не сопоставлены обязательные поля: ' . implode(', ', $missing));
        }
        $job->setColumnMap($columnMap);
        $job->clearRows();
        $this->em->flush();

        $sheet = $this->load($job)->getSheetByName((string) $job->getSheetName()) ?? throw new BusinessException('Лист не найден');
        $rows = $sheet->toArray(null, false, false, false);

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 1;
            if ($rowNumber <= $job->getHeaderRow()) {
                continue;
            }
            $raw = [];
            foreach ($columnMap as $field => $idx) {
                $v = $row[$idx] ?? null;
                $raw[$field] = is_string($v) ? trim($v) : $v;
            }
            if (count(array_filter($raw, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // пустая строка
            }
            // Значение recovery key НЕ сохраняется — только признак наличия (п. 9)
            if (array_key_exists('recovery_key', $raw)) {
                $raw['recovery_key'] = ($raw['recovery_key'] !== null && $raw['recovery_key'] !== '') ? '[есть ключ — значение не импортируется]' : null;
            }
            $r = new ImportRow($job, $rowNumber);
            $r->setRaw($raw);
            $job->addRow($r);
        }

        $this->analyzeRows($job);
        $job->setStatus(ImportJob::STATUS_ANALYZED);
        $this->audit->log('import.analyze', ['objectType' => 'import_job', 'objectId' => (string) $job->getId(), 'objectLabel' => $job->getOriginalName(), 'new' => $job->getSummary()]);
        $this->em->flush();
    }

    /** Повторный анализ всех строк (после правок отдельных строк). */
    public function analyzeRows(ImportJob $job): void
    {
        $seenInv = [];
        $seenFull = [];
        $mappings = $this->loadMappings();

        foreach ($job->getRows() as $r) {
            $this->analyzeRow($r, $seenInv, $seenFull, $mappings);
        }
        $job->setSummary($this->summarize($job));
    }

    private function loadMappings(): array
    {
        $out = [ValueMapping::KIND_STATUS => [], ValueMapping::KIND_LOCATION => [], ValueMapping::KIND_EMPLOYEE => []];
        foreach ($this->em->getRepository(ValueMapping::class)->findAll() as $m) {
            $out[$m->getKind()][$m->getSourceValue()] = $m->getTargetValue();
        }

        return $out;
    }

    private function analyzeRow(ImportRow $r, array &$seenInv, array &$seenFull, array $mappings): void
    {
        $raw = $r->getRaw();
        $issues = [];
        $n = [];
        $err = function (string $field, string $msg) use (&$issues) { $issues[] = ['level' => 'error', 'field' => $field, 'message' => $msg]; };
        $warn = function (string $field, string $msg) use (&$issues) { $issues[] = ['level' => 'warning', 'field' => $field, 'message' => $msg]; };

        // Инвентарный номер — основной уникальный идентификатор
        $inv = Asset::normalizeCode((string) ($raw['inventory_number'] ?? ''));
        if ($inv === '') {
            $err('inventory_number', 'Пустой инвентарный номер');
        } elseif (!preg_match('/^[\x20-\x7E]+$/', $inv)) {
            $err('inventory_number', 'Инвентарный номер содержит недопустимые для Code 128 символы (кириллица?)');
        } elseif (mb_strlen($inv) > 64) {
            $err('inventory_number', 'Инвентарный номер длиннее 64 символов');
        } elseif (isset($seenInv[$inv])) {
            $err('inventory_number', sprintf('Дубликат в файле (строка %d)', $seenInv[$inv]));
        } else {
            $seenInv[$inv] = $r->getRowNumber();
        }
        $n['inventory_number'] = $inv;

        $full = trim((string) ($raw['full_inventory_number'] ?? ''));
        if ($full !== '') {
            if (isset($seenFull[$full])) {
                $warn('full_inventory_number', sprintf('Полный инв. номер повторяется (строка %d)', $seenFull[$full]));
            } else {
                $seenFull[$full] = $r->getRowNumber();
            }
        }
        $n['full_inventory_number'] = $full ?: null;

        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $err('name', 'Пустое наименование');
        }
        $n['name'] = $name;

        // Дата
        $n['date'] = null;
        $dateRaw = $raw['date'] ?? null;
        if ($dateRaw !== null && $dateRaw !== '') {
            $d = self::parseDate($dateRaw);
            if ($d) {
                $n['date'] = $d->format('Y-m-d');
            } else {
                $warn('date', sprintf('Некорректная дата «%s» — поле будет пропущено', $dateRaw));
            }
        }

        // Сотрудник
        $n['employee_id'] = null;
        $en = trim((string) ($raw['employee_en'] ?? ''));
        $ru = trim((string) ($raw['employee_ru'] ?? ''));
        $n['employee_en'] = $en ?: null;
        $n['employee_ru'] = $ru ?: null;
        if ($en !== '' || $ru !== '') {
            $emp = null;
            foreach ([$en, $ru] as $nm) {
                if ($nm !== '' && isset($mappings['employee'][ValueMapping::key($nm)])) {
                    $emp = $this->employees->find($mappings['employee'][ValueMapping::key($nm)]);
                    break;
                }
            }
            $emp ??= $this->employees->findByAnyName($en ?: null, $ru ?: null);
            if ($emp) {
                $n['employee_id'] = $emp->getId()->toRfc4122();
                $n['employee_name'] = $emp->getName();
            } else {
                $warn('employee', sprintf('Неизвестный сотрудник «%s»', $en ?: $ru));
            }
        }

        // Статус — через справочник соответствий
        $statusRaw = trim((string) ($raw['status'] ?? ''));
        $status = null;
        if ($statusRaw !== '') {
            $mapped = $mappings['status'][ValueMapping::key($statusRaw)] ?? null;
            $status = $mapped ? AssetStatus::tryFrom($mapped) : AssetStatus::fromHuman($statusRaw);
            if (!$status) {
                $warn('status', sprintf('Неизвестный статус «%s»', $statusRaw));
            }
        }
        if (!$status) {
            $status = ($en !== '' || $ru !== '') ? AssetStatus::Assigned : AssetStatus::InStock;
        }
        if ($status === AssetStatus::Assigned && !$n['employee_id'] && $en === '' && $ru === '') {
            $warn('status', 'Статус «Закреплено», но сотрудник не указан — будет «На складе»');
            $status = AssetStatus::InStock;
        }
        $n['status'] = $status->value;

        // Расположение — нормализация город/офис/помещение
        $locRaw = trim((string) ($raw['location'] ?? ''));
        $n['location_id'] = null;
        if ($locRaw !== '') {
            $mapped = $mappings['location'][ValueMapping::key($locRaw)] ?? null;
            $loc = $mapped ? $this->locations->find((int) $mapped) : $this->locations->findByHumanPath($locRaw);
            if ($loc) {
                $n['location_id'] = $loc->getId();
                $n['location_name'] = $loc->getPathString();
            } else {
                $warn('location', sprintf('Неизвестное расположение «%s»', $locRaw));
            }
        }

        $n['comment'] = trim((string) ($raw['comment'] ?? '')) ?: null;
        $n['has_recovery_key'] = !empty($raw['recovery_key']);
        if ($n['has_recovery_key']) {
            $warn('recovery_key', 'Recovery key не переносится (п. 9 ТЗ): сохраняется только признак наличия');
        }

        // Совпадение с реестром
        $existing = $inv !== '' ? $this->assets->findOneBy(['inventoryNumber' => $inv]) : null;
        $diff = [];
        if ($existing) {
            $r->setMatchedAssetId($existing->getId()->toRfc4122());
            $cmp = [
                'name' => [$existing->getName(), $n['name'] ?: null],
                'full_inventory_number' => [$existing->getFullInventoryNumber(), $n['full_inventory_number']],
                'status' => [$existing->getStatus()->value, $statusRaw !== '' ? $n['status'] : null],
                'employee' => [$existing->getEmployee()?->getName(), $n['employee_name'] ?? null],
                'location' => [$existing->getLocation()?->getPathString(), $n['location_name'] ?? null],
                'comment' => [$existing->getComment(), $n['comment']],
                'date' => [$existing->getSourceDate()?->format('Y-m-d'), $n['date']],
            ];
            foreach ($cmp as $f => [$old, $new]) {
                if ($new !== null && $new !== '' && $old !== $new) {
                    $diff[$f] = [$old, $new];
                }
            }
        }
        $r->setDiff($diff);
        $r->setNormalized($n);
        $r->setIssues($issues);

        $suggested = match (true) {
            $r->hasErrors() => ImportRow::ACTION_SKIP,
            $existing && !$diff => ImportRow::ACTION_SKIP,
            (bool) $existing => ImportRow::ACTION_UPDATE,
            default => ImportRow::ACTION_CREATE,
        };
        $r->setSuggestedAction($suggested);
        // Решение пользователя сохраняется, если оно еще допустимо
        $userAction = $r->getAction();
        $valid = match ($userAction) {
            ImportRow::ACTION_CREATE => !$existing && !$r->hasErrors(),
            ImportRow::ACTION_UPDATE => $existing && !$r->hasErrors(),
            default => true,
        };
        if (!empty($raw['_excluded'])) {
            $r->setAction(ImportRow::ACTION_SKIP);
        } else {
            $r->setAction($valid && $userAction !== ImportRow::ACTION_SKIP ? $userAction : $suggested);
        }
    }

    public static function parseDate(mixed $v): ?\DateTimeImmutable
    {
        if ($v instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($v);
        }
        if (is_numeric($v) && (float) $v > 1000 && (float) $v < 80000) {
            return \DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $v));
        }
        $s = trim((string) $v);
        foreach (['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $s);
            if ($d && $d->format($fmt) === $s && (int) $d->format('Y') > 1990 && (int) $d->format('Y') < 2100) {
                return $d;
            }
        }

        return null;
    }

    public function summarize(ImportJob $job): array
    {
        $s = ['rows' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'matches' => 0, 'duplicates' => 0,
            'errors' => 0, 'warnings' => 0, 'bad_inventory' => 0, 'bad_dates' => 0,
            'unknown_status' => [], 'unknown_location' => [], 'unknown_employee' => []];
        foreach ($job->getRows() as $r) {
            ++$s['rows'];
            ++$s[$r->getAction()];
            if ($r->getMatchedAssetId()) {
                ++$s['matches'];
            }
            if ($r->hasErrors()) {
                ++$s['errors'];
            }
            if ($r->hasWarnings()) {
                ++$s['warnings'];
            }
            foreach ($r->getIssues() as $i) {
                $raw = $r->getRaw();
                match ($i['field']) {
                    'inventory_number' => str_contains($i['message'], 'Дубликат') ? ++$s['duplicates'] : ++$s['bad_inventory'],
                    'date' => ++$s['bad_dates'],
                    'status' => isset($raw['status']) && str_starts_with($i['message'], 'Неизвестный') ? $s['unknown_status'][(string) $raw['status']] = ($s['unknown_status'][(string) $raw['status']] ?? 0) + 1 : null,
                    'location' => $s['unknown_location'][(string) ($raw['location'] ?? '')] = ($s['unknown_location'][(string) ($raw['location'] ?? '')] ?? 0) + 1,
                    'employee' => $s['unknown_employee'][(string) (($raw['employee_en'] ?? '') ?: ($raw['employee_ru'] ?? ''))] = ($s['unknown_employee'][(string) (($raw['employee_en'] ?? '') ?: ($raw['employee_ru'] ?? ''))] ?? 0) + 1,
                    default => null,
                };
            }
        }
        arsort($s['unknown_status']);
        arsort($s['unknown_location']);
        arsort($s['unknown_employee']);

        return $s;
    }

    // ------------------------------------------------------------------ 6. правки строк

    public function setAction(ImportRow $r, string $action): void
    {
        if (!$r->getJob()->isEditable()) {
            throw new BusinessException('Импорт уже завершен');
        }
        if (!isset(ImportRow::ACTION_LABELS[$action])) {
            throw new BusinessException('Неизвестное действие');
        }
        if ($action !== ImportRow::ACTION_SKIP && $r->hasErrors()) {
            throw new BusinessException(sprintf('Строка %d содержит ошибки — исправьте ее или пропустите', $r->getRowNumber()));
        }
        if ($action === ImportRow::ACTION_CREATE && $r->getMatchedAssetId()) {
            throw new BusinessException('Актив уже существует — возможно только обновление');
        }
        if ($action === ImportRow::ACTION_UPDATE && !$r->getMatchedAssetId()) {
            throw new BusinessException('Совпадение не найдено — возможно только создание');
        }
        $raw = $r->getRaw();
        if ($action === ImportRow::ACTION_SKIP) {
            $raw['_excluded'] = true;
        } else {
            unset($raw['_excluded']);
        }
        $r->setRaw($raw);
        $r->setAction($action);
        $r->getJob()->setSummary($this->summarize($r->getJob()));
    }

    public function editRow(ImportRow $r, array $values): void
    {
        if (!$r->getJob()->isEditable()) {
            throw new BusinessException('Импорт уже завершен');
        }
        $raw = $r->getRaw();
        foreach (['inventory_number', 'full_inventory_number', 'name', 'employee_en', 'employee_ru', 'status', 'location', 'comment', 'date'] as $f) {
            if (array_key_exists($f, $values)) {
                $raw[$f] = trim((string) $values[$f]);
            }
        }
        unset($raw['_excluded']);
        $r->setRaw($raw);
        $r->setAction(ImportRow::ACTION_SKIP); // перерасчет предложит действие заново
        $this->analyzeRows($r->getJob());
    }

    /** Сохранение соответствия в справочник и повторный анализ. */
    public function addMapping(ImportJob $job, string $kind, string $source, string $target): void
    {
        $repo = $this->em->getRepository(ValueMapping::class);
        $m = $repo->findOneBy(['kind' => $kind, 'sourceValue' => ValueMapping::key($source)]);
        if ($m) {
            $m->setTargetValue($target);
        } else {
            $this->em->persist(new ValueMapping($kind, $source, $target));
        }
        $this->em->flush();
        $this->analyzeRows($job);
    }

    // ------------------------------------------------------------------ 7–8. запись и протокол

    public function commit(ImportJob $job, User $by, bool $createEmployees = false): array
    {
        if ($job->getStatus() !== ImportJob::STATUS_ANALYZED) {
            throw new BusinessException('Импорт не готов к записи');
        }
        $this->audit->setSource('import');
        $protocol = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'employees_created' => 0, 'started_at' => date(DATE_ATOM)];
        $createdEmployees = [];

        try {
            $this->em->wrapInTransaction(function () use ($job, $createEmployees, &$protocol, &$createdEmployees) {
                $i = 0;
                foreach ($job->getRows() as $r) {
                    if ($r->getAction() === ImportRow::ACTION_SKIP) {
                        $r->setOutcome('skipped', $r->hasErrors() ? 'Строка с ошибками' : 'Пропущено');
                        ++$protocol['skipped'];
                        continue;
                    }
                    $n = $r->getNormalized();
                    $employee = $n['employee_id'] ? $this->employees->find($n['employee_id']) : null;
                    if (!$employee && $createEmployees && ($n['employee_en'] || $n['employee_ru'])) {
                        $key = mb_strtolower(($n['employee_en'] ?? '') . '|' . ($n['employee_ru'] ?? ''));
                        $employee = $createdEmployees[$key] ?? null;
                        if (!$employee) {
                            $employee = (new Employee())
                                ->setDisplayName($n['employee_en'] ?: $n['employee_ru'])
                                ->setFullNameEn($n['employee_en'])
                                ->setFullNameRu($n['employee_ru'])
                                ->setSource('excel');
                            $this->em->persist($employee);
                            $createdEmployees[$key] = $employee;
                            ++$protocol['employees_created'];
                        }
                    }
                    $location = $n['location_id'] ? $this->locations->find($n['location_id']) : null;
                    $status = AssetStatus::from($n['status']);
                    if ($status === AssetStatus::Assigned && !$employee) {
                        $status = AssetStatus::InStock;
                    }

                    if ($r->getAction() === ImportRow::ACTION_CREATE) {
                        $a = (new Asset())->setInventoryNumber($n['inventory_number']);
                        $a->setFullInventoryNumber($n['full_inventory_number'])->setName($n['name'])
                            ->setComment($n['comment'])->setLocation($location)
                            ->setSourceDate($n['date'] ? new \DateTimeImmutable($n['date']) : null)
                            ->setHasRecoveryKey($n['has_recovery_key'])
                            ->setRecoveryKeyStorage($n['has_recovery_key'] ? 'Excel (значение не перенесено)' : null);
                        $a->setStatus($status);
                        $a->setEmployee($status === AssetStatus::Assigned ? $employee : null);
                        $this->assetService->create($a, 'import', sprintf('Импорт %s, строка %d', $job->getOriginalName(), $r->getRowNumber()));
                        $r->setMatchedAssetId($a->getId()->toRfc4122());
                        $r->setOutcome('created');
                        ++$protocol['created'];
                    } else {
                        $a = $this->assets->find($r->getMatchedAssetId());
                        if (!$a) {
                            $r->setOutcome('error', 'Актив не найден');
                            ++$protocol['errors'];
                            continue;
                        }
                        $before = AssetService::snapshot($a);
                        $diff = $r->getDiff();
                        if (isset($diff['name'])) { $a->setName($n['name']); }
                        if (isset($diff['full_inventory_number'])) { $a->setFullInventoryNumber($n['full_inventory_number']); }
                        if (isset($diff['comment'])) { $a->setComment($n['comment']); }
                        if (isset($diff['date'])) { $a->setSourceDate(new \DateTimeImmutable($n['date'])); }
                        if ($n['has_recovery_key'] && !$a->hasRecoveryKey()) {
                            $a->setHasRecoveryKey(true)->setRecoveryKeyStorage('Excel (значение не перенесено)');
                        }
                        $this->assetService->saveEdit($a, $before, 'import');
                        $this->assetService->applyImportState(
                            $a,
                            isset($diff['status']) ? $status : null,
                            isset($diff['employee']) ? ($employee ?? false) : null,
                            isset($diff['location']) ? $location : null,
                            sprintf('Импорт %s, строка %d', $job->getOriginalName(), $r->getRowNumber()),
                        );
                        $r->setOutcome('updated', implode(', ', array_keys($diff)));
                        ++$protocol['updated'];
                    }
                    if (++$i % 100 === 0) {
                        $this->em->flush();
                    }
                }
                $protocol['finished_at'] = date(DATE_ATOM);
                $job->setProtocol($protocol);
                $job->setStatus(ImportJob::STATUS_COMMITTED);
                $job->setCommittedAt(new \DateTimeImmutable());
                $this->audit->log('import.commit', [
                    'objectType' => 'import_job', 'objectId' => (string) $job->getId(), 'objectLabel' => $job->getOriginalName(),
                    'new' => $protocol,
                ]);
                $this->em->flush();
            });
        } finally {
            $this->audit->setSource(null);
        }

        return $protocol;
    }

    public function cancel(ImportJob $job): void
    {
        if (!$job->isEditable()) {
            throw new BusinessException('Импорт уже завершен');
        }
        $job->setStatus(ImportJob::STATUS_CANCELLED);
        $this->audit->log('import.cancel', ['objectType' => 'import_job', 'objectId' => (string) $job->getId(), 'objectLabel' => $job->getOriginalName()]);
        $this->em->flush();
    }
}
