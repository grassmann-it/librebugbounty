<?php

namespace App\Controller;

use App\Service\FindingProblemService;
use App\Service\MissingImageRecoveryService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class MissingImageRecoveryController
{
    public function __construct(private readonly MissingImageRecoveryService $recovery, private readonly FindingProblemService $problems,
        private readonly CsrfTokenManagerInterface $csrf, private readonly UiTranslator $i18n) {}

    #[Route('/errors/missing-images/recover', name: 'missing_image_recovery', methods: ['POST'])]
    public function recover(Request $request): Response
    {
        $input = $request->request->all();
        $token = $input['_token'] ?? null;
        if (!is_string($token) || !$this->csrf->isTokenValid(new CsrfToken('missing_image_recovery', $token))) {
            return new Response($this->i18n->trans('Ungültiges Formular. Bitte lade die Seite neu.'), 403, ['Cache-Control' => 'no-store']);
        }
        try {
            $filter = $this->problems->parse($input);
            $id = $input['finding'] ?? null;
            $url = $input['pocUrl'] ?? null;
            $revision = $input['pocRevision'] ?? null;
            if ($id !== null && (!is_string($id) || !Uuid::isValid($id) || !is_string($url) || !is_string($revision) || !ctype_digit($revision))) {
                throw new \InvalidArgumentException('Ungültiges Formular. Bitte lade die Seite neu.');
            }
            $result = $this->recovery->recover(array_map(strval(...), $filter), $id, $id !== null ? $url : null, $id !== null ? (int) $revision : null);
        } catch (\InvalidArgumentException $error) {
            return new Response($this->i18n->trans($error->getMessage()), 400, ['Cache-Control' => 'no-store']);
        }
        $request->getSession()->getFlashBag()->add('missing_image_recovery', $result);
        return new RedirectResponse('/errors?'.http_build_query($filter, '', '&', PHP_QUERY_RFC3986), 303, ['Cache-Control' => 'no-store']);
    }
}
