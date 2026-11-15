<?php

namespace App\Tests\Functional;

use App\Entity\Location;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Создание локации из модального окна: форма шлет пустые id и parent. */
class LocationSaveTest extends WebTestCase
{
    public function testCreateRootLocationWithEmptyIdAndParent(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setUsername('l' . bin2hex(random_bytes(5)))->setFullName('Location test')->setRoles(['ROLE_IT']);
        $u->setPassword('x')->setMustChangePassword(false);
        $em->persist($u);
        $em->flush();
        $client->loginUser($u);

        $crawler = $client->request('GET', '/locations');
        $token = $crawler->filter('#loc-modal input[name=_token]')->attr('value');
        $name = 'Almaty ' . bin2hex(random_bytes(4));
        $client->request('POST', '/locations/save', ['id' => '', 'parent' => '', 'type' => 'city', 'room_type' => 'cabinet', 'name' => $name, 'code' => '01', '_token' => $token]);

        $this->assertResponseRedirects();
        $this->assertNotNull($em->getRepository(Location::class)->findOneBy(['name' => $name]));
    }
}
