<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\Employee;
use App\Entity\License;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LicenseFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $date = ['required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'];
        $b
            ->add('vendor', TextType::class, ['label' => 'Производитель'])
            ->add('product', TextType::class, ['label' => 'Продукт'])
            ->add('licenseType', ChoiceType::class, ['label' => 'Тип лицензии', 'choices' => License::TYPES])
            ->add('contract', TextType::class, ['label' => 'Договор', 'required' => false])
            ->add('subscriptionId', TextType::class, ['label' => 'ID подписки', 'required' => false])
            ->add('seatsTotal', IntegerType::class, ['label' => 'Мест приобретено'])
            ->add('validFrom', DateType::class, ['label' => 'Действует с'] + $date)
            ->add('validUntil', DateType::class, ['label' => 'Действует до'] + $date)
            ->add('cost', NumberType::class, ['label' => 'Стоимость', 'required' => false, 'scale' => 2, 'input' => 'string'])
            ->add('currency', ChoiceType::class, ['label' => 'Валюта', 'choices' => ['KZT' => 'KZT', 'USD' => 'USD', 'EUR' => 'EUR', 'RUB' => 'RUB'], 'required' => false])
            ->add('supplier', TextType::class, ['label' => 'Поставщик', 'required' => false])
            ->add('responsible', EntityType::class, ['class' => Employee::class, 'label' => 'Ответственный', 'required' => false, 'placeholder' => '—', 'choice_label' => 'name',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('e')->orderBy('e.displayName')])
            ->add('department', EntityType::class, ['class' => Department::class, 'label' => 'Подразделение', 'required' => false, 'placeholder' => '—', 'choice_label' => 'name'])
            ->add('status', ChoiceType::class, ['label' => 'Статус', 'choices' => License::STATUSES])
            ->add('external1cId', TextType::class, ['label' => 'Внешний ID 1С', 'required' => false])
            ->add('comment', TextareaType::class, ['label' => 'Примечание', 'required' => false, 'attr' => ['rows' => 3]]);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => License::class]);
    }
}
