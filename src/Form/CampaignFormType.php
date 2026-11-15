<?php

namespace App\Form;

use App\Entity\AssetType;
use App\Entity\InventoryCampaign;
use App\Entity\Location;
use App\Entity\User;
use App\Enum\LocationType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CampaignFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $date = ['required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'];
        $b
            ->add('number', TextType::class, ['label' => 'Номер'])
            ->add('name', TextType::class, ['label' => 'Название'])
            ->add('basis', TextType::class, ['label' => 'Основание (приказ/распоряжение)', 'required' => false])
            ->add('startDate', DateType::class, ['label' => 'Дата начала'] + $date)
            ->add('endDate', DateType::class, ['label' => 'Дата окончания'] + $date)
            ->add('locations', EntityType::class, [
                'class' => Location::class, 'multiple' => true, 'required' => false, 'label' => 'Офисы / помещения (пусто — все)',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('l')
                    ->where('l.type IN (:t)')->setParameter('t', [LocationType::City, LocationType::BusinessCenter, LocationType::Office, LocationType::Floor, LocationType::Room])
                    ->andWhere('l.active = true'),
                'choice_label' => fn (Location $l) => $l->getType()->label() . ': ' . $l->getPathString(),
                'attr' => ['size' => 8],
            ])
            ->add('assetTypes', EntityType::class, [
                'class' => AssetType::class, 'multiple' => true, 'required' => false, 'label' => 'Категории (пусто — все)',
                'choice_label' => fn (AssetType $t) => (string) $t, 'attr' => ['size' => 6],
            ])
            ->add('itResponsible', EntityType::class, [
                'class' => User::class, 'multiple' => true, 'label' => 'Ответственные IT',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('u')->where('u.active = true')->orderBy('u.fullName'),
                'choice_label' => fn (User $u) => (string) $u, 'attr' => ['size' => 5],
            ])
            ->add('commissionUsers', EntityType::class, [
                'class' => User::class, 'multiple' => true, 'required' => false, 'mapped' => false, 'label' => 'Состав комиссии',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('u')->where('u.active = true')->orderBy('u.fullName'),
                'choice_label' => fn (User $u) => (string) $u, 'attr' => ['size' => 5],
                'data' => $o['commission'],
            ])
            ->add('chairman', EntityType::class, [
                'class' => User::class, 'required' => false, 'mapped' => false, 'label' => 'Председатель комиссии', 'placeholder' => '—',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('u')->where('u.active = true')->orderBy('u.fullName'),
                'choice_label' => fn (User $u) => (string) $u,
                'data' => $o['chairman'],
            ])
            ->add('comment', TextareaType::class, ['label' => 'Комментарий', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => InventoryCampaign::class, 'commission' => [], 'chairman' => null]);
    }
}
