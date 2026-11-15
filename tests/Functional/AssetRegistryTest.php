<?php

namespace App\Tests\Functional;

use App\Entity\Asset;
use App\Entity\Employee;
use App\Entity\User;
use App\Service\AssetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Реестр: столбцы по формату Excel, фильтры по столбцам, сортировка. */
class AssetRegistryTest extends WebTestCase
{
    public function testColumnsFiltersAndSort(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setUsername('r' . bin2hex(random_bytes(5)))->setFullName('Registry test')->setRoles(['ROLE_IT']);
        $u->setPassword('x')->setMustChangePassword(false);
        $em->persist($u);
        $tag = bin2hex(random_bytes(4));
        $emp = (new Employee())->setDisplayName('John Smith ' . $tag)->setFullNameRu('Смирнов Иван ' . $tag);
        $em->persist($emp);
        $em->flush();

        $mk = function (string $inv, ?Employee $e, bool $rk) use ($em) {
            $a = (new Asset())->setInventoryNumber($inv);
            $a->setName('Ноутбук ' . $inv)->setFullInventoryNumber('FULL-' . $inv)->setComment('прим ' . $inv)
                ->setSourceDate(new \DateTimeImmutable('2026-01-15'))->setHasRecoveryKey($rk);
            if ($e) {
                $a->setEmployee($e);
            }
            self::getContainer()->get(AssetService::class)->create($a, 'cli');
            $em->flush();
        };
        $mk("RA-$tag", $emp, true);
        $mk("RB-$tag", null, false);

        $client->loginUser($u);
        $crawler = $client->request('GET', '/assets');
        $this->assertResponseIsSuccessful();
        $headers = $crawler->filter('table.registry thead tr')->first()->text();
        foreach (['№', 'Дата', 'Инв. номер полный', 'Инв. номер', 'Наименование', 'ФИО ENG', 'ФИО RUS', 'Статус', 'Расположение', 'Примечание', 'BitLocker/FileVault'] as $h) {
            $this->assertStringContainsString($h, $headers);
        }

        $crawler = $client->request('GET', '/assets?emp_ru=' . urlencode('смирнов иван ' . $tag));
        $this->assertStringContainsString("RA-$tag", $crawler->filter('tbody')->text());
        $this->assertStringNotContainsString("RB-$tag", $crawler->filter('tbody')->text());
        $this->assertStringContainsString('John Smith ' . $tag, $crawler->filter('tbody')->text());

        $crawler = $client->request('GET', "/assets?inv=$tag&rk=0");
        $this->assertStringContainsString("RB-$tag", $crawler->filter('tbody')->text());
        $this->assertStringNotContainsString("RA-$tag", $crawler->filter('tbody')->text());

        $crawler = $client->request('GET', "/assets?full=FULL-R&inv=$tag&date=2026-01-15&comment=прим&sort=inv&dir=desc");
        $rows = $crawler->filter('tbody tr')->each(fn ($r) => $r->text());
        $this->assertCount(2, $rows);
        $this->assertStringContainsString("RB-$tag", $rows[0]);

        foreach (['date', 'full', 'emp_en', 'emp_ru', 'loc', 'comment', 'rk'] as $s) {
            $client->request('GET', "/assets?sort=$s&dir=desc");
            $this->assertResponseIsSuccessful($s);
        }
    }
}
