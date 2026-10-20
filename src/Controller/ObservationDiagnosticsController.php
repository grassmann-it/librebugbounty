<?php

namespace App\Controller;

use App\Entity\Finding;
use App\Entity\HttpObservation;
use App\Service\ObservationDiagnostics;
use App\Service\UiTranslator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ObservationDiagnosticsController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObservationDiagnostics $diagnostics,
        private readonly UiTranslator $i18n,
    ) {
    }

    #[Route('/findings/{id}/diagnostics', name: 'observation_diagnostics', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        $finding = $this->entityManager->find(Finding::class, $id);
        if (!$finding instanceof Finding) return new Response('', 404, $headers);
        $observations = $this->entityManager->getRepository(HttpObservation::class)->findBy(
            ['finding' => $finding], ['createdAt' => 'DESC', 'id' => 'DESC'], 100,
        );
        $selected = null;
        $requested = $request->query->getString('observation');
        if ($requested !== '') {
            $selected = $this->entityManager->find(HttpObservation::class, $requested);
            if (!$selected instanceof HttpObservation || $selected->getFinding()->getId() !== $id) return new Response('', 404, $headers);
        } else {
            $selected = $observations[0] ?? null;
        }
        $diagnostic = $selected === null ? null : $this->diagnostics->inspect($selected);
        $t = fn (string $key): string => $this->i18n->trans($key);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $json = static fn (mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        $locale = $this->i18n->locale();
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/observation-diagnostics.php';
        return new Response(ob_get_clean(), 200, [...$headers, 'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
