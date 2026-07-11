<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

// Symfony Form definition for the "generate text" page.
final class GeneratePredictionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // The id of the model to use. Required.
            ->add('modelId', TextType::class, ['constraints' => [new Assert\NotBlank()]])
            // The prompt the user typed. Required, 4-row textarea.
            ->add('prompt', TextareaType::class, ['attr' => ['rows' => 4], 'constraints' => [new Assert\NotBlank()]])
            // Sampling strategy: a dropdown with two choices.
            ->add('strategy', ChoiceType::class, [
                'choices' => ['Greedy' => 'greedy', 'Top-K' => 'top_k'],
                'data' => 'greedy',
            ])
            // How many new tokens to generate.
            ->add('maxNewTokens', IntegerType::class, [
                'data' => 30,
                'constraints' => [new Assert\Positive()],
            ])
            // K for top-K sampling. Optional (only used with TopK).
            ->add('topK', IntegerType::class, [
                'data' => 5,
                'required' => false,
                'constraints' => [new Assert\Positive()],
            ])
            ->add('generate', SubmitType::class, ['label' => 'Generate']);
    }
}
