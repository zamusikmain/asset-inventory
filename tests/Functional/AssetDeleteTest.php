<?php

namespace App\Tests\Functional;

use App\Entity\Asset;
use App\Entity\AssetMovement;
use App\Entity\User;
use App\Service\AssetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Безвозвратное удаление активов: только администратор и только с паролем. */
class AssetDeleteTest extends WebTestCase
{
    private const PASSWORD = 'Corr3ct!Horse#Battery';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(array $roles): User
    {
        $u = (new User())->setUsername('d' . bin2hex(random_bytes(5)))->setFullName('Delete test')->setRoles($roles);
        $u->setPassword(self::getContainer()->get('security.user_password_hasher')->hashPassword($u, self::PASSWORD))->setMustChangePassword(false);
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function asset(string $inv): string
    {
        $a = (new Asset())->setInventoryNumber($inv);
        $a->setName('Удаляемый актив');
        self::getContainer()->get(AssetService::class)->create($a, 'cli');
        $this->em()->flush();

        return $a->getId()->toRfc4122();
    }

    private function exists(string $id): bool
    {
        $this->em()->clear();

        return $this->em()->getRepository(Asset::class)->find($id) !== null;
    }

    private function post(array $data): void
    {
        $this->client->request('GET', '/assets');
        $token = $this->client->getCrawler()->filter('#delete-form input[name=_token]')->attr('value');
        $this->client->request('POST', '/assets/delete', $data + ['_token' => $token]);
    }

    public function testDeleteSelectedRequiresCorrectPassword(): void
    {
        $this->client->loginUser($this->user(['ROLE_ADMIN']));
        $a = $this->asset('DEL-' . bin2hex(random_bytes(4)));
        $b = $this->asset('DEL-' . bin2hex(random_bytes(4)));

        $this->post(['mode' => 'selected', 'ids' => [$a], 'password' => 'wrong']);
        $this->assertTrue($this->exists($a), 'неверный пароль не должен удалять');

        $this->post(['mode' => 'selected', 'ids' => [$a], 'password' => self::PASSWORD]);
        $this->assertResponseRedirects('/assets');
        $this->assertFalse($this->exists($a));
        $this->assertTrue($this->exists($b), 'невыбранный актив остается');
        $this->assertSame(0, $this->em()->getRepository(AssetMovement::class)->count(['asset' => $a]));
    }

    public function testDeleteAllByFilter(): void
    {
        $this->client->loginUser($this->user(['ROLE_ADMIN']));
        $prefix = 'BULK-' . bin2hex(random_bytes(4));
        $ids = [$this->asset($prefix . '-1'), $this->asset($prefix . '-2')];
        $other = $this->asset('KEEP-' . bin2hex(random_bytes(4)));

        $this->post(['mode' => 'all', 'f' => ['q' => $prefix], 'password' => self::PASSWORD]);
        $this->assertResponseRedirects('/assets');
        foreach ($ids as $id) {
            $this->assertFalse($this->exists($id));
        }
        $this->assertTrue($this->exists($other), 'актив вне фильтра остается');
    }

    public function testNonAdminForbidden(): void
    {
        $this->client->loginUser($this->user(['ROLE_IT']));
        $a = $this->asset('DEL-' . bin2hex(random_bytes(4)));
        $this->client->request('GET', '/assets');
        $this->assertSelectorNotExists('#delete-form');
        $this->client->request('POST', '/assets/delete', ['mode' => 'selected', 'ids' => [$a], 'password' => self::PASSWORD]);
        $this->assertResponseStatusCodeSame(403);
        $this->assertTrue($this->exists($a));
    }
}
