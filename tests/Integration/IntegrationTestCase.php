<?php

namespace App\Tests\Integration;

use App\Entity\Asset;
use App\Entity\Employee;
use App\Entity\Location;
use App\Entity\User;
use App\Enum\AssetStatus;
use App\Enum\LocationType;
use App\Enum\RoomType;
use App\Service\AssetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Интеграционные тесты на реальной PostgreSQL (БД assets_test). Данные каждого
 * теста уникальны (случайные инвентарные номера), поэтому очистка не требуется.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected User $user;
    private static int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = (new User())->setUsername('t' . bin2hex(random_bytes(5)))->setFullName('Тестовый IT')->setRoles(['ROLE_ADMIN']);
        $this->user->setPassword('x')->setMustChangePassword(false);
        $this->em->persist($this->user);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()));
    }

    protected function service(string $id): object
    {
        return self::getContainer()->get($id);
    }

    protected function uid(string $prefix = 'T'): string
    {
        return strtoupper($prefix . '-' . bin2hex(random_bytes(4)) . '-' . ++self::$seq);
    }

    /** @return array{office: Location, room1: Location, room2: Location, otherOffice: Location, otherRoom: Location} */
    protected function locations(): array
    {
        $mk = function (?Location $p, LocationType $t, string $n, ?RoomType $rt = null) {
            $l = (new Location())->setParent($p)->setType($t)->setName($n . ' ' . $this->uid('L'))->setRoomType($rt);
            $this->em->persist($l);

            return $l;
        };
        $city = $mk(null, LocationType::City, 'Алматы');
        $office = $mk($city, LocationType::Office, 'Офис');
        $floor = $mk($office, LocationType::Floor, 'Этаж');
        $office2 = $mk($city, LocationType::Office, 'Офис 2');
        $out = [
            'office' => $office,
            'room1' => $mk($floor, LocationType::Room, '301', RoomType::Cabinet),
            'room2' => $mk($floor, LocationType::Room, '302', RoomType::Cabinet),
            'otherOffice' => $office2,
            'otherRoom' => $mk($office2, LocationType::Room, '101', RoomType::Cabinet),
        ];
        $this->em->flush();

        return $out;
    }

    protected function employee(?string $email = 'emp@company.local'): Employee
    {
        $e = (new Employee())->setDisplayName('Employee ' . $this->uid('E'))->setFullNameRu('Сотрудник ' . $this->uid('E'))->setEmail($email)->setPosition('Менеджер');
        $this->em->persist($e);
        $this->em->flush();

        return $e;
    }

    protected function asset(?Location $loc = null, AssetStatus $status = AssetStatus::InStock): Asset
    {
        $a = (new Asset())->setInventoryNumber($this->uid('A'));
        $a->setName('Ноутбук тестовый')->setSerialNumber($this->uid('SN'))->setLocation($loc);
        $a->setStatus($status);
        $this->service(AssetService::class)->create($a, 'cli');
        $this->em->flush();

        return $a;
    }
}
