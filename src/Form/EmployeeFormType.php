<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\Employee;
use App\Entity\Location;
use App\Enum\LocationType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmployeeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            ->add('displayName', TextType::class, ['label' => 'DisplayName / ФИО ENG'])
            ->add('fullNameRu', TextType::class, ['label' => 'ФИО (рус.)', 'required' => false])
            ->add('fullNameEn', TextType::class, ['label' => 'ФИО (англ.)', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'Email', 'required' => false])
            ->add('upn', TextType::class, ['label' => 'UPN', 'required' => false])
            ->add('position', TextType::class, ['label' => 'Должность', 'required' => false])
            ->add('department', EntityType::class, ['class' => Department::class, 'label' => 'Подразделение', 'required' => false, 'placeholder' => '—', 'choice_label' => 'name',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('d')->orderBy('d.name')])
            ->add('newDepartment', TextType::class, ['label' => '…или новое подразделение', 'required' => false, 'mapped' => false])
            ->add('company', TextType::class, ['label' => 'Компания', 'required' => false])
            ->add('office', EntityType::class, ['class' => Location::class, 'label' => 'Офис', 'required' => false, 'placeholder' => '—',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('l')->where('l.type = :t')->setParameter('t', LocationType::Office),
                'choice_label' => fn (Location $l) => $l->getPathString()])
            ->add('manager', EntityType::class, ['class' => Employee::class, 'label' => 'Руководитель', 'required' => false, 'placeholder' => '—',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('e')->where("e.status = 'active'")->orderBy('e.displayName'),
                'choice_label' => 'name'])
            ->add('status', ChoiceType::class, ['label' => 'Статус', 'choices' => Employee::STATUSES])
            ->add('external1cId', TextType::class, ['label' => 'Внешний ID 1С', 'required' => false])
            ->add('entraObjectId', TextType::class, ['label' => 'Entra Object ID', 'required' => false])
            ->add('extensionAttribute', TextType::class, ['label' => 'extensionAttribute (ФИО из 1С)', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => Employee::class]);
    }
}
