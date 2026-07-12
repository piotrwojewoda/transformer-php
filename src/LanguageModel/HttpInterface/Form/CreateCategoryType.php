<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class CreateCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['attr' => ['maxlength' => 120]])
            ->add('save', SubmitType::class, ['label' => 'Create category']);
    }
}
