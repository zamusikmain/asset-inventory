<?php

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\AssetBundle;
use App\Entity\AssetType;
use App\Entity\AttributeDefinition;
use App\Exception\BusinessException;
use App\Repository\AssetRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Справочники: типы/категории активов с настраиваемыми атрибутами (п. 6.2) и комплекты (п. 6.3).
 */
#[IsGranted('ROLE_IT')]
class CatalogController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuditLogger $audit)
    {
    }

    private function csrf(string $id, Request $r): void
    {
        if (!$this->isCsrfTokenValid($id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    #[Route('/catalog/types', name: 'type_index')]
    public function types(): Response
    {
        $counts = [];
        foreach ($this->em->createQuery('SELECT IDENTITY(a.type) AS t, COUNT(a.id) AS n FROM App\Entity\Asset a GROUP BY a.type')->getArrayResult() as $r) {
            $counts[(int) $r['t']] = (int) $r['n'];
        }

        return $this->render('catalog/types.html.twig', [
            'types' => $this->em->getRepository(AssetType::class)->findBy([], ['category' => 'ASC', 'name' => 'ASC']),
            'counts' => $counts,
            'data_types' => AttributeDefinition::TYPES,
        ]);
    }

    #[Route('/catalog/types/save', name: 'type_save', methods: ['POST'])]
    public function saveType(Request $r): Response
    {
        $this->csrf('type_save', $r);
        $id = (int) $r->request->getString('id');
        $t = $id ? $this->em->find(AssetType::class, $id) : new AssetType();
        $t->setCategory($r->request->getString('category'))->setName($r->request->getString('name'));
        if ($t->getCategory() === '' || $t->getName() === '') {
            throw new BusinessException('Укажите категорию и тип');
        }
        $this->em->persist($t);
        $this->audit->log($id ? 'catalog.type_update' : 'catalog.type_create', ['objectType' => 'asset_type', 'objectLabel' => (string) $t]);
        $this->em->flush();

        return $this->redirectToRoute('type_index', ['_fragment' => 't' . $t->getId()]);
    }

    #[Route('/catalog/types/{id}/attributes', name: 'attribute_save', methods: ['POST'])]
    public function saveAttribute(AssetType $type, Request $r): Response
    {
        $this->csrf('attr' . $type->getId(), $r);
        $id = (int) $r->request->getString('attr_id');
        $d = $id ? $this->em->find(AttributeDefinition::class, $id) : new AttributeDefinition();
        $d->setCode(mb_strtolower(trim($r->request->getString('code'))))
            ->setLabel(trim($r->request->getString('label')))
            ->setDataType(in_array($r->request->getString('data_type'), AttributeDefinition::TYPES, true) ? $r->request->getString('data_type') : 'string')
            ->setUnit(trim($r->request->getString('unit')) ?: null)
            ->setOptionsText($r->request->getString('options'))
            ->setRequired($r->request->getBoolean('required'))
            ->setSortOrder((int) $r->request->getString('sort_order'));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $d->getCode()) || $d->getLabel() === '') {
            throw new BusinessException('Код атрибута: латиница, цифры, «_»; название обязательно');
        }
        $type->addAttribute($d);
        $this->em->persist($d);
        $this->audit->log('catalog.attribute_save', ['objectType' => 'asset_type', 'objectId' => (string) $type->getId(), 'objectLabel' => (string) $type, 'new' => ['code' => $d->getCode(), 'label' => $d->getLabel(), 'type' => $d->getDataType()]]);
        $this->em->flush();

        return $this->redirectToRoute('type_index', ['_fragment' => 't' . $type->getId()]);
    }

    #[Route('/catalog/attributes/{id}/delete', name: 'attribute_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteAttribute(AttributeDefinition $d, Request $r): Response
    {
        $this->csrf('attrdel' . $d->getId(), $r);
        $type = $d->getAssetType();
        $this->audit->log('catalog.attribute_delete', ['objectType' => 'asset_type', 'objectId' => (string) $type->getId(), 'old' => ['code' => $d->getCode()]]);
        $type->removeAttribute($d);
        $this->em->remove($d);
        $this->em->flush();

        return $this->redirectToRoute('type_index');
    }

    // ------------------------------------------------------------------ комплекты

    #[Route('/bundles', name: 'bundle_index')]
    public function bundles(): Response
    {
        return $this->render('catalog/bundles.html.twig', ['bundles' => $this->em->getRepository(AssetBundle::class)->findBy([], ['name' => 'ASC'])]);
    }

    #[Route('/bundles/save', name: 'bundle_save', methods: ['POST'])]
    public function saveBundle(Request $r): Response
    {
        $this->csrf('bundle_save', $r);
        $id = (int) $r->request->getString('id');
        $b = $id ? $this->em->find(AssetBundle::class, $id) : new AssetBundle();
        $b->setName(trim($r->request->getString('name')))->setComment(trim($r->request->getString('comment')) ?: null);
        if ($b->getName() === '') {
            throw new BusinessException('Укажите название комплекта');
        }
        $this->em->persist($b);
        $this->em->flush();
        $this->audit->log($id ? 'bundle.update' : 'bundle.create', ['objectType' => 'asset_bundle', 'objectId' => (string) $b->getId(), 'objectLabel' => $b->getName()], true);

        return $this->redirectToRoute('bundle_show', ['id' => $b->getId()]);
    }

    #[Route('/bundles/{id}', name: 'bundle_show')]
    public function bundle(AssetBundle $b): Response
    {
        return $this->render('catalog/bundle.html.twig', ['bundle' => $b]);
    }

    /** Добавление компонента по коду (сканером) и удаление компонента. */
    #[Route('/bundles/{id}/items', name: 'bundle_items', methods: ['POST'])]
    public function bundleItems(AssetBundle $b, Request $r, AssetRepository $assets): Response
    {
        $this->csrf('bundle' . $b->getId(), $r);
        if ($code = trim($r->request->getString('code'))) {
            $a = $assets->findByCode($code) ?? throw new BusinessException('Актив ' . $code . ' не найден');
            if ($a->getBundle() && $a->getBundle() !== $b) {
                throw new BusinessException(sprintf('%s уже входит в комплект «%s»', $a->getInventoryNumber(), $a->getBundle()->getName()));
            }
            $a->setBundle($b);
            $this->audit->log('bundle.add_item', ['objectType' => 'asset', 'objectId' => $a->getId()->toRfc4122(), 'objectLabel' => (string) $a, 'new' => ['bundle' => $b->getName()]]);
        }
        if ($remove = $r->request->getString('remove')) {
            $a = $this->em->find(Asset::class, $remove);
            if ($a && $a->getBundle() === $b) {
                $a->setBundle(null);
                $this->audit->log('bundle.remove_item', ['objectType' => 'asset', 'objectId' => $a->getId()->toRfc4122(), 'objectLabel' => (string) $a, 'old' => ['bundle' => $b->getName()]]);
            }
        }
        $this->em->flush();

        return $this->redirectToRoute('bundle_show', ['id' => $b->getId()]);
    }
}
