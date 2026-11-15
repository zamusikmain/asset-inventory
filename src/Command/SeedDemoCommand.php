<?php

namespace App\Command;

use App\Entity\Asset;
use App\Entity\AssetType;
use App\Entity\AttributeDefinition;
use App\Entity\Department;
use App\Entity\Employee;
use App\Entity\Location;
use App\Enum\AssetStatus;
use App\Enum\LocationType;
use App\Enum\RoomType;
use App\Service\AssetService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Базовые справочники (типы, атрибуты) и, по желанию, демо-данные для DEV/TEST.
 */
#[AsCommand(name: 'app:seed', description: 'Заполнить справочники; --demo — демонстрационные данные; --excel=путь — пример файла импорта')]
class SeedDemoCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AssetService $assets, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('demo', null, InputOption::VALUE_NONE, 'Создать демо-локации, сотрудников и активы')
            ->addOption('excel', null, InputOption::VALUE_REQUIRED, 'Сгенерировать пример Excel для импорта по указанному пути');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);
        $this->audit->setSource('cli');
        $types = $this->seedTypes();
        $io->success(sprintf('Типов активов: %d', count($types)));

        if ($in->getOption('demo')) {
            $rooms = $this->seedLocations();
            $emps = $this->seedEmployees($rooms);
            $n = $this->seedAssets($types, $rooms, $emps);
            $io->success(sprintf('Демо: помещений %d, сотрудников %d, активов %d', count($rooms), count($emps), $n));
        }
        if ($path = $in->getOption('excel')) {
            $this->sampleExcel($path);
            $io->success('Пример Excel: ' . $path);
        }

        return Command::SUCCESS;
    }

    /** @return array<string, AssetType> */
    private function seedTypes(): array
    {
        $def = [
            'Компьютеры' => [
                'Ноутбук' => [['cpu', 'Процессор', 'string', null], ['ram_gb', 'Оперативная память', 'int', 'ГБ'], ['ssd_gb', 'SSD', 'int', 'ГБ'], ['diagonal', 'Диагональ', 'decimal', '"'], ['os', 'ОС', 'select', null, ['Windows 11', 'Windows 10', 'macOS', 'Linux']]],
                'Системный блок' => [['cpu', 'Процессор', 'string', null], ['ram_gb', 'Оперативная память', 'int', 'ГБ'], ['ssd_gb', 'SSD', 'int', 'ГБ']],
                'Моноблок' => [['cpu', 'Процессор', 'string', null], ['ram_gb', 'Оперативная память', 'int', 'ГБ'], ['diagonal', 'Диагональ', 'decimal', '"']],
            ],
            'Периферия' => [
                'Монитор' => [['diagonal', 'Диагональ', 'decimal', '"'], ['resolution', 'Разрешение', 'string', null]],
                'Док-станция' => [], 'Блок питания' => [['power_w', 'Мощность', 'int', 'Вт']], 'Сумка' => [], 'Клавиатура/мышь' => [], 'Гарнитура' => [],
            ],
            'Печать' => ['МФУ' => [['color', 'Цветная печать', 'bool', null]], 'Принтер этикеток' => []],
            'Сеть' => ['Коммутатор' => [['ports', 'Порты', 'int', null]], 'Точка доступа' => [], 'Маршрутизатор' => []],
            'Серверное' => ['Сервер' => [['cpu', 'Процессор', 'string', null], ['ram_gb', 'Оперативная память', 'int', 'ГБ']], 'ИБП' => [['power_va', 'Мощность', 'int', 'ВА']]],
            'Мобильные' => ['Смартфон' => [['imei', 'IMEI', 'string', null]], 'Планшет' => []],
            'Прочее' => ['Сканер штрихкодов' => [], 'Проектор' => [], 'Прочее' => []],
        ];
        $repo = $this->em->getRepository(AssetType::class);
        $out = [];
        foreach ($def as $cat => $types) {
            foreach ($types as $name => $attrs) {
                $t = $repo->findOneBy(['category' => $cat, 'name' => $name]) ?? (new AssetType())->setCategory($cat)->setName($name);
                if (!$t->getId()) {
                    foreach ($attrs as $i => $a) {
                        $d = (new AttributeDefinition())->setCode($a[0])->setLabel($a[1])->setDataType($a[2])->setUnit($a[3])->setSortOrder($i);
                        if (isset($a[4])) {
                            $d->setOptions($a[4]);
                        }
                        $t->addAttribute($d);
                    }
                    $this->em->persist($t);
                }
                $out[$name] = $t;
            }
        }
        $this->em->flush();

        return $out;
    }

    /** @return list<Location> */
    private function seedLocations(): array
    {
        $mk = function (?Location $parent, LocationType $type, string $name, ?RoomType $rt = null, ?string $code = null): Location {
            $l = (new Location())->setParent($parent)->setType($type)->setName($name)->setRoomType($rt)->setCode($code);
            $this->em->persist($l);

            return $l;
        };
        $kz = $mk(null, LocationType::Country, 'Казахстан');
        $rooms = [];
        foreach ([['Алматы', 'БЦ Нурлы Тау', 'Офис 5', ['3' => ['301', '302', '303'], '4' => ['401', '402']]], ['Астана', 'БЦ Изумрудный квартал', 'Офис 12', ['7' => ['701', '702']]]] as [$city, $bc, $office, $floors]) {
            $c = $mk($kz, LocationType::City, $city);
            $b = $mk($c, LocationType::BusinessCenter, $bc);
            $o = $mk($b, LocationType::Office, $office);
            foreach ($floors as $fl => $rs) {
                $f = $mk($o, LocationType::Floor, $fl . ' этаж');
                foreach ($rs as $r) {
                    $rooms[] = $mk($f, LocationType::Room, $r, RoomType::Cabinet, $city === 'Алматы' ? 'ALA-' . $r : 'AST-' . $r);
                }
                if (in_array((string) $fl, ['3', '7'], true)) {
                    $rooms[] = $mk($f, LocationType::Room, 'Склад IT', RoomType::Warehouse, $city === 'Алматы' ? 'ALA-SKLAD' : 'AST-SKLAD');
                    $rooms[] = $mk($f, LocationType::Room, 'Серверная', RoomType::ServerRoom, $city === 'Алматы' ? 'ALA-SRV' : 'AST-SRV');
                    $rooms[] = $mk($f, LocationType::Room, 'Переговорная', RoomType::Meeting);
                }
            }
        }
        $this->em->flush();

        return $rooms;
    }

    /** @return list<Employee> */
    private function seedEmployees(array $rooms): array
    {
        $deps = [];
        foreach (['IT', 'Бухгалтерия', 'Продажи', 'HR', 'Юридический отдел'] as $d) {
            $deps[$d] = (new Department())->setName($d);
            $this->em->persist($deps[$d]);
        }
        $people = [
            ['Aliya Nurlanovna Seitkali', 'Сейткали Алия Нурлановна', 'Главный бухгалтер', 'Бухгалтерия'],
            ['Daniyar Bekov', 'Беков Данияр Ерланович', 'Системный администратор', 'IT'],
            ['Aigerim Sarsenova', 'Сарсенова Айгерим Маратовна', 'Менеджер по продажам', 'Продажи'],
            ['Ruslan Akhmetov', 'Ахметов Руслан Серикович', 'Руководитель отдела продаж', 'Продажи'],
            ['Madina Omarova', 'Омарова Мадина Кайратовна', 'HR-менеджер', 'HR'],
            ['Timur Zhaksylykov', 'Жаксылыков Тимур Асхатович', 'Юрист', 'Юридический отдел'],
            ['Dana Ibraeva', 'Ибраева Дана Болатовна', 'Бухгалтер', 'Бухгалтерия'],
            ['Arman Kassymov', 'Касымов Арман Нурланович', 'Инженер техподдержки', 'IT'],
        ];
        $offices = $this->em->getRepository(Location::class)->findBy(['type' => LocationType::Office]);
        $out = [];
        foreach ($people as $i => [$en, $ru, $pos, $dep]) {
            $e = (new Employee())->setDisplayName($en)->setFullNameRu($ru)->setFullNameEn($en)->setPosition($pos)->setDepartment($deps[$dep])
                ->setCompany('ТОО «Компания»')->setOffice($offices[$i % count($offices)])
                ->setEmail(strtolower(str_replace(' ', '.', explode(' ', $en)[0] . ' ' . (explode(' ', $en)[2] ?? explode(' ', $en)[1]))) . '@company.local');
            $this->em->persist($e);
            $out[] = $e;
        }
        $this->em->flush();

        return $out;
    }

    private function seedAssets(array $types, array $rooms, array $emps): int
    {
        $models = [
            ['Ноутбук', 'Lenovo', 'ThinkPad T14 Gen 4', ['cpu' => 'Intel Core i5-1335U', 'ram_gb' => '16', 'ssd_gb' => '512', 'diagonal' => '14', 'os' => 'Windows 11']],
            ['Ноутбук', 'Apple', 'MacBook Air M2', ['cpu' => 'Apple M2', 'ram_gb' => '16', 'ssd_gb' => '512', 'diagonal' => '13.6', 'os' => 'macOS']],
            ['Монитор', 'Dell', 'P2422H', ['diagonal' => '23.8', 'resolution' => '1920x1080']],
            ['Док-станция', 'Lenovo', 'ThinkPad USB-C Dock Gen 2', []],
            ['Блок питания', 'Lenovo', '65W USB-C', ['power_w' => '65']],
            ['МФУ', 'HP', 'LaserJet Pro MFP M428fdw', ['color' => '0']],
            ['Коммутатор', 'Cisco', 'Catalyst 1000-24T', ['ports' => '24']],
            ['ИБП', 'APC', 'Smart-UPS 1500', ['power_va' => '1500']],
            ['Принтер этикеток', 'Brother', 'QL-810W', []],
            ['Сканер штрихкодов', 'Mulex', 'ScanFlex 2D', []],
        ];
        $n = 0;
        $warehouse = array_values(array_filter($rooms, fn (Location $r) => $r->getRoomType() === RoomType::Warehouse))[0];
        for ($i = 1; $i <= 40; ++$i) {
            [$type, $vendor, $model, $attrs] = $models[$i % count($models)];
            $a = (new Asset())->setInventoryNumber(sprintf('IT-%06d', $i));
            $a->setFullInventoryNumber(sprintf('00000%06d/IT', $i))->setType($types[$type])->setName($type . ' ' . $vendor . ' ' . $model)
                ->setManufacturer($vendor)->setModel($model)->setSerialNumber(strtoupper(substr(md5((string) $i), 0, 10)))->setCondition('good');
            foreach ($types[$type]->getAttributes() as $d) {
                if (isset($attrs[$d->getCode()])) {
                    $a->setAttribute($d, $attrs[$d->getCode()]);
                }
            }
            if (in_array($type, ['Ноутбук', 'Монитор', 'Док-станция', 'Блок питания'], true) && $i <= 24) {
                $emp = $emps[$i % count($emps)];
                $a->setStatus(AssetStatus::Assigned)->setEmployee($emp)->setLocation($rooms[$i % (count($rooms) - 3)]);
                if ($type === 'Ноутбук') {
                    $a->setHasRecoveryKey(true)->setRecoveryKeyStorage('Entra ID (Intune)');
                }
            } elseif (in_array($type, ['Коммутатор', 'ИБП'], true)) {
                $srv = array_values(array_filter($rooms, fn (Location $r) => $r->getRoomType() === RoomType::ServerRoom))[0];
                $a->setStatus(AssetStatus::Installed)->setLocation($srv);
            } else {
                $a->setStatus(AssetStatus::InStock)->setLocation($warehouse);
            }
            $this->assets->create($a, 'cli', 'Демо-данные');
            ++$n;
        }
        $this->em->flush();

        return $n;
    }

    private function sampleExcel(string $path): void
    {
        $book = new Spreadsheet();
        $s = $book->getActiveSheet();
        $s->setTitle('Оборудование');
        $s->fromArray(['№', 'Дата', 'Инвентарный номер Полный', 'Инвентарный номер', 'Наименование', 'ФИО ENG', 'ФИО RUS', 'Статус', 'Расположение', 'Примечание', 'BitLocker/FileVault Key'], null, 'A1');
        $rows = [
            [1, '15.01.2024', '00000000101/IT', 'NB-0101', 'Ноутбук Lenovo ThinkPad T14', 'Daniyar Bekov', 'Беков Данияр Ерланович', 'Выдан', 'ALA-301', '', '123456-654321-111111-222222-333333-444444-555555-666666'],
            [2, '15.01.2024', '00000000102/IT', 'NB-0102', 'Ноутбук HP ProBook 450', 'Aigerim Sarsenova', '', 'в работе', 'Алматы / БЦ Нурлы Тау / Офис 5 / 3 этаж / 302', '', ''],
            [3, '2024-02-01', '00000000103/IT', 'MN-0103', 'Монитор Dell P2422H', '', '', 'На складе', 'ALA-SKLAD', '', ''],
            [4, '31.02.2024', '00000000104/IT', 'MN-0104', 'Монитор Samsung 27"', 'Unknown Person', 'Неизвестный Сотрудник', 'Списан', 'Склад 2', 'дата некорректна', ''],
            [5, '', '', '', 'Строка без номера', '', '', '', '', '', ''],
            [6, '01.03.2024', '00000000101/IT', 'NB-0101', 'Дубликат номера', '', '', 'На складе', '', '', ''],
            [7, '01.03.2024', '', 'ИТ-0107', 'Кириллица в номере', '', '', 'На складе', '', '', ''],
            [8, 45352, '00000000108/IT', 'PR-0108', 'МФУ HP LaserJet', '', '', 'Установлен', 'ALA-SRV', 'серийная дата Excel', ''],
            [9, '05.03.2024', '00000000001/IT', 'IT-000001', 'Обновленное наименование из Excel', '', '', 'На складе', '', 'совпадение с демо-активом', ''],
            [10, '05.03.2024', '', 'UPS-0110', 'ИБП APC 1500', '', '', 'странный статус', 'Серверная', '', ''],
        ];
        $s->fromArray($rows, null, 'A2');
        (new Xlsx($book))->save($path);
    }
}
