<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

// Symfony Form definition for the "ingest text" page.
// A form type just describes the fields; the actual rendering
// and binding is handled by the framework.
final class IngestTextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // The user-facing name of the corpus (max 120 chars).
            ->add('name', TextType::class, ['attr' => ['maxlength' => 120]])
            // The actual text, a big textarea (12 rows tall).
            ->add('text', TextareaType::class, ['attr' => ['rows' => 12]])
            // The submit button.
            ->add('save', SubmitType::class, ['label' => 'Ingest']);
    }
}
