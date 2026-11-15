<?php

namespace App\Form;

use App\Entity\Asset;
use App\Entity\AssetBundle;
use App\Entity\AssetType;
use App\Entity\Location;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Карточка актива. Статус, сотрудник и местоположение существующего актива меняются
 * отдельными операциями (выдача/возврат/перемещение/смена статуса) с историей.
 */
class AssetFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            ->add('inventoryNumber', TextType::class, ['label' => 'Инвентарный номер', 'attr' => ['autofocus' => true, 'autocomplete' => 'off']])
            ->add('fullInventoryNumber', TextType::class, ['label' => 'Полный инвентарный номер', 'required' => false])
            ->add('barcode', TextType::class, ['label' => 'Штрихкод (Code 128)', 'required' => false, 'help' => 'По умолчанию совпадает с инвентарным номером'])
            ->add('type', EntityType::class, [
                'class' => AssetType::class, 'label' => 'Тип / категория', 'required' => false, 'placeholder' => '— не указан —',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('t')->orderBy('t.category')->addOrderBy('t.name'),
                'group_by' => fn (AssetType $t) => $t->getCategory(),
                'choice_label' => 'name',
            ])
            ->add('name', TextType::class, ['label' => 'Наименование'])
            ->add('manufacturer', TextType::class, ['label' => 'Производитель', 'required' => false])
            ->add('model', TextType::class, ['label' => 'Модель', 'required' => false])
            ->add('serialNumber', TextType::class, ['label' => 'Серийный номер', 'required' => false])
            ->add('condition', ChoiceType::class, ['label' => 'Состояние', 'choices' => Asset::CONDITIONS, 'required' => false, 'placeholder' => '—'])
            ->add('bundle', EntityType::class, [
                'class' => AssetBundle::class, 'label' => 'Комплект', 'required' => false, 'placeholder' => '— не входит в комплект —',
                'choice_label' => 'name',
            ])
            ->add('sourceDate', DateType::class, ['label' => 'Дата исходной записи', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('comment', TextareaType::class, ['label' => 'Примечание', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('external1cId', TextType::class, ['label' => 'Внешний ID 1С', 'required' => false])
            ->add('hasRecoveryKey', CheckboxType::class, ['label' => 'Есть recovery key (BitLocker/FileVault)', 'required' => false])
            ->add('recoveryKeyStorage', TextType::class, ['label' => 'Где хранится ключ', 'required' => false, 'help' => 'Например: Entra ID / Intune / сейф IT'])
            ->add('recoveryKeyDeviceId', TextType::class, ['label' => 'ID устройства (для ключа)', 'required' => false])
            ->add('recoveryKeyCheckedAt', DateType::class, ['label' => 'Дата проверки наличия ключа', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable']);

        if ($o['is_new']) {
            $b->add('location', EntityType::class, [
                'class' => Location::class, 'label' => 'Местоположение', 'required' => false, 'placeholder' => '— не указано —',
                'mapped' => true,
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('l')->where('l.active = true'),
                'choice_label' => fn (Location $l) => $l->getPathString(),
            ]);
            $b->add('initialStatus', ChoiceType::class, [
                'label' => 'Начальный статус', 'mapped' => false,
                'choices' => ['Создано' => 'created', 'На проверке' => 'review', 'На складе' => 'in_stock', 'В резерве' => 'reserved', 'Установлено в помещении' => 'installed'],
                'data' => 'in_stock',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => Asset::class, 'is_new' => false]);
    }
}
