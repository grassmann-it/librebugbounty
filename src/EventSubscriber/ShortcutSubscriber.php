<?php

namespace App\EventSubscriber;

use App\Service\ShortcutSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Render the persisted bindings with the page, before any keyboard handler runs. */
final class ShortcutSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ShortcutSettings $shortcuts)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        if ($event->isMainRequest() && !preg_match('~^/(?:api/|artifacts/|review/[^/]+/interactive/)~', $event->getRequest()->getPathInfo())) {
            $event->getRequest()->attributes->set('studio_shortcuts', $this->shortcuts->bindings());
        }
    }
}
