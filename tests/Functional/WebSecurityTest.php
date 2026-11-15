<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Авторизация, RBAC, блокировка входа, CSRF-защита API. */
class WebSecurityTest extends WebTestCase
{
    private function user(array $roles, bool $mustChange = false): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setUsername('w' . bin2hex(random_bytes(5)))->setFullName('Web test')->setRoles($roles);
        $u->setPassword(self::getContainer()->get('security.user_password_hasher')->hashPassword($u, 'Corr3ct!Horse#Battery'))->setMustChangePassword($mustChange);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testAnonymousRedirectedToLogin(): void
    {
        $client = static::createClient();
        foreach (['/', '/assets', '/assets/new', '/api/v1/lookup?code=X'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseRedirects('/login', null, $url);
        }
        $client->request('GET', '/health');
        $this->assertResponseIsSuccessful();
    }

    public function testRbac(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user(['ROLE_COMMISSION']));
        $client->request('GET', '/assets');
        $this->assertResponseIsSuccessful();
        foreach (['/assets/new', '/import', '/admin/users', '/admin/audit', '/employees'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testMustChangePasswordEnforced(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user(['ROLE_IT'], true));
        $client->request('GET', '/assets');
        $this->assertResponseRedirects('/password');
    }

    public function testLockoutAfterFailedLogins(): void
    {
        $client = static::createClient();
        $u = $this->user(['ROLE_IT']);
        for ($i = 0; $i < 5; ++$i) {
            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->selectButton('Войти')->form(['_username' => $u->getUsername(), '_password' => 'wrong']));
        }
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Войти')->form(['_username' => $u->getUsername(), '_password' => 'Corr3ct!Horse#Battery']));
        $client->followRedirect();
        $this->assertSelectorTextContains('.flash.error', 'заблокирована');
    }

    public function testApiMutationRequiresCustomHeader(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user(['ROLE_IT']));
        $client->request('POST', '/api/v1/inventory/sync', [], [], ['CONTENT_TYPE' => 'application/json'], '{"ops":[]}');
        $this->assertResponseStatusCodeSame(403);
        $client->request('POST', '/api/v1/inventory/sync', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], '{"ops":[]}');
        $this->assertResponseIsSuccessful();
    }
}
