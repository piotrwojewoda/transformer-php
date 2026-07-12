<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private string $defaultLocale = 'en',
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($locale = $request->query->get('locale')) {
            $request->setLocale($locale);
        } elseif ($cookie = $request->cookies->get('lang')) {
            $request->setLocale($cookie);
        } else {
            $request->setLocale($this->defaultLocale);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }
}
