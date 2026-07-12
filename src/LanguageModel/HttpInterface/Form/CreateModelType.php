<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

// Symfony Form definition for the "create model" page.
// Each field has a default value and a validation rule.
final class CreateModelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['attr' => ['maxlength' => 120]])
            // dModel: the width of every vector (default 8).
            ->add('dModel', IntegerType::class, [
                'data' => 8,
                'constraints' => [new Assert\Positive()],
            ])
            // numHeads: how many attention heads (default 1).
            ->add('numHeads', IntegerType::class, [
                'data' => 1,
                'constraints' => [new Assert\Positive()],
            ])
            // numLayers: how many blocks to stack (default 1).
            ->add('numLayers', IntegerType::class, [
                'data' => 1,
                'constraints' => [new Assert\Positive()],
            ])
            // dFf: the hidden FFN size (default 16).
            ->add('dFf', IntegerType::class, [
                'data' => 16,
                'constraints' => [new Assert\Positive()],
            ])
            // maxSeqLen: max sequence length (default 32).
            ->add('maxSeqLen', IntegerType::class, [
                'data' => 32,
                'constraints' => [new Assert\Positive()],
            ])
            // vocabSize: at least 4 (3 reserved + 1 real).
            ->add('vocabSize', IntegerType::class, [
                'data' => 64,
                'constraints' => [new Assert\Positive(), new Assert\GreaterThanOrEqual(4)],
            ])
            // learningRate: must be a positive number.
            ->add('learningRate', NumberType::class, [
                'data' => 0.005,
                'constraints' => [new Assert\Positive()],
                'attr' => ['step' => 'any'],
            ])
            // totalEpochs: how many epochs to train.
            ->add('totalEpochs', IntegerType::class, [
                'data' => 50,
                'constraints' => [new Assert\Positive()],
            ])
            // seqLen: training window size.
            ->add('seqLen', IntegerType::class, [
                'data' => 32,
                'constraints' => [new Assert\Positive()],
            ])
            ->add('save', SubmitType::class, ['label' => 'Create model']);
    }
}
