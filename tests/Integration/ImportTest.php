<?php

namespace App\Tests\Integration;

use App\Entity\Asset;
use App\Entity\ImportJob;
use App\Entity\ImportRow;
use App\Enum\AssetStatus;
use App\Service\Import\ExcelImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Критерии 15–16: Excel загружается и проходит предварительную проверку;
 * без подтверждения пользователя существующие данные не изменяются.
 */
class ImportTest extends IntegrationTestCase
{
    private function excel(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $s = $book->getActiveSheet();
        $s->fromArray(['Реестр IT-оборудования'], null, 'A1'); // титульная строка над заголовками
        $s->fromArray(['№', 'Дата', 'Инвентарный номер Полный', 'Инвентарный номер', 'Наименование', 'ФИО ENG', 'ФИО RUS', 'Статус', 'Расположение', 'Примечание', 'BitLocker/FileVault Key'], null, 'A2');
        $s->fromArray($rows, null, 'A3');
        $path = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }

    public function testPreviewDoesNotChangeDataUntilCommit(): void
    {
        $loc = $this->locations();
        $existing = $this->asset($loc['room1']);
        $emp = $this->employee();
        $new1 = $this->uid('N');
        $rows = [
            [1, '15.01.2024', 'FULL-1', $new1, 'Ноутбук новый', $emp->getDisplayName(), '', 'Выдан', $loc['room2']->getName(), '', 'SECRET-RECOVERY-KEY-VALUE'],
            [2, '31.02.2024', '', $existing->getInventoryNumber(), 'Новое имя', '', '', 'На складе', '', 'обновление', ''],
            [3, '', '', '', 'Без номера', '', '', '', '', '', ''],
            [4, '', '', $new1, 'Дубликат', '', '', '', '', '', ''],
            [5, '', '', 'ИНВ-КИРИЛЛИЦА', 'Плохой код', '', '', 'странный', 'Нет такого', '', ''],
        ];
        $svc = $this->service(ExcelImportService::class);
        $job = $svc->upload($this->excel($rows), $this->user);
        $this->assertSame(2, $job->getHeaderRow(), 'Строка заголовков найдена автоматически');
        $this->assertSame([], $svc->missingRequired($job->getColumnMap()));

        $svc->analyze($job, $job->getColumnMap());
        $s = $job->getSummary();
        $this->assertSame(5, $s['rows']);
        $this->assertSame(1, $s['create']);
        $this->assertSame(1, $s['update']);
        $this->assertSame(1, $s['matches']);
        $this->assertSame(1, $s['duplicates']);
        $this->assertSame(2, $s['bad_inventory']);
        $this->assertSame(1, $s['bad_dates']);
        $this->assertArrayHasKey('странный', $s['unknown_status']);
        $this->assertArrayHasKey('Нет такого', $s['unknown_location']);

        $rowsById = [];
        foreach ($job->getRows() as $r) {
            $rowsById[$r->getRowNumber()] = $r;
        }
        $this->assertSame(['Ноутбук тестовый', 'Новое имя'], $rowsById[4]->getDiff()['name'], 'Старое и новое значение');
        $this->assertStringNotContainsString('SECRET-RECOVERY', json_encode($rowsById[3]->getRaw()), 'Recovery key не сохраняется');

        // До подтверждения реестр не изменен
        $db = $this->em->getConnection();
        $this->assertFalse($db->fetchOne('SELECT 1 FROM assets WHERE inventory_number = ?', [$new1]));
        $this->assertSame('Ноутбук тестовый', $db->fetchOne('SELECT name FROM assets WHERE inventory_number = ?', [$existing->getInventoryNumber()]));

        // Запись после подтверждения
        $p = $svc->commit($job, $this->user);
        $this->assertSame(1, $p['created']);
        $this->assertSame(1, $p['updated']);
        $this->assertSame(3, $p['skipped']);

        $created = $this->em->getRepository(Asset::class)->findOneBy(['inventoryNumber' => $new1]);
        $this->assertSame(AssetStatus::Assigned, $created->getStatus());
        $this->assertSame($emp->getId()->toRfc4122(), $created->getEmployee()->getId()->toRfc4122());
        $this->assertTrue($created->hasRecoveryKey());
        $this->assertSame('2024-01-15', $created->getSourceDate()->format('Y-m-d'));
        $this->assertSame('Новое имя', $this->em->getRepository(Asset::class)->findOneBy(['inventoryNumber' => $existing->getInventoryNumber()])->getName());
        $this->assertSame(ImportJob::STATUS_COMMITTED, $job->getStatus());
    }

    public function testUserCanExcludeRow(): void
    {
        $svc = $this->service(ExcelImportService::class);
        $inv = $this->uid('X');
        $job = $svc->upload($this->excel([[1, '', '', $inv, 'Исключаемый', '', '', '', '', '', '']]), $this->user);
        $svc->analyze($job, $job->getColumnMap());
        $row = $job->getRows()->first();
        $this->assertSame(ImportRow::ACTION_CREATE, $row->getAction());
        $svc->setAction($row, ImportRow::ACTION_SKIP);
        $svc->analyzeRows($job);
        $this->assertSame(ImportRow::ACTION_SKIP, $row->getAction(), 'Решение пользователя сохраняется при перепроверке');
        $svc->commit($job, $this->user);
        $this->assertNull($this->em->getRepository(Asset::class)->findOneBy(['inventoryNumber' => $inv]));
    }
}
