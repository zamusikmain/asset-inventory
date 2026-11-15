<?php

namespace App\Form;

use App\Entity\Employee;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            ->add('username', TextType::class, ['label' => 'Логин'])
            ->add('fullName', TextType::class, ['label' => 'ФИО'])
            ->add('email', EmailType::class, ['label' => 'Email', 'required' => false])
            ->add('roles', ChoiceType::class, [
                'label' => 'Роли', 'choices' => User::ROLES, 'multiple' => true, 'expanded' => true,
                'getter' => fn (User $u) => $u->getStoredRoles(),
                'setter' => fn (User $u, array $roles) => $u->setRoles($roles),
            ])
            ->add('employee', EntityType::class, ['class' => Employee::class, 'label' => 'Карточка сотрудника', 'required' => false, 'placeholder' => '—', 'choice_label' => 'name'])
            ->add('upn', TextType::class, ['label' => 'UPN (для будущего SSO)', 'required' => false])
            ->add('active', CheckboxType::class, ['label' => 'Активна', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => User::class]);
    }
}
