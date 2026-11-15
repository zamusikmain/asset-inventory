<?php

namespace App\Tests\Functional;

use App\Entity\Asset;
use App\Entity\Location;
use App\Entity\OfficePlan;
use App\Entity\User;
use App\Enum\LocationType;
use App\Service\AssetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Удаление локаций: только админ, только пустые; планы удаляются вместе с локацией. */
class LocationDeleteTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function login(array $roles): void
    {
        $u = (new User())->setUsername('ld' . bin2hex(random_bytes(5)))->setFullName('Loc delete')->setRoles($roles);
        $u->setPassword('x')->setMustChangePassword(false);
        $this->em->persist($u);
        $this->em->flush();
        $this->client->loginUser($u);
    }

    private function loc(?Location $parent = null, LocationType $type = LocationType::City): Location
    {
        $l = (new Location())->setParent($parent)->setType($type)->setName('Del ' . bin2hex(random_bytes(4)));
        $this->em->persist($l);
        $this->em->flush();

        return $l;
    }

    private function delete(Location $l): void
    {
        $crawler = $this->client->request('GET', '/locations/' . $l->getId());
        $token = $crawler->filter('form[action$="/locations/' . $l->getId() . '/delete"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/locations/' . $l->getId() . '/delete', ['_token' => $token]);
    }

    private function exists(int $id): bool
    {
        $this->em->clear();

        return $this->em->getRepository(Location::class)->find($id) !== null;
    }

    public function testDeleteEmptyLocationWithPlan(): void
    {
        $this->login(['ROLE_ADMIN']);
        $l = $this->loc();
        $id = $l->getId();
        $plan = new OfficePlan($l, 'План', 'plans/missing.svg', 'image/svg+xml');
        $this->em->persist($plan);
        $this->em->flush();
        $planId = $plan->getId();

        $this->delete($l);
        $this->assertResponseRedirects('/locations');
        $this->assertFalse($this->exists($id));
        $this->assertNull($this->em->getRepository(OfficePlan::class)->find($planId));
    }

    public function testBlockedWhenHasChildrenOrAssets(): void
    {
        $this->login(['ROLE_ADMIN']);
        $city = $this->loc();
        $this->loc($city, LocationType::Office);
        $this->delete($city);
        $this->assertTrue($this->exists($city->getId()), 'узел с вложенными не удаляется');

        $withAsset = $this->loc();
        $a = (new Asset())->setInventoryNumber('LD-' . bin2hex(random_bytes(4)));
        // после запроса клиент пересобирает ядро: берем EM и сервис из текущего контейнера
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $a->setName('Монитор')->setLocation($em->getReference(Location::class, $withAsset->getId()));
        self::getContainer()->get(AssetService::class)->create($a, 'cli');
        $em->flush();
        $this->delete($withAsset);
        $this->assertTrue($this->exists($withAsset->getId()), 'локация с активами не удаляется');
    }

    public function testNonAdminForbidden(): void
    {
        $this->login(['ROLE_IT']);
        $l = $this->loc();
        $crawler = $this->client->request('GET', '/locations/' . $l->getId());
        $this->assertCount(0, $crawler->filter('form[action$="/delete"]'));
        $this->client->request('POST', '/locations/' . $l->getId() . '/delete');
        $this->assertResponseStatusCodeSame(403);
        $this->assertTrue($this->exists($l->getId()));
    }
}
